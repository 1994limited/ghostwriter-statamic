<!--
    Suggest edits on an existing entry's publish form: owns the Suggest
    edits guide (suggest/guide.js, in the Finish this page shell) and its
    Statamic adapter, asks the server for the review as the form holds the
    page now, and shows the confirm, with its call count, before a review
    runs as a queued job. Nothing here saves the entry: accepted changes go
    into the form; only alt text is saved, on the asset, after its own
    confirm.

    `?ghostwriter=suggest` (Content to revisit's Review) opens a review
    that still fits the page, or the confirm, ready to run.
-->
<script>
import { unref } from 'vue';
import { Alert, Button, Modal } from '@statamic/cms/ui';
import ghost from '../icon.js';
import { SuggestGuide } from '../suggest/guide.js';
import { suggestAdapter } from '../suggest/statamic.js';
import { onEditorsChange } from '../suggest/bard.js';
import { statamicAdapter } from '../finish/statamic.js';
import { request } from '../stock/request.js';

const POLL = 3000;

export default {
    components: { Alert, Button, Modal },

    props: {
        collection: { type: String, required: true },
        entry: { type: String, required: true },
        blueprint: { type: String, default: null },
        form: { type: Object, required: true },
        baseUrl: { type: String, required: true },
    },

    data() {
        return { slot: null, confirming: false, starting: false, info: null, error: null };
    },

    computed: {
        ghost: () => ghost,
    },

    mounted() {
        const save = document.querySelector('header[data-ui-header] [data-ui-button-group]')?.parentElement;

        if (save?.parentElement) {
            this.slot = document.createElement('div');
            this.slot.dataset.ghostwriter = 'suggest';
            this.slot.className = 'flex items-center';
            save.parentElement.insertBefore(this.slot, save);
        }

        const t = (text, params = {}) => __(text, params);
        // Finish this page's adapter, for what Suggest edits shares with it:
        // a Link field pointed at an entry, or its own picker opened.
        const finish = statamicAdapter({ form: this.form, baseUrl: this.baseUrl, payload: () => ({ collection: this.collection, entry: this.entry, values: this.values() }), recheck: () => {}, t });

        this.pending = [];
        this.guide = new SuggestGuide({
            adapter: suggestAdapter({ form: this.form, finish }),
            api: {
                decide: (list) => this.decide(list),
                another: (step) => this.post(`suggest/${this.reviewId()}/another`, { ...this.payload(), suggestion: step.id }).then((data) => data.versions),
                saveAlt: (step, alt) => this.post(`suggest/${this.reviewId()}/alt`, { suggestion: step.id, alt }),
                undoAlt: (step, before) => this.post(`suggest/${this.reviewId()}/unalt`, { suggestion: step.id, before }),
            },
            t,
            onStart: () => this.ask(),
            key: `${this.collection}.${this.entry}`,
        }).mount(this.slot);

        this.open = () => this.ask();
        Statamic.$events.$on('ghostwriter.suggest.open', this.open);

        // The form changed: each suggestion's words are looked for again.
        this.$watch(() => unref(this.form.values), () => {
            clearTimeout(this.typing);
            this.typing = setTimeout(() => this.guide.recheck(), 400);
        }, { deep: true });

        this.stopEditors = onEditorsChange(() => {
            clearTimeout(this.typing);
            this.typing = setTimeout(() => this.guide.recheck(), 100);
        });

        this.onVisible = () => document.visibilityState === 'visible' && this.polling && this.load();
        document.addEventListener('visibilitychange', this.onVisible);

        this.load({ initial: true });
    },

    beforeUnmount() {
        clearTimeout(this.timer);
        clearTimeout(this.typing);
        Statamic.$events.$off('ghostwriter.suggest.open', this.open);
        document.removeEventListener('visibilitychange', this.onVisible);
        this.stopEditors?.();
        this.guide?.destroy();
        this.slot?.remove();
    },

    methods: {
        values() {
            return JSON.parse(JSON.stringify(unref(this.form.values) ?? {}));
        },

        payload() {
            return { entry: this.entry, site: unref(this.form.site) ?? null, values: this.values() };
        },

        post(path, body) {
            return request(`${this.baseUrl}/${path}`, { method: 'POST', body });
        },

        reviewId() {
            return this.guide.data.review?.id;
        },

        async load({ initial = false, open = false } = {}) {
            clearTimeout(this.timer);

            let data;

            try {
                data = await this.post('suggest/guide', this.payload());
            } catch (error) {
                if (!initial) this.guide.flash(error.message);

                return;
            }

            const review = data.review;
            const wasRunning = this.guide.data.running;

            if (initial) {
                const asked = new URLSearchParams(window.location.search).get('ghostwriter') === 'suggest';
                const usable = review && review.status === 'ready' && review.fresh && data.suggestions.some((s) => s.state === 'open');

                if (asked) {
                    this.forget();

                    if (usable || data.running) this.guide.receive(data, { open: true });
                    else {
                        this.guide.receive(data);
                        this.ask(data);
                    }
                } else if (data.running) {
                    this.guide.receive(data, { show: true });
                } else if (review && review.status === 'ready' && data.suggestions.some((s) => s.state === 'open')) {
                    // A stored review: its pill, the guide minimised (it
                    // wasn't just asked for).
                    this.guide.receive(data, { show: true });
                } else {
                    this.guide.receive(data);
                }
            } else {
                this.guide.receive(data, { open: open || (wasRunning && !data.running && !this.guide.minimised) });
            }

            // Decisions made while it ran go in now it's finished.
            if (!data.running && review?.status === 'ready' && this.pending.length) {
                const list = this.pending.splice(0);
                this.decide(list);
            }

            this.polling = data.running;

            if (data.running) this.timer = setTimeout(() => (document.visibilityState === 'visible' ? this.load() : null), POLL);
        },

        // `?ghostwriter=suggest` is for this visit only.
        forget() {
            try {
                const url = new URL(window.location.href);
                url.searchParams.delete('ghostwriter');
                window.history.replaceState(window.history.state, '', url);
            } catch (error) {
                // The address just keeps it.
            }
        },

        async decide(list) {
            const review = this.guide.data.review;

            if (!review || review.status !== 'ready') {
                if (review && this.guide.data.running) this.pending.push(...list);

                return { decided: [] };
            }

            return this.post(`suggest/${review.id}/decide`, { decisions: list });
        },

        // The confirm: what a review reads and what it costs, before it runs.
        async ask(data = null) {
            this.error = null;
            this.info = data;
            this.confirming = true;

            if (!data) {
                try {
                    this.info = await this.post('suggest/guide', this.payload());
                } catch (error) {
                    this.error = error.message;
                }
            }
        },

        async start() {
            this.starting = true;
            this.error = null;

            try {
                const data = await this.post('suggest/start', this.payload());

                this.confirming = false;
                this.guide.local.clear();
                this.guide.receive(data, { open: true });
                this.polling = true;
                this.timer = setTimeout(() => this.load(), POLL);
            } catch (error) {
                this.error = error.message;
            } finally {
                this.starting = false;
            }
        },

        cost(info) {
            const calls = info?.calls ?? 1;

            return calls > 1
                ? __('This page is long, so it\'s read in :calls parts, each reviewed and then double-checked: :total calls to your AI provider.', { calls, total: calls * 2 })
                : __('Uses Ghostwriter twice: one call reviews the page, a second double-checks every suggestion before you see it.');
        },
    },
};
</script>

<template>
    <Modal v-model:open="confirming" :title="__('Suggest edits')" :icon="ghost">
        <div class="space-y-3 p-1 text-sm" data-ghostwriter-suggest-confirm>
            <p>{{ __('Ghostwriter reads this page against your voice guide and the rest of the site, and suggests small changes for you to accept or not. Each suggestion is checked in its paragraph before you see it.') }}</p>
            <p>{{ __('Nothing changes until you accept a suggestion, and nothing is saved until you save. Alt text is the one exception: it\'s saved to the image, after you confirm.') }}</p>
            <template v-if="info">
                <p v-if="info.candidates" class="text-gray-600 dark:text-gray-400!">{{ info.candidates === 1 ? __('1 thing found already, without AI, to check in context.') : __(':count things found already, without AI, to check in context.', { count: info.candidates }) }}</p>
                <Alert v-if="!info.configured" variant="warning" :text="__('Add an API key first: Suggest edits reads the page with your AI provider.')" />
                <p v-else class="font-medium">{{ cost(info) }}</p>
            </template>
            <p v-else-if="!error" class="text-gray-500">{{ __('Loading…') }}</p>
            <Alert v-if="error" variant="error" :text="error" />
            <div class="flex flex-wrap justify-end gap-2 pt-2">
                <Button variant="ghost" :text="__('Cancel')" @click="confirming = false" />
                <Button variant="primary" :icon="ghost" :text="__('Suggest edits')" :loading="starting" :disabled="!info || !info.configured || starting" data-ghostwriter-suggest-start @click="start" />
            </div>
        </div>
    </Modal>
</template>
