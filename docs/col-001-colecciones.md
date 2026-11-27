# COL-001 · Colecciones de manga

Implementación local en `feature/col-001-manga-collections`. La rama partió del HEAD de `codex/aud-001-custom-payment-recovery` y el árbol de trabajo conserva sus cambios locales. Revisar ambos conjuntos de cambios por separado al preparar la integración.

## Datos y relaciones

La migración aditiva `2026_11_17_000001_create_product_collections.php` crea dos tablas vacías.

- `product_collections` guarda nombre, slug único, categoría, descripción, portada opcional, estado de publicación, orden y metadatos SEO. El slug se genera al crear y se conserva al renombrar la serie.
- `product_collection_volumes` vincula productos con colecciones y guarda número y orden. Los índices únicos sobre `product_id` y sobre `(product_collection_id, volume_number)` impiden que un producto se reutilice en otra colección o que dos productos ocupen el mismo número en la serie.
- `ProductCollection` pertenece a `Category` y tiene muchos `ProductCollectionVolume`. Cada vínculo pertenece a un `ProductCollection` y a un `Product`. Un `Product` tiene como máximo un vínculo, mediante `collectionVolume()`.

Desasociar elimina únicamente el vínculo. El producto sigue siendo la referencia de carrito, favoritos, inventario y pedidos históricos.

## Rutas y pantallas

| Método y ruta | Función |
| --- | --- |
| GET `/coleccion/{collection}` | Ficha de la serie publicada. El parámetro opcional `tomo` abre un tomo concreto. |
| GET `/housekeeping/collections` | Listado de series con categoría, publicación y número de tomos. |
| GET `/housekeeping/collections/create` | Formulario de creación. |
| POST `/housekeeping/collections` | Crear la serie. |
| GET `/housekeeping/collections/{collection}/edit` | Editar datos y portada, controlar publicación y ver el stock por tomo. |
| PUT/PATCH `/housekeeping/collections/{collection}` | Guardar cambios de la serie. |
| POST `/housekeeping/collections/{collection}/volumes` | Asociar un producto existente. |
| PATCH `/housekeeping/collections/{collection}/volumes/{volume}` | Actualizar número y orden. |
| DELETE `/housekeeping/collections/{collection}/volumes/{volume}` | Retirar el tomo de la serie. |

Housekeeping requiere los middleware `auth` y `admin`. Laravel limita las rutas de edición y retiro de tomos al vínculo de la colección indicada.

## Comportamiento público

El catálogo agrupa las series publicadas antes de paginar. Una serie puede coincidir por su nombre o por el nombre o SKU de un tomo visible. El filtro de categoría usa la categoría de la serie y los filtros de precio y oferta usan el precio vigente de los tomos que coinciden. La serie aparece como agotada cuando todos sus tomos públicos están agotados. Una serie entra a destacados si al menos uno de sus tomos públicos está destacado. Los productos de una colección en borrador, o con categoría de colección no pública, se muestran individualmente si son visibles.

La URL anterior `/producto/{product:slug}` responde con un 302 hacia la serie y el número del tomo mientras exista la agrupación pública. El 302 permite que la URL original vuelva a cargar la ficha individual al despublicar o retirar el vínculo. La URL de un tomo agotado puede mostrar su información directamente, con compra deshabilitada. En el selector, el botón de ese tomo sigue deshabilitado.

El selector contiene únicamente los números registrados y visibles. Usa cinco columnas, borde verde reforzado para la selección y gris con diagonal para los agotados. Alpine actualiza imagen principal, miniaturas, descripción, precio, SKU, disponibilidad, favorito y cantidad máxima. El selector admite teclado y anuncia los datos actualizados con una región viva. El formulario envía el ID del producto y la cantidad al carrito existente. Laravel conserva la autoridad sobre precios y stock. Las regresiones de COM-01, COM-02 y COM-03 se ejecutaron con proveedores simulados.

## Archivos de COL-001

