# Informe final: Personalizados e Impresión 3D

## Resultado

Ajuste vigente de productos: [chapitas de medida estándar y parches personalizados](personalizados-chapitas-parches.md). Sustituye las opciones y etiquetas anteriores sin modificar registros históricos.

Actualización: la aceptación, generación de pedidos y pago con Mercado Pago ya están implementados. Consulta [personalizados-pagos.md](personalizados-pagos.md). El resto de este documento conserva el informe de la fase base anterior.

Flujo implementado: cliente autenticado envía una solicitud; el administrador revisa los archivos, actualiza el estado y fija una cotización; el cliente ve su estado y precio desde Mi Cuenta. La página de selección es pública.

Se reutilizan los layouts, autenticación, roles, componentes y colores existentes. No se integran solicitudes al carrito, pagos, Mercado Pago ni stock. No se hicieron commits ni push.

## Migraciones aditivas

| Archivo en database/migrations | Función |
| --- | --- |
| 2026_10_27_000001_create_custom_requests_tables.php | Crea custom_requests y custom_request_files, con datos de contacto históricos, opciones de producto, estado, cotización y notas internas. |
| 2026_10_27_000002_add_3d_printing_to_custom_requests.php | Añade proyecto, tipo de pieza, material, dimensiones, uso, descripción y referencias a la misma tabla; añade extension y file_type a archivos y completa metadata anterior. |
| 2026_10_27_000003_create_custom_request_sequences_table.php | Añade una secuencia independiente para números legibles PER-000001. Conserva números anteriores y reserva cualquier rango numérico existente. |

Las tres migraciones están aplicadas. Esta iteración aplicó la tercera; las dos primeras ya estaban aplicadas. Se usó `php artisan migrate --force` porque la configuración del entorno solicita confirmación de producción. No se ejecutó migrate:fresh contra la base de la tienda ni se eliminaron productos, pedidos o usuarios.

## Modelos y relaciones

- `app/Models/CustomRequest.php`: pertenece a User y tiene muchos CustomRequestFile. Centraliza los siete productos, nueve estados y opciones de poleras y piezas 3D. Genera request_number con una secuencia de base de datos independiente del ID de solicitud, segura frente a asignaciones simultáneas.
- `app/Models/CustomRequestFile.php`: pertenece a CustomRequest. Conserva nombre original, ruta privada, MIME detectado, extensión validada, tamaño y clasificación reference/model_3d. Oculta stored_path en serialización y distingue imágenes de modelos.
- `app/Models/User.php`: incorpora hasMany customRequests.
- Si se elimina una cuenta, la solicitud conserva el contacto histórico y su user_id queda nulo. Solo administración conserva acceso.

## Controladores y Form Requests

- `app/Http/Controllers/CustomRequestController.php`: página pública, retorno desde login, creación autenticada, historial propio, detalle y archivos privados. Guarda todos los adjuntos y metadata dentro del flujo de una transacción; si falla, revierte los registros y elimina los archivos de ese envío.
- `app/Http/Controllers/Housekeeping/CustomRequestController.php`: listado global paginado con filtros, detalle y actualización administrativa.
- `app/Http/Requests/StoreCustomRequest.php`: toma nombre y correo directamente de la cuenta autenticada, ignorando suplantaciones del formulario. Valida configuración según producto, teléfono opcional, cantidad y todos los adjuntos. Mantiene compatibilidad con el antiguo campo design de los productos normales y convierte las ubicaciones antiguas Frontal/Espalda en Delantera/Trasera.
- `app/Http/Requests/Housekeeping/CustomRequestFilter.php`: autoriza administradores y valida búsqueda, estado, producto y rango de fechas, incluyendo filtros con solo una fecha.
- `app/Http/Requests/Housekeeping/UpdateCustomRequest.php`: autoriza gestión y valida estado, cotización no negativa con hasta dos decimales y notas internas.
- `app/Rules/CustomOrderFile.php`: validación de contenido y tipos de archivo.

## Autorización

`app/Policies/CustomRequestPolicy.php` se descubre automáticamente por Laravel:

