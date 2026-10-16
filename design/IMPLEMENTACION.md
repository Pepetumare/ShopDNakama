# Shop D. Nakama — portada

La ruta `/` renderiza `store/home.blade.php` mediante `StoreController`. El layout y los componentes de `resources/views/components/store/` separan encabezado, navegación, hero, categorías, productos, servicios, beneficios y footer.

## Diseño y recursos

- Referencia principal: `design/referencia-home.png`.
- Bootstrap 5.3.6 se conserva localmente en `resources/css/vendor/bootstrap.min.css`, con su licencia MIT. La copia procede del recurso Bootstrap incluido en PHPUnit Code Coverage; Vite lo integra sin depender de CDN o de la instalación por red.
- `resources/css/store.css` define la paleta, tipografía, composición y adaptaciones para móvil y tablet.
- Las ilustraciones se muestran mediante posiciones de fondo de la referencia copiada a `public/images/shop-reference.png`. No se modifican los originales. Es una solución provisional: para entregar recursos independientes de mayor calidad hacen falta los archivos originales de logo, productos y banners.
- Google Fonts proporciona Kalam y Roboto Condensed; hay fuentes de respaldo si no hay conexión. Las fuentes exactas del mockup no están identificadas.
- Alpine controla la navegación móvil. Los iconos son SVG locales en un componente Blade.

## Alcance

Los productos y precios son datos de muestra en el controlador. Búsqueda y categorías filtran esa muestra. Los botones de carrito están deshabilitados. No hay checkout, pagos, inventario, pedidos ni administración. Los mensajes de beneficios son contenido visual del mockup, no servicios de pago integrados. No se publican teléfonos ni correos ficticios: falta definir los canales comerciales reales.

Las vistas de autenticación originales de Breeze `layouts/guest.blade.php` y `layouts/navigation.blade.php` se restauraron porque faltaban y causaban errores en las pruebas existentes.

## Validación

- `npm run build` compila los recursos de la tienda y los recursos existentes.
- `php artisan test`: 29 pruebas, 76 aserciones. La suite utiliza SQLite en memoria según `phpunit.xml`, sin modificar la base de la aplicación.
- El PHP local es 8.2.18 y su `pdo_sqlite` está deshabilitado. Para ejecutar la suite se copió su configuración a un archivo temporal y se habilitó esa extensión únicamente para el proceso de pruebas, usando `PHPRC`. No se cambió la configuración global de PHP.
- Revisión en Chrome headless: escritorio, tablet y móvil; comprobación de ancho y apertura del menú. Capturas locales en `storage/app/previews/`.

## Pendientes de entorno y fidelidad

El entorno objetivo PHP 8.3+ y MySQL/MariaDB debe configurarse antes de desplegar. Esta intervención visual no cambia `.env`, conexiones ni bases existentes. La portada reproduce la distribución de la referencia, pero la tipografía, algunos iconos y los recursos provisionales impiden afirmar equivalencia píxel a píxel. Contacto comercial y redes sociales requieren información real.
