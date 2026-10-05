import { test } from 'node:test';
import assert from 'node:assert/strict';
import { unlinkedWords } from './words.js';

test('a link Suggest links found is found outside links, in order, as core counts them', () => {
    const text = 'Tell us about your garden, or [tell us about your garden](https://example.org). Then tell us about your garden again, and tell us about your garden once more.';
    const found = unlinkedWords(text, 'tell us about your garden');

    assert.equal(found.length, 2, 'The linked one and the capitalised one are not counted.');
    assert.equal(text.slice(found[0].index, found[0].index + found[0].length), 'tell us about your garden');
    assert.ok(found[0].index > text.indexOf('Then'));
    assert.deepEqual(unlinkedWords(text, ''), []);
    assert.deepEqual(unlinkedWords('![tell us about your garden](/a.jpg)', 'tell us about your garden'), [], 'Nor an image\'s words.');
});
