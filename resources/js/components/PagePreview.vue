<!--
    The Preview tab: the unsaved draft rendered through the site's own
    templates (Statamic's Live Preview), in a sandboxed, same-origin frame.
    Nothing is saved.

    - It renders once the draft is ready, and again 800 ms after the draft
      changes (click-to-edit, Edit YAML, a new turn, an image chosen, a
      layout chosen, an extra changed), only while it's showing. It shows
      the chosen layout, as Use this draft would put it into the form. The server reuses a render for an unchanged draft.
    - The next render loads in a hidden frame and swaps in when it has
      loaded, at the same block, so nothing flashes. Meanwhile the old one
      stays, dimmed, with a spinner in the frame's bar.
    - A render that fails, or takes longer than the timeout, says so in
      words, with Blocks a click away; one that is merely slow keeps the
      last version.
    - Hovering the page outlines each block with its name (overlay.js, over
      core's locator). Links in the frame do nothing.
    - Gap markers show as chips, not raw text: an amber chip for a fact to
      add, a dotted underline for a count to check, a dashed underline for
      a link to choose, each with a tooltip (core's markers.js). A chip is a
      button (`gap`): the panel opens a small popover at it, to resolve the
      gap in the draft itself. The frame never changes the draft.
    - Comments (`commenting`, `threads`): numbered pins on the blocks that
      hold each thread's words in this render, whatever the layout; in
      comment mode a click on a block, or words selected in one, is a
      `pick` for the panel's composer, and gap chips wait. Blocks a run
      changed carry a Changed mark (and flash once, `flash`).
-->
<script>
import { Button } from '@statamic/cms/ui';
import { attach, debounce, gapLabels, labelFor, previewError } from '../preview/overlay.js';
import { changedKeys, coverOf, placePins, planPaths } from '../preview/comments.js';

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
        // Comment mode, and the threads to pin (the session's review).
        commenting: { type: Boolean, default: false },
        threads: { type: Array, default: () => [] },
        // The block a composer is open on.
        picked: { type: String, default: null },
        // {numbers, nonce}: threads a run just changed, to flash once.
        flash: { type: Object, default: null },
    },

    // `failed`: this render failed; `rendered`: one has swapped in.
    // `gap`: a chip in the page clicked, {kind, hint, list?, value?, match, occurrence, element, frame, scale}.
    // `pick`: {key, label, units, path, kind, quote, rect, keyboard}; `pin`: a pin's number; `escape`: Esc in comment mode.
    emits: ['failed', 'rendered', 'blocks', 'gap', 'pick', 'pin', 'escape'],

    data() {
        return {
            // The frame on show: {id, url, title, path}. `next` is loading behind it.
            current: null,
            next: null,
            // Preview requests in flight; with `next`, whether a render is.
            requests: 0,
            // {message, detail, slow}: a failed render, or a slow one (slow keeps the last).
            problem: null,
            partial: false,
            paneWidth: 0,
            viewHeight: window.innerHeight || 900,
            // The height of the draft's scrolling pane, when there is one.
            scrollerHeight: 0,
            renderedKey: null,
            frameId: 0,
        };
    },

    computed: {
        // Only while a render is in flight: asked for, or loading behind the
        // page on show. Never for the page's own images or fonts.
        loading() {
            return this.requests > 0 || this.next !== null;
        },

        timeout() {
            return (this.session.page_preview?.timeout ?? 8) * 1000;
        },

        // What a render depends on: the draft, the chosen layout and the
        // extras it may use, and the images chosen in the panel.
        renderKey() {
            return JSON.stringify([this.session.id, this.session.draft, this.session.layouts?.chosen ?? null, (this.session.layouts?.plans ?? []).map((plan) => [plan.id, plan.stale]), this.session.extras ?? [], (this.session.images ?? []).map((image) => [image.key, image.path ?? null, image.status])]);
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

        // As tall as the draft's pane (less the bar), so scrolled to, the
        // page fills it; failing that, as tall as the window allows.
        frameHeight() {
            if (this.scrollerHeight > 0) return Math.max(320, Math.min(900, this.scrollerHeight - 64));

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

        commenting() {
            this.drawComments();
        },

        threads: {
            deep: true,
            handler() {
                this.drawComments();
            },
        },

        picked(key) {
            this.currentOverlay()?.setPicked(key);
        },

        flash(flash) {
            if (!flash?.numbers?.length) return;

            this.pendingFlash = flash.numbers;
            this.flashNow();
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
        this.scroller = this.$el.closest?.('[data-gw-scroller]');
        if (this.scroller) this.resizer.observe(this.scroller);
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
            this.scrollerHeight = this.scroller?.clientHeight ?? 0;
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

            let data;

            this.requests += 1;

            try {
                ({ data } = await this.$axios.post(`${this.baseUrl}/sessions/${this.session.id}/preview`, {
                    blueprint: this.blueprint,
                    values: this.formValues(),
                    site: window.Statamic?.$config?.get?.('selectedSite') ?? null,
                }));
            } catch (error) {
                this.requests -= 1;

                if (key !== this.renderKey) return;
                this.renderedKey = key;

                return this.fail({ message: error.response?.data?.message ?? this.__('The preview could not be made.') });
            }

            this.requests -= 1;

            // Superseded by a newer draft: that render (or the next visit) shows it.
            if (key !== this.renderKey) return;

            if (!data.available) {
                this.renderedKey = key;

                return this.fail({ message: this.__('This collection has no pages on the site, so there is nothing to preview.') });
            }

            this.renderedKey = key;

            // The same render as the one on show: nothing to load.
            if (this.current && this.current.url === data.url && !this.problem) {

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

                return this.fail({ message: error.message, detail: [error.exception, error.template, error.file].filter(Boolean).join(' · ') });
            }

            const anchor = this.current ? this.overlays.get(this.current.id)?.nearestTop() : null;
            const map = entry.map ?? [];
            const byKey = Object.fromEntries(map.map((block) => [block.key, block]));
            const places = planPaths(map);
            const overlay = entry.sameOrigin
                ? attach(frame, map, {
                    titleKey: entry.titleKey,
                    scale: this.frameBox.scale,
                    labels: gapLabels((text) => this.__(text)),
                    // A chip waits while commenting: a click there is a comment.
                    onGap: (found) => !this.commenting && this.$emit('gap', { ...found, frame, scale: this.frameBox.scale }),
                    onPick: (pick) => this.$emit('pick', {
                        ...pick,
                        label: labelFor(byKey[pick.key], byKey),
                        units: coverOf(pick.key, map),
                        path: byKey[pick.key]?.path ?? null,
                        planPath: places[pick.key] ?? places[byKey[pick.key]?.parent] ?? null,
                        kind: pick.quote ? 'text' : 'block',
                    }),
                    onPin: (number) => this.$emit('pin', number),
                    onEscape: () => this.$emit('escape'),
                    commentLabels: {
                        target: (label, count) => (count ? this.__n(':label block, :count comment. Add a comment.|:label block, :count comments. Add a comment.', count, { label }) : this.__(':label block. Add a comment.', { label })),
                        pin: (number, state, label) => this.__('Comment :number, :state, on :label', { number, state: this.__(state), label }),
                        click: this.__('click to comment'),
                        changed: this.__('Changed'),
                    },
                })
                : null;

            if (entry.sameOrigin && !overlay) {
                this.next = null;

                return this.fail({ message: this.__('Your server stops pages showing in a frame, so the preview can’t show here. Live Preview needs the same setting.') });
            }

            this.overlays.set(entry.id, overlay);
            this.current && this.overlays.get(this.current.id)?.setComments({ on: false });
            // For tests and debugging from the CP page: what the locator found.
            Object.defineProperty(frame, 'ghostwriterOverlay', { value: overlay, configurable: true });
            overlay?.scrollToBlock(anchor);

            const old = this.current;

            this.current = { ...entry, title: overlay?.title || '', path: this.path(entry.url), ms: Math.round(performance.now() - entry.started) };
            this.next = null;
            this.problem = null;
            this.partial = Boolean(overlay?.partial());
            this.drawComments();
            overlay?.setPicked(this.picked);
            this.flashNow();
            this.$emit('rendered', this.current);

            if (old) {
                this.overlays.get(old.id)?.stop();
                this.overlays.delete(old.id);
            }
        },

        currentOverlay() {
            return this.current ? this.overlays.get(this.current.id) ?? null : null;
        },

        // The pins and Changed marks for this render's blocks.
        drawComments() {
            const overlay = this.currentOverlay();

            if (!overlay) return;

            const map = this.current.map ?? [];
            const located = overlay.boxes().map((entry) => entry.key);

            overlay.setComments({ on: this.commenting, pins: placePins(this.threads, map, located), changed: changedKeys(this.threads, map, located) });
        },

        // The blocks a run changed flash once, on the render that shows the change.
        flashNow() {
            const overlay = this.currentOverlay();

            if (!overlay || !this.pendingFlash?.length || this.loading) return;

            const map = this.current.map ?? [];
            const located = overlay.boxes().map((entry) => entry.key);
            const keys = changedKeys(this.threads.filter((thread) => this.pendingFlash.includes(thread.number)), map, located);

            if (keys.length) {
                overlay.flash(keys);
                this.pendingFlash = null;
            }
        },

        /** "Show on page": scrolls to a thread's pin and focuses it. False when it has none here. */
        showThread(number) {
            return Boolean(this.currentOverlay()?.focusPin(number));
        },

        /** Where a pin is, in the frame's document: for the composer, opened on it again. */
        pinRect(number) {
            return this.currentOverlay()?.pinRect(number) ?? null;
        },

        /** Back to the blocks, for the keyboard (after a composer closes). */
        focusTarget(key = null) {
            this.currentOverlay()?.focusTarget(key);
        },

        /** A rect in the frame's document, in the CP page's coordinates now. */
        pageRect(rect) {
            const overlay = this.currentOverlay();
            const frame = this.frame();

            if (!overlay || !frame || !rect) return null;

            const view = overlay.toViewport(rect);
            const outer = frame.getBoundingClientRect();
            const scale = this.frameBox.scale;

            return { left: outer.left + view.left * scale, top: outer.top + view.top * scale, right: outer.left + (view.left + view.width) * scale, bottom: outer.top + (view.top + view.height) * scale };
        },

        frame() {
            return this.$el?.querySelector?.('iframe[data-ghostwriter-preview="current"]') ?? null;
        },

        slow() {
            this.next = null;

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
        <p class="text-sm text-gray-500">{{ commenting ? __('Click a block, or select words in it, to comment. Tab moves to the blocks; arrows move between them; Enter comments.') : __('Rendered with the site’s own templates. Hover to see the blocks. Nothing is saved until you use the draft.') }}</p>

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

        <div class="sr-only" aria-live="polite" aria-atomic="true">{{ loading ? (current ? __('Updating preview…') : __('Rendering the page…')) : '' }}</div>

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
                    <span v-if="loading && current" class="flex shrink-0 items-center gap-1.5" data-ghostwriter-updating>
                        <svg class="size-3.5 animate-spin motion-reduce:animate-none" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                            <circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="3" class="opacity-25" />
                            <path d="M21 12a9 9 0 0 0-9-9" stroke="currentColor" stroke-width="3" stroke-linecap="round" />
                        </svg>
                        {{ __('Updating preview…') }}
                    </span>
                    <span class="shrink-0 rounded-full bg-amber-100 px-2 py-px font-medium whitespace-nowrap text-amber-800 dark:bg-amber-500/20! dark:text-amber-300!">{{ frameBox.outer < 360 ? __('Not saved') : __('Preview · not saved') }}</span>
                </div>
                <div class="relative overflow-hidden" :style="{ height: `${frameHeight}px` }" :aria-busy="loading ? 'true' : 'false'">
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
                    <div v-if="!current && loading" class="absolute inset-0 flex items-center justify-center gap-2 bg-white text-sm text-gray-500 dark:bg-gray-900!" data-ghostwriter-updating>
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
