# COM-03 · Conciliación de pagos Mercado Pago

Cada ID de pago verificado queda registrado por separado. Un pedido puede tener varios pagos asociados y conserva un solo ID principal para sus cambios comerciales. Un cobro adicional abre una incidencia y no reemplaza el pago principal, no prepara otro pedido y no vuelve a mover el inventario. La conciliación consulta al proveedor y deja evidencia. Las decisiones administrativas se registran por separado.

Antes, el pedido guardaba un único ID. Los intentos no terminales podían reemplazar ese ID y, si el pedido ya estaba pagado o reembolsado, el webhook ignoraba otro ID sin conservarlo en un registro por transacción.

## Esquema y archivos

La migración aditiva `2026_11_06_000003_create_payment_reconciliation_tables.php` crea dos tablas.

| Tabla | Contenido y relaciones |
|---|---|
| `payment_transactions` | Un registro por proveedor e ID externo, con restricción única. Referencia el pedido y, cuando existe, el intento de COM-02. Conserva preferencia informada, importe, moneda, estado oficial, aprobación, última verificación, incidencia, resolución y correlación. |
| `payment_reconciliation_events` | Diario de verificaciones, búsquedas, fallos, resultados y acciones manuales. Vincula el pedido y el pago cuando es posible. Guarda actor, fecha, correlación y observación. |

- `PaymentReconciliation.php` concentra las verificaciones y la aplicación comercial. `MercadoPagoWebhookController.php` autentica y delega. `MercadoPagoService.php` consulta pagos, preferencias y búsquedas. Las consultas comparten el transporte acotado `MercadoPagoSingleRequestTransport.php`.
- `Order.php`, `PaymentTransaction.php` y `PaymentReconciliationEvent.php` representan el pedido, los pagos y el diario.
- `PaymentReconciliationController.php`, las vistas `housekeeping/payments/` y los enlaces en Housekeeping muestran operaciones y resoluciones. Las rutas usan la protección administrativa existente.
- `routes/console.php` registra `payments:reconcile`. Las pruebas nuevas cubren el flujo funcional y la carrera en MySQL.

## Webhook y aplicación comercial

1. El webhook exige la firma y el ID de consulta correspondiente. El backend obtiene el estado oficial por HTTPS fuera de la transacción MySQL. Los datos de la notificación no se usan para confirmar pagos.
2. Dentro de la transacción se bloquea la solicitud personalizada, si aplica, luego el pedido, el registro del pago y los productos requeridos por COM-01. Se comprueban ID, referencia, método, importe, moneda y preferencia cuando el proveedor la informa. El DTO del SDK omite el campo de preferencia, por lo que se lee ese campo puntual de la respuesta si está presente.
3. Una relación no demostrada no produce una transacción asociada. Una diferencia de monto o moneda para una relación válida se conserva como incidencia y responde 422. Un fallo del proveedor o un webhook que llega antes de persistir la preferencia de COM-02 responde 503. En este último caso el registro es provisional y no se aplica ningún cambio comercial hasta verificar la preferencia.
4. Si la aprobación es válida y la reserva está vigente, COM-01 realiza el único descuento. Si la reserva venció, fue liberada, no hay stock o el pedido está cancelado, la aprobación queda registrada con incidencia. Los cobros adicionales para pedidos ya pagados o reembolsados conservan su propio ID y requieren revisión. Un reembolso de un ID secundario no cambia el estado del pago principal.
5. Las consultas desordenadas usan la fecha de actualización informada por el proveedor. Con fechas iguales o ausentes, se impiden regresiones de los estados financieros terminales. Las respuestas repetidas agregan una comprobación al diario sin repetir la aplicación comercial.

## Operación administrativa

1. Abrir **Housekeeping → Conciliación de pagos** y seleccionar el pedido. También se puede llegar desde el detalle de pedidos. La vista separa pagos, reservas y comprobaciones.
2. Usar **Consultar Mercado Pago** para verificar por referencia y revisar los ID conocidos. Si hay otra página, continuar con el botón correspondiente. Para COM-02, una preferencia única verificada puede quedar enlazada al pedido sin publicar la URL ni cambiar el carrito.
3. Distinguir búsqueda vacía, fallo temporal, revisión en curso y resultados verificados. Una búsqueda vacía no demuestra el cierre. Si hay pagos pendientes o información insuficiente, mantener la reserva y la incidencia según corresponda.
4. Registrar una observación con la referencia externa de la evidencia, sin incluir datos de tarjeta ni credenciales. Marcar una incidencia como resuelta solo después de verificar la decisión comercial y el resultado externo. La acción guarda actor, fecha y nota. La resolución administrativa no cambia el estado oficial, no libera la reserva y no genera cobros ni reembolsos. Para liberar una reserva tras conciliarla, seguir el procedimiento de COM-01.

