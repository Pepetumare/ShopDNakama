<template x-if="isPanoramicMug">
    <div class="cp-mug-preview">
        <h4>Vista previa panorámica</h4>
        <p class="cp-wrap-caption">Asa ← Área imprimible envolvente → Asa</p>
        <div class="cp-panorama" role="img" aria-label="Plantilla panorámica envolvente de la taza">
            <div class="cp-area" @pointerdown="startPreviewDrag($event)" @pointermove="dragPreview($event)" @pointerup="endPreviewDrag()" @pointercancel="endPreviewDrag()" @lostpointercapture="endPreviewDrag()">
                <img class="cp-design" :src="previewUrl || null" :style="designStyle" @load="recordImageDimensions($event)" alt="Diseño original en la plantilla panorámica" draggable="false">
            </div>
        </div>
        <p>La plantilla muestra el diseño completo alrededor de la taza; sus extremos quedan próximos al asa.</p>
        <h4>Vista previa sobre taza</h4>
        <div class="cp-stage cp-cylinder-stage" :class="'cp-handle-' + preview.handle_side" role="img" :aria-label="'Simulación cilíndrica, asa a la ' + (preview.handle_side === 'left' ? 'izquierda' : 'derecha')">
            <svg class="cp-cylinder-art" viewBox="0 0 400 400" aria-hidden="true"><path d="M286 110H314C380 110 380 252 314 252H286V226H312C346 226 346 137 312 137H286Z"/><path d="M65 85H292V302Q178 347 65 302Z"/><ellipse cx="178" cy="85" rx="113" ry="17"/></svg>
            <div class="cp-cylinder-print" aria-hidden="true">
                <template x-for="slice in cylinderSlices" :key="slice.index">
                    <div class="cp-cylinder-slice" :style="slice.style"><div class="cp-cylinder-plane" :style="slice.inner"><img class="cp-design" :src="previewUrl || null" :style="designStyle" alt="" draggable="false"></div></div>
                </template>
                <div class="cp-cylinder-shading"></div>
            </div>
        </div>
    </div>
</template>
