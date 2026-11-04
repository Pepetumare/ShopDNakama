@props(['version' => null])
<div x-show="!isPanoramicMug" :class="previewClass" role="img" :aria-label="'Maqueta referencial · ' + (preview.side === 'back' ? 'Espalda' : 'Frontal')">
    <svg class="cp-shirt" viewBox="0 0 400 400" aria-hidden="true">
        <path d="M140 36 L94 54 L30 129 L89 173 L112 144 L112 366 L288 366 L288 144 L311 173 L370 129 L306 54 L260 36 Q200 63 140 36Z"/>
        <path class="cp-neck" :d="preview.side === 'front' ? 'M140 36 Q200 130 260 36' : 'M140 36 Q200 73 260 36'"/>
        <path class="cp-seam" d="M118 351H282 M42 127L86 158 M314 158L358 127"/>
    </svg>
    <svg class="cp-mug-art" viewBox="0 0 400 400" aria-hidden="true"><path d="M286 110H314C380 110 380 252 314 252H286V226H312C346 226 346 137 312 137H286Z"/><path d="M65 85H292V302Q178 347 65 302Z"/><ellipse cx="178" cy="85" rx="113" ry="17"/></svg>
    <div class="cp-area" :style="areaStyle" @pointerdown="startPreviewDrag($event)" @pointermove="dragPreview($event)" @pointerup="endPreviewDrag()" @pointercancel="endPreviewDrag()" @lostpointercapture="endPreviewDrag()">
        <img class="cp-design" :src="previewUrl || null" :style="designStyle" alt="Diseño original del cliente aplicado al producto" draggable="false">
    </div>
    <span class="cp-face" x-show="compositionType.startsWith('shirt_')" x-text="preview.side === 'back' ? 'Espalda' : 'Frontal'"></span>
</div>
@if($version !== 1)<x-custom-requests.mug-preview/>@endif
