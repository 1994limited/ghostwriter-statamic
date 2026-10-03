// Ghostwriter's marks inside Bard: a ProseMirror plugin that decorates every
// `[[ask: …]]`, leftover `[[token]]` and link to `#gw-link:` with a dashed
// underline, the current gap's in purple. Decorations are the editor's own
// mechanism, so they survive every re-render, and edits go through
// transactions, so undo works. Each editor is registered by its field path,
// so the guide can select and replace inside it.
import { asks, leftovers, isLinkSentinel, linkHint, normaliseHint, sentenceAround } from './patterns.js';

// Each live editor with a function giving its field path now: a set moved
// up or down changes the path, so it is asked each time.
const editors = new Set();
const listeners = new Set();
const KEY = 'ghostwriterGaps';

// What the guide points at now: { path, id, kind, hint, occurrence, match } or null.
let current = null;

export function setCurrent(gap) {
    current = gap ? { path: gap.dotted, kind: gap.kind, hint: gap.hint, occurrence: gap.occurrence ?? 0, match: gap.meta?.match ?? null } : null;
    editors.forEach(({ editor }) => redraw(editor));
}

export function editorAt(path) {
    for (const entry of editors) {
        if (!entry.editor.isDestroyed && entry.path() === path) return entry.editor;
    }

    return null;
}

export function onEditorsChange(callback) {
    listeners.add(callback);

    return () => listeners.delete(callback);
}

function redraw(editor) {
    if (!editor.isDestroyed) editor.view.dispatch(editor.state.tr.setMeta(KEY, true));
}

// Each textblock's text, with where it starts in the document. Inline nodes
// that aren't text (a hard break, an image) take one place, as in the doc.
function blocks(doc) {
    const found = [];

    doc.descendants((node, pos) => {
        if (!node.isTextblock) return true;

        let text = '';

        node.forEach((child) => {
            text += child.isText ? child.text : '￼';
        });

        found.push({ text, start: pos + 1 });

        return false;
    });

    return found;
}

// Every marker in the document, in order, with its range.
export function markersIn(doc) {
    const found = [];

    blocks(doc).forEach(({ text, start }) => {
        asks(text).forEach((m) => found.push({ kind: 'ask', from: start + m.index, to: start + m.index + m.length, match: m.match, hint: m.hint, block: { text, start } }));
        leftovers(text).forEach((m) => found.push({ kind: 'leftover-token', from: start + m.index, to: start + m.index + m.length, match: m.match, hint: m.hint, block: { text, start } }));
    });

    // Links to choose: runs of text carrying the same sentinel link mark.
    let run = null;

    doc.descendants((node, pos) => {
        if (!node.isText) {
            run = null;

            return true;
        }

        const mark = node.marks.find((m) => m.type.name === 'link' && isLinkSentinel(m.attrs.href));

        if (mark && run && run.href === mark.attrs.href && run.to === pos) {
            run.to = pos + node.nodeSize;
            run.words += node.text;
        } else if (mark) {
            run = { kind: 'link', from: pos, to: pos + node.nodeSize, href: mark.attrs.href, hint: linkHint(mark.attrs.href), words: node.text };
            found.push(run);
        } else {
            run = null;
        }

        return false;
    });

    return found.sort((a, b) => a.from - b.from);
}

// The marker in this document a gap names: the nth of its kind with its hint.
export function findGap(doc, gap) {
    const kind = gap.kind === 'link' ? 'link' : gap.kind;
    const same = markersIn(doc).filter((m) => m.kind === kind && (gap.meta?.match && kind === 'ask' ? m.match === gap.meta.match || normaliseHint(m.hint) === normaliseHint(gap.hint) : normaliseHint(m.hint) === normaliseHint(gap.hint)));

    return same[gap.occurrence ?? 0] ?? same[0] ?? null;
}

function decorations(state, tiptap, path) {
    const { Decoration, DecorationSet } = tiptap.pm.view;
    const list = [];
    const markers = markersIn(state.doc);
    const target = current && current.path === path ? findGap(state.doc, { kind: current.kind, hint: current.hint, occurrence: current.occurrence, meta: { match: current.match } }) : null;

    markers.forEach((m) => {
        const now = target && target.from === m.from && target.to === m.to;
        list.push(Decoration.inline(m.from, m.to, { class: `gw-gap-mark${now ? ' is-current' : ''}`, 'data-gw-kind': m.kind }));
    });

    return DecorationSet.create(state.doc, list);
}

// Statamic.$bard.addExtension(({ bard, tiptap }) => ...): one extension per editor.
export function extension({ bard, tiptap }) {
    const { Extension } = tiptap.core;
    const { Plugin, PluginKey } = tiptap.pm.state;
    const key = new PluginKey(KEY);
    const pathOf = () => (bard.fieldPathPrefix ? `${bard.fieldPathPrefix}.${bard.handle}` : bard.handle);

    return Extension.create({
        name: 'ghostwriterGaps',

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

// What the guide does inside a Bard field, through the editor so undo works.
export function bardEditor(path) {
    const editor = () => editorAt(path);

    const range = (gap) => {
        const ed = editor();

        return ed ? findGap(ed.state.doc, gap) : null;
    };

    return {
        has(gap) {
            return !!range(gap);
        },

        select(gap) {
            const ed = editor();
            const r = range(gap);

            if (!ed || !r) return false;

            ed.chain().focus().setTextSelection({ from: r.from, to: r.to }).scrollIntoView().run();

            return true;
        },

        replace(gap, text) {
            const ed = editor();
            const r = range(gap);

            if (!ed || !r) return false;

            ed.chain().focus().insertContentAt({ from: r.from, to: r.to }, text ? { type: 'text', text } : '').run();

            return true;
        },

        replaceSentence(gap, text) {
            const ed = editor();
            const r = range(gap);

            if (!ed || !r?.block) return false;

            const { start, end } = sentenceAround(r.block.text, r.from - r.block.start, r.to - r.block.start);

            ed.chain().focus().insertContentAt({ from: r.block.start + start, to: r.block.start + end }, text ? { type: 'text', text } : '').run();

            return true;
        },

        link(gap, href) {
            const ed = editor();
            const r = range(gap);

            if (!ed || !r) return false;

            const type = ed.schema.marks.link;
            const { tr } = ed.state;

            tr.removeMark(r.from, r.to, type).addMark(r.from, r.to, type.create({ href }));
            ed.view.dispatch(tr);

            return true;
        },

        unlink(gap) {
            const ed = editor();
            const r = range(gap);

            if (!ed || !r) return false;

            ed.view.dispatch(ed.state.tr.removeMark(r.from, r.to, ed.schema.marks.link));

            return true;
        },
    };
}
