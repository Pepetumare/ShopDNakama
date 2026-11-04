# Diseño asistido opcional

Servicio de adaptación manual por el equipo de Shop D. Nakama, por **$500 CLP por solicitud/lote**, independiente de la cantidad de unidades. Mantiene el original privado y la vista previa del cliente; no genera ni transforma imágenes automáticamente.

## Persistencia y migración

Migración aditiva `2026_10_27_000007_add_design_assistance_to_custom_requests.php`:

| Campo | Tipo y valor inicial | Uso |
| --- | --- | --- |
| `design_assistance` | boolean, false | Consentimiento del cliente |
| `design_assistance_notes` | text, nullable | Indicaciones específicas para adaptación |
| `design_assistance_fee` | unsigned integer, 0 | Recargo acordado, fijado al crear la solicitud |
| `base_quoted_price` | decimal(12,2), nullable | Precio del personalizado antes del servicio |

`quoted_price` conserva su significado de **precio final del lote**, incluido el servicio y sin despacho. Las solicitudes anteriores reciben false/0/null sin cambiar precios, estados, archivos, previews ni pedidos. Cuando la base histórica es null se muestra el `quoted_price` existente como base, ya que no había recargo.

Se ejecutó `php artisan migrate --force` porque la aplicación local está configurada como production. Se aplicaron la migración nueva y la migración aditiva de preview `000006`, que estaba pendiente. `migrate:status` confirma ambas aplicadas. No se ejecutó un borrado/reinicio de datos ni rollback.

## Configuración y validación

`config/custom_orders.php` define:

- `design_assistance_price => 500`, sin tomar precios del navegador.
- `design_assistance_products => ['mug', 'shirt_white', 'shirt_black', 'badges', 'stickers', 'patch']`.

`CustomRequest::supportsDesignAssistance()` centraliza la disponibilidad; 3D se excluye aunque se agregue por error a la configuración. El frontend recibe esa lista del servidor y el backend valida nuevamente.

Al enviar la solicitud:

- Activo: notas obligatorias, texto plano, entre 10 y 2000 caracteres. El servidor asigna el recargo configurado.
- Inactivo/omitido: fee 0 y notas null, incluso si se mandan notas o un fee falsificado.
- `design_assistance_fee`, precios cotizados y campos administrativos enviados por el cliente no forman parte de los campos aceptados.
- Se rechaza HTML en las notas; Blade escapa su contenido y Alpine usa `x-text`. CSRF, autorización y acceso privado a originales siguen funcionando.

El fee se guarda como snapshot: cambios futuros de configuración no alteran solicitudes ya creadas.

## Formulario y modal

Junto al preview se ofrece la opción con +$500 y su explicación. Activarla abre inmediatamente un `<dialog>` nativo, con título, ejemplos, textarea, Cancelar y Guardar indicaciones. El diálogo aporta foco contenido, cierre con Escape y soporte táctil; sus botones y checkbox tienen tamaños cómodos en móvil.

Cancelar, Escape o cambiar producto desactiva el servicio. Desactivar conserva temporalmente el borrador en memoria para poder reutilizarlo, pero el checkbox no se envía y las notas quedan deshabilitadas para el POST. Guardar exige texto válido y conserva el servicio activo. Se pueden editar las indicaciones antes de enviar. 3D no monta los controles del servicio. El preview sigue funcionando y se agrega el aviso solicitado sobre los ajustes posteriores del equipo.

## Cotización y pedido

Para solicitudes asistidas, Housekeeping recibe **base_quoted_price** y muestra un cálculo orientativo de base + fee. `ManageCustomQuote` calcula el total definitivo en centavos en backend:

`quoted_price = base_quoted_price + design_assistance_fee`

No suma el fee al total previo. Reenviar la misma base o guardar notas administrativas no duplica el recargo. El campo histórico `quoted_price` sigue funcionando para solicitudes sin servicio; para asistidas se exige la entrada explícita de base. Se mantienen los límites del monto, transiciones de estado y bloqueo de cambios de precio después de aceptar.

Al aceptar, `CustomQuoteCheckout` comprueba la coherencia entre base, fee y total. El subtotal del pedido es el total cotizado ya calculado, y el despacho se agrega una vez. Los items son un lote de personalizado por su base y una línea de Diseño asistido por $500, con cantidad 1. Reintentar aceptación reutiliza pedido/preferencia/items; no agrega otro servicio. MercadoPagoService conserva el flujo existente y genera su payload desde estos items y el despacho del servidor.

Ejemplo verificado: base $10.000 + diseño $500 + despacho $3.990 = **$14.490**, incluso si el POST de aceptación falsifica total, precio, servicio o recargo.

## Vistas

- Mi Cuenta: Diseño asistido Sí/No, recargo e indicaciones; cotización desglosada en base, servicio, despacho y total a pagar.
- Housekeeping: bloque DISEÑO ASISTIDO con estado Solicitado, recargo y texto completo; conserva el acceso al original y al preview del cliente. Base editable, recargo explícito y total, sin exponer notas internas al cliente.
- Pedido: línea separada de servicio, incluida en el subtotal y total. No se modifica el original ni se intenta preparar automáticamente el diseño final.

## Archivos de esta implementación

- `database/migrations/2026_10_27_000007_add_design_assistance_to_custom_requests.php`
- `config/custom_orders.php`
- `app/Models/CustomRequest.php`
- `app/Http/Requests/StoreCustomRequest.php`
- `app/Http/Requests/Housekeeping/UpdateCustomRequest.php`
- `app/Http/Controllers/CustomRequestController.php`
- `app/Services/ManageCustomQuote.php`
- `app/Services/CustomQuoteCheckout.php`
- `resources/js/design-assistance.js`
- `resources/js/store.js`
- `resources/css/custom-preview.css`
- `resources/views/custom-requests/create.blade.php`
- `resources/views/custom-requests/design-assistance.blade.php`
- `resources/views/components/custom-requests/design-assistance.blade.php`
- `resources/views/components/custom-requests/quote-breakdown.blade.php`
- `resources/views/account/custom-requests/show.blade.php`
- `resources/views/housekeeping/custom-requests/show.blade.php`
- `tests/Feature/DesignAssistanceTest.php`
- `tests/js/design-assistance.test.mjs`
- `docs/personalizados-diseno-asistido.md`

## Verificación

- Suite PHP completa: **188 tests / 2278 aserciones**.
- Diseño asistido: **8 tests PHP / 162 aserciones**, incluyendo productos admitidos, validación, manipulación del fee, privacidad y escape, cotización sin duplicación, pedido y payload real del SDK de Mercado Pago con transporte simulado y sin red, idempotencia y compatibilidad histórica.
- JavaScript: **13 tests**, incluidos 4 nuevos de activación/modal, cancelación, validación y exclusión 3D. Son pruebas de lógica, sin una revisión visual en dispositivo físico.
- `npm run build`, `./vendor/bin/pint`, `git diff --check`: correctos.
- Tests PHP ejecutados con SQLite en memoria y `pdo_sqlite` habilitado mediante ini temporal; sin cambiar la configuración global ni probar sobre datos de aplicación.

No se hizo commit ni push.
