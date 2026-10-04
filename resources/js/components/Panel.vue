<!--
    The whole Ghostwriter flow for one collection, in steps:

      setup    the collection has not been learned yet
      type     choose what to write (skipped when there is only one kind)
      write    the conversation, with the draft beside it: Ghostwriter asks
               for the quick details, fills in the brief as a card to
               check, then writes

    Model calls run in the background, so while one is in flight the panel
    polls until the answer lands.
-->
<script>
import { Alert, Button, Heading, Subheading, Textarea } from '@statamic/cms/ui';
import BriefCard from './BriefCard.vue';
import DraftPreview from './DraftPreview.vue';
import ExtrasList from './ExtrasList.vue';
import GapPopover from './GapPopover.vue';
import LayoutCards from './LayoutCards.vue';
import PagePreview from './PagePreview.vue';
import LearnForm from './LearnForm.vue';
import SetupAlert from './SetupAlert.vue';
import { useLabel } from '../preview/layouts.js';

// The panel's content width below which it is one column (measure()).
const NARROW = 900;

export default {
    components: { Alert, BriefCard, Button, DraftPreview, ExtrasList, GapPopover, Heading, LayoutCards, LearnForm, PagePreview, SetupAlert, Subheading, Textarea },

    props: {
        collection: { type: String, required: true },
        blueprint: { type: String, default: null },
        baseUrl: { type: String, required: true },
        resume: { type: String, default: null },
        // The entry being edited, for starting again from it.
        entry: { type: String, default: null },
        // An idea from the content plan to open on.
        idea: { type: String, default: null },
        // What the publish form holds now, for editing an entry from it.
        formValues: { type: Function, default: () => ({}) },
    },

    // `session` says which piece is open (an id, or null for none), so the
    // launcher can carry on with it when the panel is opened again.
    emits: ['apply', 'session'],

    data() {
        return {
            info: null,
            type: null,
            // Seconds the current turn has been running, for the spinner.
            waited: 0,
            ticker: null,
            // Validation errors on the brief card's answers.
            errors: {},
            // The piece open in the conversation. Before the quick details
            // are sent it is only on screen (no id): nothing is kept for a
            // kind chosen and left.
            session: null,
            // Which brief card button is waiting on the server.
            pending: null,
            // Said to screen readers when the brief card arrives.
            announcement: '',
            message: '',
            editing: false,
            raw: '',
            busy: false,
            retrying: false,
            adding: false,
            showBrief: false,
            // How the draft is shown: rendered as the page (Preview, the
            // default where the site can), in its blocks, or as plain
            // reading text. The choice is remembered.
            chosenView: (() => {
                try {
                    return ['preview', 'blocks', 'text'].includes(localStorage.getItem('ghostwriter.draft-tab')) ? localStorage.getItem('ghostwriter.draft-tab') : 'preview';
                } catch (error) {
                    return 'preview';
                }
            })(),
            // Desktop or Phone, for Preview.
            previewWidth: 'desktop',
            // Blocks, for a piece whose preview failed when it was last open
            // in this tab: until a tab is chosen.
            sessionView: null,
            // The layout card being chosen, and Refresh layouts, while the server stores them.
            choosing: null,
            refreshingLayouts: false,
            // The page preview has loaded once: the cards' thumbnails may follow.
            previewLoaded: false,
            // It failed: the cards show their blocks instead of thumbnails.
            previewFailed: false,
            // The gap chip whose popover is open, and whether its answer is being saved.
            gap: null,
            gapBusy: false,
            // A gap resolved in the Preview: focus goes into the page once it has rendered again.
            refocusPreview: false,
            // In a narrow panel the conversation and the draft are one column,
            // one at a time, with a switch between them at the top.
            narrow: false,
            pane: 'conversation',
            // The draft changed while the conversation was showing (narrow only).
            draftFresh: false,
            timer: null,
        };
    },

    computed: {
        // The tabs this piece has: Preview only where its pages can render.
        tabs() {
            return [
                ...(this.session?.page_preview ? [{ value: 'preview', label: this.__('Preview') }] : []),
                { value: 'blocks', label: this.__('Blocks') },
                { value: 'text', label: this.__('Text') },
            ];
        },

        view() {
            const view = this.sessionView ?? this.chosenView;

            return this.tabs.some((tab) => tab.value === view) ? view : 'blocks';
        },

        step() {
            if (!this.info) return 'loading';
            if (this.session) return 'write';
            if (this.adding || this.learning) return 'setup';
            if (!this.type) return 'type';

            return 'brief';
        },

        learning() {
            return this.info?.state.status === 'working';
        },

        // With only the general brief on offer and no kinds to suggest, there
        // is nothing to choose between.
        nothingToChoose() {
            return this.info.types.length === 1 && this.info.kinds.length === 0 && this.info.ideas.length === 0;
        },

        learned() {
            return this.info.types.filter((type) => !type.generic);
        },

        general() {
            return this.info.types.find((type) => type.generic);
        },

        working() {
            return this.session?.status === 'working';
        },

        // Only the messages to show (core's BriefThread::visible()); the
        // brief card is drawn where its message is.
        conversation() {
            return this.session.messages;
        },

        stage() {
            return this.session?.stage ?? null;
        },

        // What the reply box is for, as its placeholder and its label.
        composerLabel() {
            if (this.stage === 'details' || this.stage === 'filling') return this.__('A working title and a line or two about it…');
            if (this.stage === 'proposed') return this.__('Check the brief above, then start writing.');
            if (this.asking) return this.__('Type your answers here. Short is fine; number them if it helps.');

            return this.session.draft ? this.__('Ask for a change…') : this.__('Answer the questions…');
        },

        // Before the brief is agreed: the draft can wait.
        briefing() {
            return ['details', 'filling', 'proposed'].includes(this.stage);
        },

        asking() {
            return this.session?.waiting_on_you === true && !this.working;
        },

        // What is probably going on, going by how long it has been.
        progress() {
            if (this.stage === 'filling') return this.__('Filling in the brief…');
            if (this.waited < 8) return this.session.draft ? this.__('Reading your message…') : this.__('Reading the brief…');
            if (this.waited < 30) return this.session.draft ? this.__('Revising the draft…') : this.__('Thinking it through…');

            return this.session.draft ? this.__('Still revising. Long drafts take a while…') : this.__('Writing. Long drafts take a while…');
        },

        // "Use this draft (Numbers first)" once there are layouts to choose from.
        useText() {
            return useLabel(this.session.editing ? this.__('Use these changes') : this.__('Use this draft'), this.session.layouts);
        },

        // Thumbnails render after the page preview, or straight away on Blocks and Text.
        thumbsReady() {
            return this.view !== 'preview' || this.previewLoaded;
        },

        elapsed() {
            return `${Math.floor(this.waited / 60)}:${String(this.waited % 60).padStart(2, '0')}`;
        },
    },

    async mounted() {
        this.measure();
        this.sizer = new ResizeObserver(() => this.measure());
        this.sizer.observe(this.$el);

        await this.load();

        if (this.resume) {
            await this.open(this.resume);
        } else if (this.idea) {
            const idea = this.info?.ideas.find((candidate) => candidate.id === this.idea);

            if (idea) this.fromIdea(idea);
        }
    },

    watch: {
        // A popover belongs to the chip it was opened from: another tab, or Edit YAML, closes it.
        view() {
            this.gap = null;
        },

        editing() {
            this.gap = null;
        },

        // Count from the message being answered, so reopening the panel
        // mid-turn shows how long it has really been.
        working: {
            immediate: true,
            handler(working) {
                clearInterval(this.ticker);

                if (!working) return;

                const since = Date.parse(this.session.since ?? '') || Date.now();
                const tick = () => (this.waited = Math.max(0, Math.round((Date.now() - since) / 1000)));

                tick();
                this.ticker = setInterval(tick, 1000);
            },
        },
    },

    beforeUnmount() {
        clearTimeout(this.timer);
        clearInterval(this.ticker);
        this.sizer?.disconnect();
    },

    methods: {
        url(path) {
            return `${this.baseUrl}/${path}`;
        },

        // Two columns need about 900 px of panel (the conversation at 360,
        // the draft at 540); below that, one column with a switch. The
        // panel's own width, not the window's: it is a slide-over.
        measure() {
            const style = getComputedStyle(this.$el);
            const width = this.$el.clientWidth - parseFloat(style.paddingLeft || 0) - parseFloat(style.paddingRight || 0);

            this.narrow = width > 0 && width < NARROW;
        },

        // Conversation or Draft, in a narrow panel.
        showPane(pane, focus = false) {
            this.pane = pane;

            if (pane === 'draft') this.draftFresh = false;

            if (focus) this.$nextTick(() => document.getElementById(`gw-pane-tab-${pane}`)?.focus());
        },

        paneKey(event) {
            if (!['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) return;

            event.preventDefault();

            const panes = ['conversation', 'draft'];
            const next = event.key === 'Home' ? 0 : event.key === 'End' ? 1 : (panes.indexOf(this.pane) + 1) % 2;

            this.showPane(panes[next], true);
        },

        fail(error) {
            // Say which rule was broken, not just that one was.
            const first = Object.values(error.response?.data?.errors ?? {})[0]?.[0];

            this.$toast.error(first ?? error.response?.data?.message ?? this.__('Something went wrong.'));
        },

        later(callback) {
            clearTimeout(this.timer);
            this.timer = setTimeout(callback, 2500);
        },

        async load() {
            try {
                const wasLearning = this.learning;
                const { data } = await this.$axios.get(this.url(`collections/${this.collection}`), { params: { blueprint: this.blueprint } });

                this.info = data;

                if (data.state.status === 'working') {
                    this.later(() => this.load());
                } else {
                    if (wasLearning && data.state.status === 'idle') this.adding = false;
                    if (data.types.length === 1 && data.kinds.length === 0 && data.ideas.length === 0 && !this.adding && !this.session) this.choose(data.types[0]);
                }
            } catch (error) {
                this.fail(error);
            }
        },

        async learn(options) {
            try {
                const { data } = await this.$axios.post(this.url(`collections/${this.collection}/analyse`), options);

                this.info = data;
                this.later(() => this.load());
            } catch (error) {
                this.fail(error);
            }
        },

        // `examples` preselects the entries to model this piece on: a kind's
        // members, or whatever the type itself was taught from. The
        // conversation opens on Ghostwriter asking for the quick details;
        // the piece is kept once they are sent.
        choose(type, examples = null) {
            this.type = type;
            this.errors = {};
            this.announcement = '';
            this.session = {
                id: null,
                stage: 'details',
                status: 'idle',
                type,
                examples: examples ?? type.examples ?? [],
                messages: [{ index: 0, role: 'assistant', step: 'ask', html: null, content: this.__('What’s it called, and what should it say? A line or two is plenty.') }],
                draft: null,
                brief: null,
                brief_text: null,
                images: [],
                editing: false,
            };

            this.pane = 'conversation';
            this.$nextTick(() => this.focusComposer());
        },

        focusComposer() {
            this.$refs.composer?.$el?.querySelector?.('textarea')?.focus() ?? this.$refs.composer?.$el?.focus?.();
        },

        // "Draft this": an idea from the content plan. No question first:
        // the brief card is filled in from the idea, ready to check.
        async fromIdea(idea) {
            const type = this.info.types.find((candidate) => candidate.handle === idea.type) ?? this.general;

            if (!this.info.configured) return this.choose(type);

            this.type = type;

            try {
                const { data } = await this.$axios.post(this.url(`types/${type.handle}/sessions`), { examples: type.examples ?? [], idea: idea.id });

                this.receive(data);
            } catch (error) {
                this.fail(error);
            }
        },

        async open(id) {
            try {
                const { data } = await this.$axios.get(this.url(`sessions/${id}`));

                this.receive(data);
            } catch (error) {
                this.fail(error);

                // Gone, or not this person's: start afresh next time.
                if (!this.session) this.$emit('session', null);
            }
        },

        receive(data) {
            const changed = data.draft !== this.session?.draft;

            // The brief card has just been filled in (or filled in again):
            // say so, and take keyboard focus to it.
            const filled = data.stage === 'proposed' && data.id === this.session?.id && (this.session?.stage !== 'proposed' || data.brief?.attempt !== this.session?.brief?.attempt);

            if (data.id !== this.session?.id) {
                this.gap = null;
                this.pane = data.draft ? 'draft' : 'conversation';
                this.draftFresh = false;
                this.$emit('session', data.id);
                this.sessionView = this.failedBefore(data.id) ? 'blocks' : null;
                this.previewLoaded = false;
                this.previewFailed = false;
            }

            // Other layouts have arrived while the draft was on show: say so.
            if (data.id === this.session?.id && this.session?.layouts?.planning && !data.layouts?.planning && (data.layouts?.plans?.length ?? 0) > 1) {
                this.announce(this.__(':count layouts to choose from, above the draft.', { count: data.layouts.plans.length }));
            }

            const opened = data.id !== this.session?.id;

            this.session = data;

            if (changed) {
                this.raw = data.draft ?? '';
                this.editing = false;

                // In a narrow panel, a new draft while the conversation shows: marked on Draft.
                if (!opened && this.narrow && this.pane === 'conversation' && data.draft) this.draftFresh = true;
            }

            // Questions waiting, or the brief to check: the conversation.
            if ((data.waiting_on_you === true && data.status !== 'working') || filled) this.pane = 'conversation';

            const drawing = (data.images ?? []).some((image) => image.status === 'working');

            // The planner may still be looking for other layouts after the draft lands.
            if (data.status === 'working' || drawing || data.layouts?.planning) this.later(() => this.open(data.id));

            this.$nextTick(() => {
                const chat = this.$refs.chat;
                if (chat) chat.scrollTop = chat.scrollHeight;

                // Questions waiting: put the cursor where the answer goes.
                if (this.asking) this.focusComposer();

                if (filled) {
                    this.announce(this.__('The brief is filled in. Check it, then start writing.'));
                    this.$refs.card?.[0]?.focus?.() ?? this.$refs.card?.focus?.();
                }
            });
        },

        // Polite, for screen readers: cleared first so the same words are
        // read again after Try again.
        announce(text) {
            this.announcement = '';
            this.$nextTick(() => (this.announcement = text));
        },

        // The brief card's buttons. Each sends the card as the person left it.
        async briefAction(action, card) {
            this.pending = action;
            this.errors = {};

            const [method, path] = {
                agree: ['post', 'brief/agree'],
                'try-again': ['post', 'brief/try-again'],
                save: ['patch', 'brief'],
            }[action];

            try {
                const { data } = await this.$axios[method](this.url(`sessions/${this.session.id}/${path}`), card);

                this.receive(data);

                if (action === 'save') this.$toast.success(this.__('The brief is saved. Ghostwriter works from it from the next message.'));
            } catch (error) {
                this.errors = error.response?.data?.errors ?? {};
                this.fail(error);
            } finally {
                this.pending = null;
            }
        },

        // "Draft updated · 957 → 1,012 words (+55)"
        draftNote(draft) {
            const words = (count) => Number(count).toLocaleString();

            if (draft.change === 'written' || draft.was === null) {
                return this.__('Draft written · :words words', { words: words(draft.words) });
            }

            const difference = draft.words - draft.was;
            const change = difference === 0 ? this.__('same length') : `${difference > 0 ? '+' : '−'}${words(Math.abs(difference))}`;

            return this.__('Draft updated · :was → :words words (:change)', { was: words(draft.was), words: words(draft.words), change });
        },

        // For when the questions are not worth answering.
        skipQuestions() {
            this.message = this.__('Please draft it with what you have. Put anything you are unsure of in square brackets.');
            this.send();
        },

        async send() {
            if (!this.message.trim() || this.working || this.stage === 'proposed') return;

            const message = this.message;
            this.message = '';

            try {
                // The quick details for a piece not kept yet: it is kept now,
                // and the brief is filled in from them.
                const { data } = this.session.id
                    ? await this.$axios.post(this.url(`sessions/${this.session.id}/messages`), { message })
                    : await this.$axios.post(this.url(`types/${this.session.type.handle}/sessions`), { examples: this.session.examples, details: message });

                this.receive(data);
            } catch (error) {
                this.message = message;
                this.fail(error);
            }
        },

        // The last turn failed: send the same message again.
        async retry() {
            this.retrying = true;

            try {
                const { data } = await this.$axios.post(this.url(`sessions/${this.session.id}/retry`));

                this.receive(data);
            } catch (error) {
                this.fail(error);
            } finally {
                this.retrying = false;
            }
        },

        // Who started a piece and who last changed it, when conversations are shared.
        people(item) {
            return [
                item.started_by ? this.__('Started by :name', { name: item.started_by }) : null,
                item.touched_by ? this.__('last changed by :name', { name: item.touched_by }) : null,
            ].filter(Boolean).join(', ');
        },

        setView(view) {
            this.chosenView = view;
            this.sessionView = null;

            try {
                localStorage.setItem('ghostwriter.draft-tab', view);
            } catch (error) {
                // Private windows and the like: the choice just is not remembered.
            }
        },

        // The tabs by keyboard: arrows move along them (and choose), Home and End to the ends.
        tabKey(event, index) {
            const keys = { ArrowRight: index + 1, ArrowLeft: index - 1, Home: 0, End: this.tabs.length - 1 };

            if (!(event.key in keys)) return;

            event.preventDefault();

            const tab = this.tabs[(keys[event.key] + this.tabs.length) % this.tabs.length];
            this.setView(tab.value);
            this.$nextTick(() => this.$refs.tabs?.querySelector(`[data-tab="${tab.value}"]`)?.focus());
        },

        // The preview failed: it says so where it is, and the piece opens on
        // Blocks from now on in this browser tab.
        previewFailedFor() {
            this.previewFailed = true;

            try {
                if (this.session?.id) sessionStorage.setItem(`ghostwriter.preview-failed.${this.session.id}`, '1');
            } catch (error) {
                // Not remembered.
            }
        },

        previewRendered() {
            this.previewLoaded = true;
            this.previewFailed = false;

            // After a gap resolved there: the next chip on the page, or the page.
            if (this.refocusPreview) {
                this.refocusPreview = false;
                this.$nextTick(() => {
                    const frame = this.$el.querySelector('iframe[data-ghostwriter-preview="current"]');
                    const chip = frame?.contentDocument?.querySelector('.gw-gap[tabindex], a.gw-gap');

                    (chip ?? frame)?.focus();
                });
            }

            try {
                if (this.session?.id) sessionStorage.removeItem(`ghostwriter.preview-failed.${this.session.id}`);
            } catch (error) {
                // Nothing to forget.
            }
        },

        // A piece whose preview failed before opens on Blocks.
        failedBefore(id) {
            try {
                return Boolean(id) && sessionStorage.getItem(`ghostwriter.preview-failed.${id}`) === '1';
            } catch (error) {
                return false;
            }
        },

        // One piece of writing changed where it is shown.
        async editField({ path, value, format, revert }) {
            try {
                const { data } = await this.$axios.patch(this.url(`sessions/${this.session.id}/field`), { path: path.map(String), value, format });

                this.receive(data);
            } catch (error) {
                this.fail(error);
                revert?.();
            }
        },

        // A gap chip clicked in the Preview or the Text tab: its popover.
        openGap(gap) {
            if (this.working) return;

            this.gap = gap;
        },

        // Closed: focus back on the chip (or, once it has gone, where it was).
        closeGap({ refocus = true } = {}) {
            const gap = this.gap;

            this.gap = null;

            if (!refocus || !gap) return;

            this.$nextTick(() => {
                if (gap.element?.isConnected) gap.element.focus();
                else if (gap.host?.isConnected) (gap.host.querySelector('.gw-gap[tabindex], a.gw-gap') ?? gap.host).focus();
                else (this.$el.querySelector('iframe[data-ghostwriter-preview="current"]') ?? this.$refs.draftPane)?.focus?.();
            });
        },

        // Written into the draft as typed: no model. Saved like any draft
        // edit, under the piece's lock; the Preview, layouts, Blocks and Text follow.
        async resolveGap({ value, reference }) {
            const gap = this.gap;

            if (!gap) return;

            this.gapBusy = true;

            try {
                const { data } = await this.$axios.patch(this.url(`sessions/${this.session.id}/gap`), {
                    kind: gap.kind,
                    hint: gap.hint,
                    list: gap.list ?? null,
                    occurrence: gap.occurrence ?? 0,
                    path: gap.path ? gap.path.map(String) : null,
                    value,
                    reference,
                });

                this.receive(data);
                this.refocusPreview = Boolean(gap.frame);
                this.announce({
                    ask: this.__('Added to the draft.'),
                    check: value === '' ? this.__('Count removed from the draft.') : this.__('Count confirmed in the draft.'),
                    link: this.__('Link chosen in the draft.'),
                }[gap.kind]);
                this.closeGap();
            } catch (error) {
                this.fail(error);
            } finally {
                this.gapBusy = false;
            }
        },

        // A layout card chosen: stored on the piece, for everyone on it. No model.
        async chooseLayout(plan) {
            this.choosing = plan;

            try {
                const { data } = await this.$axios.patch(this.url(`sessions/${this.session.id}/layout`), { plan });

                this.receive(data);

                const chosen = data.layouts?.plans?.find((card) => card.id === plan);

                if (chosen) this.announce(this.__(':name layout. The preview, Blocks and Text show it.', { name: chosen.name }));
            } catch (error) {
                this.fail(error);
            } finally {
                this.choosing = null;
            }
        },

        // Refresh layouts: one call to the planner, in the background.
        async refreshLayouts() {
            this.refreshingLayouts = true;

            try {
                const { data } = await this.$axios.post(this.url(`sessions/${this.session.id}/layouts/refresh`));

                this.receive(data);
            } catch (error) {
                this.fail(error);
            } finally {
                this.refreshingLayouts = false;
            }
        },

        async editExtra({ id, payload, revert }) {
            try {
                const { data } = await this.$axios.patch(this.url(`sessions/${this.session.id}/extras/${encodeURIComponent(id)}`), payload);

                this.receive(data);
            } catch (error) {
                this.fail(error);
                revert?.();
            }
        },

        async removeExtra({ id, label }) {
            try {
                const { data } = await this.$axios.delete(this.url(`sessions/${this.session.id}/extras/${encodeURIComponent(id)}`));

                this.receive(data);
                this.announce(this.__(':kind item deleted.', { kind: label }));
            } catch (error) {
                this.fail(error);
            }
        },

        // Editing an entry: throw the conversation's changes away and start
        // from the entry as its form holds it now.
        async startAgain() {
            if (!this.entry || !confirm(this.__('Start again from the entry as it stands? Changes asked for in this conversation but not yet put into the entry are dropped.'))) return;

            try {
                const { data } = await this.$axios.post(`${this.baseUrl}/entries/${this.entry}/session`, { fresh: true, values: this.formValues() });

                this.receive(data);
            } catch (error) {
                this.fail(error);
            }
        },

        async saveDraft() {
            this.busy = true;

            try {
                const { data } = await this.$axios.patch(this.url(`sessions/${this.session.id}/draft`), { draft: this.raw });

                this.session = data;
                this.editing = false;
            } catch (error) {
                this.fail(error);
            } finally {
                this.busy = false;
            }
        },

        async apply() {
            this.busy = true;

            try {
                const { data } = await this.$axios.post(this.url(`sessions/${this.session.id}/apply`), {
                    blueprint: this.blueprint,
                    // Changes go over the form as it stands, so nothing typed
                    // into it is undone; on a new entry, so an image already
                    // chosen in the form is kept.
                    values: this.formValues(),
                });

                this.$emit('apply', data);
            } catch (error) {
                this.fail(error);
            } finally {
                this.busy = false;
            }
        },

        startOver() {
            clearTimeout(this.timer);

            this.session = null;
            this.message = '';
            this.$emit('session', null);

            if (this.nothingToChoose) this.choose(this.type);
            else this.type = null;
        },
    },
};
</script>

<template>
    <div class="gw-writer h-full overflow-y-auto sm:p-6" :class="{ 'is-narrow': narrow, 'is-writing': step === 'write' }">
        <div v-if="step === 'loading'" class="py-24 text-center text-gray-500">{{ __('Loading…') }}</div>

        <template v-else>
            <SetupAlert v-if="!info.configured" :provider="info.provider" />

            <Alert
                v-if="!info.has_voice && step !== 'write'"
                variant="warning"
                :heading="__('No voice guide yet')"
                :text="__('Ghostwriter will still write, but in a plain voice rather than yours. Write it under Ghostwriter → Voice guide for a better draft.')"
                class="mb-6"
            />

            <!-- Teach it a kind of content -->
            <div v-if="step === 'setup'" class="mx-auto max-w-xl py-10">
                <Heading
                    size="lg"
                    :text="__('Teach Ghostwriter a kind of content')"
                />
                <Subheading
                    class="mt-2 mb-6"
                    :text="__('Worth doing for something you write often. It reads the entries you point it at and writes a brief of its own for that kind, with questions that fit. This takes about a minute and happens once.')"
                />
                <Alert v-if="info.state.status === 'failed'" variant="error" :text="info.state.error" class="mb-6" />
                <LearnForm :entries="info.entries" :disabled="!info.configured" :loading="learning" @learn="learn" />
                <Button v-if="!learning" class="mt-4" size="sm" variant="ghost" :text="__('Cancel')" @click="adding = false" />
            </div>

            <!-- What to write -->
            <div v-else-if="step === 'type'" class="mx-auto max-w-3xl">
                <div class="mb-4 flex items-center justify-between">
                    <Heading size="lg" :text="__('What are you writing?')" />
                    <Button size="sm" variant="ghost" :text="__('Teach a kind')" @click="adding = true" />
                </div>

                <template v-if="info.ideas.length">
                    <div class="mb-2 flex items-baseline justify-between">
                        <Subheading :text="__('From the content plan')" />
                        <span class="text-sm text-gray-500">{{ __n(':count idea|:count ideas · scroll for more', info.ideas.length) }}</span>
                    </div>
                    <div class="-mx-1 mb-8 flex snap-x gap-3 overflow-x-auto px-1 pb-3">
                        <button
                            v-for="idea in info.ideas"
                            :key="idea.id"
                            type="button"
                            class="flex w-64 shrink-0 snap-start flex-col rounded-lg border border-gray-200 p-4 text-start hover:border-gray-400! dark:border-gray-700! dark:hover:border-gray-500!"
                            :title="idea.why"
                            @click="fromIdea(idea)"
                        >
                            <span class="font-medium">{{ idea.title }}</span>
                            <span v-if="idea.why" class="mt-1 line-clamp-3 text-sm text-gray-500">{{ idea.why }}</span>
                        </button>
                    </div>
                </template>

                <div v-if="learned.length" class="mb-8 grid gap-3 md:grid-cols-2">
                    <button
                        v-for="option in learned"
                        :key="option.handle"
                        type="button"
                        class="rounded-lg border border-gray-200 p-4 text-start hover:border-gray-400! dark:border-gray-700! dark:hover:border-gray-500!"
                        @click="choose(option)"
                    >
                        <Heading :text="option.title" />
                        <Subheading class="mt-1" :text="option.description" />
                    </button>
                </div>

                <template v-if="info.kinds.length">
                    <Subheading :text="__('Something like what is already here')" class="mb-2" />
                    <div class="mb-8 grid gap-3 md:grid-cols-2">
                        <button
                            v-for="kind in info.kinds"
                            :key="kind.label"
                            type="button"
                            class="rounded-lg border border-gray-200 p-4 text-start hover:border-gray-400! dark:border-gray-700! dark:hover:border-gray-500!"
                            @click="choose(general, kind.examples)"
                        >
                            <Heading :text="kind.label" />
                            <Subheading class="mt-1" :text="__n(':count entry built this way|:count entries built the same way', kind.count)" />
                        </button>
                    </div>
                </template>

                <button
                    type="button"
                    class="w-full rounded-lg border border-dashed border-gray-300 p-4 text-start hover:border-gray-400! dark:border-gray-600! dark:hover:border-gray-500!"
                    @click="choose(general, [])"
                >
                    <Heading :text="__('Something else')" />
                    <Subheading class="mt-1" :text="__('Describe what you want. Pick entries to model it on, or let Ghostwriter choose the shape.')" />
                </button>

                <div v-if="info.sessions.length" class="mt-10">
                    <Subheading :text="__('Or carry on with')" class="mb-2" />
                    <button
                        v-for="item in info.sessions"
                        :key="item.id"
                        type="button"
                        class="flex w-full items-center justify-between rounded-md px-3 py-2 text-start hover:bg-gray-50! dark:hover:bg-gray-800!"
                        @click="open(item.id)"
                    >
                        <span class="truncate">{{ item.title }}</span>
                        <span class="text-sm text-gray-500">{{ [item.type, people(item), item.updated_at].filter(Boolean).join(' · ') }}</span>
                    </button>
                </div>
            </div>

            <!-- The conversation and the draft -->
            <!--
                The conversation and the draft: side by side in a wide panel;
                in a narrow one (measure()), one column, one at a time, with
                Conversation | Draft at the top. Flex and grid only, so
                nothing can sit over anything else at any width.
            -->
            <div v-else class="gw-writer__body">
                <div v-if="narrow" class="gw-writer__switch" role="tablist" :aria-label="__('Writing panel')">
                    <button
                        v-for="name in ['conversation', 'draft']"
                        :id="`gw-pane-tab-${name}`"
                        :key="name"
                        type="button"
                        role="tab"
                        class="gw-writer__switch-tab"
                        :aria-selected="pane === name ? 'true' : 'false'"
                        :aria-controls="`gw-pane-${name}`"
                        :tabindex="pane === name ? 0 : -1"
                        @click="showPane(name)"
                        @keydown="paneKey"
                    >
                        {{ name === 'conversation' ? __('Conversation') : __('Draft') }}
                        <span v-if="name === 'conversation' && (asking || working)" class="gw-writer__dot" :class="asking ? 'is-asking' : ''" aria-hidden="true"></span>
                        <span v-if="name === 'conversation' && asking" class="sr-only">{{ __('(waiting for your answer)') }}</span>
                        <span v-if="name === 'draft' && draftFresh" class="gw-writer__dot" aria-hidden="true"></span>
                        <span v-if="name === 'draft' && draftFresh" class="sr-only">{{ __('(updated)') }}</span>
                    </button>
                </div>

                <div class="gw-writer__grid">
                <div
                    id="gw-pane-conversation"
                    class="gw-writer__pane flex min-h-0 min-w-0 flex-col rounded-lg border border-gray-200 dark:border-gray-700!"
                    :class="{ 'is-away': narrow && pane !== 'conversation' }"
                    :role="narrow ? 'tabpanel' : null"
                    :aria-labelledby="narrow ? 'gw-pane-tab-conversation' : null"
                >
                    <div class="sr-only" aria-live="polite" aria-atomic="true">{{ announcement }}</div>
                    <div ref="chat" class="flex-1 space-y-3 overflow-y-auto p-4">
                        <!-- A piece from before the brief card: its brief, as text. -->
                        <div v-if="session.brief_text" class="rounded-lg bg-gray-100 px-3 py-2 text-sm dark:bg-gray-800!">
                            <button type="button" class="font-medium underline" :aria-expanded="showBrief ? 'true' : 'false'" @click="showBrief = !showBrief">
                                {{ showBrief ? __('Hide the brief') : __('Show the brief') }}
                            </button>
                            <div v-if="showBrief" class="mt-2 whitespace-pre-wrap">{{ session.brief_text }}</div>
                        </div>

                        <template v-for="(entry, index) in conversation" :key="entry.index ?? index">
                        <div
                            v-if="entry.step !== 'card' || !session.brief || session.brief.agreed !== true"
                            class="rounded-lg px-3 py-2 text-sm whitespace-pre-wrap"
                            :class="[
                                entry.role === 'user' ? 'ms-8 bg-gray-100 dark:bg-gray-800!' : 'me-8 border',
                                entry.role !== 'user' && asking && index === conversation.length - 1
                                    ? 'border-amber-400 bg-amber-50 dark:border-amber-500! dark:bg-amber-950/40!'
                                    : entry.role !== 'user' ? 'border-gray-200 dark:border-gray-700!' : '',
                            ]"
                        ><span v-if="entry.role === 'user' && entry.from" class="mb-1 block text-xs font-medium text-gray-500">{{ entry.mine ? __('You') : entry.from }}</span><span v-if="entry.role !== 'user' && asking && index === conversation.length - 1" class="mb-1 block text-xs font-semibold tracking-wide text-amber-700 uppercase dark:text-amber-400!">{{ __('Ghostwriter needs your answer') }}</span><div v-if="entry.html" class="gw-prose gw-reply whitespace-normal" v-html="entry.html"></div><template v-else>{{ entry.content }}</template><span
                                v-if="entry.draft"
                                class="mt-2 flex items-center gap-1.5 border-t border-gray-200 pt-2 text-xs font-medium text-green-700 dark:border-gray-700! dark:text-green-400!"
                            ><svg class="size-3.5 shrink-0" viewBox="0 0 16 16" fill="none" aria-hidden="true"><path d="M3 8.5l3.2 3.2L13 4.8" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" /></svg>{{ draftNote(entry.draft) }}</span></div>
                        <BriefCard
                            v-if="entry.step === 'card' && session.brief"
                            ref="card"
                            :brief="session.brief"
                            :questions="session.type?.questions ?? []"
                            :entries="info.entries"
                            :disabled="working || (session.brief.agreed !== true && stage !== 'proposed')"
                            :pending="pending"
                            :errors="errors"
                            @agree="(card) => briefAction('agree', card)"
                            @try-again="(card) => briefAction('try-again', card)"
                            @save="(card) => briefAction('save', card)"
                        />
                        </template>

                        <div v-if="working" class="me-8 flex items-center gap-2.5 rounded-lg border border-gray-200 px-3 py-2 text-sm text-gray-500 dark:border-gray-700!" role="status">
                            <svg class="size-4 shrink-0 animate-spin motion-reduce:animate-none" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                <circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="3" class="opacity-25" />
                                <path d="M21 12a9 9 0 0 0-9-9" stroke="currentColor" stroke-width="3" stroke-linecap="round" />
                            </svg>
                            <span>{{ session.waiting_on ? __(':name is waiting on Ghostwriter', { name: session.waiting_on }) : progress }}</span>
                            <span class="ms-auto tabular-nums">{{ elapsed }}</span>
                        </div>
                        <Alert v-if="working && session.queue_waiting" variant="warning" :text="session.queue_waiting" role="status" />

                        <!-- Nothing kept yet: pieces already under way are a click away. -->
                        <div v-if="!session.id && nothingToChoose && info.sessions.length" class="pt-4">
                            <Subheading :text="__('Or carry on with')" class="mb-2" />
                            <button
                                v-for="item in info.sessions"
                                :key="item.id"
                                type="button"
                                class="flex w-full items-center justify-between gap-3 rounded-md px-3 py-2 text-start hover:bg-gray-50! dark:hover:bg-gray-800!"
                                @click="open(item.id)"
                            >
                                <span class="truncate">{{ item.title }}</span>
                                <span class="shrink-0 text-sm text-gray-500">{{ item.updated_at }}</span>
                            </button>
                        </div>

                        <div v-if="session.status === 'failed'" class="space-y-2">
                            <Alert variant="error" :heading="__('That didn’t work')" :text="session.error" />
                            <Button v-if="session.can_retry" size="sm" :text="__('Try again')" :loading="retrying" :disabled="retrying" @click="retry" />
                        </div>
                    </div>

                    <div class="space-y-2 border-t p-4" :class="asking ? 'border-amber-400 bg-amber-50 dark:border-amber-500! dark:bg-amber-950/40!' : 'border-gray-200 dark:border-gray-700!'">
                        <div v-if="asking" class="flex items-center gap-2 text-sm font-medium text-amber-800 dark:text-amber-300!">
                            <span class="relative flex size-2.5">
                                <span class="absolute inline-flex size-full animate-ping rounded-full motion-reduce:animate-none bg-amber-500 opacity-60"></span>
                                <span class="relative inline-flex size-2.5 rounded-full bg-amber-500"></span>
                            </span>
                            {{ session.draft ? __('Your turn: answer above to carry on.') : __('Your turn: answer the questions above and the draft follows.') }}
                        </div>
                        <Textarea
                            ref="composer"
                            v-model="message"
                            :rows="3"
                            :disabled="working || stage === 'proposed'"
                            :aria-label="composerLabel"
                            :placeholder="composerLabel"
                            @keydown.meta.enter.stop.prevent="send"
                            @keydown.ctrl.enter.stop.prevent="send"
                        />
                        <div class="flex items-center justify-between">
                            <Button v-if="!session.editing" size="sm" variant="ghost" :text="__('Start over')" @click="startOver" />
                            <Button v-else size="sm" variant="ghost" :text="__('Start again from the entry')" :disabled="working" @click="startAgain" />
                            <Button :text="working ? __('Working…') : __('Send')" :loading="working" :disabled="working || stage === 'proposed' || !message.trim()" @click="send" />
                        </div>
                    </div>
                </div>

                <div
                    id="gw-pane-draft"
                    class="gw-writer__pane flex min-h-0 min-w-0 flex-col rounded-lg border border-gray-200 dark:border-gray-700!"
                    :class="{ 'is-away': narrow && pane !== 'draft' }"
                    :role="narrow ? 'tabpanel' : null"
                    :aria-labelledby="narrow ? 'gw-pane-tab-draft' : null"
                >
                    <div class="flex flex-wrap items-center justify-between gap-2 border-b border-gray-200 px-4 py-2.5 dark:border-gray-700!">
                        <div class="flex flex-wrap items-center gap-x-3 gap-y-2 text-sm text-gray-500">
                            <span>{{ session.draft ? __n(':count word|:count words', session.words) : __('Draft') }}</span>
                            <div v-if="session.draft && !editing" ref="tabs" class="flex rounded-md border border-gray-200 text-xs dark:border-gray-700!" role="tablist" :aria-label="__('Draft view')">
                                <button
                                    v-for="(tab, index) in tabs"
                                    :id="`gw-tab-${tab.value}`"
                                    :key="tab.value"
                                    type="button"
                                    role="tab"
                                    class="px-2 py-0.5"
                                    :class="view === tab.value ? 'bg-gray-100 font-medium text-gray-900 dark:bg-gray-800! dark:text-gray-100!' : ''"
                                    :data-tab="tab.value"
                                    :aria-selected="view === tab.value ? 'true' : 'false'"
                                    aria-controls="gw-draft-view"
                                    :tabindex="view === tab.value ? 0 : -1"
                                    @click="setView(tab.value)"
                                    @keydown="tabKey($event, index)"
                                >{{ tab.label }}</button>
                            </div>
                            <div v-if="session.draft && !editing && view === 'preview'" class="flex rounded-md border border-gray-200 text-xs dark:border-gray-700!" role="group" :aria-label="__('Preview width')">
                                <button type="button" class="px-2 py-0.5" :class="previewWidth === 'desktop' ? 'bg-gray-100 font-medium text-gray-900 dark:bg-gray-800! dark:text-gray-100!' : ''" :aria-pressed="previewWidth === 'desktop' ? 'true' : 'false'" @click="previewWidth = 'desktop'">{{ __('Desktop') }}</button>
                                <button type="button" class="px-2 py-0.5" :class="previewWidth === 'phone' ? 'bg-gray-100 font-medium text-gray-900 dark:bg-gray-800! dark:text-gray-100!' : ''" :aria-pressed="previewWidth === 'phone' ? 'true' : 'false'" @click="previewWidth = 'phone'">{{ __('Phone') }}</button>
                            </div>
                        </div>
                        <div v-if="session.draft" class="flex flex-wrap gap-2">
                            <template v-if="editing">
                                <Button size="sm" variant="ghost" :text="__('Cancel')" @click="(editing = false), (raw = session.draft)" />
                                <Button size="sm" :text="__('Save changes')" :loading="busy" @click="saveDraft" />
                            </template>
                            <template v-else>
                                <Button size="sm" :text="__('Edit YAML')" :disabled="working" @click="editing = true" />
                                <Button
                                    size="sm"
                                    variant="primary"
                                    :text="useText"
                                    :disabled="working || !!session.draft_problem"
                                    :loading="busy"
                                    @click="apply"
                                />
                            </template>
                        </div>
                    </div>

                    <div ref="draftPane" class="relative min-h-0 flex-1 overflow-y-auto p-4" tabindex="-1" data-gw-scroller>
                        <div v-if="!session.draft" class="py-24 text-center text-gray-500">
                            <template v-if="working">
                                <svg class="mx-auto mb-3 size-6 animate-spin motion-reduce:animate-none" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                    <circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="3" class="opacity-25" />
                                    <path d="M21 12a9 9 0 0 0-9-9" stroke="currentColor" stroke-width="3" stroke-linecap="round" />
                                </svg>
                                {{ stage === 'filling' ? __('Filling in the brief…') : __('Ghostwriter is working. A draft usually takes a minute or two.') }}
                            </template>
                            <template v-else-if="briefing">{{ __('Once the brief looks right, Ghostwriter starts writing and the draft appears here.') }}</template>
                            <template v-else-if="asking">
                                <span class="mb-2 block text-base font-medium text-amber-700 dark:text-amber-400!">{{ __('Ghostwriter has questions for you first') }}</span>
                                {{ __('They are in the conversation on the left. Answer them there and the draft will appear here.') }}
                                <span class="mt-3 block"><Button size="sm" :text="__('Just draft it with what you have')" @click="skipQuestions" /></span>
                            </template>
                            <template v-else>{{ __('No draft yet. Answer the questions in the conversation and the draft will appear here.') }}</template>
                        </div>

                        <template v-else>
                            <Alert v-if="session.draft_problem" variant="warning" :text="session.draft_problem" class="mb-4" />
                            <p v-else-if="session.applied" class="mb-4 rounded-md border border-gray-200 px-3 py-2 text-sm text-gray-500 dark:border-gray-700!">
                                {{ session.editing ? __('These changes have been put into the form. Using them again replaces what is in the form.') : __('This draft has been put into the form. Using it again replaces what is in the form.') }}
                            </p>

                            <Textarea v-if="editing || session.draft_problem" v-model="raw" elastic :rows="24" class="font-mono text-sm" @focus="editing = true" />

                            <LayoutCards
                                v-else
                                :session="session"
                                :base-url="baseUrl"
                                :blueprint="blueprint"
                                :form-values="formValues"
                                :ready="thumbsReady"
                                :thumbnails="!previewFailed"
                                :disabled="working"
                                :pending="choosing"
                                :refreshing="refreshingLayouts"
                                @choose="chooseLayout"
                                @refresh="refreshLayouts"
                            />

                            <div v-if="!editing && !session.draft_problem" id="gw-draft-view" role="tabpanel" :aria-labelledby="`gw-tab-${view}`">
                                <PagePreview
                                    v-if="session.page_preview && session.id"
                                    v-show="view === 'preview'"
                                    :key="session.id"
                                    :session="session"
                                    :base-url="baseUrl"
                                    :blueprint="blueprint"
                                    :form-values="formValues"
                                    :width="previewWidth"
                                    :active="view === 'preview'"
                                    @failed="previewFailedFor"
                                    @rendered="previewRendered"
                                    @blocks="setView('blocks')"
                                    @gap="openGap"
                                />
                                <template v-if="view !== 'preview'">
                                    <p v-if="!working" class="mb-3 text-sm text-gray-500">{{ __('Click any writing (or Tab to it) to change it. It’s saved when you leave it; Esc puts it back.') }}</p>
                                    <DraftPreview :nodes="session.preview" :view="view" :editable="!working" @edit="editField" @gap="openGap" />
                                    <ExtrasList v-if="view === 'text'" :extras="session.extras ?? []" :editable="!working" @edit="editExtra" @remove="removeExtra" @gap="openGap" />
                                </template>
                            </div>

                            <GapPopover
                                v-if="gap"
                                :key="`${gap.kind}:${gap.hint}:${gap.occurrence}`"
                                :gap="gap"
                                :container="$refs.draftPane"
                                :base-url="baseUrl"
                                :session-id="session.id"
                                :busy="gapBusy"
                                @resolve="resolveGap"
                                @close="closeGap"
                            />
                        </template>
                    </div>
                </div>
                </div>
            </div>
        </template>
    </div>
</template>
