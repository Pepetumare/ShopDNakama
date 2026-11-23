# AUD-004 — Conciliación paginada y persistente

Implementación local para revisión independiente, sin commit ni publicación. Base: AUD-001 `79f699601a02c4ec35ba58020e85106ca6963287`, que contiene AUD-003. No incorpora COL-001 ni modifica los PR #2/#3.

## Cierre del contrato legado y revisión final — 23-11-2026

**Veredicto local: LISTO PARA COMMIT.** No se hizo commit ni publicación. El antiguo fallo de CustomPaymentRecoveryTest representa **A: una expectativa obsoleta sustituida por una garantía equivalente o superior**, no una pérdida financiera pendiente. Los resultados parciales de la sección siguiente se conservan como historia de la corrección P1 y quedan sustituidos por este cierre.

### Garantía original y equivalencia

La intención de la prueba era impedir que un ID cuyo GET falló quedara abandonado al avanzar la búsqueda. El mecanismo probado (cursor inmóvil y búsquedas 0,0,10) se añadió en la primera implementación de AUD-004: el código base AUD-001 todavía avanzaba ante review_incomplete sin una bandeja durable. La condición no es una regla financiera de AUD-001 ni demuestra que la antigua versión guardara los IDs fallidos.

Se distinguen cinco hechos: descubrir un ID no equivale a verificarlo; avanzar el cursor certifica que los IDs de esa página están guardados; una página verified exige las versiones de verificación de todos sus IDs; cerrar el barrido solo significa alcanzar el final visible; cerrar la revisión financiera exige además que no queden trabajos sin detalle aceptado, páginas pendientes, estados no terminales, incidencias ni aprobaciones sin aplicar. La política de enlaces continúa comprobando tanto evidencia financiera como trabajo descubierto todavía incierto.

El test actualizado conserva los fixtures, el fallo individual y la aprobación posterior. Sustituye la expectativa de cursor inmóvil por 29 aserciones: IDs 700..709 durables, cursor y espejo a 10, trabajo 701 sin verificación y con reintento en 15 minutos, página pending, revisión abierta y enlace retenido. El siguiente turno verifica 701 y la aprobación 710; comprobar una página no cierra la revisión mientras existan otros pagos pending. Una repetición posterior mantiene once transacciones y cero movimientos de stock para ese fixture personalizado sin producto. El fixture de aceptación con producto conserva además su único descuento de stock.

Copia íntegra anterior del archivo: ../contract-closure/historical/CustomPaymentRecoveryTest.php; huella original en historical/sha256.json, comprobada idéntica. El diff de este cierre se limita a imports de las tres tablas y a ese contrato de prueba. Las pruebas independientes originales del P1 y la prueba fallida de capacidad no se alteraron. El informe independiente original tampoco se reescribió.

### Persistencia comprobada entre procesos reales

CustomDurableRestartTest añade dos casos MariaDB, fallo transitorio y persistente, con tres procesos PHP nuevos por caso y caché no compartida. El primer proceso termina; las consultas directas confirman diez IDs, GET fallido pendiente, próxima fecha válida, cursor 10, página pendiente, revisión abierta y enlace bloqueado aunque los otros nueve pagos ya estén rechazados. El segundo proceso continúa desde 10 y aplica el aprobado posterior; en el caso persistente deja el ID y la página pendientes, mientras en el transitorio completa ese reintento. Un tercer proceso, tras volver disponible el detalle y vencer únicamente el reloj de reintento aislado, completa el trabajo y las páginas. La revisión financiera solo se cierra cuando todos los pagos son terminales y consistentes. Cada caso termina con once transacciones, un movimiento de stock y stock 3→2, sin duplicación.

Los IDs de esos procesos se separan por order_id para evitar colisiones entre fixtures MariaDB. No se publican preferencias ni se utilizan llamadas financieras reales. La prueba permanente de aceptación mantiene además los IDs literales 1001..1011.

