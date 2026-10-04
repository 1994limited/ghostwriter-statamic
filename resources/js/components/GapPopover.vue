<!--
    A gap resolved from its chip, in the draft itself: the small popover a
    chip opens in the Preview or the Text tab, anchored to the chip, inside
    the draft's pane (over the preview's frame).

    - A fact to add: "Only you know this: adult ticket price", an answer
      box, Add it, and Leave it for later.
    - A count to check: "Counted from ‘…’. 3 areas, is that right?", with
      Looks right, Change it (the value, editable) and Remove it.
    - A link to choose: the entries its hint suggests (no model), or Choose
      an entry to search for one.

    The answer goes into the draft exactly as typed; the panel saves it
    (`resolve` with the value) and the Preview, layouts, Blocks and Text
    follow. Leave it for later changes nothing. It is a dialog: focus moves
    into it, Tab stays in it, Esc closes it, and the panel puts focus back
    on the chip.
-->
<script>
import { Button } from '@statamic/cms/ui';

let ids = 0;

/** The chip's box in the CP page's coordinates: a chip in the preview's (scaled) frame is mapped out of it. */
export function anchorRect(gap) {
    const box = gap.element.getBoundingClientRect();

    if (!gap.frame) return box;

    const frame = gap.frame.getBoundingClientRect();
    const scale = gap.frame.offsetWidth ? frame.width / gap.frame.offsetWidth : 1;

    return { left: frame.left + box.left * scale, right: frame.left + box.right * scale, top: frame.top + box.top * scale, bottom: frame.top + box.bottom * scale };
}

export default {
    components: { Button },

    props: {
        // {kind, hint, value?, list?, element, frame?}
        gap: { type: Object, required: true },
        // The positioned pane the popover sits in.
        container: { type: Object, default: null },
        baseUrl: { type: String, required: true },
        sessionId: { type: String, required: true },
        busy: { type: Boolean, default: false },
    },

    // `resolve` {value, reference?}; `close` {refocus}.
    emits: ['resolve', 'close'],

    data() {
        return {
            id: `gw-gap-${++ids}`,
            answer: '',
            changing: false,
            changed: this.gap.value ?? this.gap.hint,
            // Links: the hint's suggestions, and Choose an entry's search.
            suggestions: null,
            choosing: false,
            query: '',
            results: null,
            searching: false,
            box: { top: 0, left: 0, width: 320 },
        };
    },

    computed: {
        title() {
            if (this.gap.kind === 'ask') return this.__('Only you know this: :hint', { hint: this.gap.hint });
            if (this.gap.kind === 'check') return this.__('Counted from ‘:list’. :value, is that right?', { list: this.gap.list, value: this.gap.value ?? this.gap.hint });

            return this.__('Link to choose');
        },
    },

    mounted() {
        this.place();
        this.scrollers = [this.container, window, this.gap.frame?.contentWindow].filter(Boolean);
        this.scrollers.forEach((target) => target.addEventListener('scroll', this.place, { passive: true }));
        window.addEventListener('resize', this.place);
        document.addEventListener('mousedown', this.outside, true);
        this.gap.frame?.contentDocument?.addEventListener('mousedown', this.outside, true);

        if (this.gap.kind === 'link') this.suggest();

        this.$nextTick(() => this.focusFirst());
    },

    beforeUnmount() {
        this.scrollers.forEach((target) => target.removeEventListener('scroll', this.place));
        window.removeEventListener('resize', this.place);
        document.removeEventListener('mousedown', this.outside, true);
        this.gap.frame?.contentDocument?.removeEventListener('mousedown', this.outside, true);
        clearTimeout(this.searchTimer);
    },

    methods: {
        // Below the chip (above it when there's no room), inside the pane.
        place() {
            const pane = this.container;

            if (!pane || !this.gap.element?.isConnected) return;

            const outer = pane.getBoundingClientRect();
            const anchor = anchorRect(this.gap);
            const width = Math.min(340, Math.max(220, pane.clientWidth - 16));
            const left = Math.min(Math.max(8, anchor.left - outer.left), Math.max(8, pane.clientWidth - width - 8));
            const height = this.$refs.dialog?.offsetHeight ?? 160;
            const below = anchor.bottom - outer.top + pane.scrollTop + 6;
            const above = anchor.top - outer.top + pane.scrollTop - height - 6;
            const roomBelow = outer.bottom - anchor.bottom;

            this.box = { width, left, top: roomBelow < height + 12 && above > pane.scrollTop ? above : below };
        },

        focusFirst() {
            const target = this.$refs.dialog?.querySelector('input, textarea, button:not([disabled])');

            (target ?? this.$refs.dialog)?.focus();
        },

        // Tab stays inside.
        trap(event) {
            const focusable = [...this.$refs.dialog.querySelectorAll('input, textarea, button:not([disabled]), [href]')].filter((element) => element.offsetParent !== null);

            if (!focusable.length) return;

            const first = focusable[0];
            const last = focusable[focusable.length - 1];

            if (event.shiftKey && document.activeElement === first) {
                event.preventDefault();
                last.focus();
            } else if (!event.shiftKey && document.activeElement === last) {
                event.preventDefault();
                first.focus();
            }
        },

        // A press outside closes it (without moving focus back: the person went elsewhere).
        outside(event) {
            if (this.$refs.dialog?.contains(event.target) || event.target === this.gap.element || this.gap.element?.contains?.(event.target)) return;

            this.$emit('close', { refocus: false });
        },

        resolve(value, reference = null) {
            if (this.busy) return;

            this.$emit('resolve', { value, reference });
        },

        submitAnswer() {
            if (this.answer.trim() !== '') this.resolve(this.answer);
        },

        startChange() {
            this.changing = true;
            this.$nextTick(() => {
                this.$refs.change?.focus();
                this.$refs.change?.select();
                this.place();
            });
        },

        submitChange() {
            if (this.changed.trim() !== '') this.resolve(this.changed.trim());
        },

        async suggest() {
            this.suggestions = await this.search(this.gap.hint);
            this.$nextTick(() => this.place());
        },

        async search(q) {
            if (!q || !q.trim()) return [];

            try {
                const { data } = await this.$axios.get(`${this.baseUrl}/sessions/${this.sessionId}/links`, {
                    params: { q, site: window.Statamic?.$config?.get?.('selectedSite') ?? null },
                });

                return data.entries ?? [];
            } catch (error) {
                return [];
            }
        },

        startChoosing() {
            this.choosing = true;
            this.$nextTick(() => {
                this.$refs.query?.focus();
                this.place();
            });
        },

        typed() {
            clearTimeout(this.searchTimer);
            this.searching = true;
            this.searchTimer = setTimeout(async () => {
                this.results = await this.search(this.query);
                this.searching = false;
                this.$nextTick(() => this.place());
            }, 250);
        },

        // Words take the entry's address; a link field's own value takes its reference (the server knows which).
        choose(entry) {
            this.resolve(entry.url ?? entry.value, entry.value);
        },
    },
};
</script>

