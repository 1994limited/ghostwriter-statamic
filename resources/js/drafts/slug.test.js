// The draft's address into the slug field: node --test resources/js/drafts/*.test.js
import test from 'node:test';
import assert from 'node:assert/strict';
import { RETRIES, keepSlug, replaceable } from './slug.js';

const fromTitle = ['winter-garden-care'];

test('only a slug nobody typed may be replaced', () => {
    assert.equal(replaceable('', { fromTitle }), true);
    assert.equal(replaceable('  ', { fromTitle }), true);
    assert.equal(replaceable(null, { fromTitle }), true);
    assert.equal(replaceable('winter-garden-care', { fromTitle }), true, 'the field made it from the title');
    assert.equal(replaceable('old-draft', { fromTitle, ours: ['old-draft'] }), true, 'Ghostwriter set it before');
    assert.equal(replaceable('e2e-bdbbd773', { fromTitle }), false, 'typed');
    assert.equal(replaceable('winter-garden', { fromTitle }), false, 'typed');
});

/** A slug field and a clock run by hand. */
function field(value = '') {
    const timers = [];
    const state = {
        value,
        focused: false,
        sets: 0,
        later: (fn, ms) => timers.push({ fn, ms }),
        run(until) {
            timers.filter((t) => !t.done && t.ms <= until).forEach((t) => {
                t.done = true;
                t.fn();
            });
        },
    };

    return state;
}

function keep(f, options = {}) {
    return keepSlug({ slug: 'winter-garden-care-2', fromTitle, get: () => f.value, set: (slug) => { f.value = slug; f.sets++; }, typing: () => f.focused, later: f.later, ...options });
}

test('the address goes in, and again over the slug the field makes from the title', () => {
    const f = field('');
    keep(f);

    f.run(0);
    assert.equal(f.value, 'winter-garden-care-2');

    // Statamic's slug-from-title lands a moment later.
    f.value = 'winter-garden-care';
    f.run(400);
    assert.equal(f.value, 'winter-garden-care-2');
    assert.equal(f.sets, 2);
});

test('a slug typed while it settles is kept, whatever comes after', () => {
    const f = field('');
    keep(f);
    f.run(400);

    // Typed (the e2e happy path fills it and moves on) before the last retry.
    f.value = 'e2e-bdbbd773';
    f.run(RETRIES.at(-1));
    assert.equal(f.value, 'e2e-bdbbd773');

    // Even if the title's slug turned up again later, nothing is set any more.
    f.value = 'winter-garden-care';
    f.run(10_000);
    assert.equal(f.value, 'winter-garden-care');
});

test('a slug the person already had is never replaced', () => {
    const f = field('my-own-address');
    keep(f);
    f.run(10_000);
    assert.equal(f.value, 'my-own-address');
    assert.equal(f.sets, 0);
});

test('nothing is set while the field has focus', () => {
    const f = field('');
    f.focused = true;
    keep(f);
    f.run(1200);
    assert.equal(f.value, '');

    f.focused = false;
    f.run(2500);
    assert.equal(f.value, 'winter-garden-care-2');
});

test('the address Ghostwriter set before may be replaced by a newer one', () => {
    const f = field('first-draft');
    keep(f, { ours: ['first-draft'] });
    f.run(0);
    assert.equal(f.value, 'winter-garden-care-2');
});

test('stopping cancels the retries to come', () => {
    const f = field('');
    const stop = keep(f);
    f.run(0);
    stop();
    f.value = 'winter-garden-care';
    f.run(10_000);
    assert.equal(f.value, 'winter-garden-care');
});