- view: únicamente el propietario dentro de Mi Cuenta.
- viewFile: propietario o administrador.
- manage: administrador.

Se reutilizan middleware auth y admin. El envío tiene throttle de 10 solicitudes por minuto. Los campos administrativos y el propietario no se aceptan desde el formulario público. Las notas internas no se renderizan para el cliente y están ocultas en serialización.

## Rutas

Definidas en `routes/web.php` y `routes/housekeeping.php`:

| Método | Ruta | Acceso |
| --- | --- | --- |
| GET | /personalizados | Público |
| GET | /personalizados/continuar | Autenticado; login conserva retorno |
| POST | /personalizados | Autenticado |
| GET | /personalizados/archivos/{file} | Propietario o administrador |
| GET | /mi-cuenta/personalizados | Listado propio |
| GET | /mi-cuenta/personalizados/{customRequest} | Propietario |
| GET | /housekeeping/personalizados | Administrador |
| GET | /housekeeping/personalizados/{customRequest} | Administrador |
| PATCH | /housekeeping/personalizados/{customRequest} | Administrador |

`?download=1` fuerza la descarga desde el controlador autorizado. PDF y modelos siempre se envían como adjuntos; solo imágenes admitidas se muestran en línea. No se generan enlaces públicos ni URLs que contengan stored_path.

## Vistas y navegación

- `resources/views/custom-requests/create.blade.php`: siete tarjetas ilustradas y formulario dinámico con adjuntos múltiples para todos los productos. Datos de cuenta de solo lectura, teléfono opcional y comentarios.
- `resources/views/custom-requests/three-d-fields.blade.php`: proyecto, tipo de pieza, ancho/alto/profundidad, mm/cm, color, material opcional, uso, descripción y referencias.
- `resources/views/account/custom-requests/index.blade.php` y `show.blade.php`: número, producto, fecha, cantidad, estado, precio y detalle propio con archivos.
- `resources/views/components/custom-requests/details.blade.php`: configuración y archivos compartidos entre cuenta y administración, sin renderizar notas internas.
- `resources/views/components/custom-requests/product-art.blade.php`: ilustraciones de las tarjetas de la interfaz. No habilita archivos SVG enviados por clientes.
- Navbar público actualizado en `resources/views/components/store/header.blade.php`; enlace Mis personalizados en `resources/views/account/layout.blade.php`.
- Estilos del módulo en store.css y housekeeping.css; previews y cambio de archivos con Alpine en `resources/js/store.js`.

Tazas blanca y mágica habilitadas. Poleras blanca/negra con S a XXL y Delantera/Trasera/Ambos lados. Chapitas de medida estándar; stickers con medidas aproximadas; parches personalizados con ancho, alto en cm, forma y color de borde opcional.

## Housekeeping

- `app/Http/Controllers/HousekeepingController.php`: registra el módulo y su enlace administrativo.
- `resources/views/housekeeping/custom-requests/index.blade.php`: número, cliente y correo, producto, cantidad, fecha, estado, precio cotizado, miniaturas/indicadores y acción Ver. Búsqueda por número/nombre/email; filtros combinables por estado, producto y fechas; paginación conserva filtros.
- `resources/views/housekeeping/custom-requests/show.blade.php`: datos de contacto, configuración, referencias, descargas, cambio de estado, precio y edición de notas internas.
- Los nueve valores internos de estado son comunes. Para 3D, in_production muestra En impresión y ready muestra Listo para entrega en los detalles y listados.

## Almacenamiento y validación de archivos

Se conserva el disco exclusivo `custom_orders` en `config/filesystems.php`, con raíz `storage/app/private/custom-orders` y serve=false, para mantener acceso a los diseños ya recibidos. Ningún archivo del módulo se guarda en public.

