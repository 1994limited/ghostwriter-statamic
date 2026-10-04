// The page preview's overlay: what the panel draws over the rendered draft in
// its same-origin frame. Core's locator (locator.js, copied as it is) finds
// and strips the invisible markers and groups the page into one region per
// block; this file draws the hover outline with the block's label, keeps it
// measured, and stops the page navigating away.
//
// - One overlay host per frame, appended to the frame's <html>, with a closed
//   shadow root (`:host { all: initial }`), so the site's CSS can't style it
//   and its scripts can't easily reach it. It is aria-hidden: the outlines
//   are visual only, and the page stays as accessible as the site made it.
//   Its DOM is built with textContent, never innerHTML with page data.
// - Boxes are in document coordinates (absolutely positioned), so they
//   scroll with the page. They are measured again when the frame resizes
//   (the Desktop/Phone switch), on scroll (for fixed and sticky regions),
//   when an image or iframe loads, when the fonts are ready, and when a
//   script adds marked text (core's watch(), then locate() again).
// - Link clicks in the frame are cancelled, in the capture phase, so the
//   preview never navigates away. Forms can't post: the frame's sandbox has
//   no allow-forms, and the preview's policy has `form-action 'none'`.
// - The title's marker is usually only in <title>: it is read from there for
//   the frame's bar, not listed as a block missing from the page.
// - Gap markers the site's templates print as they are (`[[ask: …]]`,
//   `[[check: …]]`, `#gw-link:` links) are shown as chips once the locator
//   has placed the blocks (core's markers.js, copied as it is), so each chip
//   is inside its block's region. With `onGap`, chips are buttons that open
//   the panel's gap popover; the frame itself never changes the draft.

import { canRead, findMarkers, locate, measure, watch, words } from './locator.js';
import { countByRegion, markGaps, toPlainText } from './markers.js';
import { findQuote, quoteOf } from './comments.js';

export const ERROR_META = 'ghostwriter-preview-error';