<template>
    <div
        ref="dialog"
        role="dialog"
        aria-modal="true"
        :aria-labelledby="`${id}-title`"
        tabindex="-1"
        class="absolute z-30 rounded-lg border border-gray-200 bg-white p-3 text-sm shadow-lg dark:border-gray-700! dark:bg-gray-900!"
        :style="{ top: `${box.top}px`, left: `${box.left}px`, width: `${box.width}px` }"
        data-ghostwriter-gap-popover
        :data-kind="gap.kind"
        @keydown.esc.stop.prevent="$emit('close', { refocus: true })"
        @keydown.tab="trap"
    >
        <p :id="`${id}-title`" class="font-medium text-gray-900 dark:text-gray-100!">{{ title }}</p>

        <!-- A fact to add -->
        <template v-if="gap.kind === 'ask'">
            <label :for="`${id}-answer`" class="mt-2 block text-xs text-gray-500">{{ __('Your answer goes into the draft exactly as you type it.') }}</label>
            <input
                :id="`${id}-answer`"
                v-model="answer"
                type="text"
                class="mt-1 w-full rounded-md border border-gray-300 bg-white px-2 py-1.5 text-sm dark:border-gray-600! dark:bg-gray-800!"
                autocomplete="off"
                @keydown.enter.prevent="submitAnswer"
            />
            <div class="mt-3 flex flex-wrap justify-end gap-2">
                <Button size="sm" variant="ghost" :text="__('Leave it for later')" @click="$emit('close', { refocus: true })" />
                <Button size="sm" variant="primary" :text="__('Add it')" :disabled="answer.trim() === '' || busy" :loading="busy" @click="submitAnswer" />
            </div>
        </template>

        <!-- A count to check -->
        <template v-else-if="gap.kind === 'check'">
            <div v-if="changing" class="mt-2">
                <label :for="`${id}-change`" class="block text-xs text-gray-500">{{ __('What the page should say') }}</label>
                <input
                    :id="`${id}-change`"
                    ref="change"
                    v-model="changed"
                    type="text"
                    class="mt-1 w-full rounded-md border border-gray-300 bg-white px-2 py-1.5 text-sm dark:border-gray-600! dark:bg-gray-800!"
                    autocomplete="off"
                    @keydown.enter.prevent="submitChange"
                />
                <div class="mt-3 flex flex-wrap justify-end gap-2">
                    <Button size="sm" variant="ghost" :text="__('Cancel')" @click="changing = false" />
                    <Button size="sm" variant="primary" :text="__('Save')" :disabled="changed.trim() === '' || busy" :loading="busy" @click="submitChange" />
                </div>
            </div>
            <div v-else class="mt-3 flex flex-wrap justify-end gap-2">
                <Button size="sm" variant="ghost" :text="__('Remove it')" :disabled="busy" @click="resolve('')" />
                <Button size="sm" :text="__('Change it')" :disabled="busy" @click="startChange" />
                <Button size="sm" variant="primary" :text="__('Looks right')" :disabled="busy" :loading="busy" @click="resolve(gap.value ?? gap.hint)" />
            </div>
        </template>

        <!-- A link to choose -->
        <template v-else>
            <p v-if="gap.hint" class="mt-0.5 text-xs text-gray-500">{{ __('Ghostwriter meant: :hint', { hint: gap.hint }) }}</p>
            <div v-if="!choosing" class="mt-2">
                <p v-if="suggestions === null" class="text-xs text-gray-500" role="status">{{ __('Looking for pages…') }}</p>
                <p v-else-if="!suggestions.length" class="text-xs text-gray-500">{{ __('No page matches it. Choose one instead.') }}</p>
                <ul v-else class="space-y-1" :aria-label="__('Suggested pages')">
                    <li v-for="entry in suggestions" :key="entry.value">
                        <button type="button" class="w-full rounded-md border border-gray-200 px-2 py-1.5 text-start hover:border-gray-400! dark:border-gray-700! dark:hover:border-gray-500!" :disabled="busy" @click="choose(entry)">
                            <span class="block font-medium">{{ __('Link to :title', { title: entry.title }) }}</span>
                            <span v-if="entry.url" class="block truncate text-xs text-gray-500">{{ entry.url }}</span>
                        </button>
                    </li>
                </ul>
            </div>
            <div v-else class="mt-2">
                <label :for="`${id}-query`" class="block text-xs text-gray-500">{{ __('Find a page by its title') }}</label>
                <input
                    :id="`${id}-query`"
                    ref="query"
                    v-model="query"
                    type="search"
                    class="mt-1 w-full rounded-md border border-gray-300 bg-white px-2 py-1.5 text-sm dark:border-gray-600! dark:bg-gray-800!"
                    autocomplete="off"
                    @input="typed"
                />
                <p class="sr-only" role="status">{{ searching ? __('Searching…') : results ? __n(':count page found|:count pages found', results.length) : '' }}</p>
                <ul v-if="results && results.length" class="mt-2 max-h-48 space-y-1 overflow-y-auto">
                    <li v-for="entry in results" :key="entry.value">
                        <button type="button" class="w-full rounded-md border border-gray-200 px-2 py-1.5 text-start hover:border-gray-400! dark:border-gray-700! dark:hover:border-gray-500!" :disabled="busy" @click="choose(entry)">
                            <span class="block font-medium">{{ entry.title }}</span>
                            <span v-if="entry.url" class="block truncate text-xs text-gray-500">{{ entry.url }}</span>
                        </button>
                    </li>
                </ul>
                <p v-else-if="results && !searching" class="mt-2 text-xs text-gray-500">{{ __('No page has that title.') }}</p>
            </div>
            <div class="mt-3 flex flex-wrap justify-end gap-2">
                <Button size="sm" variant="ghost" :text="__('Leave it for later')" @click="$emit('close', { refocus: true })" />
                <Button v-if="!choosing" size="sm" :text="__('Choose an entry')" @click="startChoosing" />
            </div>
        </template>
    </div>
</template>
