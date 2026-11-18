# AUD-001 · Auditoría adversarial posterior a la implementación

Fecha: 2026-11-16. Base y HEAD: `7a2763a954876920abf81e48f48d28e6ccfeb3f2`. Rama: `codex/aud-001-custom-payment-recovery`.

**Dictamen: BLOQUEADO POR DEFECTOS.** No recomiendo integrar todavía. La publicación única está bien encaminada y sus carreras existentes pasan, pero la recuperación puede devolver al comprador al checkout con una aprobación observada sin aplicar, perder la selección automática de un caso financiero pendiente e ignorar un ID administrativo correcto. Además, una limitación heredada impide conservar ciertos pagos de preferencias distintas como transacciones e incidencias.

Se añadieron exclusivamente este informe y `tests/Feature/Aud001AdversarialReviewTest.php`. Los 28 archivos de AUD-001 presentes al iniciar la revisión conservan sus hashes. No se cambió código funcional, documentación previa ni pruebas existentes. No hubo commit, push, merge, despliegue ni operaciones con producción o dinero real.

## 1. Alcance y reconstrucción

Se contrastó íntegramente `docs/aud-001-custom-payment-recovery.md` con los archivos del inventario, el diff frente a `7a2763a` y los archivos nuevos sin seguimiento. Es relevante que HEAD todavía coincide con la base: `git diff` muestra 19 archivos versionados modificados, pero no incluye los nueve archivos nuevos de la implementación, que también forman parte de la revisión.

También se inspeccionaron los puntos de integración: controlador y firma del webhook, checkout de catálogo, políticas y middleware de autorización, Form Request, configuración de CSRF, scheduler, gestión de cotizaciones, transiciones administrativas, reservas, inventario y transporte del SDK.

El ciclo real es:

1. `orderFor` bloquea solicitud y pedido; crea pedido, líneas y una intención `draft` en la misma transacción. La restricción única por solicitud impide otro pedido. Un pedido previo sin intención se adopta como incierto.
2. `checkoutUrl` revalida propiedad, cotización, pedido y snapshot. Bajo solicitud → pedido → intención, solo `draft` o `failed` reclaman `sending`, con fecha y evento persistidos.
3. Fuera de la transacción, `createPreference` utiliza un transporte sin reintentos. La metadata incorpora pedido e intención; las líneas nuevas tienen IDs diferentes y estables.
4. Otra transacción guarda el resultado o la incertidumbre. Solo `PreferenceNotSent`, emitido por validaciones locales anteriores al transporte, permite `failed`. Una caída puede dejar `sending`, que no autoriza otra publicación.
5. Los reintentos inciertos realizan lecturas. `recover` reclama una consulta con fecha y token; consulta ID o referencia, verifica la candidata y persiste asociación y `ready` bajo bloqueo. El token impide que un lector anterior sobrescriba uno posterior.
6. El webhook verifica firma y consulta el pago. Sin preferencia asociada conserva la observación financiera y responde 503. Puede recuperar la preferencia y consultar otra vez el pago para aplicar la conciliación.
7. La consulta administrativa y el scheduler reutilizan esta recuperación. Los estados de publicación y financieros están separados, pero faltan conexiones entre ambos en los hallazgos H1 y H2.

## 2. Hallazgos verificados

Los cuatro casos se reproducen sin red externa con dobles del servicio del proveedor y SQLite en memoria. Son fallos de secuenciación y selección, no afirmaciones sobre cobros reales ejecutados. Las pruebas expresan el comportamiento seguro esperado y fallan contra el código actual.

### H1 · P1 / Alta · Se entrega una URL pagable con una aprobación financiera observada sin conciliar

**Clasificación: VERIFICADO** para la entrega del enlace. **RIESGO FUNDAMENTADO** para un segundo intento/cobro; no se efectuó un cobro real duplicado.

Ubicación: `app/Services/CustomQuoteCheckout.php:159`, `app/Services/CustomQuoteCheckout.php:198`; integración con `app/Services/PaymentReconciliation.php:118` y `app/Services/CustomPaymentRecovery.php:78`.

Secuencia reproducida:

