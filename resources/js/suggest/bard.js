// Suggest edits inside Bard: each suggestion's words found in the editor's
// document (core's QuoteFinder over its text, mapped to ProseMirror
// positions across marks), a dotted indigo underline on each range with
// the current one filled, and changes made through the editor's own
// transactions, so marks and links are real nodes and ⌘Z works.
import { findQuote } from './quote.js';
import { bardContent } from './markdown.js';

const editors = new Set();
const listeners = new Set();

/** Called when a Bard editor appears, so its suggestions are looked for again. */
export function onEditorsChange(callback) {
    listeners.add(callback);

    return () => listeners.delete(callback);
}
const KEY = 'ghostwriterSuggest';

// What the guide shows: [{ path, id, quote, occurrence, current, label }].
let ranges = [];

export function setRanges(list) {
    ranges = list ?? [];
    editors.forEach(({ editor }) => redraw(editor));
}

export function editorAt(path) {
    for (const entry of editors) {
        if (!entry.editor.isDestroyed && entry.path() === path) return entry.editor;
    }

    return null;
}

function redraw(editor) {
    if (!editor.isDestroyed) editor.view.dispatch(editor.state.tr.setMeta(KEY, true));
}

/**
 * The document's text as QuoteFinder reads it, a line per textblock, with
 * each character's position in the document (null for the line breaks
 * between blocks). Inline nodes that aren't text take one place.
 */
export function textOf(doc) {
    const chars = [];
    const positions = [];

    doc.descendants((node, pos) => {
        if (!node.isTextblock) return true;

        if (chars.length) {
            chars.push('\n');
            positions.push(null);
        }

        let at = pos + 1;

        node.forEach((child) => {
            if (child.isText) {
                for (const char of child.text) {
                    chars.push(char);
                    positions.push(at);
                    at += char.length;
                }
            } else {
                chars.push('￼');
                positions.push(at);
                at += child.nodeSize;
            }
        });

        return false;
    });

    return { text: chars.join(''), chars, positions };
}

/** Where a quote is in the document: { from, to } in positions, or null. */
export function findRange(doc, quote, occurrence = 0) {
    if (!quote?.exact) return null;

    const { text, chars, positions } = textOf(doc);
    const match = findQuote(quote, text, occurrence, false);

    if (!match || match.length === 0) return null;

    const first = positions[match.offset];
    const lastIndex = match.offset + match.length - 1;
    const last = positions[lastIndex];

    if (first === null || first === undefined || last === null || last === undefined) return null;

    return { from: first, to: last + chars[lastIndex].length };
}

/** The marks every piece of text in a range has, as JSON: a quote wholly in bold stays bold. */
export function commonMarks(doc, from, to) {
    let common = null;

    doc.nodesBetween(from, to, (node) => {
        if (!node.isText) return true;

        const names = node.marks.map((mark) => mark.toJSON());
        common = common === null ? names : common.filter((mark) => names.some((other) => JSON.stringify(other) === JSON.stringify(mark)));

        return false;
    });

    return common ?? [];
}

function decorations(state, tiptap, path) {
    const { Decoration, DecorationSet } = tiptap.pm.view;
    const list = [];

    ranges.filter((range) => range.path === path).forEach((range) => {
        const found = findRange(state.doc, range.quote, range.occurrence ?? 0);

        if (found) {
            list.push(Decoration.inline(found.from, found.to, {
                class: `gw-suggest-mark${range.current ? ' is-current' : ''}`,
                'data-gw-suggestion': range.id,
                title: range.label ?? '',
            }));
        }
    });

    return DecorationSet.create(state.doc, list);
}

// Statamic.$bard.addExtension(({ bard, tiptap }) => ...): one per editor.
export function extension({ bard, tiptap }) {
    const { Extension } = tiptap.core;
    const { Plugin, PluginKey } = tiptap.pm.state;
    const key = new PluginKey(KEY);
    const pathOf = () => (bard.fieldPathPrefix ? `${bard.fieldPathPrefix}.${bard.handle}` : bard.handle);

    return Extension.create({
        name: KEY,

        onCreate() {
            editors.add({ editor: this.editor, path: pathOf });
            listeners.forEach((callback) => callback());
        },

        onDestroy() {
            editors.forEach((entry) => entry.editor === this.editor && editors.delete(entry));
        },

        addProseMirrorPlugins() {
            return [
                new Plugin({
                    key,
                    state: {
                        init: (_, state) => decorations(state, tiptap, pathOf()),
                        apply: (tr, old, _, state) => (tr.docChanged || tr.getMeta(KEY) ? decorations(state, tiptap, pathOf()) : old.map(tr.mapping, tr.doc)),
                    },
                    props: {
                        decorations(state) {
                            return key.getState(state);
                        },
                    },
                }),
            ];
        },
    });
}

/**
 * Changes inside one Bard field. Each returns what Undo needs, or null
 * when the words aren't there any more.
 */
export function bardField(path) {
    const editor = () => editorAt(path);

    return {
        has(quote, occurrence) {
            const ed = editor();

            return !!(ed && findRange(ed.state.doc, quote, occurrence));
        },

        select(quote, occurrence) {
            const ed = editor();
            const range = ed ? findRange(ed.state.doc, quote, occurrence) : null;

            if (!range) return false;

            ed.chain().setTextSelection(range).scrollIntoView().run();

            return true;
        },

        // The words replaced with inline markdown, keeping the marks the
        // words all had.
        replace(quote, occurrence, markdown) {
            const ed = editor();
            const range = ed ? findRange(ed.state.doc, quote, occurrence) : null;

            if (!range) return null;

            const before = ed.state.doc.slice(range.from, range.to).content.toJSON();
            const content = bardContent(markdown, commonMarks(ed.state.doc, range.from, range.to));

            if (content.length) ed.commands.insertContentAt(range, content);
            else ed.commands.deleteRange(range);

            return { before, after: content.map((node) => node.text).join('') };
        },

        // Undo: the new words found by the old words' context, and the old
        // content (with its marks) put back.
        restore(quote, token) {
            const ed = editor();

            if (!ed || !token) return false;

            const range = token.after === ''
                ? null
                : findRange(ed.state.doc, { exact: token.after, prefix: quote?.prefix ?? '', suffix: quote?.suffix ?? '' }, 0);

            if (!range) return false;

            ed.commands.insertContentAt(range, token.before);

            return true;
        },

        link(quote, occurrence, href, markdown = null) {
            const ed = editor();
            const range = ed ? findRange(ed.state.doc, quote, occurrence) : null;

            if (!range) return null;

            const before = ed.state.doc.slice(range.from, range.to).content.toJSON();
            const words = markdown ?? ed.state.doc.textBetween(range.from, range.to);
            const marks = commonMarks(ed.state.doc, range.from, range.to).filter((mark) => mark.type !== 'link');
            const content = bardContent(words, marks).map((node) => ({ ...node, marks: [...(node.marks ?? []).filter((mark) => mark.type !== 'link'), { type: 'link', attrs: { href } }] }));

            ed.commands.insertContentAt(range, content);

            return { before, after: content.map((node) => node.text).join('') };
        },

        unlink(quote, occurrence) {
            const ed = editor();
            const range = ed ? findRange(ed.state.doc, quote, occurrence) : null;

            if (!range) return null;

            const before = ed.state.doc.slice(range.from, range.to).content.toJSON();
            ed.view.dispatch(ed.state.tr.removeMark(range.from, range.to, ed.schema.marks.link));

            return { before, after: ed.state.doc.textBetween(range.from, range.to) };
        },
    };
}
