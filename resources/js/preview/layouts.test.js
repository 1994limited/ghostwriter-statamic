// The layout cards' and extras' logic, in Node with no DOM: node --test resources/js/preview/*.test.js
import test from 'node:test';
import assert from 'node:assert/strict';
import { cardName, cardsFor, changesText, keysForPlaces, marksFor, compareRemembered, rememberCompare, changed, extraPayload, isStat, moveTo, partLabel, sharedStart, startBlock, thumbOrder, thumbScale, useLabel } from './layouts.js';

const plans = [
    { id: 'w', name: 'As written', description: 'The writer’s own layout', blocks: 3, suggested: false, stale: false, writer: true, outline: ['Hero', 'Text', 'Call to action'] },
    { id: 'p1', name: 'Numbers first', description: 'The numbers up top', blocks: 4, suggested: true, stale: false, writer: false, outline: ['Hero', 'Stats', 'Text', 'Call to action'] },
    { id: 'p2', name: 'A block a section', description: '', blocks: 5, suggested: false, stale: true, writer: false, outline: [] },
];

test('the row shows with two or more layouts, and hides with one or none', () => {
    assert.equal(cardsFor({ plans, chosen: 'w' }).show, true);
    assert.equal(cardsFor({ plans: plans.slice(0, 1), chosen: 'w' }).show, false);
    assert.equal(cardsFor({ plans: [], chosen: null }).show, false);
    assert.equal(cardsFor(null).show, false);
});

test('while the planner looks, the writer’s card shows with skeletons for the rest', () => {
    const row = cardsFor({ plans: plans.slice(0, 1), chosen: 'w', planning: true });

    assert.equal(row.show, true);
    assert.equal(row.skeletons, 2);
    assert.equal(row.cards.length, 1);
    assert.equal(row.cards[0].chosen, true);
    assert.equal(cardsFor({ plans, chosen: 'p1', planning: false }).skeletons, 0);
});

test('stale is offered for refreshing only when no planner is already at work', () => {
    assert.equal(cardsFor({ plans, chosen: 'w', stale: true }).stale, true);
    assert.equal(cardsFor({ plans, chosen: 'w', stale: true, planning: true }).stale, false);
    assert.equal(cardsFor({ plans, chosen: 'w', stale: true, planning: true }).planning, true, 'Refresh layouts says it is looking');
});

test('the chosen card is marked, and Use this draft names it', () => {
    const row = cardsFor({ plans, chosen: 'p1' });

    assert.deepEqual(row.cards.map((card) => card.chosen), [false, true, false]);
    assert.equal(useLabel('Use this draft', { chosen_name: 'Numbers first' }), 'Use this draft (Numbers first)');
    assert.equal(useLabel('Use this draft', { chosen_name: null }), 'Use this draft');
    assert.equal(useLabel('Use these changes', null), 'Use these changes');
});

test('the arrow keys move between cards, wrapping, and pass over a stale one', () => {
    assert.equal(moveTo(plans, 0, 'ArrowRight'), 1);
    assert.equal(moveTo(plans, 1, 'ArrowRight'), 0, 'p2 needs refreshing');
    assert.equal(moveTo(plans, 0, 'ArrowLeft'), 1);
    assert.equal(moveTo(plans, 1, 'Home'), 0);
    assert.equal(moveTo(plans, 0, 'End'), 1);
    assert.equal(moveTo(plans, 0, 'Enter'), null);
    assert.equal(moveTo([{ stale: true }], 0, 'ArrowRight'), null);
});

test('thumbnails render the chosen layout first, and skip stale ones', () => {
    assert.deepEqual(thumbOrder(cardsFor({ plans, chosen: 'p1' }).cards), ['p1', 'w']);
    assert.deepEqual(thumbOrder(cardsFor({ plans, chosen: 'w' }).cards), ['w', 'p1']);
});