const STYLE = `
:host { all: initial; }
.layer { --s: 1; position: absolute; left: 0; top: 0; width: 0; height: 0; pointer-events: none; z-index: 2147483646; }
.outline { position: absolute; box-sizing: border-box; border: calc(2px / var(--s)) solid #5b4cf0; border-radius: calc(3px / var(--s)); background: rgba(91, 76, 240, 0.04); display: none; }
.outline.child { border-style: dashed; }
.label { position: absolute; left: calc(4px / var(--s)); top: calc(4px / var(--s)); max-width: calc(100% - 8px / var(--s)); overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
  background: #5b4cf0; color: #fff; font: 500 11px/1.6 -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; font-size: calc(11px / var(--s)); letter-spacing: 0; padding: 0 calc(7px / var(--s)); border-radius: calc(4px / var(--s)); }
@media (forced-colors: active) { .outline { border-color: Highlight; } .label { background: Highlight; color: HighlightText; forced-color-adjust: none; } }
.pin { position: absolute; pointer-events: auto; box-sizing: border-box; width: calc(26px / var(--s)); height: calc(26px / var(--s)); margin: 0; padding: 0; border-radius: calc(13px / var(--s)) calc(13px / var(--s)) calc(13px / var(--s)) calc(3px / var(--s));
  background: #f5a524; color: #1c1c20; border: calc(2px / var(--s)) solid #fff; box-shadow: 0 2px 6px rgba(0,0,0,.3); font: 600 12px/1 -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; font-size: calc(12px / var(--s)); cursor: pointer; display: flex; align-items: center; justify-content: center; }
.pin.sending { background: #9a9aa5; color: #fff; }
.pin.changed { background: #2f9e6b; color: #fff; }
.pin.replied { background: #5b4cf0; color: #fff; }
.pin.refused, .pin.skipped, .pin.failed { background: #fff; color: #b42318; border-color: #b42318; }
.pin.focused, .pin:focus-visible { outline: calc(3px / var(--s)) solid #5b4cf0; outline-offset: calc(1px / var(--s)); }
.changed { position: absolute; box-sizing: border-box; border: calc(2px / var(--s)) solid #2f9e6b; border-radius: calc(3px / var(--s)); pointer-events: none; }
.changed .chip { position: absolute; left: calc(4px / var(--s)); top: calc(-10px / var(--s)); background: #2f9e6b; color: #fff; font: 600 11px/1.6 -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; font-size: calc(11px / var(--s)); padding: 0 calc(7px / var(--s)); border-radius: calc(4px / var(--s)); white-space: nowrap; }
.changed.flash { animation: gw-flash 2.6s ease-out 1; }
@keyframes gw-flash { 0% { box-shadow: inset 0 0 0 3px #2f9e6b, 0 0 0 8px rgba(47,158,107,.35); } 100% { box-shadow: inset 0 0 0 3px rgba(47,158,107,0), 0 0 0 8px rgba(47,158,107,0); } }
@media (prefers-reduced-motion: reduce) { .changed.flash { animation: none; } }
.switched { position: absolute; box-sizing: border-box; border-radius: calc(4px / var(--s)); pointer-events: none; animation: gw-switched 2.2s ease-out 1 forwards; }
@keyframes gw-switched { 0%, 30% { box-shadow: inset 0 0 0 calc(3px / var(--s)) #5b4cf0, 0 0 0 calc(6px / var(--s)) rgba(91,76,240,.18); background: rgba(91,76,240,.06); } 100% { box-shadow: inset 0 0 0 calc(3px / var(--s)) rgba(91,76,240,0), 0 0 0 calc(6px / var(--s)) rgba(91,76,240,0); background: rgba(91,76,240,0); } }
@media (prefers-reduced-motion: reduce) { .switched { animation: none; box-shadow: inset 0 0 0 calc(3px / var(--s)) #5b4cf0; } }
.target { position: absolute; box-sizing: border-box; margin: 0; padding: 0; background: transparent; border: 0; opacity: 0; pointer-events: none; }
.target:focus { opacity: 1; outline: calc(3px / var(--s)) solid #5b4cf0; outline-offset: calc(-3px / var(--s)); }
.picked { position: absolute; box-sizing: border-box; border: calc(2px / var(--s)) solid #5b4cf0; border-radius: calc(3px / var(--s)); background: rgba(91, 76, 240, 0.06); pointer-events: none; }
@media (forced-colors: active) { .pin, .changed, .picked { forced-color-adjust: none; border-color: Highlight; } .switched { forced-color-adjust: none; animation: none; box-shadow: inset 0 0 0 3px Highlight; } .target:focus { outline-color: Highlight; } }
`;

/** The page's cursor while commenting: a style in the frame's own document (the preview only). */
const COMMENTING_STYLE = 'html[data-gw-commenting], html[data-gw-commenting] * { cursor: crosshair !important; } html[data-gw-commenting] ::selection { background: rgba(91, 76, 240, .25); }';

/**
 * What the frame's page says about a failed render: the meta the preview's
 * middleware writes ({status, message, exception?, file?, template?}), or null.
 */
export function previewError(doc) {
    const meta = doc?.querySelector?.(`meta[name="${ERROR_META}"]`);

    if (!meta) return null;

    try {
        return JSON.parse(meta.getAttribute('content') || '{}');
    } catch {
        return { message: '' };
    }
}

/** The page's title as the frame's bar shows it: the marker-free document title. */
export function pageTitle(doc) {
    return toPlainText(String(doc?.title ?? '').replace(/[\u{E0000}-\u{E007F}]/gu, '')).trim();
}

/**
 * The chips' words in the panel's language, for core's markers.js. `t` is
 * Statamic's __().
 */
export function gapLabels(t = (text) => text) {
    return {
        ask: t('Only you know this: add it before publishing'),
        check: t('Counted from \':list\'. Check it before publishing'),
        link: t('Link to choose'),
        askSpoken: t('Fact to add:'),
        checkSpoken: t('Count to check:'),
        linkSpoken: t('(link to choose)'),
        askRow: t('Add: :hint'),
        checkRow: t('Check: :hint'),
        linkRow: t('Choose a link: :hint'),
    };
}

/**
 * The innermost box under a point: the one with the deepest nesting, then
 * the smallest. `boxes` are {key, depth, box: {left, top, width, height}}.
 */