### Revisión del código funcional

No fue necesario modificar código funcional durante este cierre: los nueve archivos app/database de AUD-004 conservan sus hashes de inicio, registrados en ../contract-closure/functional-integrity.json. Se inspeccionaron las transacciones que guardan IDs, páginas, cursor y espejo payment_offset; restricciones UNIQUE/FK; guard del lease dentro de verify después del HTTP y en su ruta de error; propagación de authorize a la verificación recursiva de preferencias; apertura/cierre del intent; retención de enlaces y convivencia con revisión administrativa, checkout, webhook y scheduler. Los bloqueos de solicitud personalizada/pedido/cursor se toman en transacciones cortas, sin HTTP dentro. La aplicación de pagos y stock mantiene las invariantes y precedencia de AUD-001/AUD-003.

No se confirmaron defectos financieros o de concurrencia adicionales bloqueantes en las rutas y escenarios revisados. Esto no afirma exhaustividad sobre todas las intercalaciones posibles ni sustituye la validación externa del proveedor.

### Validación final

| Comprobación | Resultado |
| --- | --- |
| Suite PHP completa | 376 casos, 3777 aserciones, cero errores/fallos, 28 omitidos en SQLite |
| Regresiones financieras AUD-001/AUD-003 y durabilidad personalizada | 99 casos, 638 aserciones, cero errores/fallos, sin excluir el contrato actualizado |
| Concurrencia solicitada MariaDB | 26 casos/360 aserciones más 3 independientes/43: 29 casos/403 aserciones, todos correctos |
| Nuevos reinicios personalizados MariaDB | 2 casos/64 aserciones, correctos; total MariaDB de este cierre: 31 casos/467 aserciones |
| Dos reproducciones independientes originales del P1 | Correctas, aserciones originales intactas |
| Verificación acotada ampliada y frontera de cierre | 2 casos/9 aserciones, correctos |
| JavaScript pertinente | 13 correctos |
| Pint PHP modificado/nuevo y git diff --check | Correctos |
| Migración AUD-004, rollback y reaplicación aislados | Correctos; orders/products/custom_payment_intents permanecen en el schema desechable |

Los 28 casos omitidos por requerir MariaDB se ejecutaron separadamente; los tres independientes adicionales viven fuera de la suite PHP. Resultados y enlaces a XML: ../contract-closure/results.json. El servidor exclusivo usa el puerto 33317 y datadir aud004; se apaga al finalizar.

La prueba de 101 pagos en 16 revisiones se conserva y se ejecutó aparte: vuelve a obtener 98 verificaciones, sin perder IDs. No existe un contrato de terminar todos los GET en 16 turnos: el presupuesto por revisión incluye el canónico, relecturas y trabajo solapado. La prueba ampliada descubre todos los IDs en 13 revisiones y completa su verificación en la 17. Su fallo aislado se preserva en 101-in-16-non-contractual.xml, sin usarlo como SLA ni modificarlo para conseguir verde.

### Riesgos residuales y Git

Persisten visibilidad remota sin snapshot, ventana de búsqueda del proveedor, demora por capacidad, crecimiento de evidencia sin retención automática y aceptación externa de Mercado Pago/hosting pendiente. Las migraciones deben preceder al código que consulta las tablas nuevas. Son condiciones operativas de integración/despliegue; este veredicto autoriza considerar el cambio listo para un commit local revisado, no certifica producción ni autoriza publicar.

Rama exclusiva codex/aud-004-reconciliation-pagination; HEAD/base 79f699601a02c4ec35ba58020e85106ca6963287, con AUD-003 heredado. main, COL-001 y AUD-001 mantienen sus commits y worktrees. Sin staging nuevo, commit, push, merge, cambios en PR #2/#3, producción ni AUD-002.

## Historia: corrección P1 antes del cierre del contrato — 23-11-2026

