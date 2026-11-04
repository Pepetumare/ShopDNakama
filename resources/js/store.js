import Alpine from 'alpinejs';
import customPreview from './custom-preview';
import designAssistance from './design-assistance';
import '../css/custom-preview.css';
Alpine.data('customPreview', customPreview);
Alpine.data('designAssistance', designAssistance);

window.Alpine = Alpine;
Alpine.data('customOrderForm', (product, variant, size, position) => Object.defineProperties({
    product, variant, size, position,
    init() {
        this.resetPreview();
        this.syncPreviewColor();
        if (this.position === 'Trasera' || this.position === 'Espalda') { this.preview.side = 'back'; if (this.isOrientedShirt) this.preview.preset = 'back_center'; }
        this.$watch('product', () => { this.preview.side = 'front'; this.syncPreviewColor(); });
        this.$watch('previewShape', () => this.constrainPreview());
        this.$watch('position', value => {
            if (value === 'Delantera') this.preview.side = 'front';
            if (value === 'Trasera') this.preview.side = 'back';
        });
        this.$watch('preview.side', (side, previous) => {
            if (side === previous) return;
            this.markManual();
            if (this.isShirt && this.position !== 'Ambos lados') this.position = side === 'back' ? 'Trasera' : 'Delantera';
        });
    },
    modelAttachments: [], modelError: '',
    get isShirt() { return ['shirt_white', 'shirt_black'].includes(this.product); },
    resetOptions() {
        this.variant = 'white'; this.size = ''; this.position = '';
        this.clearAttachments(); this.modelError = '';
        if (this.$refs.attachments) this.$refs.attachments.value = '';
    },
    choose(value) {
        this.product = value;
        this.resetOptions();
        this.$nextTick(() => document.getElementById('custom-order-form').scrollIntoView({ behavior: 'smooth', block: 'start' }));
    },
    clearAttachments() {
        this.modelAttachments.forEach(file => URL.revokeObjectURL(file.downloadUrl));
        this.modelAttachments = [];
        this.previewUrl = ''; this.resetPreview();
    },
    previewFiles(event, referenceMaxKb, modelMaxKb, maxFiles) {
        this.clearAttachments(); this.modelError = '';
        const files = Array.from(event.target.files);
        if (files.length > maxFiles) {
            this.modelError = `Selecciona hasta ${maxFiles} archivos.`;
            event.target.value = ''; return;
        }
        for (const file of files) {
            const extension = file.name.split('.').pop().toLowerCase();
            const model = this.product === '3d_print' && ['stl', 'obj', '3mf'].includes(extension);
            const reference = ['jpg', 'jpeg', 'png', 'webp', 'pdf'].includes(extension);
            if ((!model && !reference) || file.size === 0 || file.size > (model ? modelMaxKb : referenceMaxKb) * 1024) {
                this.modelError = 'Revisa el formato y el tamaño máximo de los archivos seleccionados.';
                this.clearAttachments(); event.target.value = ''; return;
            }
            // Browser MIME only decides whether to display a local thumbnail.
            // File types and content are validated again by the server.
            const downloadUrl = URL.createObjectURL(file);
            this.modelAttachments.push({
                name: file.name, size: `${(file.size / 1024 / 1024).toFixed(2)} MB`, model,
                pdf: extension === 'pdf',
                url: reference && ['jpg', 'jpeg', 'png', 'webp'].includes(extension) && ['image/jpeg', 'image/png', 'image/webp'].includes(file.type) ? downloadUrl : '',
                downloadUrl,
            });
        }
        const index = this.modelAttachments.findIndex(file => file.url);
        this.preview.file_index = Math.max(0, index);
        this.previewUrl = index >= 0 ? this.modelAttachments[index].url : '';
        this.syncPreviewColor();
    },
    destroy() { this.clearAttachments(); },
}, Object.getOwnPropertyDescriptors(customPreview())));
Alpine.start();
