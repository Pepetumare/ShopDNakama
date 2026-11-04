<section x-cloak x-show="hasPreview" class="cp-editor" aria-label="Vista previa interactiva">
    <h3>Vista previa interactiva</h3>
    <p>Mueve el diseño con el dedo o el mouse. La línea punteada indica la zona segura de impresión.</p>
    <label>Imagen para la vista previa<select x-model.number="preview.file_index" @change="previewUrl = modelAttachments[preview.file_index]?.url || ''; resetPreview()"><template x-for="(file, index) in modelAttachments" :key="index"><option :value="index" :disabled="!file.url" x-text="file.name"></option></template></select></label>
    <template x-if="isShirt"><label>Cara<select x-model="preview.side"><option value="front">Frontal</option><option value="back">Espalda</option></select></label></template>
    <template x-if="isOrientedShirt"><div class="cp-controls">
        <label>Orientación del estampado<select x-model="preview.orientation" @change="markManual()"><option value="vertical">Vertical</option><option value="horizontal">Horizontal</option></select></label>
        <p>Tamaño del estampado: <span x-text="({ small: 'Pequeño', medium: 'Mediano', large: 'Grande', custom: 'Manual' })[preview.size_preset]"></span></p>
        <div class="cp-buttons"><button type="button" @click="setSizePreset('small')">Pequeño</button><button type="button" @click="setSizePreset('medium')">Mediano</button><button type="button" @click="setSizePreset('large')">Grande</button></div>
        <div class="cp-buttons" x-show="preview.side === 'front'"><button type="button" @click="setShirtPreset('left_chest')">Pecho izquierdo</button><button type="button" @click="setShirtPreset('upper_center')">Centro superior</button><button type="button" @click="setShirtPreset('center')">Centro</button><button type="button" @click="setShirtPreset('large_front')">Grande frontal</button></div>
        <div class="cp-buttons" x-show="preview.side === 'back'"><button type="button" @click="setShirtPreset('upper_back')">Superior</button><button type="button" @click="setShirtPreset('back_center')">Centro</button><button type="button" @click="setShirtPreset('large_back')">Grande espalda</button></div>
    </div></template>
    <template x-if="isPanoramicMug"><div class="cp-controls">
        <label>Modo de ajuste<select :value="preview.fit_mode" @change="setFitMode($event.target.value)"><option value="fit">Ajustar</option><option value="fill">Rellenar</option><option value="stretch">Expandir horizontalmente</option></select></label>
        <p>Ajustar conserva la proporción. Rellenar conserva la proporción y puede recortar. Expandir permite estirar deliberadamente el diseño.</p>
        <label>Orientación de la taza<select x-model="preview.handle_side"><option value="right">Asa a la derecha</option><option value="left">Asa a la izquierda</option></select></label>
        <div class="cp-buttons"><button type="button" @click="setFitMode('fit')">Ajustar al área</button><button type="button" @click="setFitMode('fill')">Rellenar área</button></div>
        <div x-show="preview.fit_mode === 'stretch'">
            <label>Escala horizontal<input type="range" min="0.25" max="3" step="0.01" x-model.number="preview.scale_x"><output x-text="Math.round(preview.scale_x * 100) + '%'"></output></label>
            <label>Escala vertical<input type="range" min="0.25" max="3" step="0.01" x-model.number="preview.scale_y"><output x-text="Math.round(preview.scale_y * 100) + '%'"></output></label>
        </div>
    </div></template>
    <x-custom-requests.preview-stage/>
    <p x-show="product === 'badges'">Medida estándar</p>
    <p x-show="product === 'stickers'">Dimensiones seleccionadas: <span x-text="previewDimensions || 'Completa el tamaño aproximado'"></span></p>
    <p x-show="product === 'patch'">Ancho × alto: <span x-text="previewWidth + ' × ' + previewHeight + ' cm'"></span>. Las formas personalizada y otra se representan con un contorno referencial.</p>
    <div class="cp-controls">
        <label>Tamaño del diseño<input type="range" min="0.1" max="1.5" step="0.01" x-model.number="preview.scale" @input="markManual()"><output x-text="Math.round(preview.scale * 100) + '%'"></output></label>
        <label>Posición horizontal<input type="range" min="0" max="1" step="0.01" x-model.number="preview.x" @input="markManual(); $nextTick(() => constrainPreview())"></label>
        <label>Posición vertical<input type="range" min="0" max="1" step="0.01" x-model.number="preview.y" @input="markManual(); $nextTick(() => constrainPreview())"></label>
        <div class="cp-buttons"><button type="button" @click="preview.x = 0.25; markManual(); constrainPreview()">Izquierda</button><button type="button" @click="preview.x = 0.5; markManual(); constrainPreview()">Centro</button><button type="button" @click="preview.x = 0.75; markManual(); constrainPreview()">Derecha</button></div>
        <div class="cp-buttons"><button type="button" @click="preview.x = 0.5; preview.y = 0.5; markManual()">Centrar</button><button type="button" @click="scalePreview(-0.05)">Reducir</button><button type="button" @click="scalePreview(0.05)">Aumentar</button><button type="button" @click="resetPreview()">Restablecer</button></div>
    </div>
    <template x-if="hasPreview"><div>@foreach(['version', 'side', 'x', 'y', 'scale', 'rotation', 'color', 'file_index'] as $field)<input type="hidden" name="preview_data[{{ $field }}]" :value="preview.{{ $field }}">@endforeach</div></template>
    <template x-if="hasPreview && preview.version === 2"><input type="hidden" name="preview_data[product]" :value="preview.product"></template>
    <template x-if="hasPreview && isPanoramicMug"><div>@foreach(['scale_x', 'scale_y', 'fit_mode', 'handle_side'] as $field)<input type="hidden" name="preview_data[{{ $field }}]" :value="preview.{{ $field }}">@endforeach</div></template>
    <template x-if="hasPreview && isOrientedShirt"><div>@foreach(['orientation', 'preset', 'size_preset'] as $field)<input type="hidden" name="preview_data[{{ $field }}]" :value="preview.{{ $field }}">@endforeach</div></template>
    <p>La vista previa es referencial. La posición y proporción final pueden ajustarse ligeramente durante la producción.</p>
</section>
<p x-show="product && product !== '3d_print' && modelAttachments.length && !hasPreview">Para componer una vista previa, selecciona una imagen JPG, PNG o WebP. Los PDF se conservan como referencias.</p>