P1 ya no se reproduce en las dos pruebas independientes originales, cuyas aserciones y archivos se conservaron sin cambios. Personalizados y catálogo comparten ahora CatalogPaymentReconciliation y las tres tablas existentes; no se añade una migración. Se eliminó el avance condicional de payment_offset del scheduler. El commit que guarda los IDs, la página y el cursor guarda también el espejo payment_offset personalizado. Los fallos individuales permanecen en la bandeja y no impiden descubrir la página siguiente.

Caso de aceptación ampliado: página 1001–1010, GET 1002 permanentemente fallido y página siguiente con 1011 aprobado. En dos turnos se descubren y verifican los pagos posteriores; 1011 se aplica, 1002 mantiene próxima fecha de reintento, la página inicial queda pendiente y financial_review_required sigue verdadero. Una repetición administrativa/programada no duplica la transacción de 1011 ni el movimiento de stock del producto asociado. Un ID desconocido cuyo detalle falla bloquea el enlace incluso sin una transacción financiera todavía, y el checkout no puede cerrar esa revisión ignorando la bandeja.

Se añadió fencing dentro de verify: el token se comprueba bajo bloqueo en la transacción financiera después del HTTP, tanto al recibir una respuesta como al registrar un error. La comprobación se propaga también a la verificación recursiva después de recuperar una preferencia. Cuatro carreras reales cubren catálogo/personalizados y respuestas tardías de reembolso con fecha posterior, o errores tardíos: el proceso sustituido devuelve review_superseded y deja idénticos el checkpoint vigente, el trabajo, los pagos, los eventos y el intent del sucesor. No se mantienen bloqueos durante HTTP. apply, inventario, importes y assertFulfillable permanecen intactos.

### Resultados de la corrección y límite de cierre

- Dos reproducciones independientes del P1: correctas, sin modificar sus aserciones. Evidencia externa: ../p1-fix/p1-original-case-one.xml y p1-original-case-two.xml.
- Aceptación ampliada: 2 pruebas, 22 aserciones, correctas.
- 22 carreras MariaDB solicitadas: 294 aserciones, correctas. Cuatro carreras nuevas de fencing: 66 aserciones, correctas. Tres carreras independientes anteriores: 43 aserciones, correctas.
- Regresiones financieras seleccionadas AUD-001/AUD-003 y aceptación nueva: 98 pruebas, 609 aserciones, correctas. Esta selección excluye explícitamente la expectativa legada descrita abajo; no sustituye el resultado de la suite completa.
- Suite completa: 374 casos, 3750 aserciones, 26 omitidos en SQLite y **un fallo conservado**. No se presenta como suite verde.
- JavaScript pertinente: 13 correctas. Rollback/reaplicación aislados, Pint PHP modificado/nuevo y git diff --check: correctos. No hay cambios frontend.

El fallo conservado es CustomPaymentRecoveryTest::test_scheduler_retries_custom_page_after_payment_verification_failure_before_advancing (línea 598). Exige payment_offset=0 después del primer GET fallido y búsquedas 0,0,10: es el contrato de acoplamiento anterior. Con la separación durable, el cursor y su espejo avanzan a 10 después de guardar todos los IDs, incluso si 701 todavía no pudo verificarse. Mantener esa expectativa como criterio obligatorio contradice la corrección solicitada. No se modificó ni eliminó el caso para conseguir verde. La implementación pasa la aceptación P1, pero el cierre integral de pruebas queda pendiente de resolver explícitamente esta expectativa en una revisión posterior.

La prueba independiente de 101 pagos en 16 revisiones se ejecutó aparte y conserva su fallo (98 verificaciones). No es un contrato de capacidad: la prueba separada de 24 revisiones completa descubrimiento en 13 y verificaciones en 17. Se mantiene su evidencia separada en ../p1-fix/non-contractual-101-in-16.xml y qa-run-bounds/bounds.xml; no se mezcla con la suite ni con las reproducciones del P1.

