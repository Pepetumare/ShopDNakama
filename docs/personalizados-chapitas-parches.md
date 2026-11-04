# Ajuste funcional: chapitas y parches

## Comportamiento

La página mantiene sus layouts y estilos. Las tarjetas son Taza, Polera blanca, Polera negra, Chapitas, Stickers, Parche personalizado e Impresión 3D.

Las chapitas nuevas no solicitan tamaño, dimensiones ni unidad. El formulario muestra Producto: Chapita personalizada y Medida: Estándar como texto fijo. El servidor ignora cualquier tamaño/dimensión enviado manualmente. Solo se necesitan cantidad y archivo; las instrucciones son opcionales. Mi Cuenta y Housekeeping muestran Medida: Estándar.

Los parches nuevos usan product_type=patch. Solicitan ancho y alto positivos, forma Circular/Cuadrado/Rectangular/Personalizada/Otra y diseño. La unidad se fija a cm en el servidor. Color de borde e instrucciones son opcionales. Se reutilizan los archivos privados JPG/JPEG/PNG/WebP/PDF, límites, previews, permisos y cotizaciones existentes. Los modelos 3D no se admiten para parches.

## Datos y compatibilidad

Migración aditiva aplicada: `database/migrations/2026_10_27_000005_add_patch_options_to_custom_requests.php`. Solo añade shape y border_color nullable. Se reutilizan width, height y dimension_unit. No actualiza ni borra filas existentes.

- Los tamaños históricos de chapitas permanecen guardados en size; no se muestran como una opción vigente ni se sobrescriben al consultar.
- Se conserva el valor histórico prints. El accessor product_label lo presenta como Parche personalizado; is_patch reconoce prints y patch.
- Los nuevos formularios solo crean patch. Un envío nuevo con prints es rechazado para evitar nuevas solicitudes con la configuración anterior.
- El filtro Parches incluye patch y prints; los enlaces antiguos con product_type=prints se normalizan al filtro nuevo.
- Las solicitudes antiguas sin medidas/forma muestran No registrado/No registrada. Su variante y ubicación originales siguen visibles como configuración registrada, sin inventar valores ni cambiar sus columnas.
- Las descripciones ya guardadas en pedidos no se reescriben. Únicamente se sustituye al renderizar el prefijo de etiqueta generado anteriormente, conservando el resto del snapshot y texto del cliente.
- Los pedidos nuevos de parches incluyen ancho, alto, unidad, forma y color de borde en su descripción. Los nuevos pedidos asociados a chapitas indican Medida: Estándar.

No se cambia la integración de pago ni el flujo de estados. No se modifican productos normales. Las referencias antiguas que bloquean categorías de servicios del catálogo físico se conservan como compatibilidad, no como opciones públicas del módulo.

## Archivos modificados en este ajuste

| Archivo | Cambio |
| --- | --- |
| app/Models/CustomRequest.php | Etiquetas, tipos vigentes, alias histórico, formas de parche y etiqueta visual de snapshots antiguos. |
| app/Http/Requests/StoreCustomRequest.php | Sin tamaño de chapitas; validación de medidas/forma/borde de parches; unidad cm fija; instrucciones opcionales. |
| app/Http/Requests/Housekeeping/CustomRequestFilter.php | Normaliza filtros históricos. |
| app/Http/Controllers/Housekeeping/CustomRequestController.php | Filtro conjunto de parches nuevos e históricos. |
| app/Services/CustomQuoteCheckout.php | Snapshot de opciones de parches y medida estándar de chapitas en pedidos nuevos. |
| config/custom_orders.php | Elimina la configuración ampliable de tamaños de chapitas. |
| resources/views/custom-requests/create.blade.php | Texto fijo para chapitas, formulario de parches y textos neutrales para ubicación del diseño en poleras. |
| resources/views/custom-requests/patch-fields.blade.php | Nuevo parcial con ancho, alto, unidad fija, forma y borde opcional. |
| resources/views/components/custom-requests/details.blade.php | Campos por producto en Mi Cuenta y Housekeeping. |
| resources/views/components/custom-requests/product-art.blade.php | Reutiliza la ilustración existente para patch. |
| resources/views/components/orders/items.blade.php | Etiqueta visual compatible en descripciones históricas, sin reescribir pedidos. |
| resources/css/store.css | Renombra el selector de la tarjeta para conservar su aspecto con el valor patch. |
| database/migrations/2026_10_27_000005_add_patch_options_to_custom_requests.php | Añade solo shape y border_color. |
| tests/Feature/CustomRequestsTest.php | Actualiza casos de validación de productos. |
| tests/Feature/CustomRequestsCompletionTest.php | Ajusta creación de chapitas y parches con adjuntos múltiples. |
| tests/Feature/BadgesAndPatchesTest.php | Diez pruebas de los requisitos nuevos y compatibilidad histórica. |
| docs/personalizados.md | Enlaza este informe y actualiza las opciones vigentes. |
| docs/personalizados-chapitas-parches.md | Este informe. |

## Validación

Las pruebas focalizadas aprobaron 37 tests con 574 aserciones. Cubren formulario sin tamaño, medida estándar, manipulación de campos excluidos, guardado privado de diseño, ancho/alto, todas las formas, PDF, campos opcionales, validación de medidas, bloqueo de modelos, Housekeeping, filtros y solicitudes/pedidos históricos.

Resultado final de la suite completa: **167 tests aprobados y 1.856 aserciones**. npm run build y compilación Blade correctos; Pint ejecutado y verificación posterior de archivos modificados Passed; git diff --check sin errores de espacios.

Migración aditiva aplicada. Se ejecutaron npm run build, compilación Blade, ./vendor/bin/pint y git diff --check. Los cambios de formato ajenos al módulo propuestos por Pint se revirtieron; no se modificaron CheckoutController, ShoppingCart ni autenticación.

Tests con SQLite en memoria y storage falso; el servicio de Mercado Pago se simula. No se realizaron pagos ni llamadas reales. El PHP de pruebas usa el INI temporal con PDO SQLite y APP_URL=http://localhost, sin cambiar php.ini ni .env.

Sin migrate:fresh contra la tienda, borrado de datos, commit ni push. No hay pasos funcionales manuales pendientes para este ajuste; no se realizó revisión visual interactiva en navegador.
