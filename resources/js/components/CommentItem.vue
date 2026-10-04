<!--
    One comment sent to Ghostwriter, with what became of it: its block's
    label, its words, its state, Ghostwriter's reply, the before and after
    word by word, and Show on page, Put it back, Resolve and Reopen. Used in
    the chat's answer message and in the comments sidebar. Ghostwriter's
    reply is HTML from escaped markdown (C6); the comment's own words are
    shown as text.
-->
<script>
import { Button } from '@statamic/cms/ui';

export default {
    components: { Button },

    props: {
        pin: { type: Object, required: true },
        // A request in flight: {action, number}.
        pending: { type: Object, default: null },
        working: { type: Boolean, default: false },
        // Show the comment's own words (the sidebar), or only the answer (the chat lists them above).
        words: { type: Boolean, default: true },
        focused: { type: Boolean, default: false },
    },

    // `show` (number), `putBack` (pin), `resolve` (pin), `reopen` (pin).
    emits: ['show', 'putBack', 'resolve', 'reopen'],

    data() {
        return { diff: false };
    },

    computed: {
        where() {
            if (this.pin.kind === 'page') return this.__('On the whole page');

            const label = this.pin.label ? this.__('On :label', { label: this.pin.label }) : this.__('On a block');

            return this.pin.blocks?.length > 1 ? `${label} · ${this.__('spans :count blocks', { count: this.pin.blocks.length })}` : label;
        },

        resolved() {
            return this.pin.status === 'resolved';
        },

        chip() {
            return {
                sending: 'bg-gray-100 text-gray-600 dark:bg-gray-700! dark:text-gray-300!',
                changed: 'bg-green-100 text-green-800 dark:bg-green-500/20! dark:text-green-300!',
                replied: 'bg-indigo-100 text-indigo-800 dark:bg-indigo-500/20! dark:text-indigo-300!',
                resolved: 'bg-gray-100 text-gray-500 dark:bg-gray-800! dark:text-gray-400!',
            }[this.pin.status] ?? 'bg-red-50 text-red-700 dark:bg-red-500/20! dark:text-red-300!';
        },

        dot() {
            return {
                sending: 'bg-gray-400 text-white',
                changed: 'bg-green-600 text-white',
                replied: 'bg-indigo-600 text-white',
                resolved: 'bg-gray-300 text-gray-600 dark:bg-gray-700! dark:text-gray-300!',
            }[this.pin.status] ?? 'border border-red-600 bg-white text-red-700';
        },

        // Resolve needs an answer; a run going now has none yet.
        canResolve() {
            return this.pin.answer !== null && !this.resolved;
        },
    },

    methods: {
        busy(action) {
            return this.pending?.action === action && this.pending?.number === this.pin.number;
        },
    },
};
</script>

