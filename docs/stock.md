# Inventario de Housekeeping

Acceso: `/housekeeping/stock`, exclusivamente con rol administrador.

## Persistencia y operaciones

- `products.stock` sigue siendo la única cantidad de inventario. `minimum_stock` configura la alerta y `stock_version` detecta formularios desactualizados, incluso si otro movimiento dejó la misma cantidad anterior.
- `stock_movements` conserva producto, pedido cuando corresponde, usuario, fecha, tipo, motivo, diferencia y cantidades anterior y posterior. Las ventas automáticas se identifican como Sistema / Mercado Pago; nunca se atribuyen al comprador como operador de inventario.
- Entradas, salidas, conteos, mermas y reposiciones se realizan dentro de transacciones con bloqueo del producto. No se aceptan cantidades negativas, desbordes ni salidas superiores al saldo. El conteo indica el total físico contado.
- Los formularios manuales tienen un token único: reenviar la misma operación no genera otro movimiento.
- Crear Productos registra su stock inicial como entrada. Editar Productos conserva el saldo existente y ofrece acceso a Gestionar stock; un campo `stock` enviado por un formulario antiguo se excluye.

## Ventas y devoluciones

El checkout valida disponibilidad pero no reserva ni descuenta unidades. Solo el webhook firmado y contrastado con la API de Mercado Pago aplica ventas aprobadas. Se bloquea el pedido y luego todos sus productos en orden de ID, se agrupan líneas del mismo producto y se verifica la disponibilidad completa antes de descontar. El movimiento y `stock_deducted_at` se guardan en la misma transacción. Una restricción única por pedido/producto/tipo aporta protección adicional.

Si el pago está aprobado pero no alcanza el inventario, se conserva el estado de pago real, no se realiza ningún descuento parcial y se registra `fulfillment_issue` para revisión. Un webhook aprobado posterior puede reintentar cuando exista stock. Los pagos pendientes y rechazados no generan movimientos.

Un reembolso o contracargo no demuestra que los productos hayan regresado físicamente. La reposición se confirma explícitamente desde el pedido o el inventario, con motivo y confirmación de recepción de todos los productos en condiciones de venta. Solo se aceptan pedidos reembolsados que registren un descuento previo. Se bloquea el pedido y sus productos; `stock_restored_at` y las restricciones únicas impiden reponer dos veces. No se implementan devoluciones parciales.

Los pedidos anteriores a esta migración conservan sus saldos y marcadores: no se inventa un historial retroactivo ni se vuelven a descontar. Para sus devoluciones se utilizan las líneas existentes del pedido; si faltan productos físicos se requiere revisión en lugar de una reposición incompleta.

La fabricación personalizada sin `OrderItem.product_id` no participa del inventario. Si una línea está vinculada a un producto, su `quantity` debe expresar unidades físicas; un lote de fabricación sin vínculo no se interpreta como mercadería.

## Migración y verificación

Migración aditiva pendiente: `database/migrations/2026_11_05_000001_add_stock_management.php`. No se ejecutó sobre la base de la aplicación ni en producción. Su valor inicial de stock mínimo es 5 y no modifica existencias.

Comandos de validación en este equipo Windows (las extensiones SQLite se habilitan solamente para el proceso de pruebas):

```powershell
php -d extension=pdo_sqlite -d extension=sqlite3 vendor/phpunit/phpunit/phpunit
php vendor/bin/pint --dirty --test
npm run build
git diff --check
```

La prueba de concurrencia crea y migra una base SQLite temporal, inicia dos procesos independientes con una barrera de salida y comprueba que solo uno puede retirar seis de ocho unidades. También se prueban versiones desactualizadas, ventas de pedidos competidores, atomicidad, permisos, tokens duplicados, webhooks repetidos, fabricación personalizada y devoluciones. SQLite no permite verificar la semántica específica de bloqueo por fila de MySQL/PostgreSQL.

## Archivos entregados

Nuevos:

- `app/Http/Controllers/Housekeeping/StockController.php`
- `app/Models/StockMovement.php`
- `app/Services/StockManager.php`
- `database/migrations/2026_11_05_000001_add_stock_management.php`
- `resources/views/housekeeping/stock/index.blade.php`
- `resources/views/housekeeping/stock/show.blade.php`
- `resources/views/housekeeping/stock/movements.blade.php`
- `tests/Feature/StockTest.php`
- `tests/Feature/StockConcurrencyTest.php`
- `tests/Support/stock-worker.php`
- `docs/stock.md`

Modificados:

- `app/Http/Controllers/HousekeepingController.php`
- `app/Http/Controllers/MercadoPagoWebhookController.php`
- `app/Http/Requests/Housekeeping/ProductRequest.php`
- `app/Models/Product.php`
- `app/Services/ProductEditor.php`
- `resources/css/housekeeping.css`
- `resources/views/housekeeping/products/form.blade.php`
- `resources/views/housekeeping/products/index.blade.php`
- `resources/views/housekeeping/orders/show.blade.php`
- `routes/housekeeping.php`
- `tests/Feature/HousekeepingCatalogTest.php`
- `tests/Feature/MercadoPagoWebhookProcessingTest.php`
- `tests/Feature/CustomQuotePaymentTest.php`
