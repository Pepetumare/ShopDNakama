# Vista previa de personalizados

Composición determinista, sin IA ni transformación del original. Se añadió al formulario existente, con Alpine ya instalado, HTML/CSS y SVG fijo propio para las maquetas. No hay dependencias nuevas, Canvas ni PNG generado.

## Comportamiento

- Poleras blanca/negra v2: frontal/espalda independiente de orientación vertical/horizontal, zona segura alta/estrecha o ancha/baja, presets Pequeño/Mediano/Grande, slider y posiciones rápidas para cada cara. Pecho izquierdo corresponde al lado izquierdo de quien viste la polera (derecha en la maqueta frontal). Después de un ajuste manual, los presets pasan a Manual para que sus etiquetas describan la composición real.
- Tazas v2: editor plano panorámico 3:1 y una segunda simulación sobre taza. Ajustar mantiene todo el diseño proporcional; Rellenar mantiene proporción y admite recorte; Expandir horizontalmente permite controlar X/Y por separado. Incluye arrastre, escala general, centrar, restablecer, ajustar/rellenar área y asa izquierda/derecha. El asa cambia solo la maqueta, sin espejar ni duplicar la configuración.
- Chapitas: máscara circular y medida estándar, sin selector de medida física.
- Stickers: marco proporcional a las dos primeras medidas numéricas del tamaño escrito. Conserva y muestra el texto original; si no hay dos medidas interpretables se usa un marco cuadrado referencial.
- Parches: marco según ancho, alto y forma. Circular/cuadrada usan relación 1:1; rectangular usa ancho/alto. Personalizada/otra usan un contorno rectangular referencial, no inventan un troquel. Las proporciones extremas se limitan visualmente a 1:5–5:1.
- Impresión 3D: no muestra ni acepta composición 2D. Conserva adjuntos, STL/OBJ/3MF, referencias, dimensiones, material y color. La metadata existente `file_type=model_3d` y `extension`, con descarga autorizada, queda disponible para un futuro visor independiente.
- Si hay varios adjuntos se puede elegir qué imagen componer. PDF se conserva como referencia, no se incrusta ni ejecuta en el compositor.

## Persistencia y seguridad

Migración aditiva `2026_10_27_000006_add_preview_data_to_custom_requests.php`: agrega JSON nullable `custom_requests.preview_data`. Solicitudes anteriores y referencias sin imagen siguen funcionando sin preview.

Contrato común: `version`, `side` (`front`/`back`), `x`, `y`, `scale`, `rotation`, `color`, `file_index`. X/Y son el centro normalizado dentro del área imprimible, entre 0 y 1. Escala está entre 0.1 y 1.5. Para v1 y poleras es el ancho de la imagen dividido por el ancho imprimible; para tazas v2 multiplica el ajuste proporcional elegido. Rotación está fijada a 0. El centro de una composición circular permanece a distancia máxima 0.48 del centro del círculo.

Taza v2 añade `product: mug`, `scale_x`, `scale_y` (0.25–3), `fit_mode` (`fit`, `fill`, `stretch`) y `handle_side` (`left`, `right`). Los ejes separados deben ser 1 en Ajustar/Rellenar para impedir deformaciones accidentales. `aspect_ratio` se obtiene del original en backend y se persiste; no se acepta del cliente. Se considera la orientación EXIF de fotografías JPEG de teléfonos, sin cambiar sus bytes ni requerir la extensión EXIF de PHP. Por ejemplo:

```json
{"version":2,"product":"mug","side":"front","x":0.5,"y":0.5,"scale":1,"scale_x":1.35,"scale_y":1,"fit_mode":"stretch","handle_side":"right","rotation":0,"color":"neutral","file_index":0,"aspect_ratio":2}
```

Polera v2 añade `product: shirt`, `orientation` (`vertical`, `horizontal`), `preset` y `size_preset`. Las ubicaciones son `custom`, `left_chest`, `upper_center`, `center`, `large_front`, `upper_back`, `back_center`, `large_back`; se validan según cara. Los tamaños son `custom`, `small`, `medium`, `large`, con escalas 0.35/0.65/1. El servidor valida que las coordenadas/escala coincidan con el preset declarado; los ajustes posteriores se envían como `custom`. Ejemplo:

```json
{"version":2,"product":"shirt","side":"back","orientation":"horizontal","x":0.5,"y":0.5,"scale":1,"preset":"large_back","size_preset":"large","rotation":0,"color":"black","file_index":0}
```

No se requiere una migración adicional: ambos contratos usan el JSON existente. Las solicitudes históricas v1 conservan exactamente su JSON, área y cálculo de escala. En particular, una taza v1 no se convierte a panorámica al abrirla. Solicitudes sin preview continúan funcionando. Solo nuevas tazas/poleras del editor usan v2; chapitas, stickers y parches conservan v1. No se modifican impresión 3D, cotización, pagos ni pedidos.