Archivos funcionales modificados respecto de la implementación auditada: PaymentReconciliation.php, CatalogPaymentReconciliation.php y Order.php. Pruebas nuevas: CustomPaymentDurableQueueTest.php, PaymentLeaseFencingConcurrencyTest.php y su worker payment-lease-fencing-worker.php. Documentación actualizada: este archivo y com-03-conciliacion.md. Las pruebas históricas e independientes no se alteraron.

Riesgos residuales: visibilidad/orden remoto sin snapshot, demora por capacidad limitada, crecimiento sin retención automática, revisión externa de Mercado Pago pendiente y nombres físicos catalog_* compartidos por compatibilidad. Un takeover puede repetir HTTP; su respuesta ya no autoriza escrituras financieras una vez perdido el lease. La selección personalizada financieramente cerrada conserva la política de AUD-001. No se implementa AUD-002, no se liberan reservas ni se cambia el contrato de cobro.

Recomendación: revisión independiente del código corregido y de la expectativa legada todavía fallida antes de confirmar cambios. P1 funcionalmente corregido; **validación global no completamente verde**. Sin commit, push, merge, cambios de PR ni despliegue.

## Diagnóstico

El scheduler del catálogo enviaba siempre offset cero. El resultado next no se persistía. Un pago desconocido fuera de los diez primeros podía no consultarse nunca. Las lecturas directas de pagos conocidos solo complementaban el primer lote.

Además, una búsqueda exitosa no implica que cada GET individual haya funcionado. La primera versión de AUD-004 retenía payment_offset hasta completar los GET de personalizados. La auditoría independiente confirmó AUD004-IND-01: un GET permanentemente fallido impedía descubrir páginas posteriores. La corrección P1 sustituye esa ruta por la misma bandeja durable del catálogo. payment_offset es ahora un espejo de compatibilidad actualizado dentro del commit de descubrimiento, no una condición de cierre financiero.

La selección anterior usaba una antigüedad de siete días para ciertos pedidos y un enfriamiento global de dos horas. Esto no ofrece una cola durable ni garantiza continuar páginas tras reiniciar.

## Persistencia aditiva

- catalog_payment_cursors: fila única por pedido de catálogo o personalizado, generación, offset de descubrimiento, scan_open, próxima búsqueda, próxima ejecución, último intento/resultado, contador de errores y lease con UUID y vencimiento. Los nombres físicos se conservan para reutilizar la migración aditiva.
- catalog_payment_work: bandeja única por pedido/ID del proveedor. Conserva intentos, fallos, resultado/HTTP, próxima consulta y versión de verificación exitosa. No contiene respuestas completas, credenciales ni datos de tarjeta.
- catalog_payment_pages: evidencia por pedido/generación/offset, siguiente posición, IDs y versiones de verificación requeridas. pending significa que faltan observaciones; verified exige todas las versiones y respuestas HTTP 200. No significa pedido pagado ni incidencia resuelta.

No se borran eventos ni transacciones financieras. Las FKs restringen el borrado de pedidos. El down elimina únicamente estas tres tablas; su uso se limita a bases desechables o a un procedimiento posterior de mantenimiento que preserve la evidencia.

## Descubrimiento y verificación

