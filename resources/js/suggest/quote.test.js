// Core's QuoteFinder cases, run against the port: node --test resources/js/suggest/*.test.js
import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { findQuote, rangeIn } from './quote.js';

const { cases } = JSON.parse(readFileSync(new URL('./quote-cases.json', import.meta.url), 'utf8'));

cases.forEach((c) => {
    test(c.name, () => {
        const found = findQuote(c.quote, c.text, c.occurrence ?? null, c.markdown ?? false);

        if (c.expect === null) {
            assert.equal(found, null);

            return;
        }

        assert.ok(found, 'found');
        assert.equal(found.offset, c.expect.offset);
        assert.equal(found.length, c.expect.length);
        assert.equal(found.fuzzy, c.expect.fuzzy);
        if (c.expect.occurrence !== undefined) assert.equal(found.occurrence, c.expect.occurrence);
        assert.equal(Array.from(c.text).slice(found.offset, found.offset + found.length).join(''), c.expect.text);
    });
});

test('a range in UTF-16 indices', () => {
    const text = '🌱 New for 2024: winter visits';
    const [start, end] = rangeIn(text, findQuote({ exact: 'New for 2024' }, text));

    assert.equal(text.slice(start, end), 'New for 2024');
});
