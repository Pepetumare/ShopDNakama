# COM-01 · Reservas de inventario

COM-02 distingue las reservas abandonadas antes del envío de las que podrían tener una preferencia creada. Estas últimas siguen retenidas hasta reconciliar el resultado externo. Ver [COM-02](com-02-checkout.md).

Antes, el checkout comprobaba el stock y liberaba el bloqueo antes de iniciar el pago. Ahora guarda el pedido, sus líneas y la reserva en una sola transacción, con los productos bloqueados por ID. El saldo disponible es el stock físico menos las reservas vigentes y vencidas pendientes de conciliación. Las salidas manuales y los pagos históricos respetan ese saldo. Un futuro POS debe usar el mismo bloqueo y la misma regla.

La migración agrega `stock_reservations`, con una fila única por pedido y producto. Guarda cantidad, vencimiento, consumo, liberación y motivo. El plazo inicial es de 15 minutos y se configura en `config/inventory.php`.

| Estado | Efecto |
|---|---|
| `active` | Retiene unidades. Puede consumirse si el webhook procesa la aprobación antes del vencimiento. |
| `consumed` | El webhook ya descontó el stock y registró el movimiento de venta. |
| `expired` | Sigue reteniendo unidades. Cualquier aprobación posterior se registra como incidencia. |
| `released` | Las unidades vuelven al saldo disponible. Se conserva el motivo. |

El checkout solicita la preferencia fuera de la transacción y solo entrega la URL si logra persistir la preferencia con la reserva vigente. Ante un fallo antes de publicar la URL libera la reserva y conserva el carrito. El webhook consulta el pago fuera del bloqueo, valida firma, referencia, moneda, importe y preferencia cuando está disponible, y aplica el resultado dentro de la transacción. La fecha límite se evalúa al procesar el webhook, por lo que una notificación retrasada también puede requerir revisión. El checkout de personalizados conserva su flujo.

## Operación y despliegue posterior en Silverhost

1. En una copia aislada, comprobar migración, pruebas y cron. Confirmar PHP CLI compatible, HTTPS, raíz `public/`, permisos y un responsable de conciliación.
2. Tomar una copia restaurable de la base y pausar el checkout durante el cambio. Aplicar la migración aditiva con `php artisan migrate --force` antes de habilitar el código que consulta la tabla. Regenerar la caché de configuración si se usa.
3. Configurar el cron para ejecutar `php artisan schedule:run` cada minuto. Verificar `php artisan schedule:list`, el comando `stock:expire-reservations` y el acceso al webhook. El mantenimiento recupera reservas abandonadas antes de publicar la preferencia. La liberación y la publicación se serializan con el bloqueo del pedido.
4. Revisar las reservas vencidas y cruzar número de pedido, preferencia y todos los intentos en Mercado Pago. Un rechazo individual no acredita el cierre de la preferencia. Mantener la retención si queda un intento incierto. Tras verificar el cierre y resolver cualquier cobro, ejecutar `php artisan stock:release-reservation NUMERO --verified --reason="Referencia de conciliación"`. El comando registra la decisión del operador y es idempotente. El sistema no ejecuta reembolsos.

## Reversión conservando registros

Pausar el checkout y mantener el webhook nuevo mientras se concilian los pedidos con reservas. Resolver también preferencias publicadas que aún puedan pagar y pagos aprobados con incidencias. Detener la tarea de expiración al retirar el código y restaurar la versión anterior solo después de cerrar los intentos afectados. Conservar la tabla y los movimientos como historial. No ejecutar el método `down` de la migración sobre una base con reservas registradas. Volver al checkout anterior reintroduce el riesgo COM-01.

## Límites y siguientes etapas

COM-02 debe persistir una identidad de intento para recuperar el mismo pedido ante un reintento. COM-03 debe registrar y conciliar cobros adicionales. Las reservas vencidas sin confirmación de cierre pueden retener stock indefinidamente. Los registros de pagos y pedidos históricos no se migran ni se reescriben.

## Pruebas

Ejecutar PHPUnit con proveedores simulados y SQLite en memoria para los casos funcionales. Para la carrera, usar una instancia de MySQL de pruebas en loopback con una base exclusiva vacía llamada `nakama_com01_test`. Configurar `NAKAMA_RESERVATION_RACE=1`, conexión `mysql`, host `127.0.0.1` y credenciales exclusivas de esa instancia. Ejecutar `vendor/bin/phpunit --filter StockReservationConcurrencyTest`. La prueba migra esa base y pone a dos procesos a competir por una unidad.

La carrera pasó en Oracle MySQL 8.4.13 aislado con 13 aserciones. Solo un proceso creó pedido y reserva, y el stock físico quedó en una unidad. También pasó en MariaDB 10.11.16 aislado. La primera corrida funcional encontró GD deshabilitado y un conflicto de escritura en el trabajador SQLite preexistente. GD se habilitó solo para el proceso de pruebas y el trabajador de prueba reintenta de forma acotada ante `SQLITE_BUSY`.

La corrida funcional final pasó con 81 pruebas y 895 aserciones. Los 16 archivos PHP de la etapa pasaron `php -l` y `git diff --check` quedó limpio.

## Archivos

- `CheckoutController`, `MercadoPagoService` y `MercadoPagoWebhookController` integran reserva, vigencia de preferencia y confirmación.
- `StockReservations`, `StockManager` y el controlador de pedidos de Housekeeping protegen el saldo, el consumo y el avance de pedidos.
- La migración `2026_11_06_000001_create_stock_reservations_table.php`, `config/inventory.php` y `routes/console.php` definen persistencia, plazo y operación.
- Las pruebas cubren el checkout, los webhooks, el mantenimiento y la concurrencia. Los trabajadores de `tests/Support/` solo se usan en pruebas.
