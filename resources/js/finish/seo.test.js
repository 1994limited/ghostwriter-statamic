import { test } from 'node:test';
import assert from 'node:assert/strict';
import { inSeoPro, seoText, seoValue } from './seo.js';

test('SEO Pro takes the text as its custom value, a plain field as it is', () => {
    assert.deepEqual(seoValue({ source: 'field', value: 'excerpt' }, 'Winter care visits.'), { source: 'custom', value: 'Winter care visits.' });
    assert.deepEqual(seoValue(undefined, 'Winter care visits.', true), { source: 'custom', value: 'Winter care visits.' });
    assert.equal(seoValue('', 'Winter care visits.'), 'Winter care visits.');
    assert.equal(seoValue(null, 'Winter care visits.'), 'Winter care visits.');
});

test('the text reads the same from either shape', () => {
    assert.equal(seoText({ source: 'custom', value: 'Mine.' }), 'Mine.');
    assert.equal(seoText({ source: 'inherit', value: null }), '');
    assert.equal(seoText('Mine.'), 'Mine.');
    assert.equal(seoText(undefined), '');
});

test('a description inside SEO Pro\'s field is told from a plain one and from a set\'s field', () => {
    const values = { seo: { enabled: true, description: { source: 'inherit', value: null } }, meta_description: '', page_builder: [{ text: 'x' }] };

    assert.equal(inSeoPro('seo.description', values), true);
    assert.equal(inSeoPro('meta_description', values), false);
    assert.equal(inSeoPro('page_builder.0.text', values), false);
});
