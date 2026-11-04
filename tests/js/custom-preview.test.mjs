import test from 'node:test';
import assert from 'node:assert/strict';
import customPreview from '../../resources/js/custom-preview.js';

test('mouse and touch drags save identical normalized positions across viewport sizes', () => {
    for (const pointerType of ['mouse', 'touch']) {
        for (const width of [180, 480]) {
            const state = customPreview({ type: 'mug', readonly: false });
            state.product = 'mug'; state.previewUrl = 'blob:original';
            const target = { getBoundingClientRect: () => ({ width, height: width }), setPointerCapture() {} };
            state.startPreviewDrag({ pointerType, pointerId: 1, button: 0, clientX: 0, clientY: 0, currentTarget: target });
            state.dragPreview({ pointerId: 1, clientX: width / 4, clientY: -width / 4 });
            assert.equal(state.preview.x, 0.75);
            assert.equal(state.preview.y, 0.25);
            state.dragPreview({ pointerId: 2, clientX: 9999, clientY: 9999 });
            assert.equal(state.preview.x, 0.75);
            state.dragPreview({ pointerId: 1, clientX: 9999, clientY: -9999 });
            assert.equal(state.preview.x, 1);
            assert.equal(state.preview.y, 0);
            state.endPreviewDrag();
            assert.equal(state.previewDrag, null);
        }
    }
});

test('controls clamp scale and position, keep circular designs visible and reset deterministically', () => {
    const state = customPreview(); state.product = 'badges';
    state.movePreview(100, 100);
    assert.ok(Math.hypot(state.preview.x - 0.5, state.preview.y - 0.5) <= 0.480001);
    state.scalePreview(100); assert.equal(state.preview.scale, 1.5);
    state.scalePreview(-100); assert.equal(state.preview.scale, 0.1);
    state.resetPreview();
    assert.equal(state.preview.x, 0.5); assert.equal(state.preview.y, 0.5); assert.equal(state.preview.scale, 0.65);
});

test('saved composition matches editor and 3d has no compositor', () => {
    const editor = customPreview(); editor.product = 'shirt_black'; editor.previewUrl = 'private/original';
    editor.preview.side = 'back'; editor.movePreview(0.1, -0.2); editor.scalePreview(0.2); editor.syncPreviewColor();
    const saved = customPreview({ readonly: true, type: editor.product, url: editor.previewUrl, data: editor.preview });
    assert.equal(saved.designStyle, editor.designStyle);
    assert.equal(saved.previewClass, editor.previewClass);
    assert.equal(saved.preview.color, 'black');
    editor.product = '3d_print'; assert.equal(editor.hasPreview, false);
});

test('patch and sticker dimensions determine aspect ratio within a responsive frame', () => {
    const state = customPreview(); state.product = 'patch'; state.previewWidth = 8; state.previewHeight = 4;
    assert.equal(state.areaStyle, 'width:72%;height:36%');
    state.previewShape = 'circular'; assert.equal(state.areaStyle, 'width:72%;height:72%');
    state.product = 'stickers'; state.previewDimensions = '5 × 10 cm';
    assert.equal(state.areaStyle, 'width:36%;height:72%');
});

test('panoramic mug fit and fill preserve image proportions; stretching is deliberate', () => {
    const state = customPreview(); state.product = 'mug'; state.resetPreview();
    for (const ratio of [0.5, 1, 3, 6]) {
        state.imageRatio = ratio;
        for (const mode of ['fit', 'fill']) {
            state.setFitMode(mode);
            const width = Number(state.designStyle.match(/width:([\d.]+)%/)[1]);
            const height = Number(state.designStyle.match(/--cp-design-height:([\d.]+)%/)[1]);
            assert.ok(Math.abs(width / height * 3 - ratio) < 0.000001);
            if (mode === 'fit') assert.ok(width <= 100 && height <= 100);
            else assert.ok(width >= 100 && height >= 100);
        }
    }
    state.imageRatio = 1; state.setFitMode('stretch'); state.preview.scale_x = 2; state.preview.scale_y = 0.75;
    const width = Number(state.designStyle.match(/width:([\d.]+)%/)[1]);
    const height = Number(state.designStyle.match(/--cp-design-height:([\d.]+)%/)[1]);
    assert.ok(Math.abs(width / height * 3 - 2 / 0.75) < 0.000001);
    state.setFitMode('fit'); assert.equal(state.preview.scale_x, 1); assert.equal(state.preview.scale_y, 1);
});