El comando programado se ejecuta cada 15 minutos y revisa hasta cinco pedidos por corrida. AUD-004 añade al catálogo y a personalizados un cursor durable, páginas auditadas y una bandeja de IDs pendientes: una búsqueda parcial o un GET fallido no descartan pagos. Cada turno descubre una página y consulta hasta diez pagos; la continuación y los reintentos usan su próxima ejecución persistida. No se excluyen pedidos normales pendientes o con incidencias por superar siete días. Los IDs se persisten antes de avanzar el descubrimiento; los GET fallidos conservan su reintento independiente y no cierran la revisión financiera. Administración y scheduler comparten la exclusión por pedido; ambos tipos usan un lease en base de datos, validado también dentro de la transacción financiera después del HTTP. El webhook sigue serializando la aplicación financiera en MySQL. La búsqueda tiene límites remotos y se repiten barridos completos; el fin de una búsqueda no certifica ausencia histórica ni pago. Véase [diseño y límites de AUD-004](aud-004-reconciliation-pagination.md).

## Datos históricos y límites

La migración no hace backfill. Un pedido histórico conserva el ID original y su detalle sigue visible. Si solo existe el ID principal y falta la preferencia, una verificación por ID y referencia añade una observación del estado actual del proveedor sin cambiar el pedido histórico. Sin un ID verificable ni coincidencias en el proveedor, no hay evidencia suficiente para reconstruir otros pagos o estados anteriores.

Si el proveedor omite la preferencia del pago, el control mantiene la relación por ID, referencia exacta, credencial del comercio, importe y moneda y exige una preferencia local registrada para aplicar cambios. No se garantiza que el proveedor evite cobros adicionales. Los casos con resultado externo incierto pueden retener unidades hasta revisión.

## Silverhost y reversión

1. Antes del despliegue, verificar PHP 8.3, MySQL/InnoDB, salida HTTPS y un almacén compartido que admita bloqueos atómicos de caché. Confirmar los límites de duración del cron. Un almacén de base de datos compartido es compatible con la configuración Laravel existente.
2. En una ventana de mantenimiento con el checkout pausado, respaldar la base, revisar `php artisan migrate:status` y aplicar las migraciones aditivas pendientes de COM-01 a COM-03 antes de recibir tráfico con el nuevo código. Verificar `php artisan schedule:list` y el cron de `schedule:run`.
3. Validar con una cuenta y credenciales de prueba de Mercado Pago la firma, el payload con y sin preferencia, búsquedas paginadas, reintentos de webhook y notificaciones desordenadas. Confirmar que Housekeeping registra una observación y una resolución simuladas y que el stock se mueve una vez.
4. Para revertir, pausar el checkout y la tarea de conciliación. Exportar y revisar las incidencias abiertas, coordinar notificaciones pendientes y volver al código anterior conservando las tablas nuevas y sus datos. La versión anterior carece del registro de pagos secundarios. No ejecutar `down` en una base con auditoría.

## Validación local

- PHPUnit con SQLite aislado y Mercado Pago simulado aprobó 235 pruebas y 2752 aserciones. El subconjunto funcional de COM-03 aprobó 8 pruebas y 103 aserciones.
- Oracle MySQL 8.4.13 aislado aprobó 3 pruebas concurrentes y 49 aserciones, incluidas las carreras de COM-01 y COM-02. En COM-03, tres procesos procesaron dos ID de pago, con un solo descuento y una incidencia por cobro adicional. Dos revisiones en paralelo compartieron el bloqueo por pedido.
- La comprobación de sintaxis de los 31 archivos PHP del árbol de cambios y `git diff --check` pasaron. Las migraciones se probaron en bases aisladas. Quedan pendientes el entorno de prueba de Mercado Pago y las comprobaciones de Silverhost.

[API oficial de búsqueda de pagos](https://www.mercadopago.cl/developers/es/reference/online-payments/checkout-api-payments/search-payments/get) · [Referencia de Checkout Pro](https://www.mercadopago.cl/developers/es/reference/online-payments/checkout-pro-preferences/overview)
