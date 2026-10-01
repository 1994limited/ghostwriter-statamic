<!--
    Draws a draft as the server laid it out: each field under its label, and
    page-builder blocks in order under their names. Writing can be changed
    where it is shown: click a piece of text to edit it, and it is saved back
    into the draft. In the "text" view only the writing is shown, read
    straight through, without the blocks around it.
-->
<script>
export default {
    name: 'DraftPreview',

    props: {
        nodes: { type: Array, required: true },
        nested: { type: Boolean, default: false },
        // 'blocks' shows everything in its block; 'text' only the writing.
        view: { type: String, default: 'blocks' },
        editable: { type: Boolean, default: false },
    },

    emits: ['edit'],

    data() {
        return { editing: null, value: '' };
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

        start(node, event) {
            if (!this.editable || !node.editable || this.editing) return;

            this.editing = this.key(node);
            this.value = node.kind === 'html' ? event.currentTarget.innerHTML : node.text;

            this.$nextTick(() => {
                const field = this.$refs[`field-${this.editing}`];
                const element = Array.isArray(field) ? field[0] : field;

                element?.focus();
            });
        },

        finish(node, event) {
            if (this.editing !== this.key(node)) return;

            const value = node.kind === 'html' ? event.target.innerHTML : this.value;
            const before = node.kind === 'html' ? node.html : node.text;

            this.editing = null;

            if (value !== before) this.$emit('edit', { path: node.path, value, format: node.kind === 'html' ? 'html' : 'text' });
        },

        cancel(node) {
            this.editing = null;

            // A contenteditable keeps what was typed; put the original back.
            if (node.kind === 'html') this.$forceUpdate();
        },
    },
};
</script>

<template>
    <div :class="nested ? 'space-y-3' : 'space-y-5'">
        <div v-for="node in shown" :key="key(node)">
            <div v-if="view === 'blocks'" class="mb-1 text-xs font-medium tracking-wide text-gray-500 uppercase">{{ node.label }}</div>

            <!-- Rich text: edited in place as HTML, saved back as markdown. -->
            <div
                v-if="node.kind === 'html'"
                :ref="`field-${key(node)}`"
                class="gw-prose rounded-md"
                :class="{ 'cursor-text hover:ring-1 hover:ring-gray-300 dark:hover:ring-gray-600': editable && node.editable && !editing, 'ring-2 ring-blue-400 p-2 outline-none': editing === key(node) }"
                :contenteditable="editing === key(node)"
                :title="editable && node.editable && !editing ? __('Click to edit') : null"
                @click="start(node, $event)"
                @blur="finish(node, $event)"
                @keydown.esc.prevent="cancel(node)"
                v-html="node.html"
            />

            <ul v-else-if="node.kind === 'list'" class="flex flex-wrap gap-1.5">
                <li v-for="item in node.items" :key="item" class="rounded-md border border-gray-200 px-2 py-0.5 text-sm dark:border-gray-700">{{ item }}</li>
            </ul>

            <div v-else-if="node.kind === 'blocks'" :class="view === 'text' ? 'space-y-5' : 'space-y-3'">
                <div v-for="(block, index) in node.items" :key="index" :class="view === 'text' ? '' : 'rounded-lg border border-gray-200 dark:border-gray-700'">
                    <div v-if="view === 'blocks'" class="flex items-center justify-between border-b border-gray-200 px-3 py-1.5 text-sm font-medium dark:border-gray-700">
                        <span>{{ block.label }}</span>
                        <span v-if="!block.known" class="text-red-600">{{ __('Unknown block, will be left out') }}</span>
                    </div>
                    <div v-if="block.fields.length" :class="view === 'text' ? '' : 'p-3'">
                        <DraftPreview :nodes="block.fields" :view="view" :editable="editable" nested @edit="$emit('edit', $event)" />
                    </div>
                    <div v-else-if="block.known && view === 'blocks'" class="px-3 py-2 text-sm text-gray-500">{{ __('Uses its usual settings.') }}</div>
                </div>
            </div>

            <div v-else-if="node.kind === 'rows'" class="space-y-2">
                <div v-for="(row, index) in node.items" :key="index" :class="view === 'text' ? '' : 'rounded-md border border-gray-200 p-2.5 dark:border-gray-700'">
                    <DraftPreview :nodes="row" :view="view" :editable="editable" nested @edit="$emit('edit', $event)" />
                </div>
            </div>

            <div v-else-if="node.kind === 'group'" :class="view === 'text' ? '' : 'border-s-2 border-gray-200 ps-3 dark:border-gray-700'">
                <DraftPreview :nodes="node.fields" :view="view" :editable="editable" nested @edit="$emit('edit', $event)" />
            </div>

            <!-- Plain text: a box appears in its place while it is edited. -->
            <template v-else>
                <textarea
                    v-if="editing === key(node)"
                    :ref="`field-${key(node)}`"
                    v-model="value"
                    class="w-full rounded-md border border-blue-400 bg-white p-2 text-sm dark:bg-gray-900"
                    :rows="node.multiline ? Math.min(12, Math.max(3, value.split('\n').length + 1)) : 1"
                    @blur="finish(node, $event)"
                    @keydown.esc.prevent="cancel(node)"
                    @keydown.enter="node.multiline || finish(node, $event)"
                />
                <div
                    v-else
                    class="rounded-md whitespace-pre-wrap"
                    :class="{ 'cursor-text hover:ring-1 hover:ring-gray-300 dark:hover:ring-gray-600': editable && node.editable && !editing, 'text-lg font-medium': view === 'text' && node.handle === 'title' }"
                    :title="editable && node.editable && !editing ? __('Click to edit') : null"
                    @click="start(node, $event)"
                >{{ node.text }}</div>
            </template>
        </div>
    </div>
</template>
