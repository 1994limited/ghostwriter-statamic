// The preview overlay's logic, in Node with no DOM: node --test resources/js/preview/*.test.js
import test from 'node:test';
import assert from 'node:assert/strict';
import { cancelLinks, debounce, depthOf, innermost, labelFor, pageTitle, previewError } from './overlay.js';

const map = {
    f3: { key: 'f3', label: 'Body', parent: null },
    s2: { key: 's2', label: 'What the practice asked for', parent: 'f3' },
    b6: { key: 'b6', label: 'Pull quote', parent: 's2' },
    b1: { key: 'b1', label: 'Hero', parent: null },
};

test('the innermost block under the pointer wins, then the smallest', () => {
    const boxes = [
        { key: 'f3', depth: 0, box: { left: 0, top: 0, width: 800, height: 600 } },
        { key: 's2', depth: 1, box: { left: 0, top: 200, width: 800, height: 300 } },
        { key: 'b6', depth: 2, box: { left: 20, top: 300, width: 600, height: 80 } },
        { key: 'b1', depth: 0, box: { left: 0, top: 700, width: 800, height: 200 } },
    ];

    assert.equal(innermost(boxes, 50, 320).key, 'b6');
    assert.equal(innermost(boxes, 50, 250).key, 's2');
    assert.equal(innermost(boxes, 50, 100).key, 'f3');
    assert.equal(innermost(boxes, 50, 650), null);
    assert.equal(innermost([{ key: 'a', depth: 0, box: { left: 0, top: 0, width: 100, height: 100 } }, { key: 'b', depth: 0, box: { left: 10, top: 10, width: 20, height: 20 } }], 15, 15).key, 'b');
});

test('a child is labelled with the block it is in, and its depth counted', () => {
    assert.equal(labelFor(map.b6, map), 'Pull quote · in What the practice asked for');
    assert.equal(labelFor(map.b1, map), 'Hero');
    assert.equal(depthOf(map.b6, map), 2);
    assert.equal(depthOf(map.f3, map), 0);
});

test('a failed render is read from the page the middleware sends', () => {
    const doc = (content) => ({ querySelector: (selector) => (selector.includes('ghostwriter-preview-error') && content !== null ? { getAttribute: () => content } : null) });

    assert.deepEqual(previewError(doc('{"status":500,"message":"Collection [x] not found"}')), { status: 500, message: 'Collection [x] not found' });
    assert.deepEqual(previewError(doc('not json')), { message: '' });
    assert.equal(previewError(doc(null)), null);
    assert.equal(previewError(null), null);
});

test('the title in the bar has no marker left in it', () => {
    assert.equal(pageTitle({ title: 'Rain gardens\u{E0067}\u{E0077}\u{E0066}\u{E0031}\u{E007F} · Northfold ' }), 'Rain gardens · Northfold');
});

test('a click on a link, or inside one, is cancelled; others are not', () => {
    const event = (link) => ({ target: { closest: () => link }, prevented: false, stopped: false, preventDefault() { this.prevented = true; }, stopPropagation() { this.stopped = true; } });
    const onLink = event({ href: '/journal' });
    const elsewhere = event(null);

    assert.equal(cancelLinks(onLink), true);
    assert.ok(onLink.prevented && onLink.stopped);
    assert.equal(cancelLinks(elsewhere), false);
    assert.equal(elsewhere.prevented, false);
});

test('renders wait for the edits to settle', async () => {
    const calls = [];
    const later = debounce((value) => calls.push(value), 30);

    later(1);
    later(2);
    later(3);
    assert.equal(later.pending(), true);
    await new Promise((resolve) => setTimeout(resolve, 60));
    assert.deepEqual(calls, [3]);

    later(4);
    later.flush();
    assert.deepEqual(calls, [3, 4]);

    later(5);
    later.cancel();
    await new Promise((resolve) => setTimeout(resolve, 60));
    assert.deepEqual(calls, [3, 4]);
});