1. La intención está `sending`; el pedido está `pending`.
2. La consulta del pago simulado devuelve `approved`, referencia e importe correctos, pero sin `preference_id`.
3. `verify` guarda la transacción financiera aprobada y responde 503 porque todavía falta la asociación.
4. Transcurren tres minutos. El navegador vuelve al flujo de checkout y la búsqueda recupera una preferencia comercialmente válida.
5. La intención pasa a `ready`. El checkout solo verifica los estados del pedido, su subtotal y la intención; no consulta ni bloquea la aprobación observada pendiente de aplicación.
6. Devuelve `https://www.mercadopago.cl/audit/audit-real`. El pedido todavía figura pendiente, aunque el registro financiero ya contiene una aprobación. No se realizó la segunda consulta financiera configurada para fallar transitoriamente.

Prueba: `test_browser_recovery_does_not_release_checkout_while_an_early_approval_is_unapplied`.

Consecuencia: la exclusión de POSTs de preferencias no basta para evitar devolver al comprador a un pago que puede haber sido aprobado. La vista también interpreta `ready` + pedido pendiente como listo para continuar. La producción sigue bloqueada; el defecto es la liberación del checkout y el riesgo de otro intento financiero.

**Cambio mínimo recomendado:** conservar explícitamente la condición de observación financiera pendiente de aplicación. Antes de liberar una URL, bajo el bloqueo del pedido comprobar ese estado y mantener el mensaje de verificación. Conciliar mediante lecturas fuera de la transacción; si la consulta falla, no liberar el enlace ni inferir `paid`. Evitar tanto bloquear indiscriminadamente pagos rechazados como utilizar un `approved` antiguo para ignorar un reembolso posterior.

Regresión adicional necesaria: navegador, webhook y recuperación superpuestos en MariaDB, incluyendo aprobación → reembolso antes de completar la asociación, y rechazo auténtico que sí permita un intento posterior seguro.

### H2 · P1 / Alta · Un caso financiero pendiente puede desaparecer del scheduler y las colas por defecto

**Clasificación: VERIFICADO.** Es un defecto nuevo de la integración entre intención y conciliación.

Ubicación: `app/Services/PaymentReconciliation.php:443`, especialmente los filtros desde la línea 450; `app/Http/Controllers/Housekeeping/PaymentReconciliationController.php:22` y `:30`.

Secuencia reproducida:

1. Pedido personalizado creado hace ocho días, sin reserva física, con intención incierta y una observación financiera `approved` aún no aplicada al pedido.
2. El scheduler selecciona el pedido y recupera su preferencia. La intención queda `ready`.
3. La búsqueda de pagos no aporta IDs y la consulta directa del pago conocido falla transitoriamente. El pedido queda `pending`, sin ID de pago asociado. La observación aprobada permanece guardada.
4. Pasan tres horas. La siguiente ejecución devuelve cero pedidos, en lugar de seleccionar nuevamente este caso.

El selector considera estados financieros pendientes/incidencias, intención `sending`/incierta, reservas o preferencia de pedido creado en los últimos siete días. No contempla una observación `approved` personalizada todavía sin aplicación comercial. Una vez que la intención pasa a `ready`, el pedido antiguo deja de cumplir todos esos criterios.

La cola administrativa por defecto también filtra pagos por pendiente/incidencia e intenciones por `sending`/incierto/`failed`. El registro no se borra y sigue accesible mediante la vista «todos» o el pedido, pero desaparece de las colas de trabajo normales.

Prueba: `test_old_early_approved_payment_remains_scheduled_after_recovery_and_transient_payment_read_failure`. Resultado: se esperaba una revisión; se obtuvieron cero.

**Cambio mínimo recomendado:** seleccionar y mostrar observaciones financieras sin aplicación/resolución, independientemente de la antigüedad del pedido o del estado `ready` de la preferencia. Puede utilizarse un marcador explícito de conciliación/aplicación, sin equiparar `resolved_at` de incidencias con pago aplicado. Mantener el caso elegible después de errores y hasta una resolución financiera comprobable.

Regresiones: pedidos de 8 y más de 90 días con ID conocido; búsquedas vacías y fallidas; fallo de GET del pago después de recuperar la preferencia; revisión repetida que finalmente aplica una sola vez el pago/inventario y retira correctamente la cola.

