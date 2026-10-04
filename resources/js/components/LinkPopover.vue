<!--
    A link Ghostwriter added to another page of the site, in the Text tab
    (SEO layer §7.5): hovering, focusing or clicking its dotted words opens
    this small popover beside them with the page it goes to (title, type,
    address), why it was chosen, Open page, and Remove link, which keeps the
    words. It isn't modal: the draft stays usable, Esc closes it and puts
    focus back on the words, and moving away closes it after a moment.
-->
<script>
import { Button } from '@statamic/cms/ui';

let ids = 0;

export default {
    components: { Button },

    props: {
        // {words, href, title, type, url, open_url, why}
        link: { type: Object, required: true },
        // The link's element in the draft.
        element: { type: Object, required: true },
        // The positioned pane the popover sits in.
        container: { type: Object, default: null },
        busy: { type: Boolean, default: false },
        disabled: { type: Boolean, default: false },
    },

    // `remove` {href}; `close` {refocus}; `stay` (the pointer is over it).
    emits: ['remove', 'close', 'stay', 'leave'],

    data() {
        return { id: `gw-link-${++ids}`, box: { top: 0, left: 0, width: 320 } };
    },

    mounted() {
        this.place();
        this.container?.addEventListener('scroll', this.place, { passive: true });
        window.addEventListener('resize', this.place);
    },

    beforeUnmount() {
        this.container?.removeEventListener('scroll', this.place);
        window.removeEventListener('resize', this.place);
    },

    methods: {
        // Below the words (above them when there's no room), inside the pane.
        place() {
            const pane = this.container;

            if (!pane || !this.element?.isConnected) return;

            const outer = pane.getBoundingClientRect();
            const anchor = this.element.getBoundingClientRect();
            const width = Math.min(330, Math.max(220, pane.clientWidth - 16));
            const left = Math.min(Math.max(8, anchor.left - outer.left), Math.max(8, pane.clientWidth - width - 8));
            const height = this.$refs.dialog?.offsetHeight ?? 150;
            const below = anchor.bottom - outer.top + pane.scrollTop + 6;
            const above = anchor.top - outer.top + pane.scrollTop - height - 6;
            const roomBelow = outer.bottom - anchor.bottom;

            this.box = { width, left, top: roomBelow < height + 12 && above > pane.scrollTop ? above : below };
        },

        focus() {
            this.$refs.dialog?.querySelector('button:not([disabled]), a[href]')?.focus();
        },
    },
};
</script>

<template>
    <div
        ref="dialog"
        role="dialog"
        :aria-labelledby="`${id}-title`"
        :aria-describedby="`${id}-by`"
        tabindex="-1"
        class="absolute z-30 rounded-lg border border-gray-200 bg-white p-3 text-sm shadow-lg dark:border-gray-700! dark:bg-gray-900!"
        :style="{ top: `${box.top}px`, left: `${box.left}px`, width: `${box.width}px` }"
        data-ghostwriter-link-popover
        @mouseenter="$emit('stay')"
        @mouseleave="$emit('leave')"
        @keydown.esc.stop.prevent="$emit('close', { refocus: true })"
    >
        <p class="flex flex-wrap items-center gap-1.5">
            <span :id="`${id}-title`" class="font-medium text-gray-900 dark:text-gray-100!">{{ link.title }}</span>
            <span v-if="link.type" class="rounded-full border border-gray-200 px-1.5 text-[11px] whitespace-nowrap text-gray-500 dark:border-gray-700!">{{ link.type }}</span>
        </p>
        <p v-if="link.url" class="mt-0.5 truncate font-mono text-xs text-gray-500" :title="link.url">{{ link.url }}</p>
        <p v-if="link.why" class="mt-1.5 text-xs text-gray-600 dark:text-gray-400!">{{ link.why }}</p>
        <p :id="`${id}-by`" class="mt-2 flex items-center gap-1.5 text-xs text-gray-500">
            <svg class="size-3.5 shrink-0" viewBox="0 0 14 14" aria-hidden="true"><path fill="currentColor" fill-rule="evenodd" d="M2.5 12.5V6a4.5 4.5 0 0 1 9 0V9.5H8.5V12.5Z M4.75 6.25a.65.65 0 1 0 1.3 0a.65.65 0 1 0-1.3 0Z M7.95 6.25a.65.65 0 1 0 1.3 0a.65.65 0 1 0-1.3 0Z" /></svg>
            {{ __('Added by Ghostwriter. Removing it keeps the words.') }}
        </p>
        <div class="mt-2.5 flex flex-wrap items-center gap-2">
            <a
                v-if="link.open_url"
                :href="link.open_url"
                target="_blank"
                rel="noopener"
                class="inline-flex items-center rounded-md border border-gray-200 px-2 py-1 text-xs font-medium hover:border-gray-400! dark:border-gray-700!"
            >{{ __('Open page') }} <span aria-hidden="true">&nbsp;↗</span><span class="sr-only"> ({{ __('opens in a new tab') }})</span></a>
            <Button size="sm" :text="__('Remove link')" :disabled="busy || disabled" :loading="busy" data-ghostwriter-remove-link @click="$emit('remove', { href: link.href })" />
        </div>
    </div>
</template>