export function innermost(boxes, x, y) {
    let best = null;

    for (const entry of boxes) {
        const b = entry.box;

        if (!b || x < b.left || x > b.left + b.width || y < b.top || y > b.top + b.height) continue;

        if (!best || entry.depth > best.depth || (entry.depth === best.depth && b.width * b.height < best.box.width * best.box.height)) {
            best = entry;
        }
    }

    return best;
}

/** A block's label: its own, with its parent's for a child ("Pull quote · in Body"). */
export function labelFor(block, byKey) {
    const parent = block?.parent ? byKey[block.parent] : null;

    return parent ? `${block.label} · in ${parent.label}` : String(block?.label ?? '');
}

/**
 * A set inside rich text (a Bard set: its parent is a section or a field,
 * not a block) is only the elements that show it: those holding its own
 * marks, its images, or its words. Core's locator lets a bare region reach
 * back over the unmarked elements before it, which suits a section (its
 * heading and first paragraphs) but would give a photo or a pull quote the
 * paragraphs above it.
 */
export function tighten(result, marks, byKey) {
    for (const region of result.regions) {
        const block = byKey[region.key];
        const parent = block?.parent ? byKey[block.parent] : null;

        if (!parent || parent.kind === 'block') continue;

        const own = marks.filter((mark) => mark.key === region.key).map((mark) => mark.element);
        const stems = (block.assets ?? []).map((asset) => String(asset).replace(/\.[^.]+$/, '')).filter(Boolean);
        const anchors = (block.anchors ?? []).map((anchor) => ` ${words(anchor).join(' ')} `).filter((anchor) => anchor.trim() !== '');

        const shows = (element) => {
            if (own.some((mark) => element === mark || element.contains?.(mark))) return true;

            if (stems.length) {
                const images = [element, ...(element.querySelectorAll?.('img, source') ?? [])];
                const urls = images.flatMap((image) => ['src', 'srcset', 'data-src'].map((name) => image.getAttribute?.(name) ?? '')).join(' ');

                if (stems.some((stem) => urls.includes(stem))) return true;
            }

            const text = ` ${words(element.textContent ?? '').join(' ')} `;

            return anchors.some((anchor) => text.includes(anchor));
        };

        const kept = region.elements.filter(shows);

        if (kept.length) region.elements = kept;
    }

    return result;
}

/** How deep a block sits: 0 at the top. */
export function depthOf(block, byKey) {
    let depth = 0;
    let at = block;

    while (at?.parent && byKey[at.parent] && depth < 20) {
        depth += 1;
        at = byKey[at.parent];
    }

    return depth;
}

/**
 * Cancels a click on a link (or anything inside one) in the frame. A link
 * to choose that is a gap chip still gets its click, to open the popover.
 */
export function cancelLinks(event) {
    const target = event.target;
    const link = target?.closest ? target.closest('a[href], area[href]') : null;

    if (link) {
        event.preventDefault();
        if (link.getAttribute?.('data-gw-gap-active') == null) event.stopPropagation();

        return true;
    }

    return false;
}

/**
 * Runs `callback` after `wait` ms of quiet; `flush()` runs it now if pending,
 * `cancel()` drops it.
 */
export function debounce(callback, wait) {
    let timer = null;
    let args = [];

    const debounced = (...given) => {
        args = given;
        clearTimeout(timer);
        timer = setTimeout(() => {
            timer = null;
            callback(...args);
        }, wait);
    };

    debounced.flush = () => {
        if (timer === null) return;
        clearTimeout(timer);
        timer = null;
        callback(...args);
    };

    debounced.cancel = () => {
        clearTimeout(timer);
        timer = null;
    };

    debounced.pending = () => timer !== null;

    return debounced;
}

