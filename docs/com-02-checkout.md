# COM-02 · Idempotencia del checkout

El checkout de catálogo asigna una clave aleatoria de 256 bits al abrir el formulario. Se guarda en `checkout_attempts` y viaja en el POST protegido por sesión y CSRF. La búsqueda se limita al usuario autenticado. Hay unicidad para el token y para el pedido asociado. Un bloqueo por usuario serializa formularios, carrito y asignación de pedido. La primera solicitud válida asocia la dirección y crea el pedido, sus líneas y la reserva de COM-01 en una sola transacción.

El borrador identifica productos, cantidades, precios vigentes y coste de despacho. Si alguno cambia antes de confirmar, hay que revisar el nuevo total y usar el nuevo formulario. La primera confirmación fija los datos de entrega. Un POST posterior con datos distintos no modifica el pedido. Para cambiar la dirección de un pedido abierto, primero hay que resolver el intento anterior. Una recarga del checkout muestra el mismo pedido recuperable incluso si el carrito ya se vació. Un nuevo intento requiere un nuevo formulario y un carrito válido.

## Estados

| Estado persistido | Transición y comportamiento |
|---|---|
| `draft` | Puede pasar a `sending` en la transacción que asocia pedido y reserva. Un borrador desplazado por un cambio de carrito pasa a `failed`. |
| `sending` | Un solo proceso gana el derecho a enviar el POST. Puede quedar `ready`, `failed` si el fallo se identifica antes del envío, o `reconciliation_required` si el resultado es incierto. Un proceso interrumpido permite búsqueda tras dos minutos. |
| `reconciliation_required` | El reintento permite lecturas, con una espera de 30 segundos entre búsquedas. Una coincidencia verificada puede pasar a `ready`. Cero o múltiples resultados conservan el estado. |
| `ready` | Conserva el ID de preferencia y, si corresponde, la URL. El POST devuelve la misma URL únicamente con reserva vigente y pedido pagable. |
| `failed` | Intento fallido o histórico. Este estado solo no acredita que la preferencia nunca se publicó. |
| `not_sent` | El intento vinculado falló antes del POST. Se comprueba la evidencia financiera antes de liberar las unidades. |

Los estados terminales comerciales se evalúan en el pedido y la reserva bajo bloqueo del pedido. Un pedido pagado, cancelado o reembolsado no vuelve a presentar una URL. Una reserva consumida, liberada o vencida tampoco vuelve a presentar una URL. La recuperación de una preferencia después del vencimiento puede guardar su ID para permitir el procesamiento de un webhook tardío, sin entregar el enlace.

## Mercado Pago y fallos

El SDK 3.16.0 incluye búsqueda de preferencias por referencia y su transporte predeterminado reintenta solicitudes fallidas. La creación de Preferencias API no documenta una garantía de deduplicación. El checkout de catálogo usa el cliente del SDK con transporte acotado a 10 segundos por solicitud, sin reintentos ni redirecciones HTTP. La llamada ocurre fuera de la transacción. Desde [AUD-001](aud-001-custom-payment-recovery.md), personalizados también utiliza envío único fuera de transacción, con intención e historial propios.

Ante timeout, respuesta perdida o fallo al persistir el ID, el siguiente POST del comprador solo busca por referencia del pedido. Se exige un resultado único y se verifica el detalle de la preferencia, incluyendo ID, referencia, plazo, ítems, moneda y despacho. Si un webhook válido llega antes de guardar el ID, recibe 503 para permitir el reintento del proveedor. Se conservan las verificaciones de importe, moneda, firma y preferencia del webhook. Se mantiene la retención de COM-01 si el resultado externo sigue incierto, aun cuando localmente no haya ID de preferencia.

Si la búsqueda no permite recuperar el intento, revisar la referencia y el estado de todos los pagos en Mercado Pago. El resultado vacío de una búsqueda no prueba que no haya existido una preferencia. Si hubo posible publicación, documentar el resultado y seguir el procedimiento de escalamiento de [AUD-002](aud-002-reservas-vencidas.md). La búsqueda del proveedor cubre hasta 90 días. COM-03 deberá automatizar la conciliación de cobros adicionales y los casos que requieran intervención fuera de este flujo.

[Crear preferencia en Mercado Pago](https://www.mercadopago.cl/developers/es/reference/online-payments/checkout-pro-preferences/create-preference/post) · [Buscar preferencias en Mercado Pago](https://www.mercadopago.cl/developers/es/reference/online-payments/checkout-pro-preferences/search-preferences/get)

## Validación

PHPUnit aprobó 227 pruebas y 2649 aserciones con SQLite aislado y proveedores simulados. En Oracle MySQL 8.4.13 aislado pasaron dos pruebas concurrentes con 30 aserciones. Una enfrentó a dos compradores por la última unidad. La otra envió dos solicitudes con el mismo intento y verificó un pedido, una reserva y un POST de creación. También pasaron los controles de sintaxis PHP y `git diff --check`.

La búsqueda del proveedor se comprobó con respuestas HTTP simuladas. Queda pendiente la verificación de punta a punta con una cuenta de prueba de Mercado Pago, el navegador y el entorno de Silverhost.

## Archivos y despliegue futuro en Silverhost

- `CheckoutAttempt.php` y `2026_11_06_000002_create_checkout_attempts_table.php` agregan la identidad persistente. Los pedidos históricos no reciben un backfill.
- `CheckoutController.php`, `ShoppingCart.php` y `checkout.blade.php` integran la identidad, el bloqueo y la recuperación.
- `MercadoPagoService.php`, `MercadoPagoSingleRequestTransport.php` y `PreferenceNotSent.php` separan los fallos anteriores al envío de los resultados inciertos. El webhook y `StockReservations.php` preservan el tratamiento de COM-01.
- `MercadoPagoCheckoutTest.php`, `StockReservationConcurrencyTest.php` y el trabajador aislado cubren regresiones y carreras.

En una ventana de mantenimiento, comprobar PHP, conexión MySQL, HTTPS saliente a Mercado Pago y cron de COM-01. Revisar `php artisan migrate:status` y ejecutar las migraciones aditivas de COM-01 y COM-02 que estén pendientes antes de habilitar el checkout. Renovar cachés de configuración y vistas si se usan. Validar en un entorno de prueba la recuperación, la respuesta del webhook y la expiración. Si se necesita revertir, pausar el checkout y conciliar los intentos abiertos antes de retirar el código. Conservar las tablas y registros de reservas e intentos.
