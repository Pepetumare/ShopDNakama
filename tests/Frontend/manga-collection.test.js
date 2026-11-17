import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import mangaCollection from '../../resources/js/manga-collection.js';

test('changing volume resets the gallery, clamps quantity, and submits the correct product data', () => {
    let updatedUrl;
    globalThis.window = {
        location: { href: 'http://localhost/coleccion/serie?tomo=1' },
        history: { replaceState: (_, __, url) => { updatedUrl = url; } },
    };
    const first = { id: 10, sku: 'UNO', price: '$10.990', availability: 'En stock', stock: 7, images: ['one', 'one-alt'] };
    const fourth = { id: 11, sku: 'CUATRO', price: '$11.990', availability: 'Últimas unidades', stock: 2, images: ['four'] };
    const store = mangaCollection({ 1: first, 2: { stock: 0 }, 4: fourth }, 1);
    store.quantity = 5;
    store.activeImage = 1;
    store.selectVolume(2);
    store.selectVolume(3);
    assert.equal(store.current, first);
    store.selectVolume(4);
    assert.equal(store.current, fourth);
    assert.equal(store.activeImage, 0);
    assert.equal(store.quantity, 2);
    assert.equal(updatedUrl.searchParams.get('tomo'), '4');
    store.quantity = '';
    store.clampQuantity();
    assert.equal(store.quantity, 1);
});

test('the responsive volume grid caps the number of columns without adding cells', () => {
    const styles = readFileSync(new URL('../../resources/css/store.css', import.meta.url), 'utf8');
    assert.match(styles, /\.manga-volume-grid\s*\{[^}]*grid-template-columns:\s*repeat\(5,\s*minmax\(0,\s*1fr\)\)/);
    assert.match(styles, /\.manga-volume:disabled\s*\{[^}]*linear-gradient\(to top right/);
});