1. Un lease se reclama en una transacción corta bajo pedido → cursor. Dura cinco minutos. No se mantienen bloqueos durante HTTP.
2. Se conserva la recuperación de preferencia existente y se importan el pago canónico y hasta cinco IDs financieros pendientes/con incidencias todavía no presentes en la bandeja, sin límite de antigüedad.
3. Por ejecución se descubre como máximo una página de diez filas. La transacción que guarda sus IDs y versiones objetivo también guarda la página y avanza el cursor: todo o nada.
4. En catálogo las páginas se solapan en dos filas (avance mínimo de una): 25 resultados estables usan offsets 0,8,16. Personalizados conserva date_created asc y el siguiente offset del proveedor (0,10,...), con IDs durables y barridos completos posteriores. Su primer cursor nuevo empieza desde cero: el offset legado sin bandeja no prueba persistencia de los IDs anteriores.
5. Se verifican como máximo diez IDs únicos. El canónico conserva lectura directa en cada revisión; los otros trabajos se ordenan por vencimiento e ID. Los fallos esperan 15,30,60 y hasta 120 minutos. Los éxitos se vuelven elegibles en dos horas.
6. Un fallo de GET o una validación financiera no aceptada conserva el ID y la página pendiente. El descubrimiento puede continuar porque ese ID ya es durable; avanzar descubrimiento nunca equivale a completar la verificación de la página.
7. Se comprueban hasta 25 páginas pendientes por turno, rotando por última comprobación. Sus versiones evitan dar por verificada una página nueva usando un éxito anterior a su descubrimiento.
8. Alcanzar el final cierra solamente el barrido de descubrimiento. Se programa otro barrido completo desde cero: dos horas para pedidos no terminales y doce para pagados/revertidos. Los trabajos conocidos pueden despertar el pedido antes; el canónico normalmente lo hace en dos horas.

Las consultas financieras usan exclusivamente PaymentReconciliation::verify. No se cambia apply, la validación de importes, moneda, preferencia, precedencia de reversos, inventario ni assertFulfillable. Después del HTTP, verify valida el lease bajo los bloqueos de la misma transacción financiera. Una respuesta o excepción tardía de un propietario sustituido no modifica pagos, incidencias, intent ni eventos financieros. No se publican preferencias, liberan reservas ni efectúan reembolsos desde la continuación nueva.

## Orden remoto, inserciones y límites reales

Para catálogo searchPayments usa sort=id y criteria=asc, un orden por identificador único documentado por el proveedor. Personalizados conserva date_created asc. No se asume que los IDs nuevos siempre sean mayores ni se inventa una garantía de snapshot.

Una inserción anterior al offset o una eliminación puede desplazar posiciones; el solapamiento reduce el riesgo local, pero no demuestra exhaustividad. Los barridos completos posteriores vuelven a descubrir IDs desplazados. La bandeja deduplica y retiene todos los IDs ya recibidos, independientemente de que desaparezcan de búsquedas posteriores. Una respuesta obsoleta tras perder el lease solo puede añadir IDs/evidencia; no modifica cursor, targets ni lease del nuevo propietario.

La garantía de redescubrimiento es eventual para un conjunto que permanezca visible y se estabilice lo suficiente para recorrerlo. No hay garantía absoluta frente a mutación remota adversarial continua, indexación que nunca publica un pago, límites del proveedor ni desaparición antes de recibir su ID.

La referencia oficial documenta una ventana de búsqueda de doce meses. No se añade un corte local por edad al trabajo pendiente: los IDs conocidos siguen consultándose por detalle, incluidos pedidos e incidencias antiguos. Un pago desconocido fuera de la ventana no puede recuperarse por este endpoint; exige evidencia/ID externo y revisión operativa. Cero resultados no demuestra ausencia histórica.

Referencia oficial de Payments Search, consultada el 18-11-2026: https://www.mercadopago.com.br/developers/pt/reference/online-payments/checkout-pro-preferences/search-payments/get . Documenta sort=id y criterios asc/desc. Algunas aperturas del dominio chileno devolvieron 403; no se hicieron llamadas autenticadas al proveedor.

Se validan los metadatos originales de paginación porque el DTO del SDK puede truncar un total decimal a entero. Totales malformados, offsets reportados distintos, IDs inválidos y páginas incoherentes conservan el cursor y producen search_error.

## Concurrencia, reinicios y administración

El cache lock existente sigue siendo una optimización. La exclusión durable usa MariaDB/InnoDB y un UUID de lease; cada escritura de progreso comprueba propietario y vencimiento bajo bloqueo. Un proceso antiguo puede terminar un GET, pero no sobrescribir el checkpoint de su sucesor. Las aplicaciones financieras repetidas pasan por la idempotencia y precedencia ya existentes.

