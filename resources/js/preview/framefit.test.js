// The preview's frame, as tall as its page: node --test resources/js/preview/*.test.js
import test from 'node:test';
import assert from 'node:assert/strict';
import { MAX_HEIGHT, fitFrame, followed, following, frameHeight, isWindowHeight, usesViewport } from './framefit.js';

test('heights from the window’s height are spotted in any of its units', () => {
    ['100vh', '100svh', 'calc(100dvh - 4rem)', '50lvh', '80vmin', '.5vh', '100vb'].forEach((value) => assert.equal(usesViewport(value), true, value));
    ['100%', '100vw', '400px', 'auto', '', null, 'var(--hero)'].forEach((value) => assert.equal(usesViewport(value), false, String(value)));
});

test('the frame is as tall as its page, never under the pane, never over the cap', () => {
    assert.equal(frameHeight(2400.4, 700), 2401);
    assert.equal(frameHeight(300, 700), 700);
    assert.equal(frameHeight(90000, 700), MAX_HEIGHT);
    assert.equal(frameHeight(5000, 700, 4000), 4000);
});

test('min- and max-height are pinned before height, and a scrolling box’s height never', () => {
    const at = { height: '700px', 'min-height': '700px', 'max-height': 'none' };

    assert.deepEqual(followed(at, { ...at, height: '940px', 'min-height': '940px' }), ['min-height']);
    assert.deepEqual(followed(at, { ...at, height: '940px' }), ['height']);
    assert.deepEqual(followed(at, { ...at, height: '940px' }, { scrolls: true }), []);
    assert.deepEqual(followed(at, at), []);
});

test('a script’s height is an inline px value equal to the window’s', () => {
    assert.equal(isWindowHeight('700px', 700), true);
    assert.equal(isWindowHeight('700.5px', 700), true);
    assert.equal(isWindowHeight('720px', 700), false);
    assert.equal(isWindowHeight('100%', 700), false);
});

test('only growths by the same amount, close together, make a run', () => {
    let run = following([], { at: 0, by: 300 });
    run = following(run, { at: 100, by: 300 });
    assert.equal(run.length, 2);
    assert.equal(following(run, { at: 200, by: 80 }).length, 1, 'An image loading grows it by something else.');
    assert.equal(following(run, { at: 9000, by: 300 }).length, 1, 'Long after: a new run.');
});

// ---- A page in a frame, laid out by a formula ------------------------------

/**
 * A page whose hero is `min-height: 100vh` (or, with `script`, a height a
 * script set to the window's), with `rest` px of page under it.
 */
