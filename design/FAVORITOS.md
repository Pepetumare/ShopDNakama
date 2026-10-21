# Favoritos persistentes — Shop D. Nakama

## Persistencia y relaciones

`2026_10_16_000007_create_favorites_table` crea una tabla nueva con ID, `user_id`, `product_id` y timestamps. La pareja usuario/producto tiene una restricción única. No se modifican los datos existentes.

`User::favorites()` y `Product::favoritedBy()` son relaciones `belongsToMany` sobre `favorites`, con timestamps. Las referencias se eliminan en cascada si se elimina su usuario o producto mediante otro flujo; esta etapa no agrega acciones para borrar usuarios o productos.

## Rutas y seguridad

- `POST /favoritos/{product:slug}` guarda un producto activo dentro de una categoría activa.
- `DELETE /favoritos/{product:slug}` quita la relación solo para el usuario autenticado, incluso si el producto se desactivó.
- `GET /mi-cuenta/favoritos` muestra exclusivamente los favoritos del usuario autenticado, paginados de doce en doce.

Las rutas requieren `auth` y los formularios usan CSRF. Los invitados son enviados al login. El producto se resuelve por slug; si no existe devuelve 404. Se usa siempre el usuario de la sesión, nunca un `user_id` recibido del navegador. Agregar repetidamente es idempotente, también ante solicitudes concurrentes gracias al índice único y `insertOrIgnore`. Quitar una relación inexistente es igualmente seguro.

## Interfaz

El componente `store.favorite-button` se reutiliza en tarjetas y ficha. Envía formularios POST/DELETE y vuelve a la página anterior después de guardar. No depende de JavaScript ni modifica solo una variable local. Cada respuesta nueva renderiza el estado persistido y el contador; esta versión utiliza recarga completa, sin AJAX.

Home, catálogo y relacionados usan `withFavoriteState()` para cargar el estado con una subconsulta `EXISTS`, sin una consulta adicional por tarjeta. La ficha carga el mismo estado para su producto principal. El header consulta el conteo real de la relación del usuario; invitados ven cero.

La lista reutiliza `product-card`. Un producto guardado que se desactive continúa en la lista personal, marcado como No disponible y sin enlace a una ficha oculta. Puede quitarse con su corazón. Esto mantiene el contador y el listado consistentes. No se pueden agregar productos desactivados mediante una URL manual.

Cuando la lista queda vacía se muestra el mensaje Aún no tienes favoritos y un enlace al catálogo. Si una eliminación deja una página de paginación fuera de rango, el listado redirige a la última página válida.

## Pruebas

`FavoritesTest` cubre invitados, agregar, recargar, duplicados, timestamps, relaciones, aislamiento entre usuarios, eliminación idempotente, contador real, estado del corazón en las cuatro ubicaciones, estado vacío, productos no disponibles y paginación. Las pruebas usan SQLite en memoria y no agregan favoritos a cuentas reales de MySQL.
