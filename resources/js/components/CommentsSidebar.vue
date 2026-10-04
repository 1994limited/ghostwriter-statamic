<!--
    The comments in Comment mode. First the editor's pins not sent yet
    (theirs alone, kept in this browser until they apply them), each to
    edit or delete, with **Apply N comments**; then every comment sent in
    the conversation, with what became of it. It is the full keyboard and
    screen-reader interface for comments: every pin has Show on page, and
    every action on the page is here too.
-->
<script>
import { Button } from '@statamic/cms/ui';
import CommentItem from './CommentItem.vue';

export default {
    components: { Button, CommentItem },

    props: {
        // The editor's pins not sent yet, numbered (pendingPins()); each may carry `error`.
        pending: { type: Array, default: () => [] },
        // Comments sent, from the server (session.comments.pins).
        sent: { type: Array, default: () => [] },
        working: { type: Boolean, default: false },
        applying: { type: Boolean, default: false },
        elapsed: { type: String, default: '' },
        waitingOn: { type: String, default: null },
        // A request in flight: {action, number}.
        busy: { type: Object, default: null },
        // The comment to show as current (a pin clicked).
        focused: { type: Number, default: null },
    },

    // `apply`; `edit` (pending pin); `remove` (pending pin); `page` ({body, done}); `show` (number); `putBack`, `resolve`, `reopen` (sent pin); `close`.
    emits: ['apply', 'edit', 'remove', 'page', 'show', 'putBack', 'resolve', 'reopen', 'close'],

    data() {
        return { pageOpen: false, pageBody: '', showResolved: false };
    },

    computed: {
        toSend() {
            return Math.min(this.pending.length, 12);
        },

        applyText() {
            if (this.applying) return this.__('Revising…');
            if (!this.toSend) return this.__('No comments to send');

            return this.__n('Apply :count comment|Apply :count comments', this.toSend);
        },

        open() {
            return this.sent.filter((pin) => pin.status !== 'resolved').reverse();
        },

        resolved() {
            return this.sent.filter((pin) => pin.status === 'resolved').reverse();
        },
    },

    watch: {
        focused(number) {
            if (number === null) return;

            this.$nextTick(() => {
                const card = this.$el.querySelector(`[data-comment="${number}"]`);
                card?.scrollIntoView?.({ block: 'nearest' });
                card?.focus?.();
            });
        },
    },

    methods: {
        where(pin) {
            if (pin.kind === 'page') return this.__('On the whole page');

            return pin.label ? this.__('On :label', { label: pin.label }) : this.__('On a block');
        },

        postPage() {
            const body = this.pageBody.trim();

            if (!body) return;

            this.$emit('page', { body, done: () => ((this.pageBody = ''), (this.pageOpen = false)) });
        },
    },
};
</script>