<template>
    <article
        :data-comment="pin.number"
        tabindex="-1"
        class="rounded-lg border bg-white p-2.5 text-sm outline-none dark:bg-gray-800!"
        :class="focused ? 'border-indigo-500 ring-1 ring-indigo-500' : 'border-gray-200 dark:border-gray-700!'"
        :aria-label="__('Comment :number, :state', { number: pin.number, state: pin.state })"
    >
        <div class="flex items-center gap-1.5">
            <span class="flex size-5 shrink-0 items-center justify-center rounded-full rounded-bl-sm text-[11px] font-semibold" :class="dot" aria-hidden="true">{{ pin.number }}</span>
            <span class="min-w-0 flex-1 truncate text-xs text-gray-500 dark:text-gray-400!" :title="where">{{ where }}</span>
            <span class="shrink-0 rounded-full px-2 text-[11px] whitespace-nowrap" :class="chip">{{ pin.status === 'sending' ? __('Revising…') : pin.state }}</span>
        </div>

        <template v-if="words">
            <p v-if="pin.quote" class="mt-1.5 line-clamp-3 border-s-2 border-gray-300 ps-2 text-xs text-gray-600 italic dark:border-gray-600! dark:text-gray-300!">“{{ pin.quote }}”</p>
            <p class="mt-1 break-words whitespace-pre-wrap" :class="resolved ? 'line-clamp-2 text-gray-500' : ''"><span v-if="pin.by" class="text-[11px] text-gray-500 dark:text-gray-400!">{{ pin.by }} · </span>{{ pin.body }}</p>
        </template>

        <p v-if="resolved" class="mt-1.5 text-xs text-gray-500">
            ✓ {{ __('Resolved by :name', { name: pin.resolved_by ?? __('someone') }) }}
            <span class="text-gray-400">·</span>
            <button type="button" class="font-medium text-indigo-700 underline dark:text-indigo-300!" :disabled="busy('reopen')" @click="$emit('reopen', pin)">{{ __('Reopen') }}</button>
        </p>

        <template v-else>
            <p v-if="pin.status === 'sending'" class="mt-1.5 flex items-center gap-1.5 border-s-2 border-indigo-400 ps-2 text-xs text-gray-500">
                <svg class="size-3.5 animate-spin motion-reduce:animate-none" viewBox="0 0 24 24" fill="none" aria-hidden="true"><circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="3" class="opacity-25" /><path d="M21 12a9 9 0 0 0-9-9" stroke="currentColor" stroke-width="3" stroke-linecap="round" /></svg>
                {{ __('Revising this block…') }}
            </p>
            <div v-else-if="pin.reply" class="mt-1.5 border-s-2 border-indigo-400 ps-2">
                <p class="text-[11px] text-gray-500 dark:text-gray-400!">{{ __('Ghostwriter') }}</p>
                <div class="gw-prose gw-note break-words" v-html="pin.reply"></div>
            </div>

            <p v-if="pin.status === 'detached'" class="mt-1.5 text-xs text-red-700 dark:text-red-300!">{{ __('The words this was about have gone from the draft.') }}</p>
            <p v-else-if="!pin.in_layout" class="mt-1.5 text-xs text-gray-500">{{ __('Not in this layout. It comes back when you switch to one that uses this text.') }}</p>
            <p v-if="pin.put_back_by" class="mt-1.5 text-xs text-gray-500">{{ __('Put back by :name.', { name: pin.put_back_by }) }}</p>

            <!-- Before / after: the change, word by word. -->
            <div v-if="pin.changes?.length && diff" class="mt-2 space-y-1 rounded-md border border-gray-200 p-2 text-xs leading-relaxed dark:border-gray-700!" role="group" :aria-label="__('Before and after')">
                <p v-for="(change, index) in pin.changes" :key="index" class="break-words">
                    <template v-if="change.layout">{{ __('A new arrangement of this block; the words are the same.') }}</template>
                    <template v-else>
                        <template v-for="(run, at) in change.diff" :key="at"><del v-if="run[0] === '-'" class="bg-red-100 text-red-800 decoration-red-500 dark:bg-red-500/20! dark:text-red-200!"><span class="sr-only">{{ __('taken out:') }} </span>{{ run[1] }}</del><ins v-else-if="run[0] === '+'" class="bg-green-100 text-green-900 no-underline dark:bg-green-500/20! dark:text-green-200!"><span class="sr-only">{{ __('put in:') }} </span>{{ run[1] }}</ins><span v-else>{{ run[1] }}</span></template>
                    </template>
                    <span v-for="filled in change.filled" :key="filled.ask" class="mt-1 block text-gray-500">{{ __('Filled in from your comment: “:value”', { value: filled.value }) }}</span>
                </p>
            </div>

            <div v-if="pin.status !== 'sending'" class="mt-2 flex flex-wrap gap-1">
                <Button v-if="pin.in_layout && pin.kind !== 'page' && pin.status !== 'detached'" size="xs" variant="ghost" :text="__('Show on page')" @click="$emit('show', pin.number)" />
                <Button v-if="pin.changes?.length" size="xs" variant="ghost" :text="__('Before / after')" :aria-pressed="diff ? 'true' : 'false'" @click="diff = !diff" />
                <Button v-if="pin.can_put_back" size="xs" variant="ghost" :text="__('Put it back')" :loading="busy('putBack')" :disabled="working" @click="$emit('putBack', pin)" />
                <Button v-if="canResolve" size="xs" :text="__('Resolve')" :loading="busy('resolve')" @click="$emit('resolve', pin)" />
            </div>
        </template>
    </article>
</template>
