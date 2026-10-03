// The guide's state, kept apart from the DOM so it can be tested: one live
// list of gaps, straight from the latest check, drives the count by Save,
// the guide's "2 of 5", its progress bar and every field's highlight.
//
// - Steps are the gaps the last check found, nothing else: a gap that has
//   gone is no longer a step, and a fix that makes a new gap (a placeholder
//   swapped for a stock preview) gives a new, open step.
// - Gaps that count (they block, or the CMS requires them) come first; the
//   suggestions follow and are numbered separately.
// - A field is "fixed" only when it had gaps in this view and the last check
//   found none left in it.

export const isSuggestion = (gap) => gap.severity === 'suggestion';

/**
 * The steps for a new check: the live gaps, counted ones first, each open
 * or skipped (as remembered), in form order.
 *
 * @param {{gaps?: object[]}} report
 * @param {{skipped?: Set<string>, dismissed?: Set<string>}} memory
 */
export function stepsFrom(report, { skipped = new Set(), dismissed = new Set() } = {}) {
    const live = (report.gaps ?? []).filter((gap) => !dismissed.has(gap.id));
    const counted = live.filter((gap) => !isSuggestion(gap));
    const suggested = live.filter(isSuggestion);

    return [...counted, ...suggested].map((gap) => ({ gap, status: skipped.has(gap.id) ? 'skipped' : 'open' }));
}

/**
 * Where the guide stands after a new check: on the same gap if it is still
 * there; otherwise on whatever now sits at its place (the next gap moved
 * up), or the first open one.
 */
export function currentAfter(steps, previousId, previousIndex) {
    const same = steps.findIndex((step) => step.gap.id === previousId);

    if (same >= 0) return same;
    if (previousIndex < steps.length) return Math.max(0, previousIndex);

    return firstOpen(steps);
}

export function firstOpen(steps) {
    const open = steps.findIndex((step) => step.status === 'open');

    return open >= 0 ? open : steps.length;
}

/** The next open step from `from`, coming round to the start; the end when none is open. */
export function nextOpen(steps, from) {
    for (let i = from; i < steps.length; i++) {
        if (steps[i].status === 'open') return i;
    }

    for (let i = 0; i < Math.min(from, steps.length); i++) {
        if (steps[i].status === 'open') return i;
    }

    return steps.length;
}

/**
 * The numbers every part of the guide shows, from the one live list:
 * `count` for the pill and the dock (what blocks or is required), `total`
 * for "n of total" (the same gaps), and the step's own number.
 */
export function counts(steps, index) {
    const counted = steps.filter((step) => !isSuggestion(step.gap));
    const suggestions = steps.length - counted.length;
    const step = steps[index];
    const suggestion = step ? isSuggestion(step.gap) : false;

    return {
        count: counted.length,
        total: counted.length,
        suggestions,
        number: step ? (suggestion ? index - counted.length + 1 : index + 1) : 0,
        suggestion,
        skipped: counted.filter((step) => step.status === 'skipped').length,
    };
}

/**
 * Each field's highlight and tag, by its form path: `current` when the
 * guide is on one of its gaps (numbered by that step), else `open` with its
 * steps' numbers ("4" or "4–6"); `fixed` for a field that had gaps in this
 * view and has none left.
 *
 * @param {Array} steps
 * @param {number} index  The current step, or -1 when none is shown.
 * @param {Set<string>} touched  Every field that had a gap in this view.
 * @returns {Map<string, {state: string, steps: number[], step: object|null}>}
 */
export function fieldStates(steps, index, touched = new Set()) {
    const fields = new Map();

    steps.forEach((step, i) => {
        const key = step.gap.dotted;
        const field = fields.get(key) ?? { state: 'open', steps: [], step: null };

        field.steps.push(i);

        if (i === index) {
            field.state = 'current';
            field.step = step;
        } else if (!field.step) {
            field.step = step;
        }

        fields.set(key, field);
    });

    touched.forEach((key) => {
        if (!fields.has(key)) fields.set(key, { state: 'fixed', steps: [], step: null });
    });

    return fields;
}

/** A field's tag: "7 · License me" when current, "4 · Fill this in" or "4–6 · 3 to do" otherwise. */
export function tagText(field, steps, t) {
    if (field.state === 'fixed') return t('Fixed ✓');

    const numbers = field.steps.map((i) => (isSuggestion(steps[i].gap) ? null : i + 1)).filter((n) => n !== null);
    const speech = field.step?.gap.speech ?? '';

    if (field.state === 'current' || numbers.length <= 1) {
        const n = field.state === 'current' ? field.steps.find((i) => steps[i] === field.step) + 1 : numbers[0];

        return n && !isSuggestion(field.step.gap) ? `${n} · ${speech}` : speech;
    }

    return `${numbers[0]}–${numbers[numbers.length - 1]} · ${t(':count to do', { count: numbers.length })}`;
}