<template>
    <section
        class="flex min-h-0 flex-col rounded-lg border border-gray-200 bg-gray-50 text-sm dark:border-gray-700! dark:bg-gray-900!"
        role="region"
        :aria-label="__('Comments')"
        data-ghostwriter-comments
    >
        <header class="flex items-center gap-2 border-b border-gray-200 px-3 py-2 dark:border-gray-700!">
            <h3 class="font-semibold text-gray-900 dark:text-gray-100!">{{ __('Comments') }}</h3>
            <span class="text-xs text-gray-500">{{ __(':count not sent', { count: pending.length }) }}</span>
            <span class="grow"></span>
            <Button size="sm" variant="ghost" :text="__('Close')" :aria-label="__('Close comments')" @click="$emit('close')" />
        </header>

        <div class="min-h-0 flex-1 space-y-2 overflow-y-auto p-2.5">
            <p v-if="!pending.length && !sent.length" class="px-1 py-2 text-gray-500">{{ __('Click a block on the page, or select some words in it, to leave a comment. Comments are sent together, and only those blocks change.') }}</p>

            <!-- Not sent yet: the editor's own. -->
            <article
                v-for="pin in pending"
                :key="pin.id"
                :data-comment="pin.number"
                tabindex="-1"
                class="rounded-lg border bg-white p-2.5 outline-none dark:bg-gray-800!"
                :class="pin.error ? 'border-red-400 dark:border-red-500!' : focused === pin.number ? 'border-indigo-500 ring-1 ring-indigo-500' : 'border-amber-300 dark:border-amber-500/60!'"
                :aria-label="__('Comment :number, not sent', { number: pin.number })"
            >
                <div class="flex items-center gap-1.5">
                    <span class="flex size-5 shrink-0 items-center justify-center rounded-full rounded-bl-sm bg-amber-400 text-[11px] font-semibold text-gray-900" aria-hidden="true">{{ pin.number }}</span>
                    <span class="min-w-0 flex-1 truncate text-xs text-gray-500 dark:text-gray-400!">{{ where(pin) }}</span>
                    <span class="shrink-0 rounded-full bg-amber-100 px-2 text-[11px] whitespace-nowrap text-amber-800 dark:bg-amber-500/20! dark:text-amber-300!">{{ __('Not sent') }}</span>
                </div>
                <p v-if="pin.quote" class="mt-1.5 line-clamp-3 border-s-2 border-gray-300 ps-2 text-xs text-gray-600 italic dark:border-gray-600! dark:text-gray-300!">“{{ pin.quote }}”</p>
                <p class="mt-1 break-words whitespace-pre-wrap">{{ pin.body }}</p>
                <p v-if="pin.error" class="mt-1 text-xs text-red-700 dark:text-red-300!" role="alert">{{ pin.error }}</p>
                <div class="mt-2 flex flex-wrap gap-1">
                    <Button v-if="pin.kind !== 'page'" size="xs" variant="ghost" :text="__('Show on page')" @click="$emit('show', pin.number)" />
                    <Button size="xs" variant="ghost" :text="__('Edit')" @click="$emit('edit', pin)" />
                    <Button size="xs" variant="ghost" :text="__('Delete')" @click="$emit('remove', pin)" />
                </div>
            </article>

            <div>
                <Button v-if="!pageOpen" size="xs" variant="ghost" :text="__('Comment on the whole page')" @click="pageOpen = true" />
                <div v-else class="rounded-lg border border-gray-200 bg-white p-2 dark:border-gray-700! dark:bg-gray-800!">
                    <label for="gw-page-comment" class="mb-1 block text-xs text-gray-500">{{ __('On the whole page') }}</label>
                    <textarea
                        id="gw-page-comment"
                        v-model="pageBody"
                        rows="2"
                        maxlength="2000"
                        class="block w-full resize-y rounded-md border border-gray-300 bg-white px-2 py-1 text-xs text-gray-900 dark:border-gray-600! dark:bg-gray-900! dark:text-gray-100!"
                        :placeholder="__('What should change?')"
                        @keydown.meta.enter.stop.prevent="postPage"
                        @keydown.ctrl.enter.stop.prevent="postPage"
                        @keydown.esc.stop.prevent="pageOpen = false"
                    ></textarea>
                    <div class="mt-1 flex justify-end gap-1">
                        <Button size="xs" variant="ghost" :text="__('Cancel')" @click="pageOpen = false" />
                        <Button size="xs" :text="__('Add')" :disabled="!pageBody.trim()" @click="postPage" />
                    </div>
                </div>
            </div>

            <template v-if="open.length">
                <h4 class="px-1 pt-2 text-xs font-semibold tracking-wide text-gray-500 uppercase">{{ __('Sent') }}</h4>
                <CommentItem
                    v-for="pin in open"
                    :key="pin.number"
                    :pin="pin"
                    :pending="busy"
                    :working="working"
                    :focused="focused === pin.number"
                    @show="$emit('show', $event)"
                    @put-back="$emit('putBack', $event)"
                    @resolve="$emit('resolve', $event)"
                    @reopen="$emit('reopen', $event)"
                />
            </template>

            <template v-if="resolved.length">
                <button type="button" class="px-1 pt-2 text-xs font-medium text-gray-500 underline" :aria-expanded="showResolved ? 'true' : 'false'" @click="showResolved = !showResolved">
                    {{ showResolved ? __('Hide :count resolved', { count: resolved.length }) : __('Show :count resolved', { count: resolved.length }) }}
                </button>
                <template v-if="showResolved">
                    <CommentItem v-for="pin in resolved" :key="pin.number" :pin="pin" :pending="busy" :working="working" @reopen="$emit('reopen', $event)" />
                </template>
            </template>
        </div>

        <footer class="space-y-1.5 border-t border-gray-200 p-2.5 dark:border-gray-700!">
            <Button class="w-full" variant="primary" :text="applyText" :loading="applying || busy?.action === 'apply'" :disabled="working || !toSend || !!busy" @click="$emit('apply')" />
            <p v-if="applying" class="text-xs text-gray-500" role="status">{{ waitingOn ? __(':name is waiting on Ghostwriter', { name: waitingOn }) : __('Ghostwriter is revising the commented blocks.') }} <span class="tabular-nums">{{ elapsed }}</span></p>
            <p v-else-if="working" class="text-xs text-gray-500">{{ __('Ghostwriter is working on this piece. Apply your comments when it has finished; you can keep adding them meanwhile.') }}</p>
            <p v-else class="text-xs text-gray-500">{{ __('Sent together as one message in the conversation. Only the commented blocks change; the layout stays unless a comment asks for a new one. Your comments are yours until you apply them.') }}</p>
        </footer>
    </section>
</template>
