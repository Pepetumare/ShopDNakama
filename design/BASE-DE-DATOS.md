# Productos persistentes de Shop D. Nakama

La home, `/catalogo` y `/producto/{product:slug}` consultan MySQL/MariaDB mediante Eloquent. No existe un fallback a productos mock. Con tablas vacías se muestra el estado vacío del catálogo y destacados.

## Esquema y publicación

- `categories`: slug único, nombre, descripción e imagen opcionales, estado y orden.
- `products`: categoría obligatoria, slug y SKU únicos (SKU opcional), descripciones, precios decimales, stock, estado, destacado, nuevo, personalizable y tipo.
- `product_images`: rutas relativas, texto alternativo, imagen principal y orden. Se prioriza `is_primary`, luego `sort_order` e ID.
- Category tiene muchos Products; Product pertenece a Category y tiene muchas ProductImages; ProductImage pertenece a Product.
- Al crear categorías o productos sin slug se genera uno único. Cambiar el nombre no cambia la URL existente.
- Solo se muestran productos activos dentro de categorías activas. La ficha de productos ocultos devuelve 404.
- Los relacionados son hasta cuatro productos activos de la misma categoría, excluyendo el producto actual.
- Los destacados requieren `is_featured = true`; se muestran hasta seis.

## Precios y stock

`App\Support\Price::clp()` y `<x-store.price>` muestran pesos chilenos sin decimales. Una oferta es válida cuando `sale_price` es no negativo y menor que `price`; el precio efectivo se usa también en filtros y orden. Si hay oferta y novedad, se prioriza el badge Oferta.

Stock: más de cinco unidades = En stock; entre una y cinco = Últimas unidades; cero = Agotado. Los controles de carrito siguen deshabilitados.

## Carga de datos e imágenes

La administración de categorías y productos está disponible para administradores en `/housekeeping/categories` y `/housekeeping/products`. Permite crear, editar, publicar y gestionar imágenes. También pueden importarse datos mediante Eloquent. Las imágenes se almacenan en `storage/app/public/products/`, con rutas relativas como `products/figura-samurai.webp`. Las categorías usan `categories/` en el mismo disco público. Ver `HOUSEKEEPING-CATALOGO.md` para operación y límites de carga.

`php artisan storage:link` publica el disco. Las imágenes sin ruta o cuyo archivo no existe en el disco público usan `public/images/product-placeholder.svg`. Un fallo de red no sustituye silenciosamente una imagen existente por el placeholder. La home conserva los recursos decorativos existentes; los productos ya no utilizan recortes de la referencia de diseño.

El disco público genera URLs relativas al origen, por ejemplo `/storage/products/archivo.jpg`, mediante `Storage::disk('public')->url()`. Así se conserva el host, puerto y HTTPS de la página aunque `APP_URL` sea distinto. `PUBLIC_STORAGE_URL` permite configurar explícitamente otra base para un CDN o una instalación en un subdirectorio. En `product_images.image` se guarda únicamente la ruta relativa `products/archivo.jpg`, no la URL ni el directorio físico.

## Seeder opcional

Solo para poblar un entorno de desarrollo de forma explícita:

```sh
php artisan db:seed --class=StoreDemoSeeder
```

No se ejecuta desde migraciones ni desde DatabaseSeeder. Usa `firstOrCreate` por slug dentro de una transacción: agrega registros faltantes sin sobrescribir categorías o productos existentes. Conserva los doce slugs del catálogo de demostración anterior. Los registros son ejemplos de desarrollo y utilizan placeholder hasta cargar imágenes propias.

## Verificación

Las migraciones nuevas únicamente crean las tres tablas y sus índices/relaciones. No se modifican ni eliminan tablas anteriores al aplicar `php artisan migrate`.

Las pruebas usan SQLite `:memory:` según `phpunit.xml`, con datos aislados. El PHP local requiere habilitar `pdo_sqlite` y `sqlite3` para ejecutarlas. No se cambia la conexión MySQL de la aplicación.