### H3 · P2 / Media · Un ID persistido no verificable anula el ID correcto aportado por un administrador

**Clasificación: VERIFICADO.** Es un defecto nuevo de recuperación operativa.

Ubicación: `app/Services/CustomPaymentRecovery.php:118`; ruta administrativa en `app/Http/Controllers/Housekeeping/PaymentReconciliationController.php:52`.

Una intención incierta contiene `audit-wrong`, un ID todavía no verificado. El administrador autorizado aporta `audit-correct`, motivo y confirmación. El doble del proveedor puede verificar comercialmente `audit-correct`, pero el código elige siempre `$direct ?: $hint`: consulta `audit-wrong`, registra error e ignora el ID aportado. La preferencia correcta nunca se consulta ni se asocia.

Esto importa porque el publicador conserva IDs de respuestas incompletas antes de verificarlos, y también se adoptan IDs históricos. La mera sintaxis válida no demuestra identidad ni recuperabilidad. El procedimiento documentado de ingresar un ID respaldado por evidencia no resuelve este caso.

Prueba: `test_admin_can_verify_a_correct_id_when_the_persisted_unverified_id_is_wrong`. Resultado: el pedido conserva ID nulo, en vez de `audit-correct`.

**Cambio mínimo recomendado:** distinguir ID candidato de asociación verificada. Permitir que una decisión administrativa trazable proponga un candidato alternativo cuando el anterior no está verificado, sometiéndolo a todas las comprobaciones de identidad y snapshot. Conservar ambos IDs, su procedencia y el resultado. No sobrescribir asociaciones `ready` mediante esta vía ni convertir errores/404 en permiso para emitir otro POST.

Regresiones: candidato persistido inexistente o comercialmente incorrecto; nuevo candidato correcto; candidato de otro pedido rechazado; asociación ya verificada inmutable; consultas repetidas y lectores concurrentes con token vigente.

### H4 · P1 / Alta · Un pago con otra preferencia no ingresa al registro financiero ni a la cola de incidencias

**Clasificación: VERIFICADO.** La rama que causa este comportamiento ya existe en `7a2763a`; no se presenta como regresión introducida por AUD-001. Es relevante para su recuperación de preferencias múltiples e integración con AUD-003.

Ubicación: `app/Services/PaymentReconciliation.php:66`, antes del upsert de `payment_transactions` de la línea 95.

Secuencia reproducida:

1. Pedido pagado mediante el ID `990` y preferencia seleccionada `audit-selected`.
2. La API simulada devuelve otro pago `992`, aprobado, del mismo pedido/referencia, CLP e importe correcto, pero con preferencia `audit-other`.
3. `verify` devuelve `preference_mismatch` y 422, conservando correctamente el ID principal `990`.
4. Como no había una fila previa para `992`, no crea una transacción financiera ni una incidencia. Solo registra un evento con ID/estado; faltan la observación monetaria persistida y la fila de incidencia que consulta AUD-003.

Prueba: `test_additional_payment_from_another_preference_is_retained_as_a_financial_incident`. Resultado: `payment_transactions` está vacía en el escenario, en vez de contener el pago adicional retenido.

No corresponde acreditar automáticamente este pago ni asumir que la preferencia es la seleccionada. El problema es descartar su observación financiera en lugar de retenerla para revisar. Los duplicados históricos son precisamente un caso contemplado por la recuperación nueva.

**Cambio mínimo recomendado:** después de verificar ID y referencia contra la API, persistir una observación financiera en cuarentena con `preference_mismatch` antes de devolver el rechazo comercial. Proteger la unicidad y la relación con otros pedidos, mantener el pago principal, no aplicar stock/producción y hacer visible la incidencia. Resolver la identidad/evidencia antes de cualquier aplicación financiera.

Regresiones: preferencia distinta con pago nuevo y conocido; pago ya vinculado a otro pedido; notificación repetida; aprobación/reversión del pago adicional; incidencia visible y bloqueo operativo de AUD-003.

## 3. Invariantes y seguridad

