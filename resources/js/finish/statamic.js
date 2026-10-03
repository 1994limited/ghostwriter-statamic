// Statamic's side of the Finish this page guide: where a gap's field is on
// the publish form, how to show it (Statamic's own $reveal opens its tab and
// expands collapsed sets, as it does for validation errors), and how each
// fix writes into the form. Fixes write into the publish form's state, never
// a save: the editor checks and saves as usual.
import { unref } from 'vue';
import { asks, leftovers, normaliseHint, sentenceAround, LINK_PREFIX } from './patterns.js';
import { bardEditor, editorAt, setCurrent } from './bard.js';
import { ensure, stock } from '../stock/store.js';
import { request } from '../stock/request.js';
import { browseButton } from './fields.js';

const idOf = (dotted) => `field_${String(dotted).replace(/\./g, '_')}`;
const still = () => window.matchMedia('(prefers-reduced-motion: reduce)').matches;
const frames = (n = 2) => new Promise((resolve) => {
    const step = (left) => (left ? requestAnimationFrame(() => step(left - 1)) : resolve());
    step(n);
});

export function statamicAdapter({ form, baseUrl, payload, recheck, t }) {
    const values = () => unref(form.values) ?? {};

    const valueAt = (dotted) => String(dotted).split('.').reduce((value, key) => (value == null ? undefined : value[key]), values());

    const set = (dotted, value) => form.setFieldValue(dotted, value);

    const locate = (gap) => {
        const label = document.querySelector(`label[for="${CSS.escape(idOf(gap.dotted))}"]`);

        return label?.closest('.form-group') ?? document.getElementById(idOf(gap.dotted))?.closest('.form-group') ?? null;
    };

    const reveal = async (gap) => {
        const field = locate(gap);

        if (!field) return false;

        Statamic.$reveal?.element(field);
        await frames(2);
        // On a phone the guide is a sheet over the bottom: the field goes to the top.
        field.scrollIntoView({ block: window.matchMedia('(max-width: 639px)').matches ? 'start' : 'center', behavior: still() ? 'auto' : 'smooth' });
        await new Promise((resolve) => setTimeout(resolve, still() ? 0 : 350));

        return !!field.offsetParent;
    };

    // The nth marker in a plain string value: [start, end) or null.
    const inString = (text, gap) => {
        if (typeof text !== 'string') return null;

        if (gap.kind === 'link') {
            const pattern = new RegExp(`\\[([^\\[\\]\\n]*)\\]\\(\\s*<?(?:https?://example\\.com/?)?${LINK_PREFIX.replace(/[.*+?^${}()|[\]\\]/g, '\\$&')}([^\\s)>]*)>?\\s*\\)`, 'g');
            const found = [...text.matchAll(pattern)].filter((m) => normaliseHint(decodeURIComponent(m[2])) === normaliseHint(gap.hint));
            const m = found[gap.occurrence ?? 0] ?? found[0];

            return m ? { start: m.index, end: m.index + m[0].length, words: m[1] } : null;
        }

        const list = gap.kind === 'leftover-token' ? leftovers(text) : asks(text);
        const same = list.filter((m) => normaliseHint(m.hint) === normaliseHint(gap.hint));
        const m = same[gap.occurrence ?? 0] ?? same[0];

        return m ? { start: m.index, end: m.index + m.length } : null;
    };

    const select = async (gap) => {
        const bard = editorAt(gap.dotted) ? bardEditor(gap.dotted) : null;

        if (bard?.has(gap)) return bard.select(gap);

        const field = locate(gap);
        const control = document.getElementById(idOf(gap.dotted));
        const input = control?.matches('input, textarea') ? control : field?.querySelector('input, textarea');
        const range = inString(input?.value, gap);

        if (input && range) {
            input.focus();
            input.setSelectionRange(range.start, range.end);

            return true;
        }

        // A link field on its placeholder: all of it, so typing replaces it.
        if (input && gap.kind === 'link' && !gap.meta?.inline) {
            input.focus();
            input.select();

            return true;
        }

        (input ?? field?.querySelector('button, [tabindex], [contenteditable="true"]'))?.focus();

        return !!field;
    };

    // Puts text where a marker is: through the editor in Bard, in the
    // string anywhere else.
    const replaceMarker = (gap, text) => {
        if (editorAt(gap.dotted)) return bardEditor(gap.dotted).replace(gap, text);

        const value = valueAt(gap.dotted);
        const range = inString(value, gap);

        if (!range) return false;

        set(gap.dotted, (value.slice(0, range.start) + text + value.slice(range.end)).replace(/ {2,}/g, ' '));

        return true;
    };

    const replaceSentence = (gap, text) => {
        if (editorAt(gap.dotted)) return bardEditor(gap.dotted).replaceSentence(gap, text);

        const value = valueAt(gap.dotted);
        const range = inString(value, gap);

        if (!range) return false;

        const line = value.lastIndexOf('\n', range.start) + 1;
        const lineEnd = value.indexOf('\n', range.end);
        const lineText = value.slice(line, lineEnd < 0 ? value.length : lineEnd);
        const { start, end } = sentenceAround(lineText, range.start - line, range.end - line);

        set(gap.dotted, value.slice(0, line + start) + text + value.slice(line + end));

        return true;
    };

    const press = (field, selector) => {
        const button = field?.querySelector(selector);

        button?.click();

        return !!button;
    };

    const fill = async (gap, task) => request(`${baseUrl}/finish/fill`, { method: 'POST', body: { ...payload(), gap: gap.id, task } });

    const openStock = async (gap) => {
        const asset = gap.meta?.asset;
        const key = asset ? `${asset.volume}::${asset.path}` : null;

        if (!key) return;

        ensure([key]);

        for (let i = 0; i < 20 && !stock.assets[key]; i++) await new Promise((resolve) => setTimeout(resolve, 100));

        Statamic.$events.$emit('ghostwriter.stock.open', { key });
    };

    const run = async (gap, fix, value) => {
        const field = locate(gap);

        try {
            switch (fix.action) {
                case 'answer':
                    return { fixed: replaceMarker(gap, value) };

                case 'remove':
                    return { fixed: replaceMarker(gap, '') };

                case 'write-around': {
                    const { text } = await fill(gap, 'write-around');

                    return { fixed: replaceSentence(gap, text), message: t('Written around it. Check it reads right.') };
                }

                case 'write-for-me': {
                    const { text } = await fill(gap, 'write-for-me');

                    set(gap.dotted, text);

                    return { fixed: true, message: t('Written. Check it, then save.') };
                }

                case 'link': {
                    if (!fix.value) break;

                    if (gap.meta?.inline) {
                        if (editorAt(gap.dotted)) return { fixed: bardEditor(gap.dotted).link(gap, `statamic://${fix.value}`) };

                        const current = valueAt(gap.dotted);
                        const range = inString(current, gap);
                        const url = fix.label && gap.meta?.candidates?.find((c) => c.value === fix.value)?.url;

                        if (!range || !url) break;

                        set(gap.dotted, `${current.slice(0, range.start)}[${range.words}](${url})${current.slice(range.end)}`);

                        return { fixed: true };
                    }

                    set(gap.dotted, fix.value);

                    return { fixed: true };
                }

                case 'remove-link': {
                    if (editorAt(gap.dotted)) return { fixed: bardEditor(gap.dotted).unlink(gap) };

                    const current = valueAt(gap.dotted);
                    const range = inString(current, gap);

                    if (!range) break;

                    set(gap.dotted, current.slice(0, range.start) + range.words + current.slice(range.end));

                    return { fixed: true };
                }

                case 'leave-empty':
                    set(gap.dotted, []);

                    return { fixed: true };

                case 'find-photo':
                case 'choose-another':
                    await reveal(gap);

                    if (press(field, 'button:has(svg[data-ghostwriter-field-action])')) return {};
                    break;

                case 'choose-asset': {
                    await reveal(gap);

                    // The field's own selector, which replaces what a
                    // one-image field holds (the placeholder) when chosen.
                    const browse = browseButton(locate(gap));

                    if (browse) {
                        browse.click();

                        return {};
                    }

                    break;
                }

                case 'license':
                case 'request-licence':
                case 'refresh-preview':
                    await openStock(gap);

                    return {};

                default:
                    break;
            }
        } catch (error) {
            Statamic.$toast.error(error.message ?? t('Something went wrong.'));

            return {};
        }

        // Choose an entry in a link or entries field: its own picker.
        if (fix.action === 'choose-entry' && !gap.meta?.inline) {
            await reveal(gap);

            const picker = field?.querySelector('[role="combobox"], button');

            if (picker) {
                picker.focus();

                return { message: t('Choose where it goes with the field\'s own picker, or type an address.') };
            }
        }

        // Focus, and anything else that is the editor's to do.
        await reveal(gap);
        await select(gap);

        return {};
    };

    return {
        locate,
        reveal,
        select,
        run,
        recheck,
        highlight: (gap) => setCurrent(gap),
        // Tags go beside the field's name, not over its border or buttons.
        tagHost: (field) => {
            const label = field.querySelector('label[data-ui-label]');

            return label?.querySelector(':scope > div') ?? label ?? field;
        },
    };
}