/**
 * Attaches the overlay to a loaded preview frame. `map` is the server's
 * BlockMap as JSON; `titleKey` the title field's key.
 *
 * `scale` is how much the frame is shown scaled down, so labels stay
 * readable. Returns null when the frame can't be read (cross-origin, or
 * refused); otherwise {result, title, missing, partial, gaps(),
 * gapCounts(), measureAll(), setScale(), boxes(), hover(), nearestTop(),
 * scrollToBlock(), stop()}. `labels` are the gap chips' words (gapLabels());
 * with `onGap(chip)`, a chip clicked (or Enter on it) calls it.
 *
 * Comments: `onPick({key, quote, rect, keyboard})` for a block clicked (or
 * Enter on its target) or words selected in comment mode, `rect` in the
 * frame's document coordinates; `onPin(number)` for a pin clicked;
 * `onEscape()` for Esc in comment mode. `commentLabels` are the targets'
 * and pins' words: {target(label, count), pin(number, state, label), click}.
 * The overlay then also has setComments({on, pins, changed}), flash(keys),
 * highlight(keys, {smooth}) (a layout just switched to),
 * setPicked(key), focusPin(number), focusTarget(key?), scrollToKey(key),
 * pinRect(number) and toViewport(rect).
 *
 * `reveal(top)` brings a point of the frame's document (its y, in the
 * frame's px) to the top of the view: the frame is as tall as its page and
 * the pane around it scrolls (framefit.js). Without it, the frame scrolls.
 */