test('a page rendered at 1280 px is scaled to the card', () => {
    assert.equal(thumbScale(256), 0.2);
    assert.equal(thumbScale(0), 0.18);
});

test('a card says what it is to a screen reader', () => {
    assert.equal(cardName(plans[1]), 'Numbers first, suggested, 4 blocks: Hero, Stats, Text, Call to action. The numbers up top.');
    assert.equal(cardName(plans[2]), 'A block a section, needs refreshing, 5 blocks.');
    assert.equal(cardName({ name: 'Short', blocks: 1, outline: ['Hero'] }), 'Short, 1 block: Hero.');
});

test('an extra’s edit sends its text and its parts, keeping what wasn’t touched', () => {
    const counted = {
        id: 'x1.1',
        text: '3 areas',
        parts: { value: '3', label: 'areas' },
        raw: { text: '[[check: 3 areas | from: Northumberland, Durham and the Tyne Valley]]', parts: { value: '[[check: 3 | from: Northumberland, Durham and the Tyne Valley]]', label: 'areas' } },
    };

    assert.deepEqual(extraPayload(counted, 'label', 'counties'), {
        text: '[[check: 3 counties | from: Northumberland, Durham and the Tyne Valley]]',
        parts: { value: '[[check: 3 | from: Northumberland, Durham and the Tyne Valley]]', label: 'counties' },
    }, 'a new label keeps the count to check');
    assert.deepEqual(extraPayload(counted, 'value', '4'), { text: '4 areas', parts: { value: '4', label: 'areas' } }, 'a number the editor types is theirs');

    const stat = { id: 'x1.2', text: '4 visits a winter', parts: { value: '4', label: 'visits a winter' } };
    assert.deepEqual(extraPayload(stat, 'label', 'visits each winter'), { text: '4 visits each winter', parts: { value: '4', label: 'visits each winter' } });

    const faq = { id: 'x2.1', text: 'Yes, from November.', parts: { question: 'Do you work in winter?' } };
    assert.deepEqual(extraPayload(faq, 'question', 'Do you visit in winter?'), { text: 'Yes, from November.', parts: { question: 'Do you visit in winter?' } });
    assert.deepEqual(extraPayload({ id: 'x3.1', text: 'A quote', parts: {} }, 'text', 'Another'), { text: 'Another' });

    assert.equal(isStat(stat), true);
    assert.equal(isStat(faq), false);
    assert.equal(changed(stat, 'text', ' 4 visits a winter '), false);
    assert.equal(changed(stat, 'label', 'visits'), true);
    assert.equal(changed({ text: 'x' }, 'question', 'Why?'), true);
    assert.equal(partLabel('attribution'), 'Who said it');
    assert.equal(partLabel('mystery'), 'mystery');
});

test('thumbnails start where the layouts begin to differ', () => {
    const cards = [
        { id: 'w', outline: ['Hero', 'Text', 'Quote', 'Call to action'] },
        { id: 'p1', outline: ['Hero', 'Stats', 'Text', 'Quote', 'Call to action'] },
        { id: 'p2', outline: ['Hero', 'Text', 'Quote', 'Text', 'Call to action'] },
    ];

    assert.equal(sharedStart(cards), 1);
    assert.equal(sharedStart([cards[0], cards[2]]), 3);
    assert.equal(sharedStart([cards[0]]), 0);
    assert.equal(sharedStart([{ outline: ['Text'] }, { outline: ['Text'] }]), 0, 'never past the last block');
    assert.equal(sharedStart([...cards, { id: 'p3', outline: ['Text'], stale: true }]), 1, 'a stale card has no thumbnail');

    const map = [
        { key: 'f1', kind: 'field', parent: null },
        { key: 'b1', kind: 'block', parent: null },
        { key: 'b2', kind: 'block', parent: null },
        { key: 'b3', kind: 'block', parent: 'b2' },
        { key: 'b4', kind: 'block', parent: null },
    ];

    assert.equal(startBlock(map, 1), 'b2');
    assert.equal(startBlock(map, 2), 'b4');
    assert.equal(startBlock(map, 0), null);
    assert.equal(startBlock(map, 9), null);
    assert.equal(startBlock([{ key: 'f1', kind: 'field' }, { key: 's1', kind: 'section', parent: 'f1' }, { key: 's2', kind: 'section', parent: 'f1' }], 1), 's2');
});

