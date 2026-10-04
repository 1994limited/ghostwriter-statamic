<!--
    A comment pinned where it was made: the block clicked (or the words
    selected) in the Preview, inside the draft's pane, over the frame. It
    isn't sent: it joins the editor's pins, to apply together. Opened again
    on a pin not sent yet, it edits its words or deletes it. It's a dialog:
    focus goes to its box, Tab stays in it, ⌘↵ (Ctrl+↵) adds and Esc
    cancels; the panel puts focus back on the block.
-->
<script>
import { Button } from '@statamic/cms/ui';

let ids = 0;

export default {
    components: { Button },

    props: {
        // () => {left, top, right, bottom} in the CP page's coordinates, or null when it can't be placed.
        anchor: { type: Function, required: true },
        // The positioned pane it sits in.
        container: { type: Object, default: null },
        // The frame it follows when the page scrolls.
        frame: { type: Object, default: null },
        label: { type: String, default: '' },
        quote: { type: String, default: null },
        busy: { type: Boolean, default: false },
        // Why the last post was refused, shown beside the box.
        error: { type: String, default: null },
        // A pin not sent yet, opened again: Save and Delete.
        editing: { type: Boolean, default: false },
        initial: { type: String, default: '' },
    },

    emits: ['post', 'remove', 'cancel'],

    data() {
        return {
            id: `gw-comment-${++ids}`,
            body: this.initial,
            box: { top: 0, left: 0, width: 320 },
        };
    },

    computed: {
        heading() {
            if (this.editing) return this.label ? this.__('Comment on :label', { label: this.label }) : this.__('Comment');

            return this.label ? this.__('New comment on :label', { label: this.label }) : this.__('New comment');
        },

        mac() {
            return /Mac|iPhone|iPad/.test(navigator.platform ?? '');
        },
    },

    mounted() {
        this.place();
        this.scrollers = [this.container, window, this.frame?.contentWindow].filter(Boolean);
        this.scrollers.forEach((target) => target.addEventListener('scroll', this.place, { passive: true }));
        window.addEventListener('resize', this.place);
        this.$nextTick(() => {
            this.place();
            this.$refs.box?.focus();
        });
    },

    beforeUnmount() {
        this.scrollers.forEach((target) => target.removeEventListener('scroll', this.place));
        window.removeEventListener('resize', this.place);
    },

    methods: {
        // Below what it's on (above when there's no room), inside the pane.
        place() {
            const pane = this.container;
            const anchor = this.anchor();

            if (!pane || !anchor) return;

            const outer = pane.getBoundingClientRect();
            const width = Math.min(340, Math.max(220, pane.clientWidth - 16));
            const left = Math.min(Math.max(8, anchor.left - outer.left), Math.max(8, pane.clientWidth - width - 8));
            const height = this.$refs.dialog?.offsetHeight ?? 180;
            const bottom = Math.min(anchor.bottom, outer.bottom - 40);
            const below = bottom - outer.top + pane.scrollTop + 6;
            const above = anchor.top - outer.top + pane.scrollTop - height - 6;
            const roomBelow = outer.bottom - bottom;

            this.box = { width, left, top: roomBelow < height + 12 && above > pane.scrollTop ? above : Math.max(pane.scrollTop + 8, below) };
        },

        post() {
            if (!this.body.trim() || this.busy) return;

            this.$emit('post', this.body.trim());
        },

        keydown(event) {
            if (event.key === 'Escape') {
                event.preventDefault();
                event.stopPropagation();
                this.$emit('cancel');
            } else if (event.key === 'Enter' && (event.metaKey || event.ctrlKey)) {
                // Not the publish form's own ⌘↵ (save) as well.
                event.preventDefault();
                event.stopPropagation();
                this.post();
            } else if (event.key === 'Tab') {
                this.trap(event);
            }
        },

        trap(event) {
            const focusable = [...this.$refs.dialog.querySelectorAll('textarea, button:not([disabled])')].filter((element) => element.offsetParent !== null);

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
    },
};
</script>

<template>
    <div
        ref="dialog"
        role="dialog"
        :aria-label="heading"
        :aria-describedby="`${id}-hint`"
        class="absolute z-30 rounded-lg border border-gray-200 bg-white p-3 text-sm shadow-xl dark:border-gray-700! dark:bg-gray-900!"
        :style="{ top: `${box.top}px`, left: `${box.left}px`, width: `${box.width}px` }"
        data-ghostwriter-composer
        @keydown="keydown"
    >
        <p class="mb-1.5 text-xs text-gray-500 dark:text-gray-400!">{{ heading }}</p>
        <p class="mb-1.5 text-xs text-gray-500 dark:text-gray-400!">{{ __('Not sent yet: apply your comments together from the list.') }}</p>
        <p v-if="quote" class="mb-2 line-clamp-3 border-s-2 border-indigo-400 ps-2 text-xs text-gray-600 italic dark:text-gray-300!">“{{ quote }}”</p>
        <textarea
            ref="box"
            v-model="body"
            rows="3"
            maxlength="2000"
            class="block w-full resize-y rounded-md border border-gray-300 bg-white px-2 py-1.5 text-sm text-gray-900 dark:border-gray-600! dark:bg-gray-800! dark:text-gray-100!"
            :aria-label="__('Comment')"
            :aria-invalid="error ? 'true' : null"
            :aria-errormessage="error ? `${id}-error` : null"
            :placeholder="__('What should change here?')"
        ></textarea>
        <p v-if="error" :id="`${id}-error`" class="mt-1.5 text-xs text-red-700 dark:text-red-300!" role="alert">{{ error }}</p>
        <div class="mt-2 flex flex-wrap items-center justify-end gap-2">
            <span :id="`${id}-hint`" class="me-auto text-xs text-gray-500 dark:text-gray-400!">{{ mac ? __('⌘↵ to add · Esc to cancel') : __('Ctrl+Enter to add · Esc to cancel') }}</span>
            <Button v-if="editing" size="sm" variant="ghost" :text="__('Delete')" @click="$emit('remove')" />
            <Button size="sm" variant="ghost" :text="__('Cancel')" @click="$emit('cancel')" />
            <Button size="sm" variant="primary" :text="editing ? __('Save') : __('Add')" :loading="busy" :disabled="busy || !body.trim()" @click="post" />
        </div>
    </div>
</template>
