# Cuenta y roles de Shop D. Nakama

Se reutiliza Laravel Breeze con Blade: registro, login, recuperación, actualización de contraseña y logout. El destino anterior `/dashboard` sigue existiendo y redirige a `/mi-cuenta`.

## Roles y permisos

La migración `2026_10_16_000004_add_role_to_users_table` agrega `users.role` con valores `user` y `admin`, predeterminado `user`. No elimina usuarios ni modifica contraseñas. Los usuarios anteriores reciben el rol normal.

`User::isAdmin()` comprueba el valor exacto `admin`. El atributo `role` no pertenece a `$fillable`: los formularios de registro, datos y perfil no pueden promover usuarios.

Todas las rutas declaradas en `routes/housekeeping.php` usan `auth` y `admin`. El alias `admin` apunta a `AdminMiddleware`, que devuelve 403 si no hay un administrador autenticado. `auth` envía a los invitados al login. El enlace visible no sustituye estas comprobaciones.

## Promover un usuario existente

Ejecutar deliberadamente desde una consola con acceso al proyecto:

```sh
php artisan tinker
```

```php
$user = App\Models\User::where('email', 'correo-del-usuario@example.com')->firstOrFail();
$user->role = 'admin';
$user->save();
```

Para revocar el acceso, asignar `user` y guardar. No se cambia la contraseña. No se crea ni se promueve ningún administrador automáticamente. No se incorpora un seeder con credenciales predeterminadas.

## Área de cuenta

`routes/account.php` protege con `auth` el resumen, datos, direcciones, favoritos, listado de pedidos y ficha del pedido. Usuarios y administradores comparten el mismo menú y las mismas vistas. Los administradores ven un acceso separado a Housekeeping tanto en la cuenta como en el header.

Mis datos actualiza únicamente nombre y correo usando la validación existente de Breeze. El cambio de contraseña usa el endpoint de Breeze y requiere la contraseña actual. Direcciones conserva su vista preparada. Favoritos guarda productos por usuario en la tabla pivote `favorites`, con contador real y estado compartido entre home, catálogo y ficha; ver `FAVORITOS.md`.

Pedidos consulta los modelos `Order` y `OrderItem`. Cada usuario ve exclusivamente sus pedidos y la policy `OrderPolicy` impide abrir pedidos ajenos mediante su URL. La ficha de ejemplo anterior se retiró. Las cuentas sin compras muestran un estado vacío. Ver `PEDIDOS-Y-CLIENTES.md` para datos, métricas y autorización.

## Housekeeping

Layout independiente en `resources/views/housekeeping/layout.blade.php`, CSS propio y navegación responsive. El dashboard muestra conteos reales de productos, categorías, usuarios y productos activos con una a cinco unidades.

Productos y categorías tienen listados, formularios y operaciones funcionales en `/housekeeping/products` y `/housekeeping/categories`. Pedidos y clientes se consultan en `/housekeeping/orders` y `/housekeeping/customers`; los administradores pueden actualizar el estado de los pedidos. Sus antiguos accesos en español redirigen a los módulos funcionales. Stock, ofertas, impresión 3D, estampados y configuración conservan las vistas preparadas. Ver `HOUSEKEEPING-CATALOGO.md` y `PEDIDOS-Y-CLIENTES.md`.

## Pruebas

`AccountAuthorizationTest` cubre invitados, usuarios, administradores, todos los módulos, visibilidad de enlaces, prevención de promoción por formularios, valor predeterminado de rol y logout. `HousekeepingOrdersTest` valida también propiedad de pedidos y acceso administrativo. Las pruebas utilizan SQLite en memoria; no usan usuarios reales de MySQL.
