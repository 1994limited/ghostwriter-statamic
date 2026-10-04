<!--
    A tick-list of a collection's entries, for choosing the ones something new
    should be modelled on.
-->
<script>
import { Checkbox, Input } from '@statamic/cms/ui';

export default {
    components: { Checkbox, Input },

    props: {
        entries: { type: Array, required: true },
        modelValue: { type: Array, required: true },
        disabled: { type: Boolean, default: false },
        max: { type: Number, default: 6 },
    },

    emits: ['update:modelValue'],

    data() {
        return { search: '' };
    },

    computed: {
        visible() {
            const term = this.search.trim().toLowerCase();
            return term ? this.entries.filter((entry) => entry.title.toLowerCase().includes(term)) : this.entries;
        },

        // Nesting only reads as a tree while the whole list is showing.
        nested() {
            return !this.search.trim();
        },

        picked() {
            return this.entries.filter((entry) => this.modelValue.includes(entry.id));
        },
    },

    watch: {
        modelValue() {
            this.$nextTick(() => this.reveal());
        },
    },

    mounted() {
        this.reveal();
    },

    methods: {
        // Ticked entries further down the list (chosen for the person) are
        // scrolled into view, unless one already shows.
        reveal() {
            const list = this.$refs.list;
            const ticked = [...(list?.querySelectorAll('[data-ticked]') ?? [])];
            if (!ticked.length) return;

            const top = list.scrollTop;
            const showing = ticked.some((row) => row.offsetTop >= top && row.offsetTop + row.offsetHeight <= top + list.clientHeight);
            if (!showing) list.scrollTop = Math.max(0, ticked[0].offsetTop - 8);
        },

        toggle(id, on) {
            this.$emit('update:modelValue', on ? [...this.modelValue, id].slice(0, this.max) : this.modelValue.filter((picked) => picked !== id));
        },
    },
};
</script>

<template>
    <div>
        <Input v-if="entries.length > 8" v-model="search" :placeholder="__('Filter…')" :aria-label="__('Filter entries')" class="mb-2" />
        <div ref="list" class="relative max-h-56 space-y-1.5 overflow-y-auto rounded-lg border border-gray-200 p-3 dark:border-gray-700!">
            <div
                v-for="entry in visible"
                :key="entry.id"
                :data-ticked="modelValue.includes(entry.id) ? '' : null"
                :style="nested && entry.depth ? { paddingInlineStart: `${entry.depth * 1.5}rem` } : null"
            >
                <Checkbox
                    :model-value="modelValue.includes(entry.id)"
                    :label="entry.published ? entry.title : `${entry.title} (${__('draft')})`"
                    :disabled="disabled || (!modelValue.includes(entry.id) && modelValue.length >= max)"
                    @update:model-value="(on) => toggle(entry.id, on)"
                />
            </div>
        </div>
        <p v-if="picked.length" class="mt-2 text-sm text-gray-500">
            {{ __('Modelled on') }}: {{ picked.map((entry) => entry.title).join(', ') }}
        </p>
    </div>
</template>
