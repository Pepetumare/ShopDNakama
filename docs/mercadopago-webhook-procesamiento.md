# Incidente de sincronización: causa y corrección

## Causa reproducida antes de cambiar el controlador

`MercadoPagoWebhookController` leía el ID del query, pero antes de invocar al SDK exigía también:

```php
abort_unless((string) $request->input('data.id') === $id, 400);
```

Eso convertía el body JSON anidado en un requisito adicional, ajeno al ID del query utilizado para firmar. En PHP, `?data.id=123&type=payment` normalmente llega a la bolsa de query como `data_id=123`. Laravel `input('data.id')` busca una estructura anidada y no encuentra ese campo plano. Incluso si la bolsa conserva el nombre literal `data.id`, `input()` sigue interpretando el punto como recorrido anidado.

Resultado probado con un manifiesto y firma válidos: **HTTP 400 antes de validar firma y antes de consultar el pago**. Por eso no se alcanzaban las escrituras de `mercadopago_payment_id`, `payment_status`, estado o stock. Las pruebas anteriores incluían siempre `data: {id: ...}` en JSON y no detectaban el problema.

La prueba `test_legitimate_query_only_notification_is_processed_without_a_body_data_id` se ejecutó primero contra el código anterior y falló con 400. Tras corregir la lectura, el mismo caso devuelve 200, persiste payment ID, cambia a paid/confirmed y descuenta stock una sola vez al repetir la notificación.

Este es un defecto demostrado en el procesador. No se dispone del contenido histórico de cada notificación rechazada para atribuir retrospectivamente a ese defecto todos los pedidos reales pendientes. La nueva instrumentación permite verificar el recorrido de un reintento legítimo y distinguir errores de firma, API o referencia.

## Firma y campos de la notificación

El paquete instalado y bloqueado es `mercadopago/dx-php` **3.16.0**. Se inspeccionó directamente su `WebhookSignatureValidator`: construye el manifiesto con `id`, `x-request-id` y `ts`, separado y terminado con punto y coma, y compara HMAC-SHA256 con `hash_equals`. No se modificó vendor ni se sustituyó su validación.

