// node --test resources/js/finish
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { bringsOut, counts, currentAfter, fieldStates, firstToDo, published, stepsFrom, tagText } from './state.js';

const t = (text, params = {}) => Object.entries(params).reduce((out, [key, value]) => out.replace(`:${key}`, value), text);
const gap = (id, dotted, kind = 'ask', severity = 'blocks', speech = 'Fill this in') => ({ id, dotted, kind, severity, speech });

test('one live list gives the menu, the guide total and the bar the same number', () => {
    const report = { count: 3, gaps: [gap('a', 'title', 'required', 'required', 'Empty!'), gap('b', 'body'), gap('s', 'summary', 'expected', 'suggestion', 'Empty!'), gap('c', 'image', 'image-placeholder', 'blocks', 'Swap me')] };
    const steps = stepsFrom(report);
    const n = counts(steps, 1);

    assert.equal(n.count, 3, 'the menu');
    assert.equal(n.total, 3, '"n of 3"');
    assert.equal(steps.filter((step) => step.gap.severity !== 'suggestion').length, 3, 'the bar');
    assert.equal(n.number, 2);
    assert.deepEqual(steps.map((step) => step.gap.id), ['a', 'b', 'c', 's'], 'counted first, suggestions after');
    assert.deepEqual(counts(steps, 3), { count: 3, total: 3, suggestions: 1, number: 1, suggestion: true, skipped: 0 });
});

test('a fixed gap leaves the list, and the guide stays where it was', () => {
    const after = stepsFrom({ gaps: [gap('a', 'title'), gap('c', 'image')] });

    assert.equal(currentAfter(after, 'b', 1), 1, 'on the next gap, which moved up');
    assert.equal(after[1].gap.id, 'c');
    assert.equal(counts(after, 1).total, 2);
    assert.equal(currentAfter(after, 'a', 0), 0, 'still on a gap that is still there');
});

test('a field is fixed only when all its gaps are gone', () => {
    const touched = new Set(['body', 'image']);
    const two = stepsFrom({ gaps: [gap('ask', 'body'), gap('link', 'body', 'link', 'blocks', 'Needs a link')] });
    const one = stepsFrom({ gaps: [gap('link', 'body', 'link', 'blocks', 'Needs a link')] });
    const none = stepsFrom({ gaps: [] });

    assert.equal(fieldStates(two, -1, touched).get('body').state, 'open');
    assert.equal(fieldStates(one, -1, touched).get('body').state, 'open', 'one gap fixed, one left: not fixed');
    assert.equal(fieldStates(none, -1, touched).get('body').state, 'fixed');
});

test('a fix that makes a new gap is a new open gap, not a fixed field', () => {
    const touched = new Set(['image']);
    const preview = stepsFrom({ gaps: [gap('sp', 'image', 'stock-preview', 'blocks', 'License me')] });

    assert.equal(currentAfter(preview, 'ph', 0), 0);
    assert.equal(fieldStates(preview, 0, touched).get('image').state, 'current');
    assert.equal(tagText(fieldStates(preview, 0, touched).get('image'), preview, t), '1 · License me', 'the new kind, not "Swap me"');
});

test('a field\'s tag shows the current step, or the range of its steps', () => {
    const steps = stepsFrom({ gaps: [gap('t', 'title', 'required', 'required', 'Empty!'), gap('a1', 'body'), gap('l', 'body', 'link', 'blocks', 'Needs a link'), gap('a2', 'body')] });

    assert.equal(tagText(fieldStates(steps, 3).get('body'), steps, t), '4 · Fill this in', 'the guide is on the third gap in the field');
    assert.equal(tagText(fieldStates(steps, 0).get('body'), steps, t), '2–4 · 3 to do');
    assert.equal(tagText(fieldStates(steps, 0).get('title'), steps, t), '1 · Empty!');
});

test('skipped gaps stay in the list and in the count', () => {
    const steps = stepsFrom({ gaps: [gap('a', 'title'), gap('b', 'body')] }, { skipped: new Set(['a']) });

    assert.equal(steps[0].status, 'skipped');
    assert.equal(counts(steps, 1).count, 2);
    assert.equal(counts(steps, 1).skipped, 1);
});

// Entry 168 in Craft, the same here: the only gap a required image left
// empty (`required`, nothing that blocks), so the guide stayed in and the
// menu showed nothing, but Suggest edits said "1 thing still to finish"
// and its button opened a guide that wasn't there.
const heroImage = () => ({
    id: 'image-empty|hero_image||0', kind: 'image-empty', severity: 'prompt', path: 'hero_image', dotted: 'hero_image', field: 'hero_image', label: 'Hero image',
    hint: null, excerpt: null, occurrence: 0, message: 'Hero image is required. Add one?', speech: 'Empty!', blocks: false, meta: [],
    fixes: [{ label: 'Find a photo', action: 'find-photo', cost: 'free', primary: true }],
});

test('what is left to finish is one number everywhere: nothing until the guide is out', () => {
    const steps = stepsFrom({ count: 1, gaps: [heroImage()] });

    assert.equal(counts(steps, 0).count, 1, 'the guide itself counts it');
    assert.equal(published(steps, 0, false), 0, 'not out: the menu and Suggest edits say nothing');
    assert.equal(published(steps, 0, true), 1);
});

test('an image the page needs brings the guide out, so the menu counts it; a required field or a suggestion alone does not', () => {
    const hero = stepsFrom({ count: 1, gaps: [heroImage()] });

    assert.equal(bringsOut(hero), true);
    assert.equal(published(hero, 0, bringsOut(hero)), 1, 'the header and the menu row say 1');
    assert.equal(bringsOut(stepsFrom({ gaps: [gap('s', 'summary', 'expected', 'suggestion', 'Empty!')] })), false);
    assert.equal(bringsOut(stepsFrom({ gaps: [gap('l', 'related', 'link-empty', 'required', 'Needs a link')] })), false);
    assert.equal(bringsOut(stepsFrom({ gaps: [gap('a', 'body')] })), true, 'what blocks does');
});

test('a guide opened from a count lands on what it counted, skipped or not', () => {
    const open = stepsFrom({ count: 1, gaps: [heroImage()] });
    const skipped = stepsFrom({ count: 1, gaps: [heroImage()] }, { skipped: new Set(['image-empty|hero_image||0']) });

    assert.equal(firstToDo(open), 0);
    assert.equal(firstToDo(skipped), 0, 'skipped, but still counted: the guide opens on it');
    assert.equal(firstToDo([]), 0);
});
