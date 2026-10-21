# Mercado Pago — Checkout Pro

SDK oficial: `mercadopago/dx-php` 3.16.0. Solo se crean preferencias; los datos de tarjeta se ingresan en Mercado Pago.

## Configuración

Agregar en `.env` (no versionar credenciales):

```dotenv
MERCADOPAGO_PUBLIC_KEY=
MERCADOPAGO_ACCESS_TOKEN=
MERCADOPAGO_WEBHOOK_SECRET=
MERCADOPAGO_SHIPPING_COST=3990
APP_URL=https://tu-dominio-publico
```

La Public Key queda disponible en configuración para usos futuros; la redirección de Checkout Pro no necesita enviarla al navegador. Access Token y secreto del webhook son exclusivos del backend. Después de cambiar variables ejecutar `php artisan config:clear`.

El despacho tiene tarifa plana configurable en CLP (3990 por defecto). Confirmar la tarifa comercial antes de habilitar ventas. No se ofrece retiro porque aún no hay configuración de local/punto de retiro.

## Flujo y rutas

- `GET /checkout`: exige sesión y carrito; muestra datos del cliente, dirección, comuna, región y resumen.
- `POST /checkout`: valida el carrito persistido, disponibilidad y stock bajo bloqueo; calcula precios vigentes desde productos, crea pedido e ítems con `status=pending`, `payment_status=pending`. Nunca acepta importes del cliente.
- `MercadoPagoService` configura el SDK, crea la preferencia con productos del pedido y `external_reference=number`, y consulta pagos con `PaymentClient`.
- El navegador se redirige al `init_point` recibido. Tras guardar la preferencia se retiran del carrito las líneas correspondientes que no cambiaron mientras se creaba la preferencia. Si falla la API, el pedido queda pendiente y el carrito se conserva.
- `GET /checkout/mercadopago/success`, `/pending`, `/failure`: vistas informativas. No modifican pedidos ni confían en parámetros de retorno.
- `POST /webhooks/mercadopago`: público, exento de CSRF solamente para esta ruta. Requiere firma válida del SDK, `data.id` en query y body coincidentes y configuración de secreto. Consulta el pago autenticado desde backend y comprueba ID, referencia, moneda CLP y monto exacto contra el pedido. Errores de API retornan 5xx para permitir reintentos.

Estados de pago nuevos: `pending`, `paid`, `rejected`, `refunded`, `cancelled`. `approved` confirma el pedido (`confirmado`) si hay stock. Se mantienen las etiquetas y estados históricos en español para no modificar pedidos existentes ni reportes.

## Stock e idempotencia

No se reserva stock al crear el pedido. Al aprobarse el pago, una transacción bloquea el pedido y los productos, verifica disponibilidad de todas las líneas, descuenta una sola vez y guarda `stock_deducted_at`. Se vuelve a consultar el pago después de bloquear el pedido para evitar aplicar notificaciones fuera de orden. Un pago aprobado no retrocede a pendiente/rechazado; un reembolso es terminal. Otro intento de pago no sustituye un pago aprobado asociado al pedido.

Si el stock se agotó mientras se pagaba, se registra el pago como `paid`, sin descontar parcialmente ni confirmar preparación; `fulfillment_issue` muestra una alerta en Housekeeping para resolver disponibilidad o reembolso. Si un pedido fue cancelado antes de pagarse también se señala para revisión. Los reembolsos y contracargos no reponen stock automáticamente: debe revisarse la devolución física.

La migración `2026_10_19_000002_add_mercadopago_to_orders` añade Payment ID y Preference ID únicos y opcionales, tipo de entrega, marca de descuento de stock y alerta de preparación. No borra datos. Los detalles de pedido muestran método, estado e identificadores; el área de cuenta conserva la autorización por propietario.

## Probar sin dinero real

1. En Mercado Pago Developers, crear/configurar una aplicación Checkout Pro y cuentas de prueba de vendedor y comprador de Chile. Usar las credenciales del vendedor de prueba, nunca las de una cuenta que cobre dinero real. El comprador debe ser otra cuenta.
2. Configurar las variables anteriores. Exponer Laravel por HTTPS público (dominio de pruebas o túnel) y establecer `APP_URL` con esa misma URL. Reiniciar el servidor y limpiar la caché de configuración.
3. En Tus integraciones → Webhooks, configurar el evento de pagos y la URL `https://tu-dominio-publico/webhooks/mercadopago`; copiar su firma secreta a `MERCADOPAGO_WEBHOOK_SECRET`.
4. Entrar a Shop D. Nakama, agregar productos y completar checkout. En una ventana privada, pagar usando el comprador y tarjetas de prueba oficiales. La integración usa `init_point`, también para cuentas de prueba.
5. Verificar que volver por success no cambia por sí mismo el estado. Tras el webhook, Mis pedidos debe mostrar Pagado y Housekeeping el Payment ID. Reenviar la notificación y comprobar que el stock no se descuenta nuevamente. Probar rechazo y reembolso.

Referencias oficiales: [compras de prueba](https://www.mercadopago.cl/developers/en/docs/checkout-pro-preferences/integration-test/test-purchases?scope=prod), [notificaciones](https://www.mercadopago.cl/developers/en/docs/checkout-pro-preferences/additional-content/notifications/webhooks), [SDK PHP](https://github.com/mercadopago/sdk-php).

Las pruebas automatizadas usan mocks de `MercadoPagoService`: no llaman a Mercado Pago. Cubren carrito vacío, importes manipulados, precios vigentes, pedido pendiente, stock, aprobación, rechazo, duplicados, firma, referencia, moneda, monto, retorno informativo, reembolso y aislamiento entre usuarios.

En esta máquina el PHP CLI requiere habilitar `pdo_sqlite` y `sqlite3` para la suite SQLite. Puede usarse un INI temporal mediante `PHPRC`; no hace falta cambiar la configuración global de PHP.
