// Counts to check in the guide, in Node: node --test resources/js/finish/*.test.js
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { checkReplacement, pickCheck } from './check.js';

const list = 'Northumberland, Durham and the Tyne Valley';
const found = [
    { match: `[[check: 3 areas | from: ${list}]]`, hint: '3 areas' },
    { match: '[[check: 2 visits | from: November and February]]', hint: '2 visits' },
    { match: `[[check: 3 areas | from: ${list}]]`, hint: '3 areas' },
];

test('a gap names its marker as written, then by its value and occurrence', () => {
    assert.equal(pickCheck(found, { hint: '2 visits', meta: { match: '[[check: 2 visits | from: November and February]]' } }), found[1]);
    assert.equal(pickCheck(found, { hint: '3 areas', occurrence: 1, meta: { match: `[[check: 3 areas | from: ${list}]]` } }), found[2]);
    assert.equal(pickCheck(found, { hint: '3 Areas', meta: {} }), found[0], 'by its value when the marker was edited');
    assert.equal(pickCheck(found, { hint: '3 areas', occurrence: 5, meta: {} }), found[0]);
    assert.equal(pickCheck(found, { hint: '9 things', meta: { match: '[[check: 9 things | from: a, b and c]]' } }), null);
});

test('each fix says what goes in the marker’s place', () => {
    const gap = { hint: '3 areas' };

    assert.equal(checkReplacement(gap, { action: 'confirm', value: '3 areas' }), '3 areas', 'Looks right');
    assert.equal(checkReplacement(gap, { action: 'confirm', value: '4 areas' }), '4 areas', 'Use “4 areas”, when the list has changed');
    assert.equal(checkReplacement(gap, { action: 'confirm' }), '3 areas');
    assert.equal(checkReplacement(gap, { action: 'change', value: '3 areas' }, ' three counties '), 'three counties');
    assert.equal(checkReplacement(gap, { action: 'change' }, '  '), null);
    assert.equal(checkReplacement(gap, { action: 'remove' }), '');
    assert.equal(checkReplacement(gap, { action: 'answer' }, 'x'), null);
});