test('mug handle affects only the mockup; saved v2 uses exactly the submitted geometry', () => {
    const editor = customPreview(); editor.product = 'mug'; editor.resetPreview(); editor.previewUrl = 'private/original';
    editor.imageRatio = 2; editor.setFitMode('stretch'); editor.preview.scale_x = 1.35; editor.preview.scale_y = 0.8;
    editor.movePreview(0.1, -0.2);
    const style = editor.designStyle;
    editor.preview.handle_side = 'left'; assert.equal(editor.designStyle, style);
    const saved = customPreview({ readonly: true, type: 'mug', url: editor.previewUrl, data: { ...editor.preview, aspect_ratio: editor.imageRatio } });
    assert.equal(saved.designStyle, style); assert.equal(saved.isPanoramicMug, true);
    saved.recordImageDimensions({ target: { naturalWidth: 123, naturalHeight: 456 } });
    assert.equal(saved.designStyle, style);
    assert.equal(saved.cylinderSlices.length, 32);
    const edge = parseFloat(saved.cylinderSlices[0].style.match(/width:([\d.]+)/)[1]);
    const center = parseFloat(saved.cylinderSlices[16].style.match(/width:([\d.]+)/)[1]);
    assert.ok(center > edge * 5);
});

test('panoramic dragging is normalized for touch and mouse in wide mobile and desktop editors', () => {
    for (const pointerType of ['touch', 'mouse']) {
        for (const width of [270, 630]) {
            const state = customPreview(); state.product = 'mug'; state.resetPreview(); state.previewUrl = 'blob:image';
            const target = { getBoundingClientRect: () => ({ width, height: width / 3 }), setPointerCapture() {} };
            state.startPreviewDrag({ pointerType, pointerId: 5, button: 0, clientX: 0, clientY: 0, currentTarget: target });
            state.dragPreview({ pointerId: 5, clientX: width * 0.1, clientY: width / 3 * 0.2 });
            assert.equal(state.preview.x, 0.6); assert.equal(state.preview.y, 0.7);
            state.endPreviewDrag(); assert.equal(state.previewDrag, null);
        }
    }
});

test('shirt orientation stays independent of side and presets remain manually adjustable', () => {
    const state = customPreview(); state.product = 'shirt_black'; state.resetPreview();
    for (const side of ['front', 'back']) {
        for (const orientation of ['vertical', 'horizontal']) {
            state.preview.side = side; state.preview.orientation = orientation;
            assert.ok(state.previewClass.includes(`cp-side-${side}`));
            assert.ok(state.previewClass.includes(`cp-orientation-${orientation}`));
            for (const preset of side === 'front' ? ['left_chest', 'upper_center', 'center', 'large_front'] : ['upper_back', 'back_center', 'large_back']) {
                state.setShirtPreset(preset);
                assert.equal(state.preview.preset, preset); assert.equal(state.preview.side, side); assert.equal(state.preview.orientation, orientation);
                assert.ok(state.preview.x >= 0 && state.preview.x <= 1 && state.preview.y >= 0 && state.preview.y <= 1);
                assert.ok(state.preview.scale >= 0.1 && state.preview.scale <= 1.5);
            }
        }
    }
    state.setSizePreset('small'); assert.equal(state.preview.scale, 0.35);
    state.setSizePreset('medium'); assert.equal(state.preview.scale, 0.65);
    state.setSizePreset('large'); assert.equal(state.preview.scale, 1);
    state.movePreview(0.01, 0.01); assert.equal(state.preview.preset, 'custom'); assert.equal(state.preview.size_preset, 'custom');
    state.resetPreview(); assert.equal(state.preview.orientation, 'vertical'); assert.equal(state.preview.preset, 'back_center');
});

test('historical v1 mug and shirt styles are unchanged and no v2 defaults are applied on load', () => {
    const data = { version: 1, side: 'back', x: 0.5, y: 0.28, scale: 0.35, rotation: 0, color: 'black', file_index: 0 };
    for (const type of ['mug', 'shirt_black']) {
        const state = customPreview({ readonly: true, type, data, url: 'private/original' });
        assert.equal(state.designStyle, 'left:50%;top:28.000000000000004%;width:35%;transform:translate(-50%,-50%)');
        assert.equal(state.isPanoramicMug, false); assert.equal(state.isOrientedShirt, false);
        assert.equal(state.preview.orientation, undefined); assert.equal(state.preview.fit_mode, undefined);
        assert.deepEqual(state.preview, data);
    }
});
