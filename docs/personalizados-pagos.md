# Cotizaciones: pedidos y Mercado Pago

Actualización AUD-001 (2026-11-16): la publicación y recuperación de preferencias se rigen ahora por [aud-001-custom-payment-recovery.md](aud-001-custom-payment-recovery.md). Un resultado ambiguo no habilita otro POST; los detalles de emisión e idempotencia de este documento describen la implementación anterior.

## Resultado y alcance

El administrador ingresa un precio positivo y la solicitud pasa a Cotizado. El propietario confirma datos de despacho, acepta y paga en Checkout Pro. Se genera un pedido real con el monto de la base de datos, visible en Mis pedidos. Únicamente el webhook con pago aprobado cambia el pedido a paid y la solicitud de quoted a approved.

Se reutilizan layouts, componentes, roles, autenticación, servicio de Mercado Pago y webhook existentes. El flujo personalizado es independiente del carrito y no descuenta stock. No se realizaron llamadas reales, cobros, commits ni push.

## Migración y relaciones

Migración aditiva aplicada: `database/migrations/2026_10_27_000004_link_custom_requests_to_orders.php`.

- orders.custom_request_id: nullable, único, FK a custom_requests, restringe eliminar solicitudes referenciadas.
- orders.mercadopago_checkout_url: conserva la URL para reutilizar la preferencia.
- order_items.description: snapshot de configuración básica.
- CustomRequest::order(): hasOne Order; Order::customRequest(): belongsTo CustomRequest.
- Se conservan User -> CustomRequest -> CustomRequestFile y User -> Order -> OrderItem.
- CustomRequest::ready_for_production solo es verdadero con estado approved, pago paid/pagado y pedido no cancelado.

Esta fase conserva un único pedido por solicitud, incluso después de cancelarlo. Reutiliza pedidos pendientes seguros y permite reintentar pagos rechazados; no genera reemplazos automáticos de pedidos cancelados/reembolsados. No se eliminaron datos históricos ni se ejecutó migrate:fresh contra la base de la tienda.

## Controladores, Form Request y servicios

| Archivo | Responsabilidad |
| --- | --- |
| app/Http/Controllers/CustomQuoteController.php | Aceptar/rechazar y redirigir a Checkout Pro; manejar errores sin registrar respuestas sensibles. |
| app/Http/Requests/AcceptCustomQuote.php | Autorizar propietario y validar datos de despacho; reintentos con pedido existente no exigen otra dirección. |
| app/Services/CustomQuoteCheckout.php | Pedido único, snapshot, bloqueos, despacho, preferencias y rechazo. |
| app/Services/ManageCustomQuote.php | Precio positivo, cotización automática, transiciones y congelación del monto aceptado. |
| app/Http/Requests/Housekeeping/UpdateCustomRequest.php | Validar estado, cotización mayor que cero con hasta dos decimales y notas internas. |
| app/Http/Controllers/Housekeeping/CustomRequestController.php | Delegar gestión y cargar pedido asociado. |
| app/Http/Controllers/AccountOrderController.php | Mostrar pedido personalizado e incorporar relación en historial/detalle. |
| app/Http/Controllers/Housekeeping/OrderController.php | Cargar solicitud vinculada desde el pedido administrativo. |

La primera aceptación solicita dirección, comuna/ciudad y región con los estilos existentes; teléfono opcional. El pedido guarda un snapshot y los reintentos no lo sobrescriben.

El pedido se crea con user_id del propietario, status/payment_status pending, payment_method mercadopago, subtotal igual al quoted_price de BD, shipping_cost desde services.mercadopago.shipping_cost (MERCADOPAGO_SHIPPING_COST), discount_total cero y total subtotal + despacho. Se comparan montos en centavos y se controla el máximo de la columna monetaria. Se ignoran montos y estados enviados por frontend.

Se crea un ítem Personalizado PER-000001 que representa el lote completo, con quantity=1 y unit_price/line_total igual al precio cotizado. La descripción incluye producto, configuración y cantidad solicitada; no se multiplica de nuevo la cotización por la cantidad de piezas.

## Rutas y autorización

| Método | Ruta | Nombre |
| --- | --- | --- |
| POST | /mi-cuenta/personalizados/{customRequest}/accept | account.custom-requests.accept |
| POST | /mi-cuenta/personalizados/{customRequest}/decline | account.custom-requests.decline |

Se conservan las rutas actuales de checkout, retornos, webhook, cuenta y administración. Las dos acciones usan auth y web/CSRF. CustomRequestPolicy añade accept/decline: exigen propiedad y no conceden al administrador permiso para actuar en nombre de otro cliente.

La aceptación revalida propiedad, quoted y precio positivo dentro de la transacción. Todas las operaciones personalizadas adquieren lockForUpdate en el orden solicitud -> pedido. La relación única evita duplicados también a nivel de BD. Si existe pedido, deben coincidir propietario, método, subtotal y un estado seguro para pagar.

Rechazar solo se permite en quoted, nunca después de un pago confirmado. Sin aceptación previa no crea ningún pedido. Si ya existe un pedido pendiente, se cancela localmente.

## Mercado Pago reutilizado e idempotencia

`app/Services/MercadoPagoService.php` continúa como única integración:

- createPreference recibe el pedido real y conserva external_reference, metadata.order_id, payer, CLP, back_urls y notification_url existentes.
- Para personalizados añade una cabecera X-Idempotency-Key estable derivada del número de pedido; los ítems sin producto de catálogo reciben un identificador personalizado.
- La preferencia y su URL se guardan bajo bloqueo. Doble clic y reenvíos reutilizan la URL, sin crear otra preferencia.
- getPreference recupera una preferencia con ID existente cuando falta su URL local.
- Un fallo de API conserva el pedido pendiente. El siguiente intento usa el mismo pedido; la clave estable mitiga repeticiones ante respuestas ambiguas, sujetas al comportamiento del proveedor.