| Resultado | Clasificación | Alcance demostrado |
|---|---|---|
| Solo `draft`/`failed` reclaman publicación | VERIFICADO | Estados persistidos bajo solicitud → pedido → intención; unicidad de intención por pedido/solicitud |
| Incertidumbre no autoriza otro POST | VERIFICADO | Navegador, recuperación administrativa/scheduler y caídas cubiertas por pruebas existentes |
| Dos procesos superpuestos publican una sola vez | VERIFICADO | MariaDB real, procesos separados y sincronización controlada |
| HTTP no conserva los bloqueos de publicación | VERIFICADO | Otro proceso adquiere solicitud, pedido e intención mientras el emisor espera la respuesta simulada |
| Orden del snapshot, CLP, líneas, diseño y despacho se validan al recuperar | VERIFICADO | Inspección y pruebas de discrepancias; total igual con reparto de precios distinto se rechaza |
| Un resultado vacío no acredita ausencia | VERIFICADO | Sigue incierto y solo permite nuevas lecturas |
| Operaciones sensibles requieren administrador, CSRF y motivo para propuesta de ID | VERIFICADO | Middleware/ruta/controlador y pruebas; H3 limita su eficacia operativa |
| Propietario controla aceptación/rechazo | VERIFICADO | Form Request y políticas; el administrador no obtiene consentimiento de otro cliente |
| Firma, GET del pago y retorno success sin acreditación | VERIFICADO | Código y regresiones de webhook; no se acepta el cuerpo o retorno como pago |
| Precedencia terminal y descuento único en casos cubiertos | VERIFICADO | Suite existente y carreras de AUD-003 |
| Publicación única implica ausencia de segundos intentos financieros | RIESGO FUNDAMENTADO | La implicación no es válida: H1 devuelve una URL pagable con aprobación observada |
| No hay IDOR, mass assignment o filtración explotable en todo el sistema | NO VERIFICABLE como afirmación universal | No se encontró una vía concreta en los cambios examinados; los payloads de estas rutas se validan y construyen en servidor |

Los modelos nuevos admiten asignación de todos los campos salvo `id`, pero las rutas nuevas no les pasan un body arbitrario: forman atributos de servidor y validan la propuesta administrativa. No se detectó una explotación concreta de mass assignment. Las notas y evidencias se imprimen escapadas en Blade. Los registros examinados omiten cuerpos, tokens, firmas y mensajes de excepción. La excepción de CSRF continúa limitada al webhook firmado.

## 4. Bloqueos, concurrencia y AUD-003

Publicación/recuperación: solicitud → pedido → intención → eventos. Webhook personalizado: solicitud → pedido → intención → transacción financiera → productos ordenados → reservas. Observación administrativa: pedido → transacción, sin esperar luego una solicitud. Las actualizaciones de stock conservan sus bloqueos y restricciones existentes. No se identificó una inversión nueva de estos bloqueos.

Las llamadas HTTP de los recorridos inspeccionados quedan fuera de las transacciones que toman esos registros. El resultado no prueba que cualquier futuro llamador pueda envolver estos servicios en una transacción externa sin consecuencias; ese contrato debería conservarse expresamente.

Los leases de lectura y cache expiran, pero el token de consulta protege la escritura de un lector anterior. El publicador no recupera automáticamente permiso para otro POST al expirar un lease. No se demostró una doble publicación en las secuencias examinadas.

La duración total de una búsqueda de muchas páginas puede superar los leases: hasta 100 solicitudes individuales de diez segundos. Esto fundamenta un riesgo de lectores superpuestos, carga y timeouts del proceso. El fencing evita que un resultado viejo sobrescriba al nuevo, pero conviene un presupuesto global de tiempo o continuación explícita. No se demostró una doble aplicación comercial por este motivo.

AUD-003 conserva sus validaciones de pago vigente, preparación, despacho, stock y reversos; sus pruebas existentes pasan. Sin embargo, esa compatibilidad no basta para recomendar integración: H4 no alimenta una incidencia que dichas validaciones puedan consultar, y H1/H2 dejan incompleta la recuperación previa a esos controles.

## 5. Discrepancias del informe original