La simulación cilíndrica divide la misma plantilla en 32 franjas y proyecta sus anchos con una función seno: centro frontal, bordes comprimidos. Usa clipping y sombreado CSS, sin render 3D ni generar otra imagen. La plantilla plana sigue siendo la referencia editable; la simulación no pretende ser una medida física ni un render de producción.

`file_index` identifica el adjunto de la solicitud en orden de ID ascendente. Se valida que exista y sea JPG/PNG/WebP con MIME real y cabecera de imagen válida. El color de polera se deriva además en backend. Solo poleras admiten `back`. Se rechazan claves desconocidas, HTML, estructuras no válidas, campos de otro producto o versión y valores fuera de rango.

El archivo original se guarda sin cambios en el disco privado `custom_orders`; la configuración se guarda en la misma transacción de la solicitud. Los blobs locales del navegador se revocan al reemplazar archivos. La reconstrucción usa la ruta privada existente, protegida por la política propietario/admin, con `nosniff` y caché privada desactivada. No se agregan rutas públicas. No se modificó la visibilidad de `admin_notes`.

## Mi Cuenta y Housekeeping

Ambas vistas usan el mismo componente `preview-stage` y la misma lógica/estilos que el editor. En Mi Cuenta aparece **Tu vista previa** y en Housekeeping **Vista previa del cliente**, sin controles de edición. Taza muestra tipo/variante, modo, asa, escalas horizontal/vertical y general, plantilla y simulación. Polera muestra Blanca/Negra, cara, orientación, tamaño y ubicación con nombres legibles junto a sus porcentajes. Los valores persistidos se usan directamente; no se reaplican presets ni Ajustar/Rellenar al abrir la solicitud. También se muestran medidas de stickers/parches y el aviso referencial solicitado.

## Móvil y accesibilidad

Pointer Events y captura de puntero soportan mouse y touch. El arrastre usa el tamaño actual del área, no píxeles persistidos. Se maneja cancelación y pérdida de captura. `touch-action:none` se limita al área interactiva; la vista guardada permite scroll normal. Hay sliders con etiqueta para X, Y y escala, botones de alinear/centrar/reducir/aumentar/restablecer, foco visible y objetivos de 44px. La escena es responsiva y mantiene su relación de aspecto.

## Archivos de esta mejora v2

- `app/Http/Requests/StoreCustomRequest.php`
- `app/Http/Controllers/CustomRequestController.php`
- `app/Support/PreviewImage.php` (nuevo)
- `resources/js/store.js`
- `resources/js/custom-preview.js`
- `resources/css/custom-preview.css`
- `resources/views/custom-requests/preview-editor.blade.php`
- `resources/views/components/custom-requests/preview-stage.blade.php`
- `resources/views/components/custom-requests/mug-preview.blade.php` (nuevo)
- `resources/views/components/custom-requests/saved-preview.blade.php`
- `tests/Feature/CustomPreviewTest.php`
- `tests/js/custom-preview.test.mjs`
- `docs/personalizados-preview.md`

Las vistas existentes de Mi Cuenta y Housekeeping reciben la mejora mediante el componente compartido, sin cambios en sus flujos.

## Pruebas

`CustomPreviewTest`: mantiene la cobertura previa de privacidad, original intacto y flujo 3D; añade escalas, modos y asas de taza, ratio de imagen y EXIF, validación contextual y de presets, orientación independiente de cara, todos los presets/tamaños, reconstrucción histórica y etiquetas administrativas nuevas.

`node --test tests/js/custom-preview.test.mjs`: mantiene pruebas anteriores y añade ajuste/relleno sin deformación con distintas proporciones, expansión independiente, arrastre panorámico mouse/touch en tamaños 270/630, compresión cilíndrica, asa sin alterar el diseño, orientación/presets ajustables y reconstrucción idéntica v1/v2. Son pruebas de lógica; no sustituyen una revisión visual en un dispositivo físico.

Para PHP, el entorno local requiere habilitar `pdo_sqlite` para las pruebas SQLite en memoria. Se usa `PHP_INI_SCAN_DIR` apuntando a un ini temporal, sin modificar la configuración global. Las verificaciones se realizan sobre la base en memoria, sin borrar datos de aplicación. No se ejecutan migraciones contra las solicitudes existentes.

Verificación final: **180 pruebas PHP / 2116 aserciones**, incluyendo **13 pruebas del compositor / 260 aserciones**, y **9 pruebas JavaScript** aprobadas. `npm run build`, `./vendor/bin/pint` y `git diff --check` terminan correctamente. No se hizo commit ni push.