El pedido se persiste antes de solicitar la preferencia. La segunda transacción serializa su creación/recuperación. El checkout normal conserva su controlador, carrito y lógica de stock; los cambios funcionales compartidos son condicionales para personalizados.

## Webhook y producción

`app/Http/Controllers/MercadoPagoWebhookController.php` mantiene validación de firma, ID, consulta vía SDK, referencia, monto y moneda. No confía en el cuerpo recibido ni en la URL success para marcar pagos.

Para personalizados bloquea solicitud y pedido, consulta el pago verificado y, únicamente con approved:

1. Guarda payment_status=paid y Payment ID.
2. Cambia quoted -> approved si el pedido no está cancelado.
3. Cambia el pedido pendiente a confirmado.
4. No consulta ni descuenta stock del catálogo.

Notificaciones repetidas con el mismo ID y estado no vuelven a guardar el pedido o actualizar la solicitud, ni devuelven una solicitud en producción a approved. Un rejected deja la solicitud quoted. Los pagos terminales conservan las protecciones previas.

Un pago que llega después de cancelar una solicitud se registra como pago real e incidencia, sin aprobarla ni iniciar producción. No se reembolsa automáticamente. Reembolsos y rechazos administrativos posteriores al pago requieren revisión; no habilitan una nueva producción ni un nuevo cobro.

Flujo principal: pending -> reviewing -> quoted -> approved -> in_production -> ready -> completed. El precio nuevo establece quoted; aceptar conserva quoted hasta confirmar el pago. Solo el webhook produce quoted -> approved. El administrador avanza los estados productivos después de pagar. El cliente puede producir quoted -> cancelled. El administrador puede rechazar según el estado actual, sin reabrir automáticamente estados terminales.

Para 3D, in_production se muestra En impresión; para otros productos, En producción.

## Vistas de Mi Cuenta y Housekeeping

- account/custom-requests/show: precio, despacho y total; Aceptar cotización y pagar solo en quoted con precio válido; Rechazar cotización en quoted.
- components/custom-requests/payment-status: número/enlace de pedido, estado de pago, Payment ID, Cotización pendiente de pago, Pago confirmado y Pagado — listo para producción.
- account/orders/index y show: identificación Personalizado PER-000001 y enlace Ver solicitud personalizada.
- components/orders/items: descripción de la configuración cuando existe.
- housekeeping/custom-requests/show: pedido asociado, pago, Payment ID, enlace y monto de solo lectura después de aceptar.
- housekeeping/custom-requests/index: identifica solicitudes pagadas listas para producción.
- housekeeping/orders/show: enlace de regreso a la solicitud.

Todas las rutas de vista anteriores continúan funcionando. Los diseños siguen privados y las notas internas no aparecen para el cliente.

## Pruebas y validación final

Nuevos tests:

- tests/Feature/CustomQuotePaymentTest.php: cubre las 16 condiciones solicitadas y añade doble envío, reintento ante fallo de API, recuperación de preferencia, monto inmutable, datos de despacho, pago tardío, webhooks inválidos, estados de producción, rechazo de cotización histórica sin precio y reembolsos.
- tests/Feature/CustomQuoteMercadoPagoServiceTest.php: simula el transporte del SDK y comprueba el payload real, la cabecera estable de idempotencia, referencia externa, moneda, despacho y URLs de retorno/webhook.

Las pruebas previas de administración se actualizaron para exigir pago antes de producción y cotización automática al ingresar precio.

| Comprobación | Resultado |
| --- | --- |
| php artisan migrate --force | Migración aditiva aplicada |
| php artisan route:list | 76 rutas registradas |
| php artisan test --compact | **157 pruebas aprobadas; 1.702 aserciones** |
| npm run build | Correcto |
| php artisan view:cache | Correcto |
| ./vendor/bin/pint | Ejecutado |
| ./vendor/bin/pint --dirty | Passed |
| git diff --check | Sin errores de espacios |

No se realizaron llamadas reales a Mercado Pago en tests: se simulan el servicio o el transporte HTTP. SQLite en memoria y storage falso aíslan los datos comerciales. En este PHP Windows se usa el INI temporal con PDO SQLite y APP_URL=http://localhost solo para las pruebas. Se revirtieron cambios de formato ajenos en autenticación, ShoppingCart y CheckoutController.

Los dobles envíos se prueban como solicitudes repetidas. No se realizó carga concurrente contra el servidor ni pago real; la exclusión se implementa con bloqueos, transacciones y restricción única.

## Pasos manuales pendientes

La migración, las pruebas y el build están ejecutados. Access token y webhook secret están presentes; su validez no se comprobó con el proveedor.

La APP_URL actual usa HTTP. Para probar Checkout Pro debe configurarse una URL pública HTTPS válida del sitio o túnel de pruebas, manteniendo /webhooks/mercadopago. Comprobar que credenciales, webhook y firma pertenecen a la misma aplicación/ambiente. No se modificó .env ni se eligió un dominio sin información del propietario.

Después, realizar una prueba sandbox: cotizar, aceptar, pagar, recibir el webhook firmado y verificar paid/approved. No se hizo revisión visual interactiva ni prueba real de pago durante esta implementación.

Sin commits ni push. Abonos, pagos parciales, edición de diseños cotizados, chat, WhatsApp, visor 3D, STEP/STP y reembolsos automáticos permanecen fuera del alcance.