- «No quedan fallos nuevos conocidos» deja de ser válido tras estas reproducciones; no se modificó el informe original para conservar la evidencia de lo que se auditó.
- El scheduler sabe consultar aprobaciones conocidas cuando selecciona el pedido, pero no garantiza volver a seleccionarlo tras pasar a `ready`: H2.
- La admisión de IDs administrativos solo funciona cuando no prevalece otro ID persistido: H3.
- La conservación del historial financiero y detección de pagos adicionales tienen una excepción anterior al upsert por preferencia diferente: H4. Es una limitación heredada no explicitada.
- `ready` describe publicación recuperada, pero hoy también permite liberar el enlace sin resolver una aprobación financiera observada: H1. Separar nombres de estados no resuelve esa dependencia comercial.
- Los resultados de las pruebas originales sí se reprodujeron; sus cantidades no acreditaban los casos nuevos.

## 6. Pruebas ejecutadas en esta revisión

| Ejecución | Resultado |
|---|---|
| Suite original, antes de añadir los casos adversariales | 298 pruebas, 3197 aserciones, cero fallos/errores; 11 omisiones concurrentes |
| MariaDB 10.11.16/InnoDB, base aislada `nakama_com01_test`, puerto 33317 | 11 pruebas, 145 aserciones, cero fallos/omisiones |
| Reproducciones adversariales nuevas | 4 pruebas, 14 aserciones, 4 fallos esperados contra el código auditado |
| JavaScript | 13 pruebas aprobadas |
| Migración en base vacía separada `nakama_aud001_review_schema` | Creación, rollback de la migración nueva y reaplicación aprobados |
| `git diff --check` | Sin errores |
| Integridad de los 28 archivos auditados | Ningún hash cambió durante la revisión |

La suite original completa incluye catálogo, personalizados, diseño asistido, webhook, conciliación, reembolsos/contracargos y regresiones de AUD-003. La ejecución MariaDB incluye las cinco pruebas nuevas de concurrencia de AUD-001, cuatro de conciliación/AUD-003 y dos de reservas/checkout. Los tests concurrentes usan procesos reales y proveedor simulado; no son pruebas reales contra Mercado Pago.

Comando de reproducción de los cuatro defectos:

```powershell
php -d extension=gd vendor/bin/phpunit tests/Feature/Aud001AdversarialReviewTest.php
```

Debe fallar con los cuatro escenarios descritos mientras no se corrija el código. No se alteraron ni omitieron pruebas existentes para obtener resultados verdes. La suite ampliada ya no se puede presentar como completamente aprobada.

## 7. Contrato oficial y validación externa