Una caída antes de persistir la página deja el mismo offset. Una caída después deja la página y todos sus IDs antes del siguiente offset. Tras vencer el lease, otro proceso continúa y drena la bandeja. Un trabajador sustituido conserva de forma aditiva los IDs recibidos sin afirmar que los verificó.

Las revisiones administrativas y programadas comparten el protocolo para ambos tipos. El offset de la interfaz es una pista antigua: no puede saltar la posición durable. En personalizados se bloquea solicitud personalizada → pedido → cursor → intent/trabajo, compatible con AUD-001. La reclamación abre la revisión financiera; terminar el barrido no la cierra mientras queden páginas, GET fallidos, incidencias, estados pendientes, aprobaciones sin aplicar o un intent no READY. Un token financiero más reciente conserva su resultado y programación.

El scheduler conserva cinco pedidos por lote cada quince minutos y rota por última revisión. Ambos tipos con cursor durable usan next_run_at y leases; personalizados sin cursor conserva el enfriamiento legado. Los personalizados financieramente cerrados mantienen la selección de AUD-001. Un ID fallido no bloquea el descubrimiento posterior. La cola personalizada prioriza IDs todavía no verificados frente a lecturas periódicas. Un ID sin detalle aceptado bloquea el enlace de pago antes de existir una payment_transaction; checkout tampoco puede cerrar el seguimiento ignorando esa bandeja.

## Coste y riesgos residuales

Máximo nominal del catálogo: una página, diez GET de pagos y la recuperación de preferencia preexistente cuando procede, más trabajo DB acotado. El lease no reemplaza los timeouts del transporte. El vencimiento puede provocar GET repetidos, pero no habilita otra publicación ni un checkpoint obsoleto.

La capacidad global sigue limitada a veinte selecciones/hora nominales; más pedidos o proveedores lentos elevan la demora. No se promete recuperación inmediata. Eventos, generaciones y páginas crecen sin eliminación automática: dimensionamiento, alertas de antigüedad y retención merecen revisión operativa posterior.

No se implementa AUD-002. Reservas vencidas siguen su política existente. La aceptación externa del contrato de búsqueda y del flujo completo de Mercado Pago permanece pendiente; respuestas simuladas no certifican producción.

## Pruebas reproducibles

- CatalogPaymentPaginationTest: 25 pagos, aprobaciones desconocidas en páginas posteriores, fallos de búsqueda y GET, barridos nuevos, inserciones/deleciones, duplicados, incidencia antigua y reparto entre pedidos.
- CatalogPaymentSearchContractTest: orden separado por tipo de pedido, metadata malformada, offset inconsistente y conjunto reducido.
- CatalogPaymentPaginationConcurrencyTest y worker: carreras scheduler/scheduler y admin/scheduler con caché no compartida, bloqueos disponibles durante HTTP, fencing con evidencia adicional y muerte real de proceso después de persistir.
- CustomPaymentRecoveryTest: continuación y contrato actualizado de persistencia/reintento, con copia histórica íntegra fuera del árbol de pruebas. CustomPaymentDurableQueueTest y CustomDurableRestartTest cubren la separación entre descubrimiento durable, reintentos y recuperación entre procesos.

PHPUnit general usa SQLite en memoria; las carreras requieren exclusivamente MariaDB 127.0.0.1:33317/nakama_com01_test y NAKAMA_RESERVATION_RACE=1. Nunca dirigir migraciones o carreras a staging financiero ni bases comerciales. Los resultados finales se entregan junto con esta implementación local.

### Ejecutar desde un checkout independiente

Los tests permanentes y sus workers están en tests/Feature y tests/Support. No requieren los ejecutores ni XML externos citados como evidencia histórica. Usar un checkout sin .env comercial, dependencias de composer.lock instaladas y extensiones PHP de SQLite, MySQL y GD disponibles. Los valores de este ejemplo son exclusivamente ficticios; no cargar credenciales reales.

