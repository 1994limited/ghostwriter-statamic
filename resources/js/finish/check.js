// Counts to check (`[[check: 3 areas | from: …]]`, core's GapKind::Check):
// which marker a gap names, and what each fix puts in its place. No DOM and
// no patterns here, so Node can test it (node --test resources/js/finish/*.test.js).

const words = (text) => String(text ?? '').toLowerCase().replace(/[^\p{L}\p{N}]+/gu, ' ').trim();

/**
 * The marker a gap names among those found in a value ({match, hint}): by
 * the marker as written (`meta.match`), else by its value, the gap's
 * occurrence-th of them; the first when the occurrence is gone.
 */
export function pickCheck(found, gap) {
    const exact = gap.meta?.match ? found.filter((m) => m.match === gap.meta.match) : [];
    const same = exact.length ? exact : found.filter((m) => words(m.hint) === words(gap.hint));

    return same[gap.occurrence ?? 0] ?? same[0] ?? null;
}

/**
 * What a fix puts where the marker was: "Looks right" its value (or the
 * new count's, for "Use “4 areas”"), "Change it" what was typed, "Remove
 * it" nothing. Null when there is nothing to put (an empty change).
 */
export function checkReplacement(gap, fix, value = null) {
    switch (fix.action) {
        case 'confirm':
            return String(fix.value ?? gap.hint ?? '');
        case 'change': {
            const typed = String(value ?? '').trim();

            return typed === '' ? null : typed;
        }
        case 'remove':
            return '';
        default:
            return null;
    }
}