- Nombres físicos UUID aleatorios; el nombre original solo es metadata. Extensiones de referencias normalizadas desde el MIME validado; modelos desde la extensión admitida y contenido comprobado.
- Límite inicial: **20 MB por archivo**, tanto referencias como modelos; hasta cinco archivos por solicitud.
- Configuración en `config/custom_orders.php`: reference_max_kb, model_max_kb y max_files. `.env.example` documenta CUSTOM_ORDERS_REFERENCE_MAX_KB=20480 y CUSTOM_ORDERS_MODEL_MAX_KB=20480. Al modificar un entorno con configuración cacheada, regenerar esa caché.
- Referencias: JPG/JPEG/PNG/WebP/PDF; exige extensión y MIME detectado compatibles. Rechaza archivos vacíos, SVG, HTML, JavaScript, PHP y ejecutables.
- Modelos: STL/OBJ/3MF solo para 3D. Acepta application/octet-stream y otros MIME propios o genéricos cuando corresponda; no confía en el MIME del navegador.
- STL: comprobación de longitud del binario o marcadores del formato de texto. OBJ: geometría básica y lista restringida de comandos, sin comandos de shell. 3MF: paquete ZIP con entradas esperadas, rutas seguras, límite de entradas y tamaño expandido, sin ejecutables ni cifrado. No se extraen archivos ni se cargan recursos externos.
- El controlador valida el patrón de la ruta interna, autoriza por la solicitud y no acepta rutas físicas proporcionadas por el cliente.
- Respuestas con nosniff, CSP restrictiva y caché privada sin almacenamiento. Modelos como application/octet-stream con disposición de descarga.

Antes del envío se muestran miniaturas de imágenes, indicador PDF, nombre y tamaño; los modelos muestran Modelo 3D seleccionado. También se pueden cambiar los archivos. No se implementa un visor 3D. file_type y extension permiten incorporar uno después. La validación no evalúa la imprimibilidad de la pieza; esa revisión sigue a cargo del administrador. STEP/STP permanecen deshabilitados.

## Pruebas y comprobaciones

Archivos:

- `tests/Feature/CustomRequestsTest.php`
- `tests/Feature/CustomRequests3dTest.php`
- `tests/Feature/CustomRequestsCompletionTest.php`

Cubren las 16 condiciones solicitadas: autenticación, creación, configuración, imágenes, modelos, rechazo de archivos peligrosos, aislamiento de historial y detalles, descargas propias y ajenas, acceso y descarga administrativos, estados, precio, visibilidad del precio, privacidad de notas y bloqueo de usuarios normales en Housekeeping. También cubren formularios de todos los productos, adjuntos múltiples, numeración independiente, contacto no suplantable, límite de 20 MB, filtros de una sola fecha, ZIP inseguro y limpieza ante fallo parcial.

Resultados finales:

| Comprobación | Resultado |
| --- | --- |
| php artisan migrate --force | Migración pendiente aplicada |
| php artisan route:list | Rutas registradas; rutas existentes conservadas |
| php artisan test --compact | **137 pruebas aprobadas; 1.456 aserciones** |
| Pruebas específicas de Personalizados | 27 aprobadas; 454 aserciones |
| npm run build | Correcto |
| php artisan view:cache | Correcto |
| ./vendor/bin/pint | Ejecutado; formato aplicado al módulo |
| git diff --check | Sin errores de espacios |

Pint también propuso formato en archivos existentes de autenticación, carrito y pagos; esos cambios ajenos al módulo se revirtieron. No hay cambios finales en MercadoPagoService ni CheckoutController.

Las pruebas usan SQLite en memoria y almacenamiento falso. En este Windows se habilitó PDO SQLite mediante PHP_INI_SCAN_DIR apuntando a un INI temporal y se fijó APP_URL=http://localhost exclusivamente en el proceso de pruebas. No se modificaron php.ini ni .env ni se usó la base de datos comercial para las pruebas.

## Pasos manuales pendientes

No hay migraciones, builds ni pasos funcionales pendientes en esta instalación. No se realizó revisión visual interactiva en navegador.

Para repetir pruebas desde otra terminal en esta instalación, habilitar PDO SQLite en ese proceso y usar APP_URL sin subdirectorio. En otro servidor, comprobar permisos de storage y límites HTTP/PHP: al menos 20 MB por archivo y más de 100 MB por solicitud si se desea admitir cinco archivos máximos simultáneamente. El PHP local permite los tamaños configurados.

No se hicieron commits ni push. Aceptación de cotizaciones, pago y visor 3D quedan para fases posteriores.
