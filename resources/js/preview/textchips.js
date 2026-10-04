// Gap chips in the Text tab's writing, which is always editable in place.
//
// A piece of writing holding a marker (`[[ask: …]]`, `[[check: …]]`, a
// `#gw-link:` link) is shown in its read view with the markers as chips
// (core's markers.js), and the chips are buttons that open the gap popover.
// While it's in that read view it can be focused (Tab) but isn't editable,
// so Tab goes on to its chips. Clicking its words, or Enter, F2 or typing,
// starts editing it: the chips are put back as the raw markers, exactly as
// stored, so the person sees and edits what's there, and nothing they save
// can hold a chip's markup (readBack() unmarks, to be sure).
//
// Writing without markers is never touched.

import { markGaps, unmarkGaps } from './markers.js';

/** Whether some stored writing holds anything to show as a chip. */
export function hasMarkers(raw) {
    return typeof raw === 'string' && (raw.includes('[[') || raw.includes('#gw-link:'));
}

/** Whether the element is showing its read view, with chips. */
export function isPainted(element) {
    return element?.dataset?.gwChips !== undefined;
}

/**
 * Shows `raw` with its markers as chips. `html`: raw is HTML (rich text),
 * else plain words. `onGap(found)` is called when a chip is activated.
 * Returns whether there were chips to show.
 */
export function paint(element, raw, { html = false, labels = {}, onGap = null } = {}) {
    if (!hasMarkers(raw)) return false;
    if (isPainted(element) && element.dataset.gwChips === raw) return true;

    if (element.dataset.gwEditable === undefined) element.dataset.gwEditable = element.getAttribute('contenteditable') ?? '';

    if (html) element.innerHTML = raw;
    else element.textContent = raw;

    const chips = markGaps(element, { labels, onActivate: onGap ? (found) => onGap(found) : null });

    if (!chips.length) {
        restoreEditable(element);

        return false;
    }

    for (const found of chips) {
        found.element.setAttribute('contenteditable', 'false');
        // A click on a chip opens it; it never starts editing the words around it.
        found.element.addEventListener('mousedown', (event) => event.preventDefault());
    }

    element.dataset.gwChips = raw;

    if (element.dataset.gwEditable !== '') {
        element.setAttribute('contenteditable', 'false');
        element.setAttribute('tabindex', '0');
    }

    return true;
}

/**
 * The read view put back as the stored writing, raw markers and all, and
 * editable again. With `point` ({x, y}), the caret goes where it was
 * clicked; otherwise at the end.
 */
export function startEditing(element, raw, { html = false, point = null } = {}) {
    if (!isPainted(element)) return;

    if (html) element.innerHTML = raw;
    else element.textContent = raw;

    delete element.dataset.gwChips;
    restoreEditable(element);
    element.focus();

    const doc = element.ownerDocument;
    const selection = doc.getSelection?.();
    let range = point && doc.caretRangeFromPoint ? doc.caretRangeFromPoint(point.x, point.y) : null;

    if (!range || !element.contains(range.startContainer)) {
        range = doc.createRange();
        range.selectNodeContents(element);
        range.collapse(false);
    }

    selection?.removeAllRanges();
    selection?.addRange(range);
}

/** The read view taken away (another tab): the stored writing, as Vue drew it. */
export function unpaint(element, raw, { html = false } = {}) {
    if (!isPainted(element)) return;

    if (html) element.innerHTML = raw;
    else element.textContent = raw;

    delete element.dataset.gwChips;
    restoreEditable(element);
}

/** What the element holds, as it is saved: never a chip's markup. */
export function readBack(element, html = false) {
    let source = element;

    if (element.querySelector?.('.gw-gap')) {
        source = element.cloneNode(true);
        unmarkGaps(source);

        return html ? source.innerHTML : source.textContent.replace(/\n$/, '');
    }

    return html ? source.innerHTML : source.innerText.replace(/\n$/, '');
}

function restoreEditable(element) {
    const editable = element.dataset.gwEditable;

    if (editable === undefined) return;

    if (editable === '') {
        element.removeAttribute('contenteditable');
    } else {
        element.setAttribute('contenteditable', editable);
        element.removeAttribute('tabindex');
    }

    delete element.dataset.gwEditable;
}

/**
 * The directive: `v-gw-chips="{ raw, html, on, labels, gap }"`. While `on`
 * (the Text tab) and the element isn't being edited, its markers show as
 * chips; `gap(found, element)` opens the popover.
 */
export const chipsDirective = {
    mounted: (element, binding) => apply(element, binding.value),
    updated: (element, binding) => apply(element, binding.value),
};

function apply(element, { raw, html = false, on = true, labels = {}, gap = null } = {}) {
    if (!on) {
        unpaint(element, raw, { html });

        return;
    }

    // Being edited: left as it is.
    if (element.ownerDocument.activeElement === element && !isPainted(element)) return;

    if (isPainted(element) && element.dataset.gwChips !== raw) delete element.dataset.gwChips;

    paint(element, raw, { html, labels, onGap: gap ? (found) => gap(found, element) : null });
}

/**
 * A key on a read view: Enter or F2 starts editing; so does typing, the
 * key typed. Returns true when it started editing.
 */
export function editKey(event, element, raw, html = false) {
    if (!isPainted(element) || event.target !== element || event.isComposing) return false;

    const typing = event.key.length === 1 && !event.ctrlKey && !event.metaKey && !event.altKey;

    if (event.key !== 'Enter' && event.key !== 'F2' && !typing) return false;

    event.preventDefault();
    startEditing(element, raw, { html });

    if (typing) element.ownerDocument.execCommand?.('insertText', false, event.key);

    return true;
}

/** A press on a read view's words (not a chip): starts editing there. */
export function editPress(event, element, raw, html = false) {
    if (!isPainted(element) || event.button !== 0 || event.target.closest?.('.gw-gap')) return false;

    event.preventDefault();
    startEditing(element, raw, { html, point: { x: event.clientX, y: event.clientY } });

    return true;
}