**VERIFICADO documentalmente:** esta integración usa Preferences API, no Orders API. Los endpoints de búsqueda de preferencias por `external_reference`, detalle por ID y búsqueda/detalle de pagos corresponden a Checkout Pro. La referencia de búsqueda incluye `next_offset` y `total`, coherentes con los DTO usados por el SDK. [Referencia oficial de Preferences](https://www.mercadopago.cl/developers/en/reference/online-payments/checkout-pro-preferences/overview), [búsqueda de preferencias](https://www.mercadopago.cl/developers/pt/reference/online-payments/checkout-pro-preferences/search-preferences/get), [búsqueda de pagos](https://www.mercadopago.cl/developers/en/reference/online-payments/checkout-pro-preferences/search-payments/get).

**VERIFICADO documentalmente:** la búsqueda general de preferencias se limita a 90 días; para datos anteriores se requiere consultar un ID conocido. El código no convierte ese límite ni los resultados vacíos en permiso para otro POST. [Anuncio oficial del límite temporal](https://www.mercadopago.cl/developers/es/news/2025/03/04/Updates-on-the-Preferences-and-Merchant-Orders-APIs).

**NO VERIFICABLE contra el proveedor en este entorno:** persistencia exacta de metadata e IDs de línea en el round trip, representación del despacho/total, consistencia temporal, firmas auténticas y resultados de pagos de prueba. Las respuestas de las pruebas se construyen en buena parte desde el propio snapshot; eso comprueba comparadores, pero no demuestra que Mercado Pago devuelva idéntica representación. La documentación oficial de creación describe collector, items e `init_point`; no se debe deducir de esa página la garantía de idempotencia de la API Orders para el endpoint Preferences. [Creación de preferencia](https://www.mercadopago.cl/developers/en/reference/online-payments/checkout-pro/preferences/create-preference/post).

Algunas aperturas directas de referencias devolvieron 403 desde el servicio de navegación; se usaron los resultados indexados oficiales y el SDK local para el contraste documental. Esto no equivale a consultar la API autenticada ni a validar respuestas reales.

La inspección local solo imprimió booleanos: no hay access token, secreto de webhook ni collector configurados, y APP_URL no es HTTPS. No se revelaron valores de credenciales. **No se efectuó ninguna prueba real con Mercado Pago.**

### Procedimiento pendiente de sandbox

1. Provisionar una instalación de staging aislada, dominio HTTPS público y base/storage/cache/sesiones/colas separados. Configurar exclusivamente credenciales y cuentas de prueba de la aplicación autorizada, collector esperado y secreto de webhook de ese mismo ambiente; no utilizar credenciales productivas.
2. Aplicar migraciones aditivas en esa base aislada y crear cliente/administrador y cotizaciones de prueba. Incluir lote, diseño de 500 CLP cuando corresponda y despacho configurado. Registrar referencias locales sin secretos.
3. Emitir una sola preferencia mediante el checkout autenticado. Comparar POST y GET por ID: referencia, collector, metadata de pedido/intención, IDs de cada línea, cantidades, CLP, base/diseño/despacho y total. Archivar muestras redactadas, con versión y fecha del contrato.
4. Buscar por referencia y seguir los offsets reales hasta terminar. Comprobar la representación de cero/uno/varios resultados y la latencia de aparición; no reenviar POST durante la espera. Para históricos, usar un ID conocido y cotejar el formato anterior de líneas; no inventar la creación de una preferencia de más de 90 días para simular un dato real.
5. Usar los medios, cuentas y datos oficiales de prueba del ambiente para obtener aprobación y rechazo; cubrir pendiente con un medio que realmente lo soporte. Capturar IDs y resultados de la API y callbacks firmados, sin registrar firmas ni tokens. Si el proveedor no permite producir un estado en sandbox, mantenerlo identificado como prueba simulada pendiente de evidencia externa.
6. Repetir una notificación auténtica en un mecanismo seguro de pruebas y comprobar una aplicación comercial y un descuento. Deshabilitar temporalmente la recepción en staging para perder una notificación, rehabilitarla y conciliar por GET sin crear preferencias ni pagos nuevos.
7. Inyectar pérdida de respuesta en el transporte de staging después de recibir una creación exitosa del proveedor: el decorador envía una sola vez y descarta/lanza el fallo local. Recuperar por GET y demostrar una preferencia remota y un pedido local. La inyección no debe consistir en repetir POST ni depender de que falle la red del proveedor.
8. Tras corregir H1–H4, repetir sus secuencias en staging. Probar reembolso y contracargo únicamente mediante capacidades oficiales de prueba; si alguna no existe, separar la simulación local de la validación externa pendiente. Verificar que la producción/despacho siguen bloqueados ante reversos o asociaciones ambiguas.

## 8. Revisión visual

**NO VERIFICABLE / PENDIENTE.** El inventario de automatización no ofrece sesiones de navegador y devuelve `The browser access host is unavailable`. No se recorrió un checkout autenticado ni se inspeccionaron capturas de escritorio/móvil. Existe una aplicación Opera abierta en el equipo, pero no se usó como sustituto de una sesión aislada y verificable de esta auditoría.

El renderizado/contenido de Blade y los controles de autorización sí se ejercitaron en las pruebas existentes. Eso no acredita contraste, foco, adaptación móvil, estados de carga o accesibilidad visual. Tras corregir H1, revisar también que nunca se muestre «listo para continuar» mientras haya una aprobación pendiente de conciliación.

## 9. Recomendación

Corregir H1 y H2 antes de presentar AUD-001 como apto para validación externa de aceptación. Corregir H3 para que el procedimiento administrativo funcione con IDs candidatos defectuosos. Resolver explícitamente H4 en esta frontera de conciliación, o mantener el bloqueo de integración mientras no exista una decisión técnica que preserve esa evidencia y sus controles operativos; no tratarlo como resuelto por el mero hecho de ser heredado.

Después: aprobar las cuatro regresiones nuevas, repetir las suites originales y carreras MariaDB con las secuencias nuevas, completar sandbox y revisión visual. No declarar AUD-001 cerrado ni listo para integración con las pruebas actuales. No se inició AUD-002 ni AUD-004.
