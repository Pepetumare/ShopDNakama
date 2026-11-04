export default (options = {}) => ({
    assistanceProduct: '',
    assistanceEnabled: !!options.enabled,
    assistanceNotes: options.notes || '',
    assistanceDraft: options.notes || '',
    assistanceOpen: false,
    get assistanceEligible() {
        return this.assistanceProduct !== '3d_print' && (options.products || []).includes(this.assistanceProduct);
    },
    syncAssistanceProduct(product) {
        if (this.assistanceProduct && this.assistanceProduct !== product) this.cancelAssistance();
        this.assistanceProduct = product;
        if (!this.assistanceEligible) this.cancelAssistance();
    },
    toggleAssistance(checked) {
        if (!checked || !this.assistanceEligible) { this.cancelAssistance(); return; }
        this.assistanceEnabled = true;
        this.openAssistance();
    },
    openAssistance() {
        this.assistanceOpen = true;
        this.$nextTick(() => {
            if (!this.assistanceEnabled || !this.assistanceEligible) return;
            this.$refs.assistanceDialog.showModal();
            this.$refs.assistanceText.focus();
        });
    },
    cancelAssistance() {
        this.assistanceEnabled = false;
        this.assistanceOpen = false;
        this.$refs.assistanceDialog?.close();
    },
    saveAssistance() {
        const text = this.assistanceDraft.trim();
        const field = this.$refs.assistanceText;
        field.setCustomValidity(text.length < 10 || text.length > 2000 ? 'Escribe entre 10 y 2000 caracteres.' : /<[^>]*>|<\s*\/?\s*[a-z!]/i.test(text) ? 'Escribe las indicaciones sin HTML.' : '');
        if (!field.reportValidity()) return;
        this.assistanceNotes = text;
        this.assistanceDraft = text;
        this.assistanceOpen = false;
        this.$refs.assistanceDialog.close();
    },
});