function page({ rest = 1500, script = false, follows = false } = {}) {
    const frame = { style: { height: '' }, attributes: {}, setAttribute(name, value) { this.attributes[name] = value; } };
    const viewport = () => parseFloat(frame.style.height) || 0;
    let sheet = '';
    const pinned = (element, property) => {
        const id = element.attrs['data-gw-fit'];
        const rule = id === undefined ? null : sheet.split('\n').find((line) => line.startsWith(`[data-gw-fit="${id}"]`));
        const match = rule && new RegExp(`[{;]${property}:([^ ;]+) !important`).exec(rule);

        return match ? match[1] : null;
    };
    const element = (tag, extra = {}) => ({
        tagName: tag.toUpperCase(),
        attrs: {},
        style: { getPropertyValue: () => '' },
        setAttribute(name, value) { this.attrs[name] = value; },
        removeAttribute(name) { delete this.attrs[name]; },
        closest: () => null,
        ...extra,
    });

    const hero = element('section');
    let scripted = 0;
    hero.style = { getPropertyValue: (property) => (script && property === 'height' ? `${scripted}px` : '') };
    const heroHeight = () => {
        if (script) return parseFloat(pinned(hero, 'height') ?? `${scripted}`);

        return parseFloat(pinned(hero, 'min-height') ?? `${viewport()}`);
    };
    Object.defineProperty(hero, 'offsetHeight', { get: heroHeight });

    const html = element('html');
    const body = element('body');
    Object.defineProperty(html, 'scrollHeight', { get: () => Math.max(viewport(), heroHeight() + rest) });
    Object.defineProperty(body, 'scrollHeight', { get: () => heroHeight() + rest });
    Object.defineProperty(html, 'offsetHeight', { get: () => heroHeight() + rest });
    Object.defineProperty(body, 'offsetHeight', { get: () => heroHeight() + rest });

    const observers = [];
    const win = {
        getComputedStyle: (el) => (el === hero
            ? { height: `${heroHeight()}px`, minHeight: script ? '0px' : `${heroHeight()}px`, maxHeight: 'none', overflowY: 'visible' }
            : { height: `${el.offsetHeight}px`, minHeight: '0px', maxHeight: 'none', overflowY: 'visible' }),
        ResizeObserver: class { constructor(callback) { observers.push(callback); } observe() {} disconnect() {} },
        requestAnimationFrame: (callback) => callback(),
    };
    const doc = {
        defaultView: win,
        documentElement: html,
        body,
        head: { appendChild: (style) => (style.parent = true) },
        styleSheets: script ? [] : [{ cssRules: [{ selectorText: '.hero', style: { getPropertyValue: (property) => (property === 'min-height' ? '100vh' : '') } }] }],
        querySelectorAll: (selector) => (selector === '.hero' ? [hero] : selector === '[style]' ? (script ? [hero] : []) : selector === '*' ? [html, body, hero] : []),
        createElement: () => ({ setAttribute() {}, remove() {}, set textContent(text) { sheet = text; }, get textContent() { return sheet; } }),
        addEventListener() {},
        removeEventListener() {},
    };
    frame.contentDocument = doc;

    return {
        frame,
        hero,
        heroHeight,
        // A script that sizes the hero to the window, as on a resize event.
        resize: () => {
            if (follows) scripted = viewport();
            observers.forEach((callback) => callback());
        },
        setScripted: (value) => (scripted = value),
    };
}

test('a 100vh hero is pinned at the pane’s height, and the frame is as tall as the page', () => {
    const p = page();
    const heights = [];
    const fit = fitFrame(p.frame, { viewport: 700, onHeight: (height) => heights.push(height) });

    assert.equal(fit.height, 2200);
    assert.equal(p.frame.style.height, '2200px');
    assert.equal(p.heroHeight(), 700, 'The hero stays at the pane’s height in the taller frame.');
    assert.equal(p.frame.attributes.scrolling, 'no');

    // Measuring again at the same height changes nothing.
    fit.measure();
    p.resize();
    assert.deepEqual(heights, [2200]);
});

test('a script that sized the hero to the window is pinned before the frame grows', () => {
    const p = page({ script: true, follows: true });
    p.setScripted(700);
    const heights = [];
    const fit = fitFrame(p.frame, { viewport: 700, onHeight: (height) => heights.push(height) });

    assert.equal(fit.height, 2200);

    // The frame's resize runs the script, which sizes the hero to the new window: pinned, it can't.
    p.resize();
    p.resize();
    assert.equal(p.heroHeight(), 700);
    assert.deepEqual(heights, [2200]);
});

test('a page that follows its frame anyway stops growing, well before the cap', () => {
    // The script's height isn't the window's at first, so nothing pins it.
    const p = page({ script: true, follows: true });
    p.setScripted(650);
    const warn = console.warn;
    console.warn = () => {};
    const fit = fitFrame(p.frame, { viewport: 700 });

    for (let i = 0; i < 40; i += 1) p.resize();
    console.warn = warn;

    assert.ok(fit.height < 20000, `Stopped at ${fit.height}.`);
});

test('a new pane height pins again at it', () => {
    const p = page();
    const fit = fitFrame(p.frame, { viewport: 700 });

    fit.setViewport(500);
    assert.equal(p.heroHeight(), 500);
    assert.equal(fit.height, 2000);
});
