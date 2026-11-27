# COR-001: correos transaccionales

## Configuración SMTP

Definir en el entorno de ejecución, sin guardar credenciales en Git:

```dotenv
MAIL_MAILER=smtp
MAIL_SCHEME=tls
MAIL_HOST=smtp.example.com
MAIL_PORT=587
MAIL_USERNAME=usuario-smtp
MAIL_PASSWORD=secreto-smtp
MAIL_FROM_ADDRESS=tienda@example.com
MAIL_FROM_NAME="Shop D. Nakama"
MAIL_TIMEOUT=10
AUTH_VERIFICATION_EXPIRE=60
QUEUE_CONNECTION=database
```

Usar los valores TLS/puerto que indique el proveedor SMTP. `AUTH_VERIFICATION_EXPIRE` define los minutos de validez del enlace de verificación. No copiar estos valores de ejemplo como credenciales reales. Después de cambiar variables con configuración cacheada, ejecutar `php artisan config:cache`.

## Worker

Los correos transaccionales se escriben primero en `transactional_notifications` y se envían mediante `SendTransactionalNotification`. La fila muestra `queued`, `processing`, `sent` o `failed`; `sent` significa que el transporte aceptó el mensaje, no que el buzón final lo haya recibido.

Con `QUEUE_CONNECTION=database`, mantener un worker activo:

```powershell
php artisan queue:work database --queue=default --tries=3 --backoff=60
```

Supervisar el proceso del worker y las filas pendientes en el panel Housekeeping → Correos transaccionales. Los mensajes fallidos se reintentan desde ese panel; el reintento solo vuelve a enviar el correo y no invoca operaciones financieras ni cambia el pedido.

## Plantilla

La versión HTML y su alternativa de texto usan español, colores verde y neutros de la tienda, y el arte original del logotipo contenido en `public/images/shop-reference.png`. Verificaciones usan URL firmada con el vencimiento de `AUTH_VERIFICATION_EXPIRE`; restablecimientos usan el token del broker estándar de Laravel.
