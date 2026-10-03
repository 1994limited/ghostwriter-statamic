<!--
    The extras under the Text tab: pieces the writer prepared with the draft
    (stats, FAQs, a pull quote…), only from what the person said, the draft
    itself or the site's existing pages. Each item says where it came from,
    and whether it needs a look: a number Ghostwriter counted from a list
    is "Needs review" until it's checked (and Finish this page asks about
    it on the form). Every part is editable in place, as the draft is, and
    ✕ deletes an item; neither calls a model. A layout uses an extra where
    it has room; one no layout uses never reaches the entry.

    A fact to add or a count to check shows as a chip, not as its marker
    (core's markers.js). Clicked to edit, the words are the item's own,
    markers included, so nothing is saved with a chip in it.
-->
<script>
import { changed, extraPayload, isStat, partLabel } from '../preview/layouts.js';
import { gapLabels } from '../preview/overlay.js';
import { has, injectStyles, toHtml } from '../preview/markers.js';

export default {
    props: {
        extras: { type: Array, required: true },
        editable: { type: Boolean, default: false },
    },

    // `edit` {id, payload, revert}; `remove` {id, label}.
    emits: ['edit', 'remove'],

    created() {
        this.labels = gapLabels((text) => this.__(text));
        injectStyles(document);
    },

    methods: {
        partLabel(part) {
            return partLabel(part, (text) => this.__(text));
        },

        // The item's parts, in the kind's order, then its text. A stat is
        // its number and its label.
        fields(extra, item) {
            if (isStat(item)) return ['value', 'label'];

            const parts = (extra.parts ?? []).filter((part) => part !== 'for' && (item.parts?.[part] ?? '') !== '');

            return [...parts.filter((part) => part === 'question'), 'text', ...parts.filter((part) => part !== 'question')];
        },

        value(item, field) {
            return field === 'text' ? item.text : item.parts?.[field] ?? '';
        },

        // The words with their markers as chips, escaped: for showing only.
        shown(item, field) {
            return toHtml(this.value(item, field), { labels: this.labels });
        },

        hasGaps(item, field) {
            return has(this.value(item, field));
        },

        // Editing starts from the item's own words, markers and all.
        enter(item, field, event) {
            if (this.hasGaps(item, field)) event.target.innerText = this.value(item, field);

            event.target.dataset.was = event.target.innerText;
        },

        leave(item, field, event) {
            const element = event.target;
            const was = element.dataset.was;
            const value = element.innerText.replace(/\n$/, '');

            delete element.dataset.was;

            if (was === undefined || !changed(item, field, value)) {
                // Unchanged: back to the chips.
                if (this.hasGaps(item, field)) element.innerHTML = this.shown(item, field);

                return;
            }

            this.$emit('edit', { id: item.id, payload: extraPayload(item, field, value.trim()), revert: () => (element.innerHTML = this.shown(item, field)) });
        },

        cancel(event) {
            if (event.target.dataset.was !== undefined) event.target.innerText = event.target.dataset.was;

            event.target.blur();
        },

        finish(event) {
            if (event.shiftKey || event.isComposing) return;

            event.preventDefault();
            event.target.blur();
        },
    },
};
</script>

<template>
    <section v-if="extras.length" class="mt-8 border-t border-gray-200 pt-4 dark:border-gray-700!" aria-labelledby="gw-extras-heading" data-ghostwriter-extras>
        <h3 id="gw-extras-heading" class="text-sm font-semibold">{{ __('Extras') }}</h3>
        <p class="mt-0.5 mb-3 text-sm text-gray-500">{{ __('Prepared with the draft, only from what you told me, the draft itself or your existing pages. A layout uses them where it has room; one that isn’t used is never put in the entry. Click to change one.') }}</p>

        <div v-for="extra in extras" :key="extra.id" class="mb-3 rounded-lg border border-gray-200 dark:border-gray-700!" :data-gw-extra="extra.kind">
            <div class="flex items-center gap-2 border-b border-gray-200 px-3 py-1.5 text-sm dark:border-gray-700!">
                <span class="font-medium">{{ extra.label }}</span>
                <span v-if="extra.use_label" class="text-xs" :class="extra.used ? 'text-green-700 dark:text-green-400!' : 'text-gray-500'">{{ extra.use_label }}</span>
            </div>
            <ul class="divide-y divide-dashed divide-gray-200 px-3 dark:divide-gray-700!">
                <li v-for="item in extra.items" :key="item.id" class="flex items-start gap-3 py-2" :data-gw-extra-item="item.id">
                    <div class="min-w-0 flex-1 space-y-1">
                        <div v-for="field in fields(extra, item)" :key="field" class="flex items-baseline gap-2 text-sm">
                            <span v-if="field !== 'text'" class="w-20 shrink-0 text-xs text-gray-500">{{ partLabel(field) }}</span>
                            <span
                                class="min-w-0 flex-1 whitespace-pre-wrap"
                                :class="[{ 'gw-editable': editable }, field === 'value' ? 'font-semibold' : '']"
                                :contenteditable="editable ? 'plaintext-only' : null"
                                :role="editable ? 'textbox' : null"
                                :aria-label="editable ? `${extra.label}: ${field === 'text' ? __('Text') : partLabel(field)}` : null"
                                spellcheck="true"
                                @focus="enter(item, field, $event)"
                                @blur="leave(item, field, $event)"
                                @keydown.esc.stop.prevent="cancel"
                                @keydown.enter="finish"
                                v-html="shown(item, field)"
                            />
                        </div>
                        <p v-if="item.count_label" class="text-xs text-gray-500">{{ item.count_label }}</p>
                    </div>
                    <div class="flex shrink-0 flex-wrap items-center justify-end gap-1.5 pt-0.5">
                        <span
                            v-if="item.state"
                            class="rounded-full border px-2 text-[11px] leading-5 whitespace-nowrap"
                            :class="item.state === 'needs-review' ? 'border-amber-300 bg-amber-50 text-amber-800 dark:border-amber-500/60! dark:bg-amber-950/40! dark:text-amber-300!' : 'border-amber-300 text-amber-700 dark:border-amber-500/60! dark:text-amber-400!'"
                        >{{ item.state_label }}</span>
                        <span v-if="item.source" class="rounded-full border border-gray-200 px-2 text-[11px] leading-5 whitespace-nowrap text-gray-600 dark:border-gray-700! dark:text-gray-300!" :data-gw-source="item.source.kind">
                            <a v-if="item.source.url" :href="item.source.url" target="_blank" rel="noopener" class="underline">{{ item.source.label }}<span aria-hidden="true"> ↗</span><span class="sr-only"> {{ __('(opens in a new tab)') }}</span></a>
                            <template v-else>{{ item.source.label }}</template>
                        </span>
                        <button
                            v-if="editable"
                            type="button"
                            class="rounded px-1 text-gray-400 hover:text-red-600! dark:hover:text-red-400!"
                            :aria-label="__('Delete this :kind item: :text', { kind: extra.label, text: item.text })"
                            :title="__('Delete')"
                            @click="$emit('remove', { id: item.id, label: extra.label })"
                        >✕</button>
                    </div>
                </li>
            </ul>
        </div>
    </section>
</template>
