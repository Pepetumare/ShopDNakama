# Carrito persistente

La migración `2026_10_19_000001_create_carts_tables` agrega `carts` y `cart_items` sin modificar datos existentes. `carts.user_id` es único y cada combinación carrito/producto también es única.

Relaciones: `User::cart`, `Cart::user`, `Cart::items`, `CartItem::cart`, `CartItem::product`. El carrito se crea cuando el usuario lo necesita.

`ShoppingCart` guarda cantidades en base de datos para usuarios y en sesión para invitados. Al iniciar sesión o registrarse fusiona cantidades, limita al stock disponible, omite productos no visibles y vacía el carrito de invitado tras completar la transacción. Agregar al carrito no reserva inventario.

`CartController` recibe únicamente identificadores de producto y cantidades validadas. Los identificadores de usuario/carrito y precios enviados por el navegador no se utilizan. Las operaciones siempre parten del usuario o sesión actual. Los cambios autenticados serializan los agregados mediante bloqueo de usuario y producto; las rutas de escritura bloquean la sesión para evitar agregados concurrentes de invitados.

Rutas:

- `GET /carrito`: contenido y resumen.
- `POST /carrito`: agrega `product_id` y `quantity`.
- `PATCH /carrito/{product}`: establece `quantity` para un producto del carrito propio.
- `DELETE /carrito/{product}`: elimina un producto del carrito propio.
- `GET /checkout`: pantalla provisional para la siguiente etapa.

Los precios se consultan desde `Product::current_price`, incluida su regla de oferta válida. Los subtotales usan centavos enteros antes de presentarse como CLP. Si cambia stock o visibilidad, la lectura ajusta las cantidades guardadas y muestra un aviso. El contador del encabezado suma unidades.

Las tarjetas compartidas conectan home, catálogo, favoritos y relacionados. La ficha envía la cantidad elegida. Las vistas nuevas `store/cart` y `store/checkout` usan el layout y los estilos actuales.

`tests/Feature/CartTest.php` cubre sesión, persistencia, cantidades, stock, productos no disponibles, precios, contador, aislamiento entre usuarios, fusión al iniciar sesión y registro, y formularios en las superficies de productos. La suite usa SQLite en memoria; PHP debe disponer de `pdo_sqlite`.
