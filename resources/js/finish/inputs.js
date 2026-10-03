// Gap chips under plain text inputs on the publish form. Text inside an
// <input> can't be highlighted, so an input whose value holds a fact to add,
// a count to check or a link to choose gets a small row of chips under it
// ("Add: adult ticket price"), from core's markers.js. The guide's own
// field-level highlight and tag stay as they are; this only shows what the
// value holds. Display only: the row is outside the input and never part of
// its value.
import { chipRow, injectStyles } from '../preview/markers.js';

export const CONTROLS = 'input[type="text"], input:not([type])';

/**
 * Where a control's row goes: after the outermost wrapper that holds only
 * the control (Statamic wraps an input in a box or two), so the row sits
 * under the box, inside the field or the grid's cell.
 */
export function rowAnchor(control, stop = (element) => false) {
    let anchor = control;

    while (anchor.parentElement && !stop(anchor.parentElement) && elementChildren(anchor.parentElement).filter((child) => !isRow(child)).length === 1) {
        anchor = anchor.parentElement;
    }

    return anchor;
}

/**
 * Keeps a row of chips under every plain text input in `root` whose value
 * has gaps: now, as the editor types, and as rows and sets are added.
 * Returns {refresh(), stop()}.
 */
export function watchInputs(root, { labels = {}, doc = document } = {}) {
    const rows = new Map();
    const stop = (element) => element === root || element.matches?.('.form-group, td, th, [data-ghostwriter]');
    let queued = false;

    injectStyles(doc);

    const update = (control) => {
        const value = control.value ?? '';
        const existing = rows.get(control);

        if (existing?.value === value && existing.row?.isConnected !== false) return;

        existing?.row?.remove();
        const row = chipRow(doc, value, { labels });

        if (!row) {
            rows.delete(control);

            return;
        }

        rowAnchor(control, stop).after(row);
        rows.set(control, { value, row });
    };

    const refresh = () => {
        const controls = new Set(root.querySelectorAll(CONTROLS));

        controls.forEach(update);

        // Inputs that have gone (a row deleted, a set collapsed).
        rows.forEach((entry, control) => {
            if (!controls.has(control) || !control.isConnected) {
                entry.row?.remove();
                rows.delete(control);
            }
        });
    };

    const later = () => {
        if (queued) return;
        queued = true;
        requestAnimationFrame(() => {
            queued = false;
            refresh();
        });
    };

    const onInput = (event) => {
        if (event.target?.matches?.(CONTROLS)) update(event.target);
    };

    root.addEventListener('input', onInput, true);

    const observer = new MutationObserver((records) => {
        if (records.some((record) => ![...record.addedNodes, ...record.removedNodes].every((node) => isRow(node)))) later();
    });
    observer.observe(root, { childList: true, subtree: true });

    refresh();

    return {
        refresh: later,
        stop() {
            root.removeEventListener('input', onInput, true);
            observer.disconnect();
            rows.forEach((entry) => entry.row?.remove());
            rows.clear();
        },
    };
}

function isRow(node) {
    return node?.nodeType === 1 && node.hasAttribute?.('data-gw-gap-row');
}

function elementChildren(element) {
    return Array.from(element.children ?? []);
}
