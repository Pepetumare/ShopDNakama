import test from 'node:test';
import assert from 'node:assert/strict';
import designAssistance from '../../resources/js/design-assistance.js';

function setup() {
    let open = false, validity = '';
    const state = designAssistance({ products: ['mug', 'shirt_white', 'shirt_black', 'badges', 'stickers', 'patch', '3d_print'] });
    state.$nextTick = callback => callback();
    state.$refs = {
        assistanceDialog: { showModal() { open = true; }, close() { open = false; } },
        assistanceText: { focus() {}, setCustomValidity(message) { validity = message; }, reportValidity() { return !validity; } },
    };
    state.syncAssistanceProduct('mug');
    return { state, isOpen: () => open, error: () => validity };
}

test('enabling opens dialog immediately and saving retains trimmed instructions', () => {
    const { state, isOpen } = setup();
    state.toggleAssistance(true); assert.equal(isOpen(), true);
    assert.equal(state.assistanceEnabled, true);
    state.assistanceDraft = '  Quiero el personaje principal al centro.  ';
    state.saveAssistance();
    assert.equal(isOpen(), false); assert.equal(state.assistanceOpen, false);
    assert.equal(state.assistanceEnabled, true);
    assert.equal(state.assistanceNotes, 'Quiero el personaje principal al centro.');
});

test('cancel and disable deactivate service but retain temporary notes for reactivation', () => {
    const { state, isOpen } = setup();
    state.toggleAssistance(true); state.assistanceDraft = 'Quiero completar el fondo de la taza.';
    state.cancelAssistance(); assert.equal(isOpen(), false); assert.equal(state.assistanceEnabled, false);
    assert.equal(state.assistanceNotes, '');
    state.toggleAssistance(true); state.saveAssistance();
    assert.equal(state.assistanceNotes, 'Quiero completar el fondo de la taza.');
    state.toggleAssistance(false); assert.equal(state.assistanceEnabled, false);
    state.toggleAssistance(true); assert.equal(state.assistanceDraft, state.assistanceNotes);
});

test('invalid lengths or HTML keep dialog open until valid instructions are supplied', () => {
    const { state, isOpen, error } = setup();
    state.toggleAssistance(true);
    for (const text of ['', ' corto ', 'x'.repeat(2001), '<b>personaje centrado</b>']) {
        state.assistanceDraft = text; state.saveAssistance();
        assert.equal(isOpen(), true); assert.notEqual(error(), ''); assert.equal(state.assistanceNotes, '');
    }
    state.assistanceDraft = 'No cortar las caras de los personajes.'; state.saveAssistance();
    assert.equal(isOpen(), false); assert.equal(error(), '');
});

test('product changes cancel service and 3d is always excluded even if configured accidentally', () => {
    const { state, isOpen } = setup();
    state.toggleAssistance(true);
    state.syncAssistanceProduct('shirt_white'); assert.equal(state.assistanceEnabled, false); assert.equal(isOpen(), false);
    assert.equal(state.assistanceEligible, true);
    state.syncAssistanceProduct('3d_print'); assert.equal(state.assistanceEligible, false);
    state.toggleAssistance(true); assert.equal(state.assistanceEnabled, false); assert.equal(isOpen(), false);
    state.syncAssistanceProduct('unknown'); assert.equal(state.assistanceEligible, false);
});
