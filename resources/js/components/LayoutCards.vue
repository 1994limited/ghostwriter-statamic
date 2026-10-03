<!--
    The layout cards above the draft: the writer's draft and up to two other
    layouts of the same words, each with a live thumbnail of the page (the
    Preview's render at thumbnail scale), its name, a line about it, how many
    blocks it has and "Suggested" on the one most like the site's pages.
    Choosing one is stored on the piece, so it's everyone's; it costs nothing.

    - Thumbnails render after the page preview has loaded, one at a time,
      the chosen layout's first (the same render as the page). Each is the
      preview's address for that layout, reused while the draft is the same,
      in a frame with no scripts (sandbox="allow-same-origin": the page's own
      frame rules refuse an opaque origin, and nothing can run in it). Each
      starts a little above the first block where the layouts differ.
    - While the planner is still looking, skeleton cards say so. A layout an
      edit left behind says "Needs refreshing", and Refresh layouts asks for
      new ones (one call). One layout alone: no row.
    - The cards are a radio group: arrows move and choose, Tab leaves.
-->
<script>
import { Button } from '@statamic/cms/ui';
import { debounce, gapLabels } from '../preview/overlay.js';
import { markGaps } from '../preview/markers.js';
import { canRead, locate, measure } from '../preview/locator.js';
import { THUMB_HEIGHT, THUMB_RENDER_WIDTH, cardName, cardsFor, moveTo, sharedStart, startBlock, thumbOrder, thumbScale } from '../preview/layouts.js';

export default {
    components: { Button },

    props: {
        session: { type: Object, required: true },
        baseUrl: { type: String, required: true },
        blueprint: { type: String, default: null },
        formValues: { type: Function, default: () => ({}) },
        // Whether thumbnails may render: the page preview has loaded (or isn't showing).
        ready: { type: Boolean, default: false },
        // False once the page preview has failed: the cards show their blocks.
        thumbnails: { type: Boolean, default: true },
        disabled: { type: Boolean, default: false },
        // The card being chosen, while the server stores it.
        pending: { type: String, default: null },
        refreshing: { type: Boolean, default: false },
    },

    emits: ['choose', 'refresh'],

    data() {
        return {
            // planId => {url, key}
            thumbs: {},
            thumbWidth: 0,
            renderHeight: THUMB_RENDER_WIDTH,
        };
    },

    computed: {
        row() {
            return cardsFor(this.session.layouts);
        },

        previewable() {
            return Boolean(this.session.page_preview) && this.thumbnails;
        },

        scale() {
            return thumbScale(this.thumbWidth);
        },

        frameStyle() {
            return {
                width: `${THUMB_RENDER_WIDTH}px`,
                height: `${Math.round(THUMB_HEIGHT / this.scale)}px`,
                transform: `scale(${this.scale})`,
                transformOrigin: '0 0',
            };
        },

        // What the thumbnails depend on: the words, the extras, the layouts and the images.
        thumbKey() {
            const layouts = (this.session.layouts?.plans ?? []).map((plan) => [plan.id, plan.stale, plan.blocks]);

            return JSON.stringify([this.session.id, this.session.draft, this.session.extras, layouts, (this.session.images ?? []).map((image) => [image.key, image.path ?? null])]);
        },
    },

    watch: {
        thumbKey() {
            this.debounced();
        },

        ready(ready) {
            if (ready) this.debounced();
        },

        'row.show'(show) {
            if (show) this.$nextTick(() => this.measure());
        },
    },

    created() {
        this.debounced = debounce(() => this.renderThumbs(), 800);
    },

    mounted() {
        this.resizer = new ResizeObserver(() => this.measure());
        this.resizer.observe(this.$el);
        this.measure();

        if (this.ready) this.debounced();
    },

    beforeUnmount() {
        this.debounced.cancel();
        this.resizer?.disconnect();
        this.stopped = true;
    },

    methods: {
        name(card) {
            return cardName(card, (text, params) => this.__(text, params));
        },

        measure() {
            const thumb = this.$el?.querySelector?.('[data-gw-thumb]');

            this.thumbWidth = Math.floor(thumb?.clientWidth ?? 0);
        },

        // One at a time, the chosen layout's first; a newer draft starts again.
        async renderThumbs() {
            if (!this.previewable || !this.ready || !this.row.show || !this.session.id) return;

            const key = this.thumbKey;
            this.running = key;

            for (const plan of thumbOrder(this.row.cards)) {
                if (this.stopped || this.running !== key) return;
                if (this.thumbs[plan]?.key === key) continue;

                try {
                    const { data } = await this.$axios.post(`${this.baseUrl}/sessions/${this.session.id}/preview`, {
                        blueprint: this.blueprint,
                        values: this.formValues(),
                        site: window.Statamic?.$config?.get?.('selectedSite') ?? null,
                        plan,
                    });

                    if (this.running !== key) return;

                    this.thumbs = { ...this.thumbs, [plan]: data.available ? { url: data.url, map: data.map, key } : { url: null, key } };
                } catch (error) {
                    this.thumbs = { ...this.thumbs, [plan]: { url: null, key } };
                }
            }
        },

        // A thumbnail starts a little above the first block where the
        // layouts differ, with its gap markers as chips, as on the Preview
        // tab. The frame runs no scripts; it's read from here.
        focus(card, event) {
            const frame = event.target;
            const key = startBlock(this.thumbs[card.id]?.map, sharedStart(this.row.cards));

            if (!canRead(frame)) return;

            try {
                const doc = frame.contentDocument;
                const result = locate(doc, this.thumbs[card.id]?.map ?? []);

                markGaps(doc, { labels: gapLabels((text) => this.__(text)) });

                if (!key) return;

                const box = measure(result.byKey[key], doc.defaultView);

                if (box) doc.defaultView.scrollTo(0, Math.max(0, box.top - 48));
            } catch (error) {
                // The top of the page will do.
            }
        },

        choose(card) {
            if (this.disabled || card.stale || card.chosen) return;

            this.$emit('choose', card.id);
        },

        key(event, index) {
            const to = moveTo(this.row.cards, index, event.key);

            if (to === null) return;

            event.preventDefault();
            this.choose(this.row.cards[to]);
            this.$nextTick(() => this.$el.querySelector(`[data-gw-card="${this.row.cards[to].id}"]`)?.focus());
        },

        // Roving tabindex: the chosen card is the one Tab reaches.
        tabindex(card) {
            return card.chosen || (!this.row.cards.some((other) => other.chosen) && card === this.row.cards[0]) ? 0 : -1;
        },
    },
};
</script>

<template>
    <div>
    <div v-if="row.show" class="mb-4 border-b border-gray-200 pb-4 dark:border-gray-700!" data-ghostwriter-layouts>
        <div class="mb-2 flex flex-wrap items-center gap-x-3 gap-y-1">
            <span id="gw-layouts-label" class="text-sm font-medium">{{ __('Layout') }}</span>
            <span class="text-xs text-gray-500">{{ __('The same words, laid out differently. Choosing one changes nothing until you use the draft.') }}</span>
            <span class="grow"></span>
            <span v-if="row.planning && !row.skeletons" class="flex items-center gap-1.5 text-xs text-gray-500" role="status">
                <svg class="size-3.5 animate-spin motion-reduce:animate-none" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                    <circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="3" class="opacity-25" />
                    <path d="M21 12a9 9 0 0 0-9-9" stroke="currentColor" stroke-width="3" stroke-linecap="round" />
                </svg>
                {{ __('Finding new layouts…') }}
            </span>
            <Button
                v-if="row.stale"
                size="sm"
                :text="__('Refresh layouts')"
                :loading="refreshing"
                :disabled="disabled || refreshing"
                @click="$emit('refresh')"
            />
        </div>

        <div
            class="gw-layout-row flex gap-3 max-sm:-mx-1! max-sm:snap-x! max-sm:overflow-x-auto! max-sm:px-1! max-sm:pb-2!"
            role="radiogroup"
            aria-labelledby="gw-layouts-label"
        >
            <button
                v-for="(card, index) in row.cards"
                :key="card.id"
                type="button"
                role="radio"
                class="gw-layout-card group flex min-w-0 flex-1 flex-col rounded-lg border bg-white p-1.5 text-start transition motion-reduce:transition-none dark:bg-gray-900! max-sm:w-40! max-sm:flex-none! max-sm:snap-start!"
                :class="[
                    card.chosen ? 'border-[var(--gw-accent)] ring-1 ring-[var(--gw-accent)]' : 'border-gray-200 hover:border-gray-400! dark:border-gray-700! dark:hover:border-gray-500!',
                    card.stale ? 'cursor-not-allowed opacity-60' : '',
                ]"
                :data-gw-card="card.id"
                :aria-checked="card.chosen ? 'true' : 'false'"
                :aria-disabled="card.stale || disabled ? 'true' : null"
                :aria-label="name(card)"
                :aria-busy="pending === card.id ? 'true' : null"
                :tabindex="tabindex(card)"
                @click="choose(card)"
                @keydown="key($event, index)"
            >
                <span class="relative block h-[118px] w-full overflow-hidden rounded-md border border-gray-100 bg-white dark:border-gray-800!" data-gw-thumb aria-hidden="true">
                    <iframe
                        v-if="previewable && thumbs[card.id]?.url"
                        :src="thumbs[card.id].url"
                        sandbox="allow-same-origin"
                        loading="lazy"
                        referrerpolicy="no-referrer"
                        tabindex="-1"
                        title=""
                        class="pointer-events-none absolute top-0 left-0 block border-0 bg-white"
                        :style="frameStyle"
                        @load="focus(card, $event)"
                    />
                    <!-- No render yet, or none to be had: the blocks in order. -->
                    <span v-else class="flex h-full flex-col gap-1 p-2">
                        <span
                            v-for="(block, i) in (card.outline ?? []).slice(0, 6)"
                            :key="i"
                            class="block truncate rounded-sm bg-gray-100 px-1.5 text-[10px] leading-4 text-gray-500 dark:bg-gray-800! dark:text-gray-400!"
                            :class="previewable ? 'animate-pulse motion-reduce:animate-none' : ''"
                        >{{ block }}</span>
                    </span>
                </span>
                <span class="mt-1.5 flex items-center gap-1.5 text-sm">
                    <span class="truncate font-medium">{{ card.name }}</span>
                    <span v-if="card.suggested" class="shrink-0 rounded-full bg-[var(--gw-ink)] px-1.5 text-[10px] leading-4 font-medium text-white dark:bg-[var(--gw-accent)]! dark:text-gray-900!">{{ __('Suggested') }}</span>
                    <span class="grow"></span>
                    <span class="shrink-0 text-xs text-gray-500">{{ card.blocks === 1 ? __('1 block') : __(':count blocks', { count: card.blocks }) }}</span>
                </span>
                <span v-if="card.stale" class="mt-0.5 text-xs text-amber-700 dark:text-amber-400!">{{ __('Needs refreshing') }}</span>
                <span v-else-if="card.description" class="mt-0.5 line-clamp-2 text-xs text-gray-500">{{ card.description }}</span>
            </button>

            <div
                v-for="n in row.skeletons"
                :key="`skeleton-${n}`"
                class="flex min-w-0 flex-1 flex-col rounded-lg border border-dashed border-gray-200 p-1.5 dark:border-gray-700! max-sm:w-40! max-sm:flex-none!"
                aria-hidden="true"
            >
                <span class="block h-[118px] w-full animate-pulse rounded-md bg-gray-100 motion-reduce:animate-none dark:bg-gray-800!"></span>
                <span class="mt-1.5 text-sm text-gray-500">{{ __('Finding other layouts…') }}</span>
            </div>
        </div>
        <p v-if="row.skeletons" class="sr-only" role="status">{{ __('Finding other layouts…') }}</p>
    </div>
    </div>
</template>