export function attach(frame, map, { titleKey = null, scale = 1, labels = {}, onChange = () => {}, onGap = null, onPick = null, onPin = null, onEscape = null, commentLabels = {}, reveal = null } = {}) {
    if (!canRead(frame)) return null;

    const doc = frame.contentDocument;
    const win = doc.defaultView;
    const byKey = Object.fromEntries((map ?? []).map((block) => [block.key, block]));

    // Find and strip every marker, <head> included (the title's code is there).
    let marks = findMarkers(doc).marks;
    const titleInHead = titleKey !== null && marks.some((mark) => mark.key === titleKey && !doc.body?.contains(mark.element));
    let result = tighten(locate(doc, map, { marks }), marks, byKey);
    // Then the gap markers as chips, inside the regions just found.
    let chips = markGaps(doc, { labels, onActivate: onGap });

    const state = { hovered: null, boxes: [], stopped: false };
    const cleanups = [];

    // The overlay host, outside the body so the page's own layout never holds it.
    const host = doc.createElement('ghostwriter-preview-overlay');
    host.setAttribute('aria-hidden', 'true');
    host.style.cssText = 'position:absolute;left:0;top:0;width:0;height:0;margin:0;padding:0;border:0;';
    const root = host.attachShadow({ mode: 'closed' });
    const style = doc.createElement('style');
    style.textContent = STYLE;
    const layer = doc.createElement('div');
    layer.className = 'layer';
    // A frame shown scaled down (Desktop in a narrow pane) keeps its labels readable.
    const setScale = (value) => {
        layer.style.setProperty('--s', String(value > 0 ? value : 1));
        if (typeof comments !== 'undefined') {
            comments.scale = value > 0 ? value : 1;
            drawComments();
        }
    };
    layer.style.setProperty('--s', String(scale > 0 ? scale : 1));
    const outline = doc.createElement('div');
    outline.className = 'outline';
    const label = doc.createElement('span');
    label.className = 'label';
    outline.append(label);
    outline.setAttribute('aria-hidden', 'true');
    // Comments: the Changed marks and the picked block under the pins, the
    // keyboard's targets, then the pins on top.
    const marksLayer = doc.createElement('div');
    marksLayer.setAttribute('aria-hidden', 'true');
    const targetsLayer = doc.createElement('div');
    const pinsLayer = doc.createElement('div');
    layer.append(marksLayer, outline, targetsLayer, pinsLayer);
    root.append(style, layer);
    doc.documentElement.append(host);
    const cursor = doc.createElement('style');
    cursor.setAttribute('data-gw-commenting-style', '');
    cursor.textContent = COMMENTING_STYLE;
    (doc.head ?? doc.documentElement).append(cursor);

    const comments = { on: false, pins: [], changed: [], flashing: new Set(), switched: [], picked: null, target: null, scale: scale > 0 ? scale : 1 };
    const pinButtons = new Map();
    const targetButtons = new Map();
    const t = {
        target: (name, count) => (commentLabels.target ? commentLabels.target(name, count) : `${name} block, ${count} comments. Add a comment.`),
        pin: (number, state, name) => (commentLabels.pin ? commentLabels.pin(number, state, name) : `Comment ${number}, ${state}, on ${name}`),
        click: commentLabels.click ?? 'click to comment',
        changed: commentLabels.changed ?? 'Changed',
    };

    const measureAll = () => {
        state.boxes = result.regions.map((region) => ({
            key: region.key,
            depth: depthOf(byKey[region.key], byKey),
            box: measure(region, win),
        })).filter((entry) => entry.box && entry.box.width > 0 && entry.box.height > 0);

        draw();
        drawComments();
        onChange();
    };

    const draw = () => {
        const entry = state.hovered ? state.boxes.find((candidate) => candidate.key === state.hovered) : null;

        if (!entry) {
            outline.style.display = 'none';

            return;
        }

        const block = byKey[entry.key];
        outline.className = block?.parent ? 'outline child' : 'outline';
        outline.style.display = 'block';
        outline.style.left = `${entry.box.left}px`;
        outline.style.top = `${entry.box.top}px`;
        outline.style.width = `${entry.box.width}px`;
        outline.style.height = `${entry.box.height}px`;
        label.textContent = comments.on ? `${labelFor(block, byKey)} · ${t.click}` : labelFor(block, byKey);
    };

    const boxOf = (key) => state.boxes.find((candidate) => candidate.key === key) ?? null;

    // A point of the document to the top of the view.
    const scrollTo = (top, smooth = false) => (reveal ? reveal(Math.max(0, top), smooth) : win.scrollTo({ top: Math.max(0, top), behavior: smooth ? 'smooth' : 'auto' }));

    // Document coordinates of a viewport rect in the frame.
    const docRect = (rect) => ({ left: rect.left + win.scrollX, top: rect.top + win.scrollY, width: rect.width, height: rect.height });

    // The words of a comment on some, found again in its block.
    const quoteRect = (key, quote) => {
        const region = result.byKey?.[key];

        if (!region || !quote) return null;

        const nodes = [];
        let text = '';

        for (const element of region.elements) {
            const walker = doc.createTreeWalker(element, 4);

            for (let node = walker.nextNode(); node; node = walker.nextNode()) {
                nodes.push({ node, start: text.length });
                text += node.nodeValue;
            }
        }

        const found = findQuote(text, { exact: quote });

        if (!found) return null;

        const at = (offset) => {
            const hit = [...nodes].reverse().find((entry) => entry.start <= offset);

            return hit ? [hit.node, Math.min(offset - hit.start, hit.node.nodeValue.length)] : null;
        };

        try {
            const range = doc.createRange();
            const start = at(found.start);
            const end = at(found.end);

            if (!start || !end) return null;

            range.setStart(...start);
            range.setEnd(...end);

            const rects = [...range.getClientRects()].filter((rect) => rect.width > 0);
            const last = rects[rects.length - 1] ?? range.getBoundingClientRect();

            return last && last.height > 0 ? docRect(last) : null;
        } catch {
            return null;
        }
    };

    const place = (element, box) => {
        element.style.left = `${box.left}px`;
        element.style.top = `${box.top}px`;
        element.style.width = `${box.width}px`;
        element.style.height = `${box.height}px`;
    };

    // Keyed, so focus stays on a pin or target while the page is measured again.
    const sync = (buttons, wanted, parent, make) => {
        for (const [id, button] of buttons) {
            if (!wanted.has(id)) {
                button.remove();
                buttons.delete(id);
            }
        }

        for (const id of wanted.keys()) {
            if (!buttons.has(id)) {
                const button = make(id);
                buttons.set(id, button);
                parent.append(button);
            }
        }
    };

    const drawComments = () => {
        if (typeof pinsLayer === 'undefined') return;

        const unit = 1 / comments.scale;

        // Changed marks and the picked block.
        marksLayer.replaceChildren();

        for (const key of comments.changed) {
            const entry = boxOf(key);

            if (!entry) continue;

            const mark = doc.createElement('div');
            mark.className = comments.flashing.has(key) ? 'changed flash' : 'changed';
            place(mark, entry.box);
            const chip = doc.createElement('span');
            chip.className = 'chip';
            chip.textContent = t.changed;
            mark.append(chip);
            marksLayer.append(mark);
        }

        // A layout just switched to: what it changes, outlined for a moment.
        for (const key of comments.switched) {
            const entry = boxOf(key);

            if (!entry) continue;

            const mark = doc.createElement('div');
            mark.className = 'switched';
            place(mark, entry.box);
            marksLayer.append(mark);
        }

        if (comments.picked && boxOf(comments.picked)) {
            const picked = doc.createElement('div');
            picked.className = 'picked';
            place(picked, boxOf(comments.picked).box);
            marksLayer.append(picked);
        }

        // Pins, by number.
        const pins = new Map(comments.pins.filter((pin) => boxOf(pin.key)).map((pin) => [String(pin.number), pin]));
        sync(pinButtons, pins, pinsLayer, (id) => {
            const button = doc.createElement('button');
            button.type = 'button';
            button.addEventListener('click', (event) => {
                event.preventDefault();
                event.stopPropagation();
                onPin?.(Number(id));
            });
            button.addEventListener('keydown', (event) => {
                if (event.key === 'Escape') onEscape?.();
            });

            return button;
        });

        const perBlock = {};

        for (const [id, pin] of pins) {
            const button = pinButtons.get(id);
            const entry = boxOf(pin.key);
            const nth = (perBlock[pin.key] = (perBlock[pin.key] ?? -1) + 1);
            const words = pin.quote ? quoteRect(pin.key, pin.quote) : null;
            const left = words ? words.left + words.width + 2 * unit : entry.box.left + entry.box.width - (32 + nth * 30) * unit;
            const top = words ? words.top - 14 * unit : entry.box.top + 6 * unit;

            button.className = `pin ${pin.status}${comments.focused === pin.number ? ' focused' : ''}`;
            button.textContent = String(pin.number);
            button.style.left = `${Math.max(0, left)}px`;
            button.style.top = `${Math.max(0, top)}px`;
            button.setAttribute('aria-label', t.pin(pin.number, pin.state, pin.label || labelFor(byKey[pin.key], byKey)));
            button.title = button.getAttribute('aria-label');
            button.tabIndex = comments.on ? 0 : -1;
        }

        // The keyboard's targets: one per block on the page, in reading order, one Tab stop.
        const wanted = new Map(comments.on ? state.boxes.map((entry) => [entry.key, entry]) : []);
        sync(targetButtons, wanted, targetsLayer, (key) => {
            const button = doc.createElement('button');
            button.type = 'button';
            button.className = 'target';
            button.addEventListener('focus', () => {
                comments.target = key;
                state.hovered = key;
                draw();
                drawComments();
            });
            button.addEventListener('blur', () => {
                if (state.hovered === key) {
                    state.hovered = null;
                    draw();
                }
            });
            button.addEventListener('keydown', (event) => targetKey(event, key));

            return button;
        });

        const keys = [...wanted.keys()];

        if (!keys.includes(comments.target)) comments.target = keys[0] ?? null;

        for (const [key, entry] of wanted) {
            const button = targetButtons.get(key);
            const count = comments.pins.filter((pin) => pin.key === key).length;

            place(button, entry.box);
            button.tabIndex = key === comments.target ? 0 : -1;
            button.setAttribute('aria-label', t.target(labelFor(byKey[key], byKey), count));
        }
    };

    const targetKey = (event, key) => {
        const keys = [...targetButtons.keys()];
        const at = keys.indexOf(key);
        const moves = { ArrowDown: at + 1, ArrowRight: at + 1, ArrowUp: at - 1, ArrowLeft: at - 1, Home: 0, End: keys.length - 1 };

        if (event.key in moves) {
            event.preventDefault();
            const next = keys[Math.min(Math.max(moves[event.key], 0), keys.length - 1)];
            comments.target = next;
            drawComments();
            targetButtons.get(next)?.focus({ preventScroll: false });

            return;
        }

        if (event.key === 'Enter' || event.key === ' ') {
            event.preventDefault();
            const entry = boxOf(key);

            if (entry) onPick?.({ key, quote: null, rect: { ...entry.box, height: Math.min(entry.box.height, 40) }, keyboard: true });

            return;
        }

        if (event.key === 'Escape') {
            event.preventDefault();
            onEscape?.();
        }
    };

    // The deepest located block holding a node.
    const regionAt = (node) => {
        let best = null;

        for (const region of result.regions) {
            if (!boxOf(region.key)) continue;
            if (!region.elements.some((element) => element === node || element.contains?.(node))) continue;
            if (!best || depthOf(byKey[region.key], byKey) > depthOf(byKey[best.key], byKey)) best = region;
        }

        return best;
    };

    // Words selected in one block: a pick with their quote.
    const pickSelection = (keyboard = false) => {
        const selection = win.getSelection?.();

        if (!selection || selection.isCollapsed || !selection.rangeCount) return false;

        const range = selection.getRangeAt(0);
        const text = range.toString();

        if (!text.trim()) return false;

        const start = range.startContainer.nodeType === 1 ? range.startContainer : range.startContainer.parentElement;
        const region = regionAt(start);

        if (!region) return false;

        let before = '';
        let after = '';

        try {
            const head = doc.createRange();
            head.setStartBefore(region.elements[0]);
            head.setEnd(range.startContainer, range.startOffset);
            before = head.toString();
            const tail = doc.createRange();
            tail.setStart(range.endContainer, range.endOffset);
            tail.setEndAfter(region.elements[region.elements.length - 1]);
            after = tail.toString();
        } catch {
            // The words alone, then.
        }

        const quote = quoteOf(text, before, after);

        if (!quote) return false;

        onPick?.({ key: region.key, quote, rect: docRect(range.getBoundingClientRect()), keyboard });

        return true;
    };

    let frameRequested = false;
    const later = () => {
        if (frameRequested || state.stopped) return;
        frameRequested = true;
        (win.requestAnimationFrame ?? ((fn) => setTimeout(fn, 16)))(() => {
            frameRequested = false;
            if (!state.stopped) measureAll();
        });
    };

    const listen = (target, type, handler, options) => {
        target?.addEventListener?.(type, handler, options);
        cleanups.push(() => target?.removeEventListener?.(type, handler, options));
    };

    // Hover: the innermost block under the pointer, in document coordinates.
    listen(doc, 'mousemove', (event) => {
        const hit = innermost(state.boxes, event.clientX + win.scrollX, event.clientY + win.scrollY);
        const key = hit ? hit.key : null;

        if (key !== state.hovered) {
            state.hovered = key;
            draw();
        }
    });
    listen(doc.documentElement, 'mouseleave', () => {
        state.hovered = null;
        draw();
    });

    // Comment mode: a click or a selection is a pick, and nothing on the page reacts to it.
    const fromOverlay = (event) => event.target === host;

    listen(doc, 'click', (event) => {
        if (!comments.on || fromOverlay(event)) return;

        event.preventDefault();
        event.stopPropagation();
    }, true);
    listen(doc, 'mouseup', (event) => {
        if (!comments.on || fromOverlay(event) || event.button !== 0) return;

        if (pickSelection()) return;

        const x = event.clientX + win.scrollX;
        const y = event.clientY + win.scrollY;
        const hit = innermost(state.boxes, x, y);

        // The composer opens where the click was.
        if (hit) onPick?.({ key: hit.key, quote: null, rect: { left: x, top: y, width: 1, height: 1 }, keyboard: false });
    });
    // In comment mode the page's own links and fields take no focus: the blocks do.
    listen(doc, 'focusin', (event) => {
        if (comments.on && !fromOverlay(event)) setTimeout(() => targetButtons.get(comments.target)?.focus(), 0);
    });
    listen(doc, 'keydown', (event) => {
        if (!comments.on) return;

        if (event.altKey && event.shiftKey && event.code === 'KeyM') {
            event.preventDefault();
            pickSelection(true);
        } else if (event.key === 'Escape' && !fromOverlay(event)) {
            onEscape?.();
        }
    });

    // Never navigate away.
    listen(doc, 'click', cancelLinks, true);
    listen(doc, 'auxclick', cancelLinks, true);

    // Re-measure: size, scroll (fixed and sticky regions), images and frames loading, fonts.
    if (win.ResizeObserver) {
        const observer = new win.ResizeObserver(later);
        observer.observe(doc.documentElement);
        if (doc.body) observer.observe(doc.body);
        cleanups.push(() => observer.disconnect());
    }
    listen(win, 'resize', later);
    listen(win, 'scroll', later, { passive: true });
    listen(doc, 'load', later, true);
    doc.fonts?.ready?.then(later).catch(() => {});
    listen(doc.fonts, 'loadingdone', later);

    // Scripts that print more marked text: locate again with every mark so far.
    const watcher = watch(doc, (found) => {
        marks = [...marks, ...found];
        result = tighten(locate(doc, map, { marks }), marks, byKey);
        chips = markGaps(doc, { labels, onActivate: onGap });
        later();
    });
    cleanups.push(() => watcher.stop());

    measureAll();

    const missing = () => result.missing.filter((key) => key !== titleKey || !titleInHead);

    return {
        get result() {
            return result;
        },
        title: pageTitle(doc),
        titleInHead,
        missing,
        partial: () => result.partial,
        // The gap chips on the page ({kind, hint, element}), and how many are in each block.
        gaps: () => chips.slice(),
        gapCounts: () => countByRegion(result.regions, chips),
        measureAll,
        setScale,
        boxes: () => state.boxes.slice(),
        hover(key) {
            state.hovered = key;
            draw();
        },
        // The block nearest the top of the view (`top`, in the document; the frame's own scroll by default), and how far below the top it starts.
        nearestTop(top = win.scrollY) {
            let best = null;

            for (const entry of state.boxes) {
                if (entry.depth > 0) continue;
                const distance = Math.abs(entry.box.top - top);
                if (!best || distance < best.distance) best = { key: entry.key, offset: entry.box.top - top, distance };
            }

            return best ? { key: best.key, offset: best.offset } : null;
        },
        scrollToBlock(anchor) {
            const entry = anchor ? state.boxes.find((candidate) => candidate.key === anchor.key) : null;

            if (entry) scrollTo(entry.box.top - anchor.offset);
        },
        // Comments: the mode, the pins ({number, key, status, state, label, quote}) and the changed blocks.
        setComments({ on = comments.on, pins = comments.pins, changed = comments.changed } = {}) {
            const was = comments.on;
            comments.on = Boolean(on);
            comments.pins = pins ?? [];
            comments.changed = changed ?? [];

            if (comments.on !== was) {
                if (comments.on) {
                    host.removeAttribute('aria-hidden');
                    doc.documentElement.setAttribute('data-gw-commenting', '');
                } else {
                    host.setAttribute('aria-hidden', 'true');
                    doc.documentElement.removeAttribute('data-gw-commenting');
                    comments.picked = null;
                }

                draw();
            }

            drawComments();
        },
        // The changed blocks flash once (a static outline under reduced motion).
        flash(keys) {
            keys.forEach((key) => comments.flashing.add(key));
            drawComments();
            setTimeout(() => {
                keys.forEach((key) => comments.flashing.delete(key));
            }, 2700);
        },
        // A layout just switched to: its changed blocks outlined, fading over
        // about two seconds (a still outline for that long under reduced
        // motion), and the first brought into view.
        highlight(keys, { smooth = true } = {}) {
            clearTimeout(comments.unswitch);
            comments.switched = keys.filter((key) => boxOf(key));
            drawComments();

            if (comments.switched.length) scrollTo(boxOf(comments.switched[0]).box.top - 60, smooth);

            comments.unswitch = setTimeout(() => {
                comments.switched = [];
                drawComments();
            }, 2200);

            return comments.switched.length;
        },
        setPicked(key) {
            comments.picked = key ?? null;
            drawComments();
        },
        focusPin(number) {
            comments.focused = number;
            drawComments();
            const button = pinButtons.get(String(number));

            if (button) {
                const entry = boxOf(comments.pins.find((pin) => pin.number === number)?.key);
                if (entry) scrollTo(entry.box.top - 60);
                button.focus({ preventScroll: true });
            }

            return Boolean(button);
        },
        focusTarget(key = null) {
            if (key) comments.target = key;
            drawComments();
            targetButtons.get(comments.target)?.focus();
        },
        scrollToKey(key) {
            const entry = boxOf(key);

            if (entry) scrollTo(entry.box.top - 60);
        },
        pinRect(number) {
            return pinButtons.get(String(number)) ? docRect(pinButtons.get(String(number)).getBoundingClientRect()) : null;
        },
        // A rect in the frame's document as the frame's viewport shows it now.
        toViewport(rect) {
            return rect ? { left: rect.left - win.scrollX, top: rect.top - win.scrollY, width: rect.width, height: rect.height } : null;
        },
        stop() {
            state.stopped = true;
            cleanups.forEach((cleanup) => cleanup());
            doc.documentElement.removeAttribute('data-gw-commenting');
            cursor.remove();
            host.remove();
        },
    };
}
