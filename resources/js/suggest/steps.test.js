// Suggest edits' state and markdown, in Node: node --test resources/js/suggest/*.test.js
import test from 'node:test';
import assert from 'node:assert/strict';
import { counts, fieldStates, fillFact, filters, nextOpen, stepsFrom, tagText, versionsOf, wordingFixes } from './steps.js';
import { bardContent, plain, tokens } from './markdown.js';

const s = (id, category, dotted, extra = {}) => ({ id, category, label: category[0].toUpperCase() + category.slice(1), dotted, state: 'open', scope: 'range', replacement: 'new', alternatives: [], ...extra });

const review = [
    s('a', 'out-of-date', 'page_builder.0.eyebrow'),
    s('b', 'voice', 'page_builder.0.heading', { alternatives: ['one', 'two'] }),
    s('c', 'fact-to-check', 'body', { replacement: null, fact: { template: 'team of {answer}', answer: 'number' } }),
    s('d', 'clarity', 'body'),
    s('e', 'link', 'body'),
    s('f', 'accessibility', 'image', { scope: 'asset' }),
    s('g', 'seo', 'meta_description'),
];

test('steps keep what this page knows, with stale ones last', () => {
    const steps = stepsFrom(review, new Map([['b', { state: 'accepted', text: 'We design gardens' }], ['a', { stale: true }]]));

    assert.deepEqual(steps.map((step) => step.id), ['b', 'c', 'd', 'e', 'f', 'g', 'a']);
    assert.equal(steps[0].state, 'accepted');
    assert.deepEqual(counts(steps), { open: 5, accepted: 1, dismissed: 0, confirmed: 0, stale: 1, total: 7 });
});

test('filters count what is open and hide empty categories unless chosen', () => {
    const steps = stepsFrom(review, new Map([['b', { state: 'dismissed' }]]));

    assert.deepEqual(filters(steps).map((f) => [f.key, f.count]), [['all', 6], ['out-of-date', 1], ['fact-to-check', 1], ['clarity', 1], ['link', 1], ['accessibility', 1], ['seo', 1]]);
    assert.ok(filters(steps, 'voice').some((f) => f.key === 'voice' && f.count === 0));
});

test('next goes to the next open one in the filter, coming round', () => {
    const steps = stepsFrom(review, new Map([['c', { state: 'accepted' }]]));

    assert.equal(nextOpen(steps, 1), 3);
    assert.equal(nextOpen(steps, 6), 0);
    assert.equal(nextOpen(steps, 0, 'clarity'), 3);
    assert.equal(nextOpen(stepsFrom(review.map((x) => ({ ...x, state: 'dismissed' }))), 0), 7);
});

test('fields are current, open with their numbers, or done', () => {
    const steps = stepsFrom(review, new Map([['a', { state: 'accepted' }]]));
    const fields = fieldStates(steps, 3);

    assert.equal(fields.get('page_builder.0.eyebrow').state, 'done');
    assert.equal(tagText(fields.get('page_builder.0.eyebrow'), 3, steps), '✓');
    assert.equal(fields.get('body').state, 'current');
    assert.equal(tagText(fields.get('body'), 3, steps), '4 · Clarity');
    assert.equal(tagText(fieldStates(steps, 0).get('body'), 0, steps), '3–5');
});

test('accept all takes open wording fixes in the filter only', () => {
    const steps = stepsFrom(review);

    assert.deepEqual(wordingFixes(steps).map((i) => steps[i].id), ['b', 'd', 'g']);
    assert.deepEqual(wordingFixes(steps, 'seo').map((i) => steps[i].id), ['g']);
});

test('another version cycles through the replacement, the alternatives and any written since', () => {
    assert.deepEqual(versionsOf({ replacement: 'a', alternatives: ['b', 'a'], versions: ['c'] }), ['a', 'b', 'c']);
});

test('a fact is filled as core fills it', () => {
    assert.equal(fillFact({ template: 'our team of {answer} designers', answer: 'number' }, ' 8 '), 'our team of 8 designers');
    assert.throws(() => fillFact({ template: 'team of {answer}', answer: 'number' }, '8 or 9?'), /number-only/);
    assert.equal(fillFact({ template: 'from £{answer}', answer: 'money' }, '£480'), 'from £480');
});

test('inline markdown becomes words or Bard content', () => {
    assert.equal(plain('We **design** gardens'), 'We design gardens');
    assert.deepEqual(tokens('[a walled garden](entry::abc)')[0], { text: 'a walled garden', bold: false, italic: false, href: 'statamic://entry::abc' });
    assert.deepEqual(bardContent('Every winter', [{ type: 'bold' }]), [{ type: 'text', text: 'Every winter', marks: [{ type: 'bold' }] }]);
});
