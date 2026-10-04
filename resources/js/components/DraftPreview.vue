<!--
    Draws a draft as the server laid it out: each field under its label, and
    page-builder blocks in order under their names. Writing can be changed
    where it is shown: every piece of text is always editable in place and
    can be reached with Tab. It is saved back into the draft when you leave
    it; Escape puts back what was there, and Enter finishes a one-line field.
    Rich text stays rich. In the "text" view only the writing is shown, read
    straight through, without the blocks around it.

    In the "text" view, writing that holds a gap marker shows it as a chip
    (core's markers.js), and a chip opens the gap popover (`gap`). Clicking
    the words, or Enter, starts editing them as stored, raw markers and
    all (textchips.js), so nothing saved ever holds a chip.
-->
<script>
import { gapLabels } from '../preview/overlay.js';
import { chipsDirective, editKey, editPress, isPainted, readBack } from '../preview/textchips.js';
import { SWITCH_HIGHLIGHT_MS, marksFor } from '../preview/layouts.js';

export default {
    name: 'DraftPreview',

    directives: { gwChips: chipsDirective },

    props: {
        nodes: { type: Array, required: true },
        nested: { type: Boolean, default: false },
        // 'blocks' shows everything in its block; 'text' only the writing.
        view: { type: String, default: 'blocks' },
        editable: { type: Boolean, default: false },
        // {places, nonce}: a layout just switched to; what it changes is outlined for a moment (top level only).
        switched: { type: Object, default: null },
    },

    // `gap`: a chip activated, {kind, hint, list?, value?, match, occurrence, element, host, path}.
    emits: ['edit', 'gap'],

    data() {
        return { marks: null };
    },

    watch: {
        switched(switched) {
            if (this.nested || !switched?.places?.length) return;

            clearTimeout(this.unmark);
            this.marks = marksFor(switched.places);

            // Brought into view in the draft's pane, then let go of.
            this.$nextTick(() => {
                const reduce = window.matchMedia?.('(prefers-reduced-motion: reduce)').matches;
                this.$el.querySelector?.('.gw-switched')?.scrollIntoView({ block: 'start', behavior: reduce ? 'auto' : 'smooth' });
            });
            this.unmark = setTimeout(() => (this.marks = null), SWITCH_HIGHLIGHT_MS);
        },
    },

    created() {
        this.labels = gapLabels((text) => this.__(text));
    },

    beforeUnmount() {
        clearTimeout(this.unmark);
    },

    computed: {
        shown() {
            if (this.view !== 'text') return this.nodes;

            // The writing only: nothing that is a setting, a list or a label.
            return this.nodes.filter((node) => ['html', 'text', 'blocks', 'rows', 'group'].includes(node.kind) && (node.kind !== 'text' || node.multiline || node.editable));
        },
    },

    methods: {
        key(node) {
            return JSON.stringify(node.path ?? node.handle);
        },

        // Outlined for a moment: a field a switched-to layout changed, or one of its blocks.
        lit(node, index = null) {
            if (!this.marks) return false;

            return index === null ? this.marks.fields.includes(node.handle) : (this.marks.blocks[node.handle] ?? []).includes(index);
        },

        canEdit(node) {
            return this.editable && node.editable;
        },

        // What the element holds now: HTML for rich text, the plain words otherwise. Never a chip.
        read(node, element) {
            return readBack(element, node.kind === 'html');
        },

        raw(node) {
            return node.kind === 'html' ? node.html : node.text;
        },

        // The chips in the Text tab's read view.
        chips(node) {
            return {
                raw: this.raw(node),
                html: node.kind === 'html',
                on: this.view === 'text',
                labels: this.labels,
                gap: this.editable ? (found, host) => this.$emit('gap', { ...found, host, path: node.editable ? node.path : null }) : null,
            };
        },

        // A read view's words clicked, or a key on it: editing starts.
        press(node, event) {
            if (this.canEdit(node)) editPress(event, event.currentTarget, this.raw(node), node.kind === 'html');
        },

        keyed(node, event) {
            if (this.canEdit(node)) editKey(event, event.currentTarget, this.raw(node), node.kind === 'html');
        },

        // Noted on the way in, so leaving can tell whether anything changed
        // and Escape can put it back. Not in a read view: nothing is being edited.
        enter(node, event) {
            if (isPainted(event.target)) return;

            event.target.dataset.was = this.read(node, event.target);
        },

        leave(node, event) {
            const element = event.target;

            if (isPainted(element)) return;

            const was = element.dataset.was;
            const value = this.read(node, element);

            delete element.dataset.was;

            if (was === undefined || value === was) {
                // Unchanged: back to the read view.
                this.$forceUpdate();

                return;
            }

            this.$emit('edit', {
                path: node.path,
                value,
                format: node.kind === 'html' ? 'html' : 'text',
                // Put back what was there, if the change could not be saved.
                revert: () => (node.kind === 'html' ? (element.innerHTML = was) : (element.innerText = was)),
            });
        },

        cancel(node, event) {
            const element = event.target;

            if (isPainted(element)) return;

            if (element.dataset.was !== undefined) {
                if (node.kind === 'html') element.innerHTML = element.dataset.was;
                else element.innerText = element.dataset.was;
            }

            element.blur();
        },

        // Enter finishes a one-line field; in anything longer it is a new line.
        enterKey(node, event) {
            // Not while an input method is still composing a character.
            if (event.defaultPrevented || isPainted(event.target) || node.kind === 'html' || node.multiline || event.shiftKey || event.isComposing) return;

            event.preventDefault();
            event.target.blur();
        },
    },
};
</script>

<template>
    <div :class="nested ? 'space-y-3' : 'space-y-5'">
        <div v-for="node in shown" :key="key(node)" :class="{ 'gw-switched': lit(node) }">
            <div v-if="view === 'blocks'" class="mb-1 text-xs font-medium tracking-wide text-gray-500 uppercase">{{ node.label }}</div>

            <!-- Rich text: edited in place as HTML, saved back as markdown. -->
            <div
                v-if="node.kind === 'html'"
                class="gw-prose"
                :class="{ 'gw-editable': canEdit(node) }"
                :contenteditable="canEdit(node) ? 'true' : null"
                :role="canEdit(node) ? 'textbox' : null"
                :aria-multiline="canEdit(node) ? 'true' : null"
                :aria-label="canEdit(node) ? node.label : null"
                v-gw-chips="chips(node)"
                @mousedown="press(node, $event)"
                @keydown="keyed(node, $event)"
                @focus="enter(node, $event)"
                @blur="leave(node, $event)"
                @keydown.esc.stop.prevent="cancel(node, $event)"
                v-html="node.html"
            />

            <ul v-else-if="node.kind === 'list'" class="flex flex-wrap gap-1.5">
                <li v-for="item in node.items" :key="item" class="rounded-md border border-gray-200 px-2 py-0.5 text-sm dark:border-gray-700!">{{ item }}</li>
            </ul>

            <div v-else-if="node.kind === 'blocks'" :class="view === 'text' ? 'space-y-5' : 'space-y-3'">
                <div v-for="(block, index) in node.items" :key="index" :class="[view === 'text' ? '' : 'rounded-lg border border-gray-200 dark:border-gray-700!', { 'gw-switched': lit(node, index) }]">
                    <div v-if="view === 'blocks'" class="flex items-center justify-between border-b border-gray-200 px-3 py-1.5 text-sm font-medium dark:border-gray-700!">
                        <span>{{ block.label }}</span>
                        <span v-if="!block.known" class="text-red-600">{{ __('Unknown block, will be left out') }}</span>
                    </div>
                    <div v-if="block.fields.length" :class="view === 'text' ? '' : 'p-3'">
                        <DraftPreview :nodes="block.fields" :view="view" :editable="editable" nested @edit="$emit('edit', $event)" @gap="$emit('gap', $event)" />
                    </div>
                    <div v-else-if="block.known && view === 'blocks'" class="px-3 py-2 text-sm text-gray-500">{{ __('Uses its usual settings.') }}</div>
                </div>
            </div>

            <div v-else-if="node.kind === 'rows'" class="space-y-2">
                <div v-for="(row, index) in node.items" :key="index" :class="view === 'text' ? '' : 'rounded-md border border-gray-200 p-2.5 dark:border-gray-700!'">
                    <DraftPreview :nodes="row" :view="view" :editable="editable" nested @edit="$emit('edit', $event)" @gap="$emit('gap', $event)" />
                </div>
            </div>

            <div v-else-if="node.kind === 'group'" :class="view === 'text' ? '' : 'border-s-2 border-gray-200 ps-3 dark:border-gray-700!'">
                <DraftPreview :nodes="node.fields" :view="view" :editable="editable" nested @edit="$emit('edit', $event)" @gap="$emit('gap', $event)" />
            </div>

            <!-- Plain text: edited in place as plain words. -->
            <div
                v-else
                class="whitespace-pre-wrap"
                :class="{ 'gw-editable': canEdit(node), 'text-lg font-medium': view === 'text' && node.handle === 'title' }"
                :contenteditable="canEdit(node) ? 'plaintext-only' : null"
                :role="canEdit(node) ? 'textbox' : null"
                :aria-multiline="canEdit(node) ? String(Boolean(node.multiline)) : null"
                :aria-label="canEdit(node) ? node.label : null"
                v-gw-chips="chips(node)"
                @mousedown="press(node, $event)"
                @keydown="keyed(node, $event)"
                @focus="enter(node, $event)"
                @blur="leave(node, $event)"
                @keydown.esc.stop.prevent="cancel(node, $event)"
                @keydown.enter="enterKey(node, $event)"
                v-text="node.text"
            />
        </div>
    </div>
</template>