| Área | Archivos nuevos | Archivos modificados |
| --- | --- | --- |
| Esquema y Eloquent | `database/migrations/2026_11_17_000001_create_product_collections.php`, `app/Models/ProductCollection.php`, `app/Models/ProductCollectionVolume.php` | `app/Models/Product.php` |
| Tienda | `app/Services/StorefrontListings.php`, `app/Http/Controllers/CollectionController.php`, `resources/views/product/collection.blade.php`, `resources/views/components/store/collection-card.blade.php`, `resources/js/manga-collection.js` | `app/Http/Controllers/CatalogController.php`, `app/Http/Controllers/StoreController.php`, `app/Http/Controllers/ProductController.php`, `resources/views/components/store/product-card.blade.php`, `resources/views/layouts/store.blade.php`, `resources/js/store.js`, `resources/css/store.css`, `routes/web.php` |
| Housekeeping | `app/Http/Controllers/Housekeeping/CollectionController.php`, `resources/views/housekeeping/collections/index.blade.php`, `resources/views/housekeeping/collections/form.blade.php` | `routes/housekeeping.php`, `resources/views/housekeeping/layout.blade.php`, `resources/css/housekeeping.css` |
| Pruebas e informe | `tests/Feature/ProductCollectionTest.php`, `tests/Frontend/manga-collection.test.js`, `docs/col-001-colecciones.md` | |

## Verificación

| Entorno | Resultado |
| --- | --- |
| PHPUnit en SQLite en memoria | 336 aprobadas y 17 omitidas de 353, 3537 aserciones. Las 17 pruebas concurrentes requieren MariaDB/MySQL. |
| PHPUnit en MariaDB 10.11.16/InnoDB aislado | 40 aprobadas, 556 aserciones. Incluye catálogo, COL-001 y las 17 regresiones concurrentes, entre ellas COM-01, COM-02 y COM-03. |
| Esquema MariaDB de prueba separado | Creación, reversión de COL-001 y reaplicación aprobadas. Las tablas de productos y pedidos seguían presentes tras revertir. |
| Frontend | `npm run build` y `node --test tests/Frontend/manga-collection.test.js` aprobados. En Edge a 375 y 1280 px se verificaron el foco y la selección con teclado, la diagonal, el salto de fila, el cambio de galería, los datos del tomo y el formulario administrativo. El formulario público añadió dos unidades del tomo elegido al carrito de prueba por $32.000. |

La primera prueba de imágenes requirió habilitar GD para PHP CLI con `-d extension=gd`. La primera ejecución combinada en MariaDB detectó que una prueba de paginación contaba también un producto creado por una carrera previa. La prueba filtra ahora sus propios datos y pasó al repetir la suite. El catálogo de esta rama requiere el esquema nuevo incluso para servir productos individuales. Para levantarlo en local con la base habitual se necesita autorizar y aplicar la migración o apuntarlo a una base aislada ya migrada.

## Silverhost, despliegue posterior y reversión

Silverhost publica disponibilidad de PHP 8.3 y PDO MySQL. En el plan concreto queda por confirmar la versión mediante `SELECT VERSION()`. La consulta de catálogo usa `ROW_NUMBER()`. Validar en staging con MySQL 8.0+ o MariaDB 10.11/InnoDB y con el disco público accesible para las portadas. La validación aquí se realizó en MariaDB aislado. Quedan pendientes la verificación en Silverhost y el plan de ejecución con el volumen real del catálogo. [Hosting Laravel de Silverhost](https://www.silverhost.cl/hosting-laravel/)

1. Revisar los cambios de COL-001 y de AUD-001 por separado. En staging, respaldar la base y el almacenamiento público, comprobar `php artisan migrate:status` y compilar los activos con `npm ci` y `npm run build`.
2. Aplicar la migración aditiva antes de servir el código nuevo, dentro de la ventana de despliegue aprobada. Si la entrega incluye otras migraciones pendientes, revisar su alcance antes de ejecutar `php artisan migrate --force`. Verificar que el document root apunta a `public` y que el disco público de Laravel sirve las portadas, con `storage:link` si corresponde.
3. Crear una serie primero como borrador. Revisar números, orden y stock, publicarla y comprobar el catálogo, una URL antigua y el carrito en staging antes de repetir el proceso en producción.
4. Para retirar una serie de inmediato, despublicarla desde Housekeeping. Para revertir la entrega completa, restaurar la versión anterior del código y sus activos dejando las tablas e imágenes nuevas disponibles para recuperar la configuración. Si se necesita retirar el esquema, exportar primero las colecciones y vínculos y ejecutar una reversión dirigida durante mantenimiento. El `down()` borra esas dos tablas y su configuración.

Durante el trabajo se utilizaron datos de prueba aislados. Quedó el árbol local listo para revisión, sin commit, push, despliegue ni compras reales.
