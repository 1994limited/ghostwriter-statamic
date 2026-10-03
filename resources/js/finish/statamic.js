// Statamic's side of the Finish this page guide: where a gap's field is on
// the publish form, how to show it (Statamic's own $reveal opens its tab and
// expands collapsed sets, as it does for validation errors), and how each
// fix writes into the form. Fixes write into the publish form's state, never
// a save: the editor checks and saves as usual.
import { unref } from 'vue';
import { asks, checks, leftovers, normaliseHint, sentenceAround, LINK_PREFIX } from './patterns.js';
import { checkReplacement, pickCheck } from './check.js';
import { bardEditor, editorAt, setCurrent } from './bard.js';
import { ensure, stock } from '../stock/store.js';
import { request } from '../stock/request.js';
import { browseButton } from './fields.js';
import { entryId, entryMeta, get, isLinkMeta, metaPath } from './links.js';

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

    const metas = () => unref(form.meta) ?? {};

    // A Link field's meta, and where it sits, or null for other fields.
    const linkMeta = (dotted) => {
        const path = metaPath(dotted, values());
        const meta = get(metas(), path);

        return isLinkMeta(meta) ? { path, meta: JSON.parse(JSON.stringify(meta)) } : null;
    };

    // The entry type's own meta (titles, URLs) for these entries, as the
    // Link field loads it when someone picks Entry.
    const loadEntryMeta = async (meta, id) => {
        const config = JSON.stringify({ ...meta.types.entry.config, handle: 'entry' });
        const base = Statamic.$config.get('cpUrl') ?? '/cp';
        const { meta: loaded } = await request(`${base.replace(/\/$/, '')}/fields/field-meta`, {
            method: 'POST',
            body: { config: btoa(unescape(encodeURIComponent(config))), value: id },
        });

        return loaded;
    };

    // Points a Link field at an entry: mode Entry, the entry showing, the
    // value `entry::<id>`. Confirmed by reading the value back.
    const linkFieldTo = async (gap, value) => {
        const link = linkMeta(gap.dotted);
        const id = entryId(value);

        if (!link || !id) {
            set(gap.dotted, value);

            return valueAt(gap.dotted) === value;
        }

        const loaded = await loadEntryMeta(link.meta, id).catch(() => null);

        form.setFieldMeta(link.path, entryMeta(link.meta, [id], loaded));
        set(gap.dotted, `entry::${id}`);
        await frames(3);

        return valueAt(gap.dotted) === `entry::${id}`;
    };

    // "Choose an entry" on a Link field in URL mode: switch it to Entry
    // through its meta, then open its own entry selector. Cancelled, the
    // field goes back to URL mode and the link to choose, so nothing is lost.
    const chooseEntryFor = async (gap) => {
        const link = linkMeta(gap.dotted);

        if (!link) return null;

        const before = { meta: link.meta, value: valueAt(gap.dotted) };
        const loaded = await loadEntryMeta(link.meta, null).catch(() => null);

        form.setFieldMeta(link.path, entryMeta(link.meta, [], loaded));
        set(gap.dotted, null);
        await frames(4);

        const field = locate(gap);
        const open = [...(field?.querySelectorAll('button:not(.gw-f-tag)') ?? [])].find((button) => button.offsetParent && /link|browse|select/i.test(button.textContent ?? ''));
        const dialogs = () => document.querySelectorAll('[role="dialog"], .stack-container').length;
        const had = dialogs();

        open?.click();

        // Wait for the selector to open, then to close.
        for (let i = 0; i < 20 && dialogs() <= had; i++) await new Promise((resolve) => setTimeout(resolve, 100));
        while (dialogs() > had) await new Promise((resolve) => setTimeout(resolve, 250));
        await frames(3);

        const chosen = entryId(valueAt(gap.dotted));

        if (chosen) return { fixed: true };

        form.setFieldMeta(link.path, before.meta);
        set(gap.dotted, before.value);

        return { message: t('Nothing chosen: the link is as it was.') };
    };

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

        if (gap.kind === 'check') {
            const m = pickCheck(checks(text), gap);

            return m ? { start: m.index, end: m.index + m.length } : null;
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

        let next = (value.slice(0, range.start) + text + value.slice(range.end)).replace(/ {2,}/g, ' ');

        // Taking text out leaves no stray space before punctuation or at the ends.
        if (text === '') next = next.replace(/ +([.,;:!?])/g, '$1').trim();

        set(gap.dotted, next);

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

    // A fix counts only when the field's value really changed: read back
    // from the form (Bard writes into it a moment after the editor).
    const changed = async (dotted, before) => {
        for (let i = 0; i < 12; i++) {
            if (JSON.stringify(valueAt(dotted) ?? null) !== before) return true;
            await new Promise((resolve) => setTimeout(resolve, 100));
        }

        return false;
    };

    const run = async (gap, fix, value) => {
        const before = JSON.stringify(valueAt(gap.dotted) ?? null);
        const result = await attempt(gap, fix, value);

        if (result?.fixed && !(await changed(gap.dotted, before))) {
            return { message: t('That didn\'t change the field. Try again, or change it by hand.') };
        }

        return result;
    };

    const attempt = async (gap, fix, value) => {
        const field = locate(gap);

        try {
            switch (fix.action) {
                case 'answer':
                    return { fixed: replaceMarker(gap, value) };

                case 'remove':
                    return { fixed: replaceMarker(gap, '') };

                // A count to check: "Looks right" (or "Use “4 areas”") puts
                // the value in place of the marker; "Change it" what was typed.
                case 'confirm':
                case 'change': {
                    const text = checkReplacement(gap, fix, value);

                    if (text === null) break;

                    return { fixed: replaceMarker(gap, text) };
                }

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

                    return { fixed: await linkFieldTo(gap, fix.value) };
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

        // Choose an entry in a Link field: switch it to Entry and open its
        // selector. In an Entries field: its own picker.
        if (fix.action === 'choose-entry' && !gap.meta?.inline) {
            await reveal(gap);

            const chosen = await chooseEntryFor(gap);

            if (chosen) return chosen;

            const picker = field?.querySelector('[role="combobox"], button');

            if (picker) {
                picker.focus();

                return { message: t('Choose where it goes with the field\'s own picker, or type an address.') };
            }
        }

        // Choose an entry for a link inside Bard: its own link editor, which
        // has the entry picker, on the link's words.
        if (fix.action === 'choose-entry' && gap.meta?.inline && editorAt(gap.dotted)) {
            await reveal(gap);
            bardEditor(gap.dotted).select(gap);
            await frames(2);

            // Bard's own Link button (it acts on mousedown, keeping the
            // editor's selection), on the link's words.
            const button = locate(gap)?.querySelector('button[aria-label="Link" i]:not(.gw-f-tag), button[aria-label*="link" i]:not(.gw-f-tag)');

            button?.dispatchEvent(new MouseEvent('mousedown', { bubbles: true }));
            button?.click();

            return { message: t('Choose an entry for the link in the link editor.'), stay: true };
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
