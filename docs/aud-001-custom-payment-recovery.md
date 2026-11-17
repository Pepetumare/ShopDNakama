# AUD-001 · Recuperación de preferencias personalizadas

Actualización posterior a la auditoría adversarial: véase **Corrección de H1–H4 / AUD-001-A, B, C y D** al final. Los resultados y el inventario de 28 archivos de la fase inicial se conservan como historial; la sección nueva registra la corrección y su validación actual. El informe independiente se conserva sin reescribir sus hallazgos.

Fecha: 2026-11-16. Base: `7a2763a` (integración AUD-003). Rama: `codex/aud-001-custom-payment-recovery`.

## Causa raíz

El pedido personalizado se conservaba tras un error, pero su preferencia se emitía dentro de una transacción reintentable, con bloqueos de solicitud y pedido. Si Mercado Pago aceptaba el POST y se perdía la respuesta o fallaba el commit, un nuevo intento volvía a publicar. El transporte predeterminado del SDK también reintenta. La cabecera `X-Idempotency-Key` no constituye una garantía documentada de deduplicación para Preferences API.

La recuperación de COM-02 exigía reservas de stock y comparaba líneas por producto; no era aplicable a personalizados. Sin preferencia ni intento persistido, la conciliación rechazaba el pago anticipado. El scheduler y la lista administrativa podían omitir ese pedido.

## Arquitectura y migración

La migración aditiva `2026_11_16_000001_create_custom_payment_intents.php` incorpora:

- `custom_payment_intents`: intención lógica única por pedido y solicitud, UUID único, referencia externa única, estado, snapshot comercial, representación histórica, inicio del envío, última consulta, token de consulta, ID de preferencia único y nullable, URL, resultado y cursor de búsqueda de pagos. Incluye timestamps e índices por estado y consulta.
- `custom_payment_intent_events`: historial independiente con fuente, resultado, IDs consultados, páginas, coincidencias, clase de excepción, estado HTTP cuando existe, identidad del comercio usada, usuario y motivo. No guarda tokens de acceso, cuerpos HTTP, datos de tarjetas ni mensajes de excepciones.
- `payment_transactions.custom_payment_intent_id`: FK nullable que vincula la observación financiera con la intención.

Las FKs usan `restrictOnDelete`. No se borran ni reescriben evidencias para resolver casos. No se ejecutó esta migración en una base comercial. Las migraciones se verifican con SQLite aislado y MariaDB de pruebas. El `down` es para pruebas/bases vacías; no se debe ejecutar sobre auditoría comercial.

Una intención nueva se crea junto al pedido y sus líneas. Un pedido previo sin intención se importa de forma conservadora como `reconciliation_required`, incluso si no tiene preferencia: no se puede demostrar que nunca se envió. La adopción es gradual durante checkout, webhook, consulta administrativa o scheduler. La lista administrativa también muestra pedidos históricos todavía sin intención.

## Estados y transiciones

| Desde | Hacia | Evidencia necesaria |
|---|---|---|
| `draft` | `sending` | Validación comercial, propietario autorizado y reclamación bajo bloqueo |
| `sending` | `ready` | Respuesta correlacionada al único POST con ID y URL válidos; snapshot sin cambios |
| `sending` | `failed` | `PreferenceNotSent`: validación local de configuración/URL anterior a invocar el transporte |
| `sending` | `reconciliation_required` | Fallo ambiguo, respuesta incompleta o envío abandonado |
| `failed` | `sending` | Revalidación y nueva reclamación; el envío anterior está demostrado como no realizado |
| `reconciliation_required` | `ready` | Lectura de una candidata inequívoca y verificación comercial completa |
| `ready` | — | No admite volver a publicar |

Las actualizaciones al mismo estado son válidas para registrar nuevas consultas. No existe transición de incierto a `draft`, `failed` o `sending`. `failed` no se utiliza para HTTP 4xx/5xx, timeouts, conexiones interrumpidas o fallos de persistencia posteriores al envío. Incluso una caída inmediatamente anterior al transporte deja `sending`: un proceso posterior no sabe hasta dónde llegó el anterior.

Estos estados describen la publicación de la preferencia. No acreditan pagos ni modifican por sí mismos estados financieros, producción o inventario.

## Protocolo de publicación

1. Verificar propiedad, cotización vigente, estado pagable y snapshot comercial.
2. Bloquear solicitud → pedido → intención. Crear/adoptar la intención si corresponde.
3. Solo `draft` o `failed` pueden reclamar publicación. Guardar `sending`, `sending_at` y evento en una transacción corta.
4. Confirmar la transacción y ejecutar exactamente un POST mediante `MercadoPagoSingleRequestTransport`: sin reintentos ni redirecciones, conexión limitada a 4 segundos y solicitud limitada a 10 segundos.
5. Reabrir una transacción corta con el mismo orden de bloqueos. Persistir ID y URL válidos o conservar el ID recuperable de una respuesta incompleta. Un fallo al guardar deja la reclamación anterior persistida.
6. Antes de liberar la URL, releer cotización, pedido e intención. Un webhook que haya acreditado el pago, una cancelación o un cambio comercial impiden devolver el enlace.

