export default (volumes, initial) => ({
    selected: initial,
    activeImage: 0,
    quantity: 1,

    get current() {
        return volumes[this.selected];
    },

    clampQuantity() {
        this.quantity = Math.min(Math.max(1, this.current.stock), Math.max(1, Math.floor(Number(this.quantity) || 1)));
    },

    selectVolume(number) {
        if (!volumes[number]?.stock || number === this.selected) return;

        this.activeImage = 0;
        this.selected = number;
        this.clampQuantity();

        const url = new URL(window.location.href);
        url.searchParams.set('tomo', number);
        window.history.replaceState(window.history.state, '', url);
    },
});