El formato coincide con la [documentación oficial de notificaciones](https://www.mercadopago.cl/developers/en/docs/checkout-api-orders/optional-notifications): `id:<data.id>;request-id:<x-request-id>;ts:<ts>;`.

El controlador corregido:

- Obtiene `data.id` del query, admitiendo tanto la clave literal como `data_id` normalizada por PHP; no utiliza el ID del body como fallback. Rechaza un query ambiguo o ID inválido.
- Prefiere `type` del query; conserva fallback de tipo del body para las notificaciones ya soportadas. Un tipo de body distinto no puede ocultar un `type=payment` del query.
- Pasa `x-signature`, `x-request-id` y el ID del query al validador del SDK.
- El body solo aporta `action` e ID para diagnóstico; no decide qué pago consultar ni su aprobación.
- Consulta el pago mediante `PaymentClient->get($id)` a través del servicio existente. El pedido se localiza por `payment.external_reference == order.number` proveniente de esa API, nunca por el body o la success URL.
- Mantiene la segunda consulta después de adquirir los bloqueos, comprueba ID, referencia, monto, CLP y preferencia si la respuesta la incluye. Requiere que exista una preference guardada en el pedido.

La API de Payment no garantiza devolver `preference_id`; si está ausente se registra null y no se inventa a partir de `payment.order.id` (que puede ser una merchant order). La preferencia almacenada se registra aparte como `stored_preference_id`. No se agregó un fallback inseguro para localizar pedidos usando datos del body.

## Estados, idempotencia y respuestas

- approved: payment ID guardado, `payment_status=paid`, `pending`/`pendiente` pasa a `confirmed` bajo las reglas existentes de stock y producción. `Order::STATUSES` reconoce confirmed y conserva confirmado para solicitudes/pedidos históricos.
- pending/rejected: payment ID guardado y estado de pago correspondiente; sin descuento de stock.
- Los estados terminales, intentos distintos de pago, solicitudes canceladas y stock insuficiente mantienen las protecciones existentes.
- Bloqueos y `stock_deducted_at` impiden descontar otra vez al recibir duplicados. No se confía en la URL de retorno.
- Procesado o duplicado válido: **200**. Firma inválida: 401. Query inválido: 400. Identidad/monto/moneda/referencia inválidos: 422. Error API/transacción o falta de secret: 503 para permitir reintentos. La respuesta nunca expone mensajes de excepción.

## Diagnósticos temporales seguros

Canal diario `mercadopago_webhook`, archivo `storage/logs/mercadopago-webhook-YYYY-MM-DD.log`, retención 3 días. Correlación por un `notification_id` UUID local por intento.

Eventos:

- `webhook_received`: tipo, action, ID del query e ID del body por separado, presencia booleana de headers.
- `webhook_signature`: true/false, clase de excepción y motivo del SDK sanitizado si falla.
- `webhook_payment_fetched`: payment ID, estado API, external_reference, preference_id si existe.
- `webhook_order_located`: order ID y preference guardada.
- `webhook_processed`: estados anteriores/nuevos y resultado, **después del commit**; también documenta duplicados o estados terminales preservados.
- `webhook_failed`/`webhook_ignored`: motivo controlado; ante fallo API se registra solo clase y código HTTP, sin payload ni mensaje de excepción.

No se registran access token, secret, firma, valor de x-request-id, tarjetas, credenciales, body completo ni respuestas API completas. Strings acotados y sanitizados impiden inyección de líneas. Un fallo de escritura del diagnóstico no bloquea el pago.

Tras cerrar el incidente, establecer `MERCADOPAGO_WEBHOOK_DIAGNOSTICS=false` y actualizar la configuración cacheada del despliegue si corresponde. `MERCADOPAGO_WEBHOOK_LOG` permite cambiar el destino; para los comandos finales de pruebas se usa un archivo temporal y así se separan de notificaciones reales.

## Configuración real comprobada sin exponer secretos

- APP_URL: `https://nakama.acsoft.cl`.
- notification_url calculada: `https://nakama.acsoft.cl/webhooks/mercadopago`.
- SDK instalado: 3.16.0.
- Access token y webhook secret: presentes; solo se comprobó su existencia, no se imprimieron ni se afirma que coincidan con el dashboard sin una notificación real.
- Siete pedidos con preference, payment ID null y pago pendiente. Se consultó únicamente el conteo; no se marcaron pagados ni se cambiaron pedidos reales para validar el fix.

La creación de Preferences ya asignaba esa notification_url y `external_reference=order.number`; no se cambió. Las Preferences existentes no se reescribieron. Para los pedidos pendientes se necesita recibir/reintentar una notificación legítima; su estado final dependerá del pago consultado a la API.

## Archivos

Controlador, servicio MercadoPago (metadata opcional de preference), alias de estado en Order, configuración de servicios/logging, `.env.example`, pruebas específicas nuevas y expectativas de estado de las pruebas de checkout/cotizaciones. La ruta, CSRF y autenticación permanecen como en la auditoría del endpoint.

No se ejecutaron migraciones, no se borraron pedidos y no se hizo commit/push.

## Resultado final de verificaciones

- `php artisan route:list --path=webhooks/mercadopago`: una ruta POST, sin `/api`.
- `php artisan test --filter=MercadoPago`: **31 pruebas / 237 aserciones**, todas aprobadas.
- Suite completa `php artisan test` (ejecución final con salida compacta): **206 pruebas / 2396 aserciones**, todas aprobadas.
- `./vendor/bin/pint` y `git diff --check`: correctos.
- PHP usa SQLite en memoria para los tests; los diagnósticos de la ejecución final se redirigen a un log temporal.