test('Compare layouts is off unless remembered on, and storage that throws counts as off', () => {
    const store = new Map();
    const storage = { getItem: (key) => store.get(key) ?? null, setItem: (key, value) => store.set(key, value) };

    assert.equal(compareRemembered(storage), false);
    rememberCompare(storage, true);
    assert.equal(compareRemembered(storage), true);
    rememberCompare(storage, false);
    assert.equal(compareRemembered(storage), false);
    assert.equal(compareRemembered(null), false);

    const broken = { getItem() { throw new Error('denied'); }, setItem() { throw new Error('denied'); } };
    assert.equal(compareRemembered(broken), false);
    assert.doesNotThrow(() => rememberCompare(broken, true));
});

test('a layout says what it changes, beside its chip and to a screen reader', () => {
    const card = { ...plans[1], changes: ['Stats added', 'Text split into 3 blocks'] };

    assert.equal(changesText(card), 'Stats added · Text split into 3 blocks');
    assert.equal(changesText(plans[0]), '');
    assert.equal(cardName(card), 'Numbers first, suggested, 4 blocks: Hero, Stats, Text, Call to action. Changes: Stats added, Text split into 3 blocks. The numbers up top.');
});

test('the places a layout changed are found in the render’s block map', () => {
    const map = [
        { key: 'f1', kind: 'field', type: 'title', path: 'title', parent: null },
        { key: 'b1', kind: 'block', type: 'hero', path: 'page_builder/0', parent: null },
        { key: 'b2', kind: 'block', type: 'quote', path: 'page_builder/1', parent: null },
        { key: 'b3', kind: 'block', type: 'text', path: 'page_builder/2', parent: null },
        { key: 's1', kind: 'section', path: 'page_builder/2/text', parent: 'b3' },
        { key: 's2', kind: 'section', path: 'page_builder/2/text', parent: 'b3' },
        { key: 'b4', kind: 'block', type: 'card', path: 'page_builder/2/cards/0', parent: 'b3' },
        { key: 'f2', kind: 'field', type: 'body', path: 'body', parent: null },
        { key: 's3', kind: 'section', path: 'body', parent: 'f2' },
        { key: 's4', kind: 'section', path: 'body', parent: 'f2' },
    ];

    assert.deepEqual(keysForPlaces(map, [{ field: 'page_builder', block: 2, section: 1 }, { field: 'page_builder', block: 1, section: null }]), ['b2', 's2'], 'in page order');
    assert.deepEqual(keysForPlaces(map, [{ field: 'body', block: null, section: 1 }]), ['s4']);
    assert.deepEqual(keysForPlaces(map, [{ field: 'page_builder', block: 0, section: 3 }]), ['b1'], 'no sections there: the block');
    assert.deepEqual(keysForPlaces(map, [{ field: 'title', block: null, section: null }, { field: 'title', block: null, section: null }]), ['f1']);
    assert.deepEqual(keysForPlaces(map, [{ field: 'page_builder', block: 9, section: null }, { field: 'gone', block: null, section: null }]), []);
    assert.deepEqual(keysForPlaces(null, []), []);
});

test('in Blocks and Text, a layout’s places are its blocks, or whole fields', () => {
    assert.deepEqual(marksFor([{ field: 'page_builder', block: 2, section: 1 }, { field: 'page_builder', block: 2, section: 0 }, { field: 'body', block: null, section: 4 }]), { fields: ['body'], blocks: { page_builder: [2] } });
    assert.deepEqual(marksFor(null), { fields: [], blocks: {} });
});
