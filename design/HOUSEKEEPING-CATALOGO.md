# Gestión del catálogo — Shop D. Nakama

## Acceso y navegación

Los módulos funcionales están en `/housekeeping/categories` y `/housekeeping/products`. Todas sus rutas GET, POST, PUT/PATCH y DELETE usan los middleware `auth` y `admin`. Los formularios también comprueban `isAdmin()` y tienen protección CSRF.

El sidebar y el dashboard llevan a los listados. Las rutas anteriores `/housekeeping/categorias` y `/housekeeping/productos` redirigen a los módulos funcionales. Los demás módulos conservan sus pantallas preparadas.

## Categorías

Listado paginado con imagen, nombre, slug, orden, cantidad de productos, estado y acceso a edición. Crear o editar permite cambiar nombre, slug, descripción, imagen opcional, estado y orden. Los números menores aparecen primero en la tienda. Desactivar una categoría también oculta sus productos, sin eliminarlos.

El slug vacío se genera desde el nombre al crear; al editar se conserva el slug anterior. Un slug indicado se normaliza y debe ser único. Los formularios de edición usan el slug como identificador de ruta.

## Productos

Listado paginado de 15 productos con búsqueda por nombre o SKU, filtros por categoría y estado y conservación de filtros al cambiar de página. Los campos de edición incluyen categoría, SKU, descripciones, tipo, precio, oferta, stock y flags activo, destacado, nuevo y personalizable.

El precio y la oferta deben ser no negativos, con hasta dos decimales. La oferta, si se informa, debe ser menor que el precio normal. El stock y los órdenes deben ser enteros no negativos. Slugs y SKU deben ser únicos. Puede asignarse una categoría inactiva, pero su estado se indica y el producto permanecerá oculto en la tienda.

La tienda consulta los mismos registros: guardar un producto modifica automáticamente su ficha y catálogo, y la home si está activo, destacado y dentro de una categoría activa. Cambiar el slug modifica la URL pública; se conserva si no se modifica.

## Imágenes

Se almacenan rutas relativas en el disco `public`, dentro de `categories/` y `products/`, con nombres generados por Laravel. Solo se aceptan JPG, PNG y WebP; hasta 5 MB por imagen y 10 imágenes por carga de producto. Los límites de subida de PHP y del servidor también deben permitir el tamaño total de la carga.

La creación elige una principal automáticamente. Tras guardar, se puede elegir otra principal, editar el orden y el texto alternativo de cada imagen. La principal se presenta primero; las demás siguen su orden. Estas modificaciones se aplican al guardar el formulario.

Cada imagen tiene su propia acción de eliminación con confirmación. Esa acción no guarda los demás cambios pendientes del formulario. Al eliminar la principal se selecciona otra; al eliminar la última, la tienda usa el placeholder. Las imágenes de otros productos no pueden editarse, seleccionarse ni eliminarse desde una URL ajena.

Las escrituras del producto y sus metadatos se realizan en transacciones. Si falla el guardado, se retiran los archivos nuevos que no quedaron referenciados. Un archivo compartido por otro registro no se elimina al retirar una referencia.

## Operación

- El enlace `public/storage` debe apuntar a `storage/app/public`; ya existe en este entorno. En otra instalación, ejecutar `php artisan storage:link` si falta.
- La administración requiere un usuario con `role = admin`; no se crea ni se promueve ningún usuario automáticamente.
- No se añaden rutas para eliminar categorías o productos. Solo se permite retirar imágenes de forma individual o sustituir la imagen de una categoría.
- No se necesitan migraciones ni seeders para esta etapa.

## Validación

`HousekeepingCatalogTest` usa SQLite en memoria y almacenamiento público simulado. Cubre creación, edición, flags, estados, validación, búsqueda combinada, publicación en la tienda, carga y eliminación de imágenes, principal, orden, imágenes ajenas y archivos compartidos. Las pruebas no modifican el catálogo MySQL existente.
