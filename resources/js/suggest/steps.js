// Suggest edits' state, kept apart from the DOM so it can be tested: the
// steps (one per suggestion, in form order, stale ones last), the category
// filters, the counts every part of the guide shows, the field tags, and
// what Accept all wording fixes takes.

export const WORDING = new Set(['voice', 'clarity', 'seo']);

/** Whether a step still needs a decision. */
export const isOpen = (step) => step.state === 'open' && !step.stale;

/**
 * The steps for a new answer from the server, keeping what this page view
 * knows that the server doesn't yet (accepted here, stale in the form).
 *
 * @param {object[]} suggestions  As the server shows them.
 * @param {Map<string, object>} local  By suggestion id: { state, text, stale }.
 */
export function stepsFrom(suggestions, local = new Map()) {
    const steps = suggestions.map((s) => {
        const mine = local.get(s.id);

        return { ...s, state: mine?.state ?? s.state, stale: mine?.stale ?? s.state === 'stale', text: mine?.text ?? null };
    });

    return [...steps.filter((step) => !step.stale), ...steps.filter((step) => step.stale)];
}

/** The steps a filter shows: every one for "all", else one category. */
export function visible(steps, filter = 'all') {
    return steps.map((step, i) => i).filter((i) => filter === 'all' || steps[i].category === filter);
}

/** The next open step after `from` in the filter, coming round to the start; steps.length when none is open. */
export function nextOpen(steps, from, filter = 'all') {
    const shown = visible(steps, filter);
    const after = shown.find((i) => i > from && isOpen(steps[i]));

    if (after !== undefined) return after;

    const before = shown.find((i) => isOpen(steps[i]));

    return before !== undefined ? before : steps.length;
}

/** The step before `from` in the filter, or `from` at the start. */
export function previous(steps, from, filter = 'all') {
    const shown = visible(steps, filter).filter((i) => i < from);

    return shown.length ? shown[shown.length - 1] : from;
}

/** The next step after `from` in the filter, open or not; steps.length past the end. */
export function following(steps, from, filter = 'all') {
    const next = visible(steps, filter).find((i) => i > from);

    return next === undefined ? steps.length : next;
}

/**
 * The filter buttons: All, then each category with its open count, in the
 * order the steps first name them; a category with none open is hidden
 * unless it's the one chosen.
 */
export function filters(steps, chosen = 'all') {
    const open = steps.filter(isOpen);
    const list = [{ key: 'all', label: null, count: open.length }];
    const seen = new Set();

    steps.forEach((step) => {
        if (seen.has(step.category)) return;

        seen.add(step.category);
        const count = open.filter((other) => other.category === step.category).length;

        if (count || chosen === step.category) list.push({ key: step.category, label: step.label, count });
    });

    return list;
}

/** The numbers the header menu, the dock and the end state show. */
export function counts(steps) {
    return {
        open: steps.filter(isOpen).length,
        accepted: steps.filter((step) => step.state === 'accepted' || step.state === 'done').length,
        dismissed: steps.filter((step) => step.state === 'dismissed').length,
        confirmed: steps.filter((step) => step.state === 'confirmed').length,
        stale: steps.filter((step) => step.stale).length,
        total: steps.length,
    };
}

/**
 * Each field's highlight and tag, by its form path: `current` when the
 * guide is on one of its steps, `open` with its open steps' numbers,
 * `done` when it had suggestions and none is open.
 *
 * @returns {Map<string, {state: string, steps: number[], step: object}>}
 */
export function fieldStates(steps, index) {
    const fields = new Map();

    steps.forEach((step, i) => {
        if (step.stale) return;

        const field = fields.get(step.dotted) ?? { state: 'done', steps: [], step: null, first: i };

        if (isOpen(step)) {
            field.steps.push(i);
            if (field.state === 'done') field.state = 'open';
        }

        if (i === index && isOpen(step)) {
            field.state = 'current';
            field.step = step;
        }

        field.step ??= step;
        fields.set(step.dotted, field);
    });

    return fields;
}

/** A field's tag: "2 · Voice" when current, "3–4" or "3" when open, "✓" when done. */
export function tagText(field, index, steps) {
    if (field.state === 'done') return '✓';
    if (field.state === 'current') return `${index + 1} · ${steps[index].label}`;

    const numbers = field.steps.map((i) => i + 1);

    return numbers.length > 1 ? `${numbers[0]}–${numbers[numbers.length - 1]}` : String(numbers[0]);
}

/**
 * What Accept all wording fixes takes in the filter: open Voice, Clarity
 * and SEO suggestions with words to put in. Never facts, links, alt text,
 * Out of date or Duplicate.
 */
export function wordingFixes(steps, filter = 'all') {
    return visible(steps, filter).filter((i) => isOpen(steps[i]) && WORDING.has(steps[i].category) && typeof steps[i].replacement === 'string' && steps[i].scope !== 'asset');
}

/** The versions "Another version" cycles through: the replacement, its alternatives, then any written since. */
export function versionsOf(step) {
    return [step.replacement, ...(step.alternatives ?? []), ...(step.versions ?? [])].filter((v, i, all) => typeof v === 'string' && v !== '' && all.indexOf(v) === i);
}

/**
 * A fact's answer put into its template, as core's FactCheck::fill():
 * a number or an amount must be one, and money keeps the template's
 * currency symbol. Throws Error('number-only') or Error('date-only').
 */
export function fillFact(fact, answer) {
    const value = String(answer ?? '').trim();
    const kind = fact?.answer ?? 'text';
    const ok = {
        number: /^\d{1,3}(?:[,.  ]\d{3})*(?:[.,]\d+)?$|^\d+(?:[.,]\d+)?$/u.test(value),
        money: /^[£$€]?\s?\d[\d,. ]*(?:k|m)?\s?€?$/iu.test(value),
        date: /\d/.test(value),
        text: value !== '',
    }[kind] ?? value !== '';

    if (!ok || value === '') throw new Error(kind === 'date' ? 'date-only' : 'number-only');

    const filled = kind === 'money' && /[£$€]/u.test(fact.template) ? value.replace(/[£$€]\s?/gu, '').trim() : value;

    return String(fact.template).replace('{answer}', filled);
}
