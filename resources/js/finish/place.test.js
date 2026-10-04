import { test } from 'node:test';
import assert from 'node:assert/strict';
import { inlineSpot, labelParts, pinnedBottom, saySide, sayRect } from './place.js';

test('a fix label cuts only the name in it short', () => {
    const name = 'Winter structure: plants that earn their keep in January';
    const parts = labelParts(`Link to ${name}`, name);

    assert.equal(parts.lead, 'Link to ');
    assert.equal(parts.tail, '');
    assert.equal(parts.name.length, 40);
    assert.ok(parts.name.endsWith('…'));
    assert.deepEqual(labelParts('Link to Short', 'Short'), { lead: 'Link to ', name: 'Short', tail: '' });
    assert.deepEqual(labelParts(`${name} verlinken`, name, 80), { lead: '', name, tail: ' verlinken' }, 'A translation with the name first keeps its words after it.');
    assert.deepEqual(labelParts('Choose an entry', null), { lead: '', name: 'Choose an entry', tail: '' });
});

test('the mark sits above the words, below them under the chrome, and waits when they are hidden', () => {
    const view = { top: 98, width: 1280, height: 800 };
    const line = (top) => ({ left: 600, right: 720, top, bottom: top + 22 });

    assert.deepEqual(inlineSpot(line(400), view), { x: 578, y: 354 });
    assert.deepEqual(inlineSpot(line(120), view), { x: 578, y: 146 }, 'No room under the toolbar: below the line.');
    assert.equal(inlineSpot(line(60), view), null, 'Under the header and toolbar.');
    assert.equal(inlineSpot(line(820), view), null, 'Below the window.');
    assert.equal(inlineSpot({ left: 1270, right: 1278, top: 400, bottom: 422 }, view).x, 1232, 'Kept inside the window.');
    assert.equal(inlineSpot(line(400), { ...view, mirror: true }).x, 698);
});

test('the mark\'s words go where they fit', () => {
    assert.equal(saySide(500, 80, 1280), 'left');
    assert.equal(saySide(40, 80, 1280), 'right', 'Near the left edge: on its right.');
    assert.equal(saySide(1200, 80, 1280, { prefer: 'right' }), 'left', 'Near the right edge: on its left.');
    assert.equal(saySide(150, 300, 375), 'below-right');
    assert.equal(saySide(250, 300, 375), 'below-left');
});

test('only what is stacked from the top counts as chrome', () => {
    const box = (top, height) => ({ top, bottom: top + height, height });

    assert.equal(pinnedBottom([box(0, 56), box(57, 41)], null, 800), 98, 'The header, and a Bard toolbar stuck under it.');
    assert.equal(pinnedBottom([box(0, 56), box(500, 41)], null, 800), 56, 'A toolbar further down the form is not.');
    assert.equal(pinnedBottom([box(0, 56), box(56, 744)], null, 800), 56, 'The main pane is not.');
    assert.equal(pinnedBottom([box(0, 56)], box(300, 41), 800), 341, 'The editor\'s own toolbar, when pointing into it.');
});

test('the mark’s words go where they cover none of the page’s text', () => {
    // Above the guide, near the right edge: the sidebar's dates are on its left.
    const covered = new Set(['left', 'below-left', 'below-right']);

    assert.equal(saySide(1330, 60, 1400, { covers: (side) => covered.has(side), y: 700 }), 'above-left');
    assert.equal(saySide(500, 60, 1400, { covers: () => false, y: 700 }), 'left', 'Nothing in the way: the preferred side.');
    assert.equal(saySide(500, 60, 1400, { covers: (side) => side === 'left', y: 700 }), 'right');
    assert.equal(saySide(1330, 60, 1400, { covers: () => true, y: 700 }), 'left', 'Text everywhere: the side that fits.');
    assert.equal(saySide(1330, 60, 1400, { covers: (side) => side !== 'below-left', y: 10 }), 'below-left', 'No room above.');
    assert.deepEqual(sayRect('above-left', 1330, 700, 60, 20), { left: 1314, top: 676, right: 1374, bottom: 696 });
    assert.deepEqual(sayRect('left', 1330, 700, 60, 20), { left: 1268, top: 702, right: 1328, bottom: 722 });
});
