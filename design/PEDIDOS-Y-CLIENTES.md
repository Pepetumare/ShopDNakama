# Pedidos y clientes — Shop D. Nakama

## Esquema e historial

Las migraciones `2026_10_16_000005_create_orders_table` y `2026_10_16_000006_create_order_items_table` crean tablas nuevas sin modificar registros existentes.

`Order` pertenece a un usuario y tiene muchos `OrderItem`. `OrderItem` pertenece a un pedido y puede referenciar un producto. El usuario expone `orders()` y `latestOrder()`. Los pedidos sin número reciben automáticamente un identificador único con prefijo `SDN-` y ULID.

Los campos `customer_*`, `shipping_*` y los nombres/SKU/precios de las líneas son copias históricas. Los importes del pedido son `subtotal`, `shipping_cost`, `discount_total` y `total`. Cada línea guarda `quantity`, `unit_price` y `line_total`. El detalle usa estos datos guardados, no el precio actual del catálogo. Al importar un pedido deben guardarse sus importes y líneas consistentes; esta etapa no implementa creación de pedidos, checkout, cobros ni cálculo automático de importes.

Las referencias a usuarios y productos son opcionales y se ponen a null si estos se eliminan por otros procesos; los datos históricos permanecen. No se incorporan acciones para eliminar pedidos ni clientes. La relación de líneas impide eliminar un pedido que aún tenga productos asociados.

## Cuenta y autorización

`/mi-cuenta/pedidos` pagina exclusivamente los pedidos del usuario autenticado. `/mi-cuenta/pedidos/{order}` utiliza `OrderPolicy::view()` para comprobar la propiedad antes de cargar el detalle. Un pedido ajeno devuelve 403 y uno inexistente, 404. Esto también se aplica a administradores dentro de su cuenta personal.

Se retiró el pedido genérico de ejemplo. Las cuentas sin compras muestran un estado vacío. Las rutas de cuenta mantienen sus nombres y estructura.

## Administración

Todas las rutas nuevas conservan los middleware `auth` y `admin`:

- `GET /housekeeping/orders`: listado con búsqueda por número, nombre y correo; filtros combinables de estado, estado de pago, cliente y fecha desde/hasta, inclusive.
- `GET /housekeeping/orders/{order}`: detalle con cliente, datos de contacto, dirección, líneas e importes guardados.
- `PATCH /housekeeping/orders/{order}/status`: modifica únicamente el estado del pedido. La policy `manage()` comprueba además el rol administrador.
- `GET /housekeeping/customers`: usuarios registrados, búsqueda por nombre/correo, cantidad de pedidos, total gastado y último pedido.
- `GET /housekeeping/customers/{user}`: datos, historial paginado, total gastado y direcciones encontradas en pedidos anteriores, sin duplicados.

Los accesos anteriores `/housekeeping/pedidos` y `/housekeeping/clientes` redirigen a los listados funcionales. Sidebar y dashboard usan las rutas nuevas.

## Estados y métricas

Estados de pedido: `pendiente`, `confirmado`, `preparando`, `enviado`, `entregado`, `cancelado`. El formulario administrativo valida estos valores y permite pasar a cualquiera de ellos. Cambiar el estado no modifica stock, método/estado de pago, importes ni propietario.

Estados de pago disponibles para registrar o importar datos: `pendiente`, `pagado`, `rechazado`, `reembolsado`. Se muestran como información; no existe todavía una operación de pago o reembolso real.

Total gastado suma `total` solo en pedidos `pagado` cuyo estado no sea `cancelado`; excluye pendientes, rechazados y reembolsados. La cantidad de pedidos y el historial incluyen todos los estados. Último pedido se determina por fecha y, en caso de empate, ID.

Clientes incluye a todos los usuarios registrados, también administradores, porque pueden comprar con su misma cuenta. Todavía no hay una agenda independiente de direcciones: el panel muestra las direcciones de los pedidos y el teléfono del último pedido si existe.

## Validación y carga inicial

Los filtros validan tipos, estados permitidos, existencia del cliente y fechas válidas; la fecha final no puede ser anterior a la inicial. Se conserva la búsqueda y los filtros al paginar. Los cambios de estado usan CSRF y una lista cerrada de campos editables.

No se ejecutan seeders ni se inventan compras. Las tablas quedan listas para recibir pedidos de una importación o del futuro flujo de compra. `HousekeepingOrdersTest` utiliza SQLite en memoria y cubre autorización, estados, filtros, fechas inclusivas, métricas, historial y conservación de datos históricos.
