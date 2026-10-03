<!--
    The Preview tab: the unsaved draft rendered through the site's own
    templates (Statamic's Live Preview), in a sandboxed, same-origin frame.
    Nothing is saved.

    - It renders once the draft is ready, and again 800 ms after the draft
      changes (click-to-edit, Edit YAML, a new turn, an image chosen), only
      while it's showing. The server reuses a render for an unchanged draft.
    - The next render loads in a hidden frame and swaps in when it has
      loaded, at the same block, so nothing flashes. Meanwhile the old one
      stays, dimmed, with a spinner in the frame's bar.
    - A render that fails, or takes longer than the timeout, says so in
      words, with Blocks a click away; one that is merely slow keeps the
      last version.
    - Hovering the page outlines each block with its name (overlay.js, over
      core's locator). Links in the frame do nothing.
-->
<script>
import { Button } from '@statamic/cms/ui';
import { attach, debounce, previewError } from '../preview/overlay.js';

const PHONE_WIDTH = 390;
const DESKTOP_MIN = 1024;
const DESKTOP_RENDER = 1280;

export default {
    components: { Button },

    props: {
        session: { type: Object, required: true },
        baseUrl: { type: String, required: true },
        blueprint: { type: String, default: null },
        formValues: { type: Function, default: () => ({}) },
        // 'desktop' or 'phone'.
        width: { type: String, default: 'desktop' },
        // Whether the tab is showing: renders wait for it.
        active: { type: Boolean, default: true },
    },

    // `failed`: this render failed; `rendered`: one has swapped in.
    emits: ['failed', 'rendered', 'blocks'],

    data() {
        return {
            // The frame on show: {id, url, title, path}. `next` is loading behind it.
            current: null,
            next: null,
            loading: false,
            // {message, detail, slow}: a failed render, or a slow one (slow keeps the last).
            problem: null,
            partial: false,
            paneWidth: 0,
            viewHeight: window.innerHeight || 900,
            renderedKey: null,
            frameId: 0,
        };
    },

    computed: {
        timeout() {
            return (this.session.page_preview?.timeout ?? 8) * 1000;
        },

        // What a render depends on: the draft and the images chosen in the panel.
        renderKey() {
            return JSON.stringify([this.session.id, this.session.draft, (this.session.images ?? []).map((image) => [image.key, image.path ?? null, image.status])]);
        },

        // The frame's width and scale: Phone is a phone's width; Desktop fills
        // the pane, or renders at 1280 px scaled to fit a narrow one.
        frameBox() {
            const pane = Math.max(this.paneWidth, 0) || 800;

            if (this.width === 'phone') {
                const width = Math.min(PHONE_WIDTH, pane);

                return { width, scale: 1, outer: width };
            }

            if (pane >= DESKTOP_MIN) return { width: pane, scale: 1, outer: pane };

            return { width: DESKTOP_RENDER, scale: pane / DESKTOP_RENDER, outer: pane };
        },

        frameStyle() {
            const { width, scale } = this.frameBox;

            return {
                width: `${width}px`,
                height: `${Math.round(this.frameHeight / scale)}px`,
                transform: scale === 1 ? null : `scale(${scale})`,
                transformOrigin: '0 0',
            };
        },

        // As tall as the window allows, so the page reads like a page.
        frameHeight() {
            return Math.max(420, Math.min(900, this.viewHeight - 280));
        },

        barTitle() {
            return this.current?.title || this.session.title || this.__('Untitled');
        },
    },

    watch: {
        'frameBox.scale'(scale) {
            this.overlays.forEach((overlay) => overlay?.setScale(scale));
        },

        renderKey() {
            this.schedule();
        },

        active(active) {
            if (active) this.schedule(true);
        },
    },

    created() {
        this.debounced = debounce(() => this.render(), 800);
        this.overlays = new Map();
    },

    mounted() {
        this.measurePane();
        this.resizer = new ResizeObserver(() => this.measurePane());
        this.resizer.observe(this.$refs.pane);
        this.schedule(true);
    },

    beforeUnmount() {
        this.debounced.cancel();
        this.resizer?.disconnect();
        clearTimeout(this.timer);
        this.overlays.forEach((overlay) => overlay?.stop());
    },

    methods: {
        measurePane() {
            this.paneWidth = Math.floor(this.$refs.pane?.clientWidth ?? 0);
            this.viewHeight = window.innerHeight || this.viewHeight;
        },

        // Now (first render, or back on the tab) or after the edits settle.
        schedule(now = false) {
            if (!this.active || !this.session.draft) return;
            if (this.renderedKey === this.renderKey && (this.current || this.problem)) return;

            if (now) {
                this.debounced.cancel();
                this.render();
            } else {
                this.debounced();
            }
        },

        async render() {
            const key = this.renderKey;
            this.loading = true;

            let data;

            try {
                ({ data } = await this.$axios.post(`${this.baseUrl}/sessions/${this.session.id}/preview`, {
                    blueprint: this.blueprint,
                    values: this.formValues(),
                    site: window.Statamic?.$config?.get?.('selectedSite') ?? null,
                }));
            } catch (error) {
                if (key !== this.renderKey) return;

                this.loading = false;
                this.renderedKey = key;

                return this.fail({ message: error.response?.data?.message ?? this.__('The preview could not be made.') });
            }

            if (key !== this.renderKey) return;

            if (!data.available) {
                this.loading = false;
                this.renderedKey = key;

                return this.fail({ message: this.__('This collection has no pages on the site, so there is nothing to preview.') });
            }

            this.renderedKey = key;

            // The same render as the one on show: nothing to load.
            if (this.current && this.current.url === data.url && !this.problem) {
                this.loading = false;

                return;
            }

            this.load(data);
        },

        // Into a hidden frame; swapped in once it has loaded.
        load(data) {
            clearTimeout(this.timer);

            this.next = { id: ++this.frameId, url: data.url, map: data.map, titleKey: data.title_key, sameOrigin: data.same_origin, started: performance.now() };

            this.timer = setTimeout(() => this.slow(), this.timeout);
        },

        loaded(entry, event) {
            if (!this.next || entry.id !== this.next.id) return;

            clearTimeout(this.timer);

            const frame = event.target;
            const error = previewError(frame.contentDocument);

            if (error) {
                this.next = null;
                this.loading = false;

                return this.fail({ message: error.message, detail: [error.exception, error.template, error.file].filter(Boolean).join(' · ') });
            }

            const anchor = this.current ? this.overlays.get(this.current.id)?.nearestTop() : null;
            const overlay = entry.sameOrigin ? attach(frame, entry.map, { titleKey: entry.titleKey, scale: this.frameBox.scale }) : null;

            if (entry.sameOrigin && !overlay) {
                this.next = null;
                this.loading = false;

                return this.fail({ message: this.__('Your server stops pages showing in a frame, so the preview can’t show here. Live Preview needs the same setting.') });
            }

            this.overlays.set(entry.id, overlay);
            // For tests and debugging from the CP page: what the locator found.
            Object.defineProperty(frame, 'ghostwriterOverlay', { value: overlay, configurable: true });
            overlay?.scrollToBlock(anchor);

            const old = this.current;

            this.current = { ...entry, title: overlay?.title || '', path: this.path(entry.url), ms: Math.round(performance.now() - entry.started) };
            this.next = null;
            this.loading = false;
            this.problem = null;
            this.partial = Boolean(overlay?.partial());
            this.$emit('rendered', this.current);

            if (old) {
                this.overlays.get(old.id)?.stop();
                this.overlays.delete(old.id);
            }
        },

        slow() {
            this.next = null;
            this.loading = false;

            if (this.current) {
                this.problem = { slow: true, message: this.__('This page is slow to render; showing the last version.') };
            } else {
                this.fail({ message: this.__('The page took too long to render.') });
            }
        },

        fail(problem) {
            this.problem = problem;
            this.$emit('failed', problem);
        },

        retry() {
            this.problem = null;
            this.renderedKey = null;
            this.schedule(true);
        },

        // The address without its token, as the bar shows it.
        path(url) {
            try {
                return new URL(url, window.location.href).pathname;
            } catch {
                return '';
            }
        },
    },
};
</script>

<template>
    <div class="space-y-3">
        <p class="text-sm text-gray-500">{{ __('Rendered with the site’s own templates. Hover to see the blocks. Nothing is saved until you use the draft.') }}</p>

        <div v-if="problem && !problem.slow" class="rounded-lg border border-amber-300 bg-amber-50 px-4 py-3 text-sm dark:border-amber-500/60! dark:bg-amber-950/40!" role="alert">
            <p class="font-medium text-amber-900 dark:text-amber-200!">
                {{ __('The page template couldn’t render this draft') }}<template v-if="problem.message">: <span class="font-normal">{{ problem.message }}</span></template>
            </p>
            <p v-if="problem.detail" class="mt-1 font-mono text-xs break-all text-amber-800 dark:text-amber-300!">{{ problem.detail }}</p>
            <div class="mt-3 flex gap-2">
                <Button size="sm" :text="__('Show blocks instead')" @click="$emit('blocks')" />
                <Button size="sm" variant="ghost" :text="__('Try again')" @click="retry" />
            </div>
        </div>

        <p v-else-if="problem && problem.slow" class="text-sm text-amber-700 dark:text-amber-400!" role="status">{{ problem.message }}</p>
        <p v-if="partial && !problem" class="text-sm text-gray-500" role="status">{{ __('Some blocks couldn’t be matched on this page. They are all in Blocks.') }}</p>

        <div ref="pane" class="w-full">
            <div
                v-show="current || next || loading"
                class="mx-auto overflow-hidden rounded-lg border border-gray-200 bg-white dark:border-gray-700!"
                :style="{ width: `${frameBox.outer}px`, maxWidth: '100%' }"
            >
                <div class="flex items-center gap-2 border-b border-gray-200 bg-gray-50 px-3 py-1.5 text-xs text-gray-600 dark:border-gray-700! dark:bg-gray-800! dark:text-gray-300!">
                    <span class="truncate font-medium" :title="barTitle">{{ barTitle }}</span>
                    <span v-if="current?.path && frameBox.outer >= 480" class="min-w-0 truncate text-gray-400 dark:text-gray-500!">{{ current.path }}</span>
                    <span class="grow"></span>
                    <svg v-if="loading" class="size-3.5 shrink-0 animate-spin motion-reduce:animate-none" viewBox="0 0 24 24" fill="none" role="img" :aria-label="__('Rendering…')">
                        <circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="3" class="opacity-25" />
                        <path d="M21 12a9 9 0 0 0-9-9" stroke="currentColor" stroke-width="3" stroke-linecap="round" />
                    </svg>
                    <span class="shrink-0 rounded-full bg-amber-100 px-2 py-px font-medium whitespace-nowrap text-amber-800 dark:bg-amber-500/20! dark:text-amber-300!">{{ __('Preview · not saved') }}</span>
                </div>
                <div class="relative overflow-hidden" :style="{ height: `${frameHeight}px` }">
                    <template v-for="entry in [current, next].filter(Boolean)" :key="entry.id">
                        <iframe
                            :src="entry.url"
                            :title="__('Preview of the draft: :title', { title: barTitle })"
                            sandbox="allow-same-origin allow-scripts"
                            referrerpolicy="no-referrer"
                            allow=""
                            class="absolute top-0 left-0 block border-0 bg-white transition-opacity duration-150 motion-reduce:transition-none"
                            :class="[entry === current ? (loading ? 'opacity-70' : '') : 'invisible']"
                            :style="frameStyle"
                            :tabindex="entry === current ? null : -1"
                            :aria-hidden="entry === current ? null : 'true'"
                            :data-ghostwriter-preview="entry === current ? 'current' : 'next'"
                            @load="loaded(entry, $event)"
                        />
                    </template>
                    <div v-if="!current && loading" class="absolute inset-0 flex items-center justify-center gap-2 bg-white text-sm text-gray-500 dark:bg-gray-900!" role="status">
                        <svg class="size-4 animate-spin motion-reduce:animate-none" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                            <circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="3" class="opacity-25" />
                            <path d="M21 12a9 9 0 0 0-9-9" stroke="currentColor" stroke-width="3" stroke-linecap="round" />
                        </svg>
                        {{ __('Rendering the page…') }}
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>
