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

import { canRead, findMarkers, locate, measure, watch, words } from './locator.js';

export const ERROR_META = 'ghostwriter-preview-error';

const STYLE = `
:host { all: initial; }
.layer { --s: 1; position: absolute; left: 0; top: 0; width: 0; height: 0; pointer-events: none; z-index: 2147483646; }
.outline { position: absolute; box-sizing: border-box; border: calc(2px / var(--s)) solid #5b4cf0; border-radius: calc(3px / var(--s)); background: rgba(91, 76, 240, 0.04); display: none; }
.outline.child { border-style: dashed; }
.label { position: absolute; left: calc(4px / var(--s)); top: calc(4px / var(--s)); max-width: calc(100% - 8px / var(--s)); overflow: hidden; text-overflow: ellipsis; white-space: nowrap;
  background: #5b4cf0; color: #fff; font: 500 11px/1.6 -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; font-size: calc(11px / var(--s)); letter-spacing: 0; padding: 0 calc(7px / var(--s)); border-radius: calc(4px / var(--s)); }
@media (forced-colors: active) { .outline { border-color: Highlight; } .label { background: Highlight; color: HighlightText; forced-color-adjust: none; } }
`;

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
    return String(doc?.title ?? '').replace(/[\u{E0000}-\u{E007F}]/gu, '').trim();
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

/** Cancels a click on a link (or anything inside one) in the frame. */
export function cancelLinks(event) {
    const target = event.target;
    const link = target?.closest ? target.closest('a[href], area[href]') : null;

    if (link) {
        event.preventDefault();
        event.stopPropagation();

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
 * refused); otherwise {result, title, missing, partial, measureAll(),
 * setScale(), boxes(), hover(), nearestTop(), scrollToBlock(), stop()}.
 */
export function attach(frame, map, { titleKey = null, scale = 1, onChange = () => {} } = {}) {
    if (!canRead(frame)) return null;

    const doc = frame.contentDocument;
    const win = doc.defaultView;
    const byKey = Object.fromEntries((map ?? []).map((block) => [block.key, block]));

    // Find and strip every marker, <head> included (the title's code is there).
    let marks = findMarkers(doc).marks;
    const titleInHead = titleKey !== null && marks.some((mark) => mark.key === titleKey && !doc.body?.contains(mark.element));
    let result = tighten(locate(doc, map, { marks }), marks, byKey);

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
    const setScale = (value) => layer.style.setProperty('--s', String(value > 0 ? value : 1));
    setScale(scale);
    const outline = doc.createElement('div');
    outline.className = 'outline';
    const label = doc.createElement('span');
    label.className = 'label';
    outline.append(label);
    layer.append(outline);
    root.append(style, layer);
    doc.documentElement.append(host);

    const measureAll = () => {
        state.boxes = result.regions.map((region) => ({
            key: region.key,
            depth: depthOf(byKey[region.key], byKey),
            box: measure(region, win),
        })).filter((entry) => entry.box && entry.box.width > 0 && entry.box.height > 0);

        draw();
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
        label.textContent = labelFor(block, byKey);
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
        measureAll,
        setScale,
        boxes: () => state.boxes.slice(),
        hover(key) {
            state.hovered = key;
            draw();
        },
        // The block nearest the top of the view, and how far below the top it starts.
        nearestTop() {
            const top = win.scrollY;
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

            if (entry) win.scrollTo(0, Math.max(0, entry.box.top - anchor.offset));
        },
        stop() {
            state.stopped = true;
            cleanups.forEach((cleanup) => cleanup());
            host.remove();
        },
    };
}
