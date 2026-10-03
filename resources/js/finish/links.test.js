import { test } from 'node:test';
import assert from 'node:assert/strict';
import { entryId, entryMeta, isLinkMeta, metaPath } from './links.js';

const linkMeta = { initialUrl: '#gw-link:contact-page', initialOption: 'url', types: { entry: { title: 'Entry', component: 'relationship', config: { type: 'entries' }, meta: null, metaLoaded: false, selected: [] } } };

test('a set\'s Link field meta is found by the set\'s _id, not its position', () => {
    const values = { page_builder: [{ _id: 'hero1', type: 'hero' }, { _id: 'cta9', type: 'cta' }] };

    assert.equal(metaPath('page_builder.0.button_link', values), 'page_builder.existing.hero1.button_link');
    assert.equal(metaPath('page_builder.1.link', values), 'page_builder.existing.cta9.link');
    assert.equal(metaPath('button_link', values), 'button_link');
});

test('Link to an entry switches the Link field to Entry with the entry selected and loaded', () => {
    const meta = entryMeta(linkMeta, ['abc'], { data: [{ id: 'abc', title: 'Contact' }] });

    assert.equal(meta.initialOption, 'entry', 'the field shows Entry, not the URL');
    assert.deepEqual(meta.types.entry.selected, ['abc']);
    assert.equal(meta.types.entry.metaLoaded, true);
    assert.equal(meta.initialUrl, '#gw-link:contact-page', 'kept, so a cancel can put it back');
    assert.equal(linkMeta.initialOption, 'url', 'the original meta is untouched');
});

test('only a Link field that can link to entries is treated as one', () => {
    assert.equal(isLinkMeta(linkMeta), true);
    assert.equal(isLinkMeta({ data: [] }), false, 'an Entries field');
    assert.equal(isLinkMeta(null), false);
});

test('entry IDs are read from link values and Bard hrefs', () => {
    assert.equal(entryId('entry::abc-123'), 'abc-123');
    assert.equal(entryId('statamic://entry::abc-123'), 'abc-123');
    assert.equal(entryId('#gw-link:contact-page'), null);
    assert.equal(entryId(null), null);
});
