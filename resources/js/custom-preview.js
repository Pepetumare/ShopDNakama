// Version 1: normalized center and width relative to the printable area.
export default (options = {}) => ({
    preview: { version: 1, side: 'front', x: 0.5, y: 0.5, scale: 0.65, rotation: 0, color: 'neutral', file_index: 0, ...options.data },
    previewUrl: options.url || '',
    previewType: options.type || '',
    previewShape: options.shape || '',
    previewWidth: options.width || '', previewHeight: options.height || '',
    previewDimensions: options.dimensions || '',
    previewDrag: null,
    imageRatio: options.data?.aspect_ratio || 1,
    get compositionType() { return options.readonly ? this.previewType : this.product; },
    get isPanoramicMug() { return this.preview.version === 2 && this.compositionType === 'mug'; },
    get isOrientedShirt() { return this.preview.version === 2 && this.compositionType.startsWith('shirt_'); },
    get hasPreview() { return this.compositionType && this.compositionType !== '3d_print' && !!this.previewUrl; },
    get previewClass() {
        const classes = ['cp-stage', `cp-${this.compositionType}`, `cp-side-${this.preview.side}`, `cp-shape-${this.compositionType === 'patch' ? this.previewShape : ''}`];
        if (this.isOrientedShirt) classes.push(`cp-orientation-${this.preview.orientation}`);
        return classes.join(' ');
    },
    get areaStyle() {
        if (!['patch', 'stickers'].includes(this.compositionType)) return '';
        const dimensions = this.previewDimensions.replaceAll(',', '.').match(/\d+(?:\.\d+)?/g) || [];
        const rawRatio = this.compositionType === 'stickers' ? Number(dimensions[0]) / Number(dimensions[1]) : Number(this.previewWidth) / Number(this.previewHeight);
        const ratio = this.compositionType === 'patch' && ['circular', 'square'].includes(this.previewShape) ? 1 : Math.max(0.2, Math.min(5, rawRatio || 1));
        return `width:${Math.min(72, 72 * ratio)}%;height:${Math.min(72, 72 / ratio)}%`;
    },
    get designStyle() {
        if (this.isPanoramicMug) {
            // The flat print template is 3:1. Fit/fill retain the source aspect ratio.
            const ratio = this.imageRatio || 1;
            const base = this.preview.fit_mode === 'fill' ? Math.max(1, ratio / 3) : Math.min(1, ratio / 3);
            const expanded = this.preview.fit_mode === 'stretch';
            const width = base * this.preview.scale * (expanded ? this.preview.scale_x : 1);
            const height = base * 3 / ratio * this.preview.scale * (expanded ? this.preview.scale_y : 1);
            return `left:${this.preview.x * 100}%;top:${this.preview.y * 100}%;width:${width * 100}%;--cp-design-height:${height * 100}%;height:${height * 100}%;transform:translate(-50%,-50%)`;
        }
        return `left:${this.preview.x * 100}%;top:${this.preview.y * 100}%;width:${this.preview.scale * 100}%;transform:translate(-50%,-50%)`;
    },
    recordImageDimensions(event) {
        // Stored v2 requests use the ratio validated from the original on the server.
        if (!options.readonly && event.target.naturalWidth && event.target.naturalHeight) this.imageRatio = event.target.naturalWidth / event.target.naturalHeight;
    },
    get cylinderSlices() {
        // Map a flat panorama onto a half-cylinder; narrow edge strips compress the artwork.
        return Array.from({ length: 32 }, (_, index) => {
            const left = (Math.sin(-Math.PI / 2 + index / 32 * Math.PI) + 1) / 2 * 100;
            const right = (Math.sin(-Math.PI / 2 + (index + 1) / 32 * Math.PI) + 1) / 2 * 100;
            return { index, style: `left:${left}%;width:${right - left + 0.02}%`, inner: `width:3200%;left:${-index * 100}%` };
        });
    },
    setFitMode(mode) {
        this.preview.fit_mode = mode; this.preview.scale = 1;
        this.preview.scale_x = mode === 'stretch' ? 1.5 : 1; this.preview.scale_y = 1;
        this.preview.x = 0.5; this.preview.y = 0.5;
    },
    markManual() {
        if (this.isOrientedShirt) { this.preview.preset = 'custom'; this.preview.size_preset = 'custom'; }
    },
    setSizePreset(size) {
        this.preview.scale = { small: 0.35, medium: 0.65, large: 1 }[size];
        this.preview.size_preset = size; this.preview.preset = 'custom';
    },
    setShirtPreset(preset) {
        const presets = {
            left_chest: [0.75, 0.23, 0.35, 'small'], upper_center: [0.5, 0.25, 0.65, 'medium'],
            center: [0.5, 0.5, 0.65, 'medium'], large_front: [0.5, 0.5, 1, 'large'],
            upper_back: [0.5, 0.25, 0.65, 'medium'], back_center: [0.5, 0.5, 0.65, 'medium'], large_back: [0.5, 0.5, 1, 'large'],
        };
        const values = presets[preset];
        if (!values) return;
        [this.preview.x, this.preview.y, this.preview.scale, this.preview.size_preset] = values;
        this.preview.preset = preset;
    },
    syncPreviewColor() {
        this.preview.color = this.compositionType === 'shirt_black' ? 'black' : this.compositionType === 'shirt_white' ? 'white' : 'neutral';
    },
    resetPreview() {
        this.preview.x = 0.5; this.preview.y = 0.5; this.preview.scale = 0.65; this.preview.rotation = 0;
        if (!options.readonly) {
            const mug = this.compositionType === 'mug', shirt = this.compositionType?.startsWith('shirt_');
            const common = { version: mug || shirt ? 2 : 1, side: this.preview.side, x: 0.5, y: 0.5, scale: mug ? 1 : 0.65, rotation: 0, color: this.preview.color, file_index: this.preview.file_index };
            this.preview = mug ? { ...common, product: 'mug', scale_x: 1, scale_y: 1, fit_mode: 'fit', handle_side: 'right' }
                : shirt ? { ...common, product: 'shirt', orientation: 'vertical', preset: common.side === 'back' ? 'back_center' : 'center', size_preset: 'medium' } : common;
        }
    },
    movePreview(dx, dy) {
        this.markManual();
        this.preview.x = Math.max(0, Math.min(1, Number(this.preview.x) + dx));
        this.preview.y = Math.max(0, Math.min(1, Number(this.preview.y) + dy));
        this.constrainPreview();
    },
    constrainPreview() {
        if (this.compositionType !== 'badges' && !(this.compositionType === 'patch' && this.previewShape === 'circular')) return;
        const dx = this.preview.x - 0.5, dy = this.preview.y - 0.5;
        const distance = Math.hypot(dx, dy);
        if (distance > 0.48) {
            this.preview.x = 0.5 + dx / distance * 0.48;
            this.preview.y = 0.5 + dy / distance * 0.48;
        }
    },
    scalePreview(delta) { this.preview.scale = Math.max(0.1, Math.min(1.5, Number(this.preview.scale) + delta)); this.markManual(); },
    startPreviewDrag(event) {
        if (options.readonly || !this.hasPreview || (event.pointerType === 'mouse' && event.button !== 0)) return;
        const rect = event.currentTarget.getBoundingClientRect();
        this.markManual();
        this.previewDrag = { id: event.pointerId, rect, x: event.clientX, y: event.clientY, px: Number(this.preview.x), py: Number(this.preview.y) };
        event.currentTarget.setPointerCapture(event.pointerId);
    },
    dragPreview(event) {
        const drag = this.previewDrag;
        if (!drag || drag.id !== event.pointerId) return;
        this.preview.x = Math.max(0, Math.min(1, drag.px + (event.clientX - drag.x) / drag.rect.width));
        this.preview.y = Math.max(0, Math.min(1, drag.py + (event.clientY - drag.y) / drag.rect.height));
        this.constrainPreview();
    },
    endPreviewDrag() { this.previewDrag = null; },
});