El snapshot fija líneas, cantidades, moneda, precios, diseño, despacho y total. Las líneas nuevas usan `custom-line-{order_item_id}` y metadata con `order_id` y UUID de intención. La cabecera de idempotencia se conserva como correlación, sin depender de ella para permitir reenvíos.

## Protocolo de recuperación

Solo utiliza los endpoints de la integración existente:

- `GET /checkout/preferences/search?external_reference=...&limit=20&offset=...`.
- `GET /checkout/preferences/{id}`.
- `GET /v1/payments/search` y `GET /v1/payments/{id}` para verificar información financiera.

Una intención `sending` reciente sin ID conocido espera dos minutos. Las consultas se reclaman con `lookup_at` y un token de fencing, con separación mínima de dos minutos. Un lector lento no puede sobrescribir una consulta posterior; conserva su evidencia como `lookup_superseded`. Todos los GET ocurren fuera de las transacciones.

Si hay un ID persistido, se consulta directamente. También se admite un ID informado por el pago consultado en la API, o por un administrador con motivo y confirmación explícita de procedencia. No se usa `payment.order.id` como ID de preferencia.

La búsqueda por referencia recorre páginas y exige totales consistentes, progreso válido, IDs válidos, ausencia de duplicados y resultados completos. Un techo defensivo de 100 páginas evita búsquedas ilimitadas: alcanzar ese techo con páginas restantes conserva incertidumbre y evidencia; requiere investigación administrativa. Se guardan las páginas recibidas incluso si una página posterior falla. Varias candidatas nunca se seleccionan automáticamente; se registran sus IDs sin realizar consultas de detalle innecesarias a todas ellas.

Para asociar una candidata se exige:

- ID exacto y referencia externa del pedido.
- Metadata del pedido y, para nuevas preferencias, UUID de intención.
- Collector esperado cuando está configurado o fijado en el snapshot. Para históricos es obligatorio configurar `MERCADOPAGO_COLLECTOR_ID` con la identidad real del comercio del ambiente correspondiente.
- Multiconjunto exacto de líneas: ID, título, cantidad, precio y CLP. Incluye el cargo por diseño por separado cuando existe.
- Despacho y suma total exactos contra el snapshot; `total_amount` se verifica si el proveedor lo incluye.
- ID y URL HTTPS válidos, sin credenciales embebidas, para llegar a `ready`.

El mismo total no permite asociar una preferencia. Si el pedido cambia durante las lecturas, se registra `order_snapshot_changed` y se bloquea la asociación. Si el pedido tiene otro ID, se registra `association_conflict`.

Las preferencias históricas podían repetir `custom-{custom_request_id}` para lote y diseño. Se comparan como multiconjunto, sin colapsarlas mediante `keyBy`. No se exige el UUID nuevo a esas preferencias; sí metadata del pedido y collector configurado. No se reescriben preferencias históricas.

La búsqueda de preferencias solo cubre los últimos 90 días. Una intención sin ID fuera de esa ventana queda `outside_search_window`; una búsqueda vacía tampoco demuestra ausencia dentro de la ventana. Los IDs conocidos se consultan directamente sin aplicar ese límite local. Las búsquedas de pagos tampoco representan toda la historia; la consulta de IDs ya conocidos se conserva aunque la búsqueda falle o esté vacía. Cero resultados, resultados múltiples, fallos HTTP, incoherencias o falta de identidad comercial nunca habilitan un nuevo POST.

