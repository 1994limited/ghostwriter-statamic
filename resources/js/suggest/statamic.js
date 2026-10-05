// Statamic's side of Suggest edits: where a suggestion's field is on the
// publish form, and how each change goes into the form's state, never a
// save. Bard goes through its editor (suggest/bard.js); text, textarea and
// Markdown values are strings changed where the quote is; SEO Pro keeps
// each value as `{source, value}`; a Link field uses Finish this page's
// own route. The editor checks and saves as usual.
import { unref } from 'vue';
import { findQuote, rangeIn } from './quote.js';
import { plain } from './markdown.js';
import { bardField, editorAt, setRanges } from './bard.js';
import { seoText, seoValue } from '../finish/seo.js';

const idOf = (dotted) => `field_${String(dotted).replace(/\./g, '_')}`;
const still = () => window.matchMedia('(prefers-reduced-motion: reduce)').matches;
const frames = (n = 2) => new Promise((resolve) => {
    const step = (left) => (left ? requestAnimationFrame(() => step(left - 1)) : resolve());
    step(n);
});
const clone = (value) => (value === undefined ? undefined : JSON.parse(JSON.stringify(value)));

export function suggestAdapter({ form, finish }) {
    const values = () => unref(form.values) ?? {};
    const valueAt = (dotted) => String(dotted).split('.').reduce((value, key) => (value == null ? undefined : value[key]), values());
    const set = (dotted, value) => form.setFieldValue(dotted, value);
    const isBard = (step) => !!editorAt(step.dotted);

    // The field, or the nearest one around it (SEO Pro's description is
    // inside its `seo` field).
    const locate = (step) => {
        const parts = String(step.dotted ?? '').split('.');
        // Only a value inside a field of its own (SEO Pro's description in
        // `seo`) falls back to the field around it, never a set's field.
        const floor = step.seo?.pro ? 1 : parts.length;

        while (parts.length >= floor && parts.length) {
            const dotted = parts.join('.');
            const label = document.querySelector(`label[for="${CSS.escape(idOf(dotted))}"]`);
            const field = label?.closest('.form-group') ?? document.getElementById(idOf(dotted))?.closest('.form-group');

            if (field) return field;

            parts.pop();
        }

        return null;
    };

    const reveal = async (step) => {
        const field = locate(step);

        if (!field) return false;

        Statamic.$reveal?.element(field);
        await frames(2);

        if (isBard(step) && step.quote) bardField(step.dotted).select(step.quote, step.occurrence);

        field.scrollIntoView({ block: window.matchMedia('(max-width: 639px)').matches ? 'start' : 'center', behavior: still() ? 'auto' : 'smooth' });
        await new Promise((resolve) => setTimeout(resolve, still() ? 0 : 350));

        return !!field.offsetParent;
    };


    /** The words a step is about, as the field holds them now. */
    const current = (step) => {
        if (step.scope === 'asset') return step.asset?.alt ?? '';
        if (isBard(step)) return null;

        const value = valueAt(step.dotted);

        return step.seo?.pro ? seoText(value) : typeof value === 'string' ? value : value == null ? '' : null;
    };

    /** Whether a step's words are still where it said (a range), or its field unchanged (a whole value). */
    const present = (step) => {
        if (step.scope === 'asset') return true;
        if (step.scope === 'field') return true;
        if (isBard(step)) return bardField(step.dotted).has(step.quote, step.occurrence);
        // A Bard field whose editor isn't drawn yet: can't tell, so not stale.
        if (step.fieldType === 'bard') return true;

        const text = current(step);

        return typeof text === 'string' && !!findQuote(step.quote, text, step.occurrence, step.fieldType === 'markdown');
    };

    /** Whether the words a step would put in are there already (accepted in another tab, or typed). */
    const applied = (step, words) => {
        if (!words || step.scope === 'asset') return false;

        const text = isBard(step) ? editorAt(step.dotted)?.state.doc.textContent ?? '' : current(step) ?? '';
        const added = plain(words);

        // Words that were in the old ones already count only once the old
        // words have gone (as core's Reconciler has it).
        return text.includes(added) && (!present(step) || (step.quote?.exact && added.includes(step.quote.exact)));
    };

    // A string value with the quote replaced; null when it isn't there.
    const spliced = (step, text, words) => {
        const match = findQuote(step.quote, text, step.occurrence, step.fieldType === 'markdown');
        const range = rangeIn(text, match);

        if (!range) return null;

        return text.slice(0, range[0]) + words + text.slice(range[1]);
    };

    // SEO Pro's custom value, or the text (finish/seo.js: the same rule as the server's StatamicSeoWriter).
    const writeString = (step, next) => set(step.dotted, step.seo ? seoValue(valueAt(step.dotted), next, step.seo.pro) : next);

    /**
     * Puts words in for a step: the quote's range, or the whole value. The
     * replacement is inline markdown, real marks and links in Bard and the
     * words alone in a text field. Returns what Undo needs, or null.
     */
    const replace = (step, markdown) => {
        if (step.scope === 'range' && isBard(step)) {
            const token = bardField(step.dotted).replace(step.quote, step.occurrence, markdown);

            return token ? { kind: 'bard', token } : null;
        }

        const before = clone(valueAt(step.dotted));
        const text = current(step);

        if (text === null) return null;

        const words = step.fieldType === 'markdown' ? markdown : plain(markdown);
        const next = step.scope === 'field' ? words : spliced(step, text, words);

        if (next === null) return null;

        writeString(step, next);

        return { kind: 'value', before };
    };

    const undo = (step, change) => {
        if (!change) return false;

        if (change.kind === 'bard') return bardField(step.dotted).restore(step.quote, change.token);

        set(step.dotted, change.before);

        return true;
    };

    // "Link to it": inline in Bard, the link mark on the words (with new
    // words when the model wrote some); a Link field, Finish's own route.
    const link = async (step, words = null) => {
        const target = step.link?.value;

        if (!target) return null;

        if (isBard(step) && step.quote) {
            const token = bardField(step.dotted).link(step.quote, step.occurrence, step.link.href, words);

            return token ? { kind: 'bard', token } : null;
        }

        const before = clone(valueAt(step.dotted));
        const result = await finish.run({ dotted: step.dotted, kind: 'link-broken', meta: { inline: false } }, { action: 'link', value: target });

        return result?.fixed ? { kind: 'value', before } : null;
    };

    const unlink = (step) => {
        if (isBard(step) && step.quote) {
            const token = bardField(step.dotted).unlink(step.quote, step.occurrence);

            return token ? { kind: 'bard', token } : null;
        }

        const before = clone(valueAt(step.dotted));
        set(step.dotted, null);

        return { kind: 'value', before };
    };

    // "Choose an entry": the field's own picker (Bard's link editor on the
    // words, or the Link field's selector), as Finish this page does it.
    const choose = async (step) => {
        if (isBard(step) && step.quote) {
            await reveal(step);
            bardField(step.dotted).select(step.quote, step.occurrence);
            await frames(2);

            const button = locate(step)?.querySelector('button[aria-label="Link" i]:not(.gw-f-tag), button[aria-label*="link" i]:not(.gw-f-tag)');

            button?.dispatchEvent(new MouseEvent('mousedown', { bubbles: true }));
            button?.click();

            return { stay: true };
        }

        return finish.run({ dotted: step.dotted, kind: 'link-broken', meta: { inline: false } }, { action: 'choose-entry' });
    };

    return {
        locate,
        reveal,
        present,
        applied,
        replace,
        undo,
        link,
        unlink,
        choose,
        // Ranges underlined in Bard; the current one filled.
        highlight(steps, current) {
            setRanges(steps.filter((step) => step.scope === 'range' && step.quote && step.state === 'open' && !step.stale).map((step) => ({
                path: step.dotted,
                id: step.id,
                quote: step.quote,
                occurrence: step.occurrence ?? 0,
                current: step === current,
                label: step.label,
            })));
        },
        tagHost: (field) => {
            const label = field.querySelector('label[data-ui-label]');

            return label?.querySelector(':scope > div') ?? label ?? field;
        },
    };
}