```powershell
$env:APP_ENV = 'testing'
$env:APP_KEY = 'base64:QUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUFBQUE='
$env:APP_URL = 'https://aud004.example.test'
$env:MERCADOPAGO_ACCESS_TOKEN = 'TEST-aud004-fake'
$env:MERCADOPAGO_WEBHOOK_SECRET = 'aud004-fake'
$env:MERCADOPAGO_COLLECTOR_ID = '42'
$env:DB_URL = ''
$env:DB_CONNECTION = 'sqlite'
$env:DB_DATABASE = ':memory:'
$env:CACHE_STORE = 'array'
$env:SESSION_DRIVER = 'array'
$env:MAIL_MAILER = 'array'
$env:QUEUE_CONNECTION = 'sync'
$env:NAKAMA_RESERVATION_RACE = '0'
$env:NAKAMA_STOCK_RACE = '0'
php -d extension=gd vendor/bin/phpunit --do-not-cache-result
```

Para las carreras, crear expresamente una instancia MariaDB/InnoDB desechable en 127.0.0.1:33317 cuyo datadir incluya aud004 y una base vacía nakama_com01_test. Los tests migran y escriben esa base. En esa instancia únicamente, preparar un usuario de pruebas con permisos de esquema y datos, configurar su usuario/contraseña ficticios en la sesión y ejecutar:

```powershell
$env:DB_CONNECTION = 'mysql'
$env:DB_HOST = '127.0.0.1'
$env:DB_PORT = '33317'
$env:DB_DATABASE = 'nakama_com01_test'
$env:DB_USERNAME = 'aud004_test'
$env:DB_PASSWORD = 'aud004_test'
$env:DB_URL = ''
$env:NAKAMA_RESERVATION_RACE = '1'
php -d extension=gd vendor/bin/phpunit --do-not-cache-result --filter 'CatalogPaymentPaginationConcurrencyTest|CustomPaymentConcurrencyTest|CustomPaymentFindingsConcurrencyTest|PaymentReconciliationConcurrencyTest|StockReservationConcurrencyTest|PaymentLeaseFencingConcurrencyTest|CustomDurableRestartTest'
```

Este filtro ejecuta los 28 casos MariaDB permanentes correspondientes a las omisiones de SQLite. Los tres casos independientes adicionales del cierre son evidencia externa y no una dependencia del repositorio. Los tests abortan si el host, puerto o nombre de base no corresponden al entorno aislado; los nuevos workers comprueban además el entorno antes de operar. No apuntar estos comandos a otra base para sortear los guards. Detener la instancia desechable al terminar.

## Resultados locales del 18-11-2026

- PHPUnit completo: 368 casos, 3.733 aserciones, cero errores/fallos; 22 omitidos en SQLite por requerir el entorno de concurrencia.
- MariaDB 10.11.16/InnoDB aislado, procesos separados: 22 casos, 294 aserciones, cero errores/fallos/omisiones. Incluye las cinco carreras nuevas y las regresiones financieras, personalizados e inventario existentes.
- JavaScript pertinente: 13 pruebas correctas. Build Vite correcto (69 módulos).
- Migraciones completas en nakama_aud004_schema desechable, rollback de la migración nueva y reaplicación: correctos. Las tablas comerciales y custom_payment_intents permanecieron tras el rollback.
- Pint limitado a los PHP modificados/nuevos y git diff --check: correctos.

Evidencia externa al árbol versionado: ../qa/full.xml y ../qa/mariadb.xml. El ejecutor ../run.ps1 fija credenciales ficticias, SQLite en memoria o exclusivamente el puerto aislado 33317; no carga un .env comercial. La instancia MariaDB fue creada expresamente para esta revisión. Las pruebas de proveedor usan dobles: no acreditan aceptación externa de Mercado Pago.
