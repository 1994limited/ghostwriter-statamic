import { test } from 'node:test';
import assert from 'node:assert/strict';
import { browseButton } from './fields.js';

const button = (text, shown = true) => ({ textContent: text, disabled: false, hidden: false, offsetParent: shown ? {} : null, getAttribute: () => null, clicked: false });
const field = (...buttons) => ({ querySelectorAll: () => buttons });

test('Choose from Assets uses the field\'s own Browse, even when the field is full of the placeholder', () => {
    const narrow = button('Browse', false);
    const wide = button('Browse Assets');

    assert.equal(browseButton(field(button(''), narrow, wide, button('choose a file'), button('image-placeholder.png'))), wide, 'the visible one');
    assert.equal(browseButton(field(narrow)), narrow, 'a hidden one when there is no other');
    assert.equal(browseButton(field(button('Remove'))), null);
    assert.equal(browseButton(null), null);
});
