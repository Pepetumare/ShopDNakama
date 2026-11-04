# Auditoría del endpoint Mercado Pago

Esta auditoría cubre el transporte HTTP. El fallo posterior de procesamiento y su corrección están documentados en [mercadopago-webhook-procesamiento.md](mercadopago-webhook-procesamiento.md).

## Resultado

El checkout revisado ya registra una única ruta pública **POST /webhooks/mercadopago**, nombrada `webhooks.mercadopago`, hacia `MercadoPagoWebhookController`. Está en `routes/web.php`, fuera de los grupos auth/admin. No existe `routes/api.php` ni otro registro del webhook en los archivos de rutas incluidos.

`bootstrap/app.php` configura la excepción CSRF exacta `webhooks/mercadopago`; no hay excepción global ni wildcard. El grupo web mantiene cookies, sesión, errores de sesión, validación CSRF (exceptuando este path) y bindings. La firma de Mercado Pago se valida dentro del controlador.

El 405 reportado **no se reprodujo**: una petición HTTP real al host público, POST a `https://nakama.acsoft.cl/webhooks/mercadopago?data.id=0`, con `type=payment` y `data[id]=0`, sin autenticación, CSRF ni firma, devolvió **401 Unauthorized** el 27 de octubre de 2026, 20:35:47 UTC. Esto es coherente con la ruta pública y el rechazo de firma ausente; no se usaron credenciales ni se enviaron pagos válidos. La causa histórica del 405 no está confirmada con la evidencia disponible. No se atribuye a caché, despliegue, proxy o redirects sin sus registros.

Se ejecutó `php artisan optimize:clear` en este entorno y se comprobó de nuevo la ruta. No se modificaron ruta, configuración CSRF ni lógica del controlador porque ya cumplen el contrato solicitado. No se realizó un despliegue remoto ni una limpieza remota de caché.

## Pruebas añadidas

`tests/Feature/MercadoPagoWebhookEndpointTest.php` comprueba:

- Una única ruta POST, URL exacta sin `/api`, controlador esperado y ausencia de auth/admin.
- POST público llega al controlador, sin token CSRF ni sesión autenticada.
- GET y HEAD devuelven 405, incluso con firma válida, sin consultar la API ni actualizar pedidos/stock.
- CSRF se exige para otras rutas y para un path vecino del webhook.
- Firma ausente o inválida devuelve 401 antes de consultar pagos.
- Firma válida verifica el pago con el servicio API simulado, valida referencia/monto/moneda, marca el pedido pagado y descuenta stock una vez ante dos notificaciones.

Los tests sustituyen únicamente el bypass de CSRF que Laravel activa normalmente durante pruebas; ejercitan el middleware real y su excepción configurada. Las llamadas API se simulan para no consultar ni modificar pagos reales.

## Verificación

- `php artisan route:list --path=webhooks/mercadopago`: **POST**, una ruta, antes y después de limpiar caché.
- `php artisan test --filter=MercadoPago`: **19 pruebas aprobadas, 164 aserciones**. Incluye pruebas existentes de referencia externa, montos, moneda, idempotencia, stock, pedido y payload de Mercado Pago.
- PHP usa SQLite en memoria con `pdo_sqlite` habilitado en un ini temporal; no se cambia la configuración global.
- `php artisan optimize:clear`: completado correctamente en este entorno.

No se borraron datos ni se hizo commit/push. El único cambio de código es la cobertura de regresión del endpoint.
