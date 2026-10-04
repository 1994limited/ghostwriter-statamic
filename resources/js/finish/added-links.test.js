import { test } from 'node:test';
import assert from 'node:assert/strict';
import { linkKey, linksTo } from './linkkeys.js';

test('a link to a page is known however it is written', () => {
    assert.equal(linkKey('statamic://entry::abc-1'), 'entry::abc-1');
    assert.equal(linkKey('entry::abc-1'), 'entry::abc-1');
    assert.equal(linkKey('{entry:12@1:url||/contact}'), 'entry:12');
    assert.equal(linkKey('%7Bentry:12@1:url%7C%7C/contact%7D'), 'entry:12');
    assert.equal(linkKey('https://northfold.test/contact#entry:12@1:url'), 'entry:12');
    assert.equal(linkKey('https://northfold.test/contact/'), 'path:/contact');
    assert.equal(linkKey('#gw-link:contact-page'), null);
});

test('the links to a page in some markdown, with their words', () => {
    const text = 'Do [tell us](statamic://entry::contact), see [plans](statamic://entry::plans) and ![a photo](/a.jpg).';

    assert.deepEqual(linksTo(text, 'entry::contact').map((m) => [m.words, m.match]), [['tell us', '[tell us](statamic://entry::contact)']]);
    assert.deepEqual(linksTo(text, 'statamic://entry::nothing'), []);
});