Fuentes oficiales consultadas: [referencia de Checkout Pro por preferencias](https://www.mercadopago.cl/developers/en/reference/online-payments/checkout-pro-preferences/overview), [búsqueda de preferencias](https://www.mercadopago.cl/developers/es/reference/online-payments/checkout-pro/preferences/search-preferences/get), [ventana de 90 días](https://www.mercadopago.cl/developers/es/news/2025/03/04/Updates-on-the-Preferences-and-Merchant-Orders-APIs), [búsqueda de pagos](https://www.mercadopago.cl/developers/en/reference/online-payments/checkout-api-payments/search-payments/get).

## Webhook y conciliación

La validación de firma y la extracción del ID en `MercadoPagoWebhookController` se conservan. `PaymentReconciliation` consulta el pago auténtico y persiste la transacción financiera y su intención antes de intentar recuperar la preferencia. Ante asociación pendiente responde 503 para permitir reintento; no marca el pedido pagado ni aprueba la cotización. Las discrepancias de importe o moneda se registran como incidencias.

Si se recupera la preferencia, se consulta nuevamente el pago fuera de bloqueos y se aplica la lógica existente. No se reconstruye `paid` a partir de metadata, la preferencia, una observación anterior o el retorno del navegador. Se conserva la unicidad proveedor/ID de pago, la precedencia de aprobaciones y reversos, detección de pagos adicionales y tratamiento de pagos tardíos de pedidos cancelados.

La conciliación manual y `payments:reconcile` comparten la recuperación. El scheduler incluye intenciones inciertas aunque no tengan stock ni transacciones, y pedidos históricos sin intención/preferencia. Para personalizados conserva el cursor de páginas de pagos entre lotes y vuelve a verificar observaciones conocidas, incluidas aprobaciones retenidas por falta de preferencia. Una consulta fallida conserva el cursor para reintentar esa página.

Las reglas de AUD-003 sobre pago vigente, preparación, despacho, asignación de stock, reembolsos, contracargos e incidencias no se relajan. Una preferencia recuperada no habilita producción por sí sola.

## Recuperación administrativa verificable

En Housekeeping → Conciliación de pagos:

1. Revisar la lista de personalizados inciertos/fallidos y los pedidos históricos sin preferencia. Se muestran creación, antigüedad, último resultado y última consulta.
2. Abrir el pedido y pulsar **Consultar Mercado Pago**. Es una operación de lectura, con autorización administrativa, CSRF y límite de frecuencia.
3. Consultar el historial de intención: páginas, IDs, coincidencias, errores, fuente, usuario y motivos; además del historial financiero existente.
4. Si hay varias candidatas, falta identidad o se agotó la ventana, obtener evidencia del panel/soporte de Mercado Pago del ambiente correcto. Registrar la referencia de esa evidencia y un motivo sin credenciales ni datos de tarjeta.
5. Si se obtiene un ID inequívoco, ingresarlo con motivo y confirmación de procedencia. El servidor consulta su detalle y verifica todos los atributos; la declaración administrativa sola no modifica el estado financiero ni fuerza la asociación.
6. Repetir la conciliación para consultar los pagos reales. Las incidencias financieras y operativas siguen su resolución independiente con las validaciones de AUD-003.

No hay acciones de reiniciar, borrar, liberar como «no enviado» ni emitir otra preferencia desde incertidumbre. Cuando no existe evidencia suficiente, se registra una observación administrativa y el caso permanece abierto y visible. Si se necesitaran acciones comerciales sobre preferencias remotas duplicadas, requerirían un procedimiento separado: este cambio no cancela, reembolsa ni modifica recursos del proveedor.

## Experiencia del cliente

- `ready`: pago listo para continuar, sin afirmar acreditación.
- `sending` / `reconciliation_required`: «Estamos verificando el inicio de tu pago. No necesitas intentarlo nuevamente por ahora». El formulario de emisión queda oculto; repetir el POST manualmente tampoco vuelve a emitir.
- `failed`: fallo local confirmado anterior al envío; se permite reintentar.
- Candidatas múltiples, discrepancias, ventana agotada, conflicto comercial o más de un día sin resolución: se solicita asistencia con el número de pedido.

## Bloqueos y ciclos

Las operaciones nuevas usan solicitud → pedido → intención → eventos/transacciones financieras. La aplicación financiera continúa hacia productos ordenados por ID y reservas según la arquitectura existente. Ninguna ruta nueva toma primero intención y después pedido o solicitud. La recuperación nunca toma el bloqueo de usuario/carrito del checkout de catálogo.

AUD-003 toma solicitud → pedido para transiciones personalizadas y resolución de preparación; las observaciones financieras toman pedido → transacción y no esperan posteriormente una solicitud. La posesión del pedido serializa los accesos que combinan intención, pagos e inventario. El catálogo conserva su orden existente usuario → intento de catálogo → pedido. No se añadió un ciclo entre ambos tipos de intento.

El token de consultas evita sobrescrituras entre lectores fuera de transacción. Los bloqueos de cache siguen siendo una optimización operativa; la exclusión de publicación depende de InnoDB y del estado persistido, no de la sesión ni de un cache local.

## Archivos y alcance

- Nuevos: migración, modelos `CustomPaymentIntent`/`CustomPaymentIntentEvent`, servicios `CustomPaymentRecovery`/`CustomPaymentPending`, pruebas `CustomPaymentRecoveryTest`/`CustomPaymentConcurrencyTest` y worker separado.
- Modificados: `CustomQuoteCheckout`, `MercadoPagoService`, `PaymentReconciliation`, controladores de cotización y conciliación, relaciones de `Order`/`PaymentTransaction`, configuración de collector, ruta administrativa y cuatro vistas de cliente/administración.
- Pruebas existentes adaptadas: transporte de SDK personalizado, diseño asistido, fallo confirmado anterior al envío y cache persistente de URL. `Tests\TestCase` bloquea solicitudes Laravel HTTP que no estén simuladas.
- Sin reescritura del checkout de catálogo, webhook firmado, inventario o transiciones de AUD-003. `.agents/` y `.codex/` no se editan ni se incluyen en commits.

## Validación y riesgos residuales

Los resultados finales de las suites se registran en la entrega. La ejecución inicial con PHP CLI encontró 52 errores por GD deshabilitado; se resolvió habilitando la extensión únicamente en el proceso de pruebas (`php -d extension=gd`), sin editar `php.ini` ni `.env`.

Resultados finales:

| Verificación | Resultado |
|---|---|
| Suite PHP completa | 298 pruebas, 3197 aserciones, cero errores/fallos; 11 casos concurrentes omitidos en SQLite |
| MariaDB/InnoDB con procesos separados | 11 pruebas, 145 aserciones, cero omisiones/fallos; cubre los 11 casos omitidos en SQLite |
| Suite JavaScript | 13 pruebas aprobadas |
| Migración en MariaDB vacía adicional | Creación, rollback de la migración nueva y reaplicación aprobados |
| Pint sobre todos los PHP modificados/nuevos | Aprobado |
| `git diff --check` | Aprobado |
| Vistas | Renderizado y contenido comprobados en pruebas; revisión visual pendiente por host de navegador indisponible |

La suite completa incluye pagos personalizados, diseño asistido, checkout de catálogo, webhook, conciliación y regresiones de AUD-003. Las pruebas nuevas de transporte cubren también un error en páginas posteriores con preservación de evidencia y una candidata con total correcto pero reparto incorrecto entre lote y diseño. No quedan fallos nuevos conocidos en las verificaciones locales.

Estado Git al entregar: HEAD y base `main`/`origin/main` en `7a2763a954876920abf81e48f48d28e6ccfeb3f2`, rama activa `codex/aud-001-custom-payment-recovery`, cambios locales sin commit; `.agents/` y `.codex/` conservadas sin seguimiento. Sin merge, push o despliegue.

Inventario exacto de archivos de AUD-001 (28):

```text
app/Http/Controllers/CustomQuoteController.php
app/Http/Controllers/Housekeeping/PaymentReconciliationController.php
app/Models/CustomPaymentIntent.php
app/Models/CustomPaymentIntentEvent.php
app/Models/Order.php
app/Models/PaymentTransaction.php
app/Services/CustomPaymentPending.php
app/Services/CustomPaymentRecovery.php
app/Services/CustomQuoteCheckout.php
app/Services/MercadoPagoService.php
app/Services/PaymentReconciliation.php
config/services.php
database/migrations/2026_11_16_000001_create_custom_payment_intents.php
docs/aud-001-custom-payment-recovery.md
docs/com-02-checkout.md
docs/personalizados-pagos.md
resources/views/account/custom-requests/show.blade.php
resources/views/components/custom-requests/payment-status.blade.php
resources/views/housekeeping/payments/index.blade.php
resources/views/housekeeping/payments/show.blade.php
routes/housekeeping.php
tests/Feature/CustomPaymentConcurrencyTest.php
tests/Feature/CustomPaymentRecoveryTest.php
tests/Feature/CustomQuoteMercadoPagoServiceTest.php
tests/Feature/CustomQuotePaymentTest.php
tests/Feature/DesignAssistanceTest.php
tests/Support/custom-payment-worker.php
tests/TestCase.php
```

Las pruebas usan transportes falsos y bases aisladas. MariaDB 10.11.16/InnoDB se ejecutó en `127.0.0.1:33317`, con datos temporales y base `nakama_com01_test`; los workers rechazan otros destinos. Se comprueban procesos superpuestos, exclusión de emisión, adquisición de bloqueos mientras HTTP espera, muerte de proceso antes/después de envío y durante persistencia, y webhook anticipado mientras el publicador sigue activo. También se ejecutan las carreras existentes de catálogo, conciliación y AUD-003.

Reproducir las pruebas concurrentes requiere iniciar una instancia MariaDB aislada en ese puerto, crear únicamente esa base de pruebas y exportar `APP_ENV=testing`, `DB_CONNECTION=mysql`, `DB_HOST=127.0.0.1`, `DB_PORT=33317`, `DB_DATABASE=nakama_com01_test`, `DB_USERNAME`/`DB_PASSWORD` de esa instancia, `DB_URL` vacío y `NAKAMA_RESERVATION_RACE=1`. Ejecutar PHPUnit con el filtro `CustomPaymentConcurrencyTest|PaymentReconciliationConcurrencyTest|StockReservationConcurrencyTest`. No dirigir este comando a una base comercial.

Persiste la incertidumbre inherente cuando el proveedor no devuelve evidencia, cuando hay duplicados previos o cuando se pierde un ID fuera de su ventana de búsqueda. Se conserva la evidencia y se requiere asistencia; no existe una deducción segura de «no se creó». El scheduler depende de que el cron y el cache compartido de la instalación funcionen. Antes de cualquier despliegue futuro se requiere respaldo, migración aditiva y verificación del collector del ambiente; no se efectuó despliegue en esta tarea.

### Validación independiente con Mercado Pago en sandbox

Pendiente, sin utilizar credenciales ni dinero reales en esta implementación:

1. Confirmar representación real de metadata, collector, líneas históricas/nuevas, diseño y despacho en POST/GET.
2. Verificar paginación y ventana temporal, y latencia de consistencia de búsqueda.
3. Provocar pérdida controlada de respuesta sin repetir POST; recuperar por referencia e ID.
4. Enviar webhooks firmados anticipados/repetidos y verificar pagos pendientes, aprobados, rechazados, reembolsos y contracargos de prueba.
5. Confirmar que diferencias de preferencia/moneda/importe y pagos adicionales mantienen las incidencias y bloquean preparación cuando corresponde.

Este documento no declara cerrado el requisito de validación contra el proveedor. No se hizo merge, push ni despliegue, y no se inició AUD-002 ni AUD-004.

## Corrección de H1–H4 / AUD-001-A, B, C y D

Fecha: 2026-11-16. Se reprodujeron los cuatro fallos antes de editar código: cuatro pruebas, 14 aserciones, cuatro fallos. `tests/Feature/Aud001AdversarialReviewTest.php` conserva exactamente su hash y sus aserciones. Se respaldaron los archivos locales modificados/nuevos en una carpeta temporal antes de corregir; no se hizo stash, limpieza ni reset. `.agents/` y `.codex/` se preservan y sus hashes se comprueban al entregar.

### Causas raíz y correcciones

| Hallazgo | Causa raíz | Corrección implementada |
|---|---|---|
| H1 / AUD-001-A | `ready` y pedido local pendiente bastaban para devolver la URL, sin considerar la aprobación observada o incidencias financieras | Una consulta común de retención financiera bloquea publicación y entrega de URL bajo bloqueo de pedido/transacciones. Se intentan lecturas acotadas de pagos conocidos fuera de transacción y se vuelve a comprobar antes del redirect |
| H2 / AUD-001-B | Al pasar a `ready`, un pedido antiguo podía dejar de cumplir los filtros temporales del scheduler aunque su aprobación no se hubiese aplicado | Seguimiento financiero persistente e independiente del estado de publicación, con próxima revisión, último intento, resultado y token. El selector también encuentra aprobaciones antiguas no aplicadas sin depender del marcador nuevo |
| H3 / AUD-001-C | El ID guardado siempre prevalecía sobre el candidato administrativo, aunque no estuviese verificado | La propuesta administrativa autorizada evalúa el ID anterior y el alternativo, conserva ambos y su evidencia, comprueba candidatos reportados y pagos contradictorios, y solo asocia sin conflictos bajo bloqueo |
| H4 / AUD-001-D | La discrepancia de preferencia retornaba antes de crear la transacción financiera | La observación API-verificada se guarda primero con unicidad proveedor/ID, luego se marca `preference_mismatch` y se rechaza su aplicación comercial. La identidad desconocida queda como evidencia sin atribución forzada |

### Nuevas invariantes comerciales

1. La única ruta que entrega una URL personalizada sigue siendo `CustomQuoteController` → `CustomQuoteCheckout`. Publicación inicial, recuperación y repetición del navegador pasan por la misma decisión final. Los retornos `success` continúan sin acreditar pagos ni entregar preferencias.
2. La reclamación de un POST también comprueba retenciones financieras antes de enviar. Ninguna aprobación observada autoriza una nueva publicación; la preferencia y los IDs se conservan para investigar.
3. Justo antes de devolver una URL se releen cotización, pedido e intención bajo solicitud → pedido → intención, y las transacciones financieras pertinentes bajo bloqueo ordenado por ID. Una aprobación, pago pendiente/en proceso, reverso o incidencia financiera abierta impide liberar el enlace. El rechazo/cancelación verificados sin aprobación previa ni incidencia pueden reutilizar una preferencia existente.
4. Una aprobación observada no se convierte en `paid` por existir en el historial. Se consulta el pago mediante el protocolo existente y se mantienen referencia, ID, monto, moneda, preferencia y precedencia financiera. Si no se puede comprobar el estado actual, continúa la verificación y no se libera la URL.
5. Para aprobaciones aún no aplicadas, una lectura posterior que contradiga la aprobación con un estado no terminal seguro se registra como `approval_regression`; no habilita otro cobro. Un `approved_at` conocido tampoco permite reabrir checkout por un simple rechazo posterior. Los pedidos ya pagados o revertidos conservan la precedencia terminal de AUD-003.
6. Los estados financieros y el bloqueo de preparación/despacho no se confunden con publicación. `preference_mismatch` queda como incidencia de una transacción, sin sustituir el pago principal ni aplicar inventario. `Order::assertFulfillable` de AUD-003 detecta esa incidencia y sigue bloqueando las transiciones.
7. Resolver una incidencia administrativa no equivale a aplicar un pago. Una aprobación de un pedido todavía pendiente sigue elegible incluso si alguien registró una resolución de incidencia. No se elimina esa observación ni se utiliza su resolución como acreditación.

La protección del servidor se lineariza en la decisión final bajo bloqueo. Si una aprobación se confirma antes de adquirir esos bloqueos, el enlace se retiene. Una notificación que llegue después de la decisión o una URL que el comprador ya tenía son límites distribuidos distintos: Laravel no puede revocar lo que ya está fuera de su control.

### Seguimiento financiero persistente y límites de trabajo

Nueva migración aditiva: `2026_11_16_000002_add_custom_financial_follow_up.php`. Añade a `custom_payment_intents`:

- `financial_review_required`: existe trabajo financiero pendiente, independiente de `state`.
- `financial_reviewed_at`: último intento de revisión financiera.
- `financial_next_review_at`: próxima revisión programada; índice para consulta.
- `financial_review_outcome`: resultado operativo de la revisión o causa de retención.
- `financial_review_token`: identidad de la revisión; evita que un lector anterior cierre el trabajo de una revisión posterior.

No se creó otra tabla de movimientos ni se cambiaron las FKs o columnas financieras históricas. La migración inicial se conserva intacta. El fallback del selector reconoce aprobaciones ya existentes sin aplicación al pedido, incluso si no tenían todavía `financial_review_required`.

Recuperar una preferencia solicita seguimiento financiero; no lo da por terminado. Errores del proveedor, aprobaciones retenidas y discrepancias también lo mantienen. El trabajo solo puede quedar sin revisión requerida después de consultas financieras efectivas, asociación lista y ausencia de observaciones pendientes/incidencias; una búsqueda vacía sola nunca lo cierra. Si el pedido tiene un pago canónico conocido, un GET correcto de ese pago sí aporta evidencia independiente de la búsqueda vacía.

Se conserva el lote programado de cinco pedidos y la frecuencia existente; los trabajos pendientes reintentan con separación de dos horas y permanecen visibles aunque sean antiguos. La vista administrativa muestra último intento, resultado y próxima revisión. Las revisiones manuales pueden consultar antes de esa fecha, bajo el mismo bloqueo de cache por pedido.

El navegador consulta como máximo diez pagos conocidos por intento y separa esas revisiones al menos 30 segundos mediante estado persistido; no utiliza POST como recuperación. Tanto revisión manual/programada como revisión del navegador usan `mercadopago-reconciliation-order:{id}`. Los tokens persistidos protegen el cierre aunque expire el lease de cache o un proceso se interrumpa. La consulta financiera no mantiene bloqueos comerciales durante HTTP.

No se corrigió la paginación general de AUD-004. Se conserva la infraestructura de páginas/cursor ya existente en AUD-001. Este cambio evita que la antigüedad descarte trabajo observado; no afirma exhaustividad ilimitada de las búsquedas del proveedor. Los límites de lectura se conservan de manera explícita y nunca habilitan publicaciones nuevas.

### Evidencia financiera discrepante y sin atribución

Para un pago con ID/referencia verificables y preferencia distinta se realiza el upsert existente con restricción única `(provider, provider_payment_id)`, se conserva la observación de importe/moneda/estado/preferencia y se crea o mantiene `preference_mismatch`. Repetir o conciliar simultáneamente la notificación conserva una transacción y agrega eventos de historia. El pedido y el ID canónico no se cambian automáticamente.

Un ID ya vinculado a otro pedido no se mueve. Una referencia desconocida o identidad incompatible no fuerza la FK `order_id` de una transacción. En esos casos se conserva un evento de investigación sin pedido atribuido, con identificadores y datos monetarios estructurados y acotados. Esa es una observación en cuarentena, no una acreditación ni un movimiento comercial nuevo. El panel incorpora la cola de observaciones sin atribución; sus repeticiones son historial de consultas, no múltiples movimientos financieros.

Las evidencias se imprimen escapadas y no contienen cuerpos de respuesta, tokens, firmas o textos de excepciones. La actualización no elimina pedidos, pagos o eventos para resolver discrepancias.

### Candidatos administrativos y conflictos

Una propuesta de ID exige administrador y motivo en el servicio, además de los controles de la ruta: auth/admin, CSRF, validación y throttle. El evento registra actor, fecha, motivo, ID anterior, candidato propuesto y resultado.

- `ready` representa una asociación verificada y no se sustituye por una propuesta distinta. Se registra `verified_preference_immutable` sin emitir ni reemplazar preferencias.
- Un ID candidato conservado tras respuesta incompleta puede no ser verificable. El administrador puede proponer una alternativa, que requiere toda la correspondencia comercial del snapshot y, en preferencias nuevas, el UUID de la intención.
- Si el anterior también resulta comercialmente válido, se conserva incertidumbre y `candidate_conflict`; no se elige silenciosamente uno de dos candidatos válidos.
- Las preferencias históricas carecen del UUID nuevo. Un error de red al consultar el ID anterior no basta para descartar su validez; se conserva conflicto. Un 404 verificado o una incompatibilidad comercial demostrada permite evaluar una alternativa histórica con metadata de pedido y collector esperado.
- Cambiar un ID histórico asociado pero todavía no verificado requiere además que no exista un pago canónico, que ese ID siga siendo el anterior bajo bloqueo y que no haya pagos relevantes contradictorios. El ID anterior no se borra del historial.
- Una preferencia asociada a pagos aprobados, pendientes o revertidos incompatibles con el candidato produce `payment_candidate_conflict`; no se altera su atribución para hacer pasar la recuperación.
- Se consideran los candidatos reportados por búsquedas anteriores. La evaluación queda acotada a 20 IDs/historial de conflictos; si se alcanza el límite o un candidato adicional reportado no puede descartarse, el caso sigue incierto y requiere asistencia. El límite no descarta evidencia para forzar asociación.

### Concurrencia y claves foráneas

El orden de las operaciones nuevas sigue siendo solicitud → pedido → intención → transacciones financieras ordenadas → productos/reservas según el flujo existente. Las revisiones comienzan y terminan en transacciones cortas; ningún GET/POST forma parte de esas transacciones. La comprobación final de URL se realiza después de cualquier lectura externa y vuelve a adquirir los bloqueos.

Las FKs de transacciones/eventos hacia pedido e intención realizan comprobaciones sobre padres ya bloqueados por la operación. No se añadió una FK nueva ni una adquisición inversa para el seguimiento financiero. La unicidad proveedor/ID sigue siendo global: un conflicto con un movimiento ya atribuido se investiga y no reasigna el dinero.

La FK existente `custom_payment_intent_events.actor_id → users` implica un bloqueo compartido del actor al insertar evidencia administrativa. No se añadieron actualizaciones del usuario dentro de los recorridos financieros. Se conserva como riesgo de contención preexistente la eliminación concurrente de usuarios: InnoDB puede detectar un deadlock con cascadas hacia pedidos/solicitudes y las transacciones locales tienen reintentos acotados. Esos reintentos nunca incluyen HTTP ni reemiten preferencias. Si se elimina el actor durante una decisión, esta puede fallar por FK y permanecer sin confirmar; se requiere otro administrador vigente, sin borrar evidencia para continuar.

Se añadieron procesos y barreras específicos para aprobación previa a la decisión de URL (aplicada y retenida por discrepancia), dos notificaciones discrepantes, dos recuperaciones administrativas, scheduler frente a manual y recuperación frente a reembolso. Los workers rechazan cualquier destino distinto de MariaDB aislada `127.0.0.1:33317/nakama_com01_test` y no permiten publicaciones en esas regresiones.

### Experiencia del cliente y URLs previamente entregadas

La retención financiera oculta el formulario de pago incluso si la preferencia está `ready`. El POST devuelve un estado informativo de verificación, sin presentar la retención esperada como error genérico. Los fallos confirmados anteriores al envío conservan el error recuperable. Una redirección legítima usa HTTP 303 y `Cache-Control: no-store`.

**RIESGO FUNDAMENTADO:** una URL previamente entregada puede seguir siendo utilizada directamente fuera de Laravel. El cambio no la revoca ni promete impedir un pago que el proveedor ya esté procesando.

**VERIFICADO documentalmente:** Mercado Pago permite definir la vigencia de preferencias mediante `expires`/fechas, y documenta `PUT /checkout/preferences/{id}` para actualizar preferencias antes de completar el pago. También documenta el cambio de `date_of_expiration`, relacionado con vencimiento de pagos; no es una garantía universal de revocación atómica de enlaces y pagos en curso. [Vigencia de preferencias](https://www.mercadopago.cl/developers/en/docs/checkout-pro-preferences/additional-settings/term-of-preference?scope=prod), [actualización de preferencia](https://www.mercadopago.cl/developers/es/reference/online-payments/checkout-pro-preferences/update-preference/put), [cambio de vencimiento](https://www.mercadopago.cl/developers/en/docs/checkout-pro-preferences/additional-settings/expiration-date?scope=prod).

**NO VERIFICABLE en este trabajo:** el efecto exacto de esas modificaciones sobre cada medio de pago, enlaces ya abiertos y pagos pendientes. No se ejecutaron PUT ni operaciones financieras externas. Una eventual política de expiración/cancelación requerirá prueba de sandbox específica y autorización del procedimiento; no se incorpora como efecto lateral de recuperar preferencias.

### Archivos de esta corrección

Se conserva el inventario inicial y se modifican únicamente sus puntos relacionados:

- `app/Services/CustomQuoteCheckout.php`, `CustomPaymentRecovery.php`, `PaymentReconciliation.php`.
- `app/Models/Order.php`, `CustomPaymentIntent.php`, `PaymentTransaction.php`.
- `app/Http/Controllers/CustomQuoteController.php` y `Housekeeping/PaymentReconciliationController.php`.
- Las cuatro vistas existentes de pago personalizado y conciliación.
- `tests/Feature/CustomPaymentRecoveryTest.php`: el caso administrativo usa actor/motivo reales de prueba; mantiene su resultado esperado.
- `tests/Feature/PaymentReconciliationTest.php`: la discrepancia ahora exige una transacción retenida, en lugar de esperar su ausencia. Se añaden aserciones de incidencia y estado financiero.
- Este informe técnico. `docs/aud-001-independent-review.md` y las cuatro pruebas adversariales quedan intactos como evidencia.

Archivos nuevos: migración `2026_11_16_000002_add_custom_financial_follow_up.php`, `CustomPaymentFindingsTest.php`, `CustomPaymentFindingsConcurrencyTest.php` y `tests/Support/custom-payment-findings-worker.php`.

### Resultado y requisitos de cierre

Los resultados finales individuales y generales de esta corrección se consignan a continuación. Las cuatro regresiones originales no se debilitaron. Las condiciones verificadas localmente no sustituyen los requisitos externos.

**VERIFICADO — regresiones adversariales originales:**

| Prueba original sin cambios | Resultado individual |
|---|---|
| Pedido antiguo con aprobación observada y error transitorio después de recuperar preferencia (H2/B) | Aprobada; continúa seleccionándose después del intervalo de revisión |
| Candidato administrativo correcto con ID persistido no verificable (H3/C) | Aprobada; evalúa y asocia el candidato válido con evidencia |
| Pago adicional de otra preferencia (H4/D) | Aprobada; persiste una transacción con incidencia y conserva el pago principal |
| Recuperación del navegador con aprobación observada sin aplicar (H1/A) | Aprobada; no devuelve URL pagable |

**VERIFICADO — ejecución final:**

| Verificación | Resultado |
|---|---|
| Cuatro adversariales originales | 4 pruebas / 14 aserciones, aprobadas sin cambiar el archivo |
| Suite PHP completa | 333 pruebas / 3371 aserciones, cero errores/fallos; 17 casos de concurrencia omitidos en SQLite |
| MariaDB 10.11.16/InnoDB, procesos separados | 17 pruebas / 227 aserciones, sin omisiones/fallos: las 11 originales y 6 casos nuevos |
| Variantes funcionales nuevas | 25 casos en `CustomPaymentFindingsTest`; incluidos en la suite completa |
| JavaScript | 13 pruebas aprobadas |
| Migración nueva en MariaDB aislada vacía | Creación, rollback y reaplicación aprobados en `nakama_aud001_fixes_schema` |
| Pint y `git diff --check` | Aprobados |
| Integridad de pruebas/directorios protegidos | Hash adversarial sin cambios; 65 archivos de `.agents/` y `.codex/` sin diferencias |

Las suites completas incluyen checkout personalizado y de catálogo, webhook, conciliación, reembolsos, contracargos, resolución de incidencias y transiciones de AUD-003. No quedan fallos locales conocidos en estas verificaciones. Los cuatro fallos iniciales quedaron corregidos; las dos expectativas antiguas ajustadas se describen en el inventario y no se ocultaron como omisiones.

**Estado Git:** rama `codex/aud-001-custom-payment-recovery`; HEAD, `main` y `origin/main` permanecen en `7a2763a954876920abf81e48f48d28e6ccfeb3f2`. Cambios locales sin commit ni staging. Se conserva la auditoría anterior como historial y no se ejecutó merge, push o despliegue.

**Recomendación:** AUD-001 técnicamente corregido en el entorno local y listo para validación externa. Esto no autoriza integración ni declara cerrado el trabajo completo.

La validación de sandbox y la revisión visual continúan pendientes e independientes. Se deben repetir las secuencias H1–H4 y las variantes nuevas con respuestas reales redactadas y comprobar la política de vigencia del proveedor por medio de pago. No se utilizaron credenciales del entorno desplegado ni se modificó `.env`. AUD-002 y AUD-004 siguen fuera de alcance. No hay autorización ni ejecución de commit, push, merge o despliegue.
