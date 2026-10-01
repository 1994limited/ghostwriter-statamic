<!--
    The whole Ghostwriter flow for one collection, in steps:

      setup    the collection has not been learned yet
      type     choose what to write (skipped when there is only one kind)
      brief    the questionnaire
      write    the conversation, with the draft beside it

    Model calls run in the background, so while one is in flight the panel
    polls until the answer lands.
-->
<script>
import { Alert, Button, Field, Heading, Input, Select, Subheading, Textarea } from '@statamic/cms/ui';
import DraftPreview from './DraftPreview.vue';
import ExamplePicker from './ExamplePicker.vue';
import ImageSlots from './ImageSlots.vue';
import LearnForm from './LearnForm.vue';
import SetupAlert from './SetupAlert.vue';

export default {
    components: { Alert, Button, DraftPreview, ExamplePicker, Field, ImageSlots, Heading, Input, LearnForm, Select, SetupAlert, Subheading, Textarea },

    props: {
        collection: { type: String, required: true },
        blueprint: { type: String, default: null },
        baseUrl: { type: String, required: true },
        resume: { type: String, default: null },
        // An idea from the content plan to open on.
        idea: { type: String, default: null },
    },

    emits: ['apply'],

    data() {
        return {
            info: null,
            type: null,
            examples: [],
            answers: {},
            quick: { title: '', notes: '' },
            // The content plan idea this piece is being written from, if any.
            planned: null,
            guessing: false,
            // Seconds the current turn has been running, for the spinner.
            waited: 0,
            ticker: null,
            guessed: false,
            errors: {},
            session: null,
            message: '',
            editing: false,
            raw: '',
            busy: false,
            adding: false,
            showBrief: false,
            timer: null,
        };
    },

    computed: {
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

        conversation() {
            return this.session.messages.slice(1);
        },

        asking() {
            return this.session?.waiting_on_you === true && !this.working;
        },

        // What is probably going on, going by how long it has been.
        progress() {
            if (this.waited < 8) return this.session.draft ? this.__('Reading your message…') : this.__('Reading the brief…');
            if (this.waited < 30) return this.session.draft ? this.__('Revising the draft…') : this.__('Thinking it through…');

            return this.session.draft ? this.__('Still revising. Long drafts take a while…') : this.__('Writing. Long drafts take a while…');
        },

        elapsed() {
            return `${Math.floor(this.waited / 60)}:${String(this.waited % 60).padStart(2, '0')}`;
        },
    },

    async mounted() {
        await this.load();

        if (this.resume) {
            await this.open(this.resume);
        } else if (this.idea) {
            const idea = this.info?.ideas.find((candidate) => candidate.id === this.idea);

            if (idea) this.fromIdea(idea);
        }
    },

    watch: {
        // Count from the message being answered, so reopening the panel
        // mid-turn shows how long it has really been.
        working: {
            immediate: true,
            handler(working) {
                clearInterval(this.ticker);

                if (!working) return;

                const since = Date.parse(this.session.messages.at(-1)?.at ?? '') || Date.now();
                const tick = () => (this.waited = Math.max(0, Math.round((Date.now() - since) / 1000)));

                tick();
                this.ticker = setInterval(tick, 1000);
            },
        },
    },

    beforeUnmount() {
        clearTimeout(this.timer);
        clearInterval(this.ticker);
    },

    methods: {
        url(path) {
            return `${this.baseUrl}/${path}`;
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
                    if (data.types.length === 1 && data.kinds.length === 0 && data.ideas.length === 0 && !this.adding) this.choose(data.types[0]);
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
        // members, or whatever the type itself was taught from.
        choose(type, examples = null) {
            this.type = type;
            this.examples = examples ?? type.examples ?? [];
            this.answers = Object.fromEntries(type.questions.map((question) => [question.handle, '']));
            this.guessed = false;
            this.planned = null;
            this.quick = { title: '', notes: '' };
            this.errors = {};
        },

        options(question) {
            return Object.entries(question.options ?? {}).map(([value, label]) => ({ value, label }));
        },

        // Tall enough to show the whole answer, however it got there: typed,
        // or filled in by the quick brief. A short-answer question starts as
        // one line; past a screenful the box scrolls.
        rowsFor(question) {
            const lines = String(this.answers[question.handle] ?? '')
                .split('\n')
                .reduce((total, line) => total + Math.max(1, Math.ceil(line.length / 85)), 0);

            return Math.min(Math.max(lines + 1, question.type === 'text' ? 1 : 3), 16);
        },

        // An idea from the content plan: pick its kind of content, put its
        // title and notes in the quick brief, and fill the brief in.
        fromIdea(idea) {
            this.choose(this.info.types.find((type) => type.handle === idea.type) ?? this.general);
            this.planned = idea.id;
            this.quick = { title: idea.title, notes: [idea.why, idea.notes].filter(Boolean).join('\n\n') };

            if (this.info.configured) this.guess();
        },

        // Title and notes in, a filled-in questionnaire out, for checking.
        async guess() {
            this.guessing = true;

            try {
                const { data } = await this.$axios.post(this.url(`types/${this.type.handle}/brief`), this.quick);

                this.answers = { ...this.answers, ...data.answers };
                this.guessed = true;
                this.errors = {};
            } catch (error) {
                this.fail(error);
            } finally {
                this.guessing = false;
            }
        },

        async start() {
            this.busy = true;
            this.errors = {};

            try {
                const { data } = await this.$axios.post(this.url(`types/${this.type.handle}/sessions`), { answers: this.answers, examples: this.examples, idea: this.planned });

                this.receive(data);
            } catch (error) {
                this.errors = error.response?.data?.errors ?? {};
                this.fail(error);
            } finally {
                this.busy = false;
            }
        },

        async open(id) {
            try {
                const { data } = await this.$axios.get(this.url(`sessions/${id}`));

                this.receive(data);
            } catch (error) {
                this.fail(error);
            }
        },

        receive(data) {
            const changed = data.draft !== this.session?.draft;

            this.session = data;

            if (changed) {
                this.raw = data.draft ?? '';
                this.editing = false;
            }

            const drawing = (data.images ?? []).some((image) => image.status === 'working');

            if (data.status === 'working' || drawing) this.later(() => this.open(data.id));

            this.$nextTick(() => {
                const chat = this.$refs.chat;
                if (chat) chat.scrollTop = chat.scrollHeight;

                // Questions waiting: put the cursor where the answer goes.
                if (this.asking) this.$refs.composer?.$el?.querySelector?.('textarea')?.focus() ?? this.$refs.composer?.$el?.focus?.();
            });
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
            if (!this.message.trim() || this.working) return;

            const message = this.message;
            this.message = '';

            try {
                const { data } = await this.$axios.post(this.url(`sessions/${this.session.id}/messages`), { message });

                this.receive(data);
            } catch (error) {
                this.message = message;
                this.fail(error);
            }
        },

        async makeImage({ key, direction, source }) {
            const form = new FormData();

            form.append('key', key);
            form.append('direction', direction ?? '');
            if (source) form.append('source', source);

            try {
                const { data } = await this.$axios.post(this.url(`sessions/${this.session.id}/images`), form);

                this.receive(data);
            } catch (error) {
                this.fail(error);
            }
        },

        async findPhotos(key, query) {
            try {
                const { data } = await this.$axios.get(this.url(`sessions/${this.session.id}/photos`), { params: { key, query } });

                return data;
            } catch (error) {
                this.fail(error);

                return { query, photos: [] };
            }
        },

        async copyImage(key, from) {
            try {
                const { data } = await this.$axios.post(this.url(`sessions/${this.session.id}/images/copy`), { key, from });

                this.receive(data);
            } catch (error) {
                this.fail(error);
            }
        },

        async usePhoto(key, photo) {
            try {
                const { data } = await this.$axios.post(this.url(`sessions/${this.session.id}/photos`), { key, source: photo.source, id: photo.id });

                this.receive(data);

                return true;
            } catch (error) {
                this.fail(error);

                return false;
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
                const { data } = await this.$axios.post(this.url(`sessions/${this.session.id}/apply`), { blueprint: this.blueprint });

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
            this.type = this.nothingToChoose ? this.type : null;
        },
    },
};
</script>

<template>
    <div class="h-full overflow-y-auto p-6">
        <div v-if="step === 'loading'" class="py-24 text-center text-gray-500">{{ __('Loading…') }}</div>

        <template v-else>
            <SetupAlert v-if="!info.configured" :provider="info.provider" />

            <Alert
                v-if="!info.has_voice && step !== 'write'"
                variant="warning"
                :heading="__('No voice guide yet')"
                :text="__('Ghostwriter will still write, but in a plain voice rather than yours. Create the voice guide from the Ghostwriter screen for a better draft.')"
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
                    <Button size="sm" variant="ghost" :text="__('Teach it a kind')" @click="adding = true" />
                </div>

                <template v-if="info.ideas.length">
                    <div class="mb-2 flex items-baseline justify-between">
                        <Subheading :text="__('From the content plan')" />
                        <span class="text-sm text-gray-500">{{ __(':count ideas · scroll for more', { count: info.ideas.length }) }}</span>
                    </div>
                    <div class="-mx-1 mb-8 flex snap-x gap-3 overflow-x-auto px-1 pb-3">
                        <button
                            v-for="idea in info.ideas"
                            :key="idea.id"
                            type="button"
                            class="flex w-64 shrink-0 snap-start flex-col rounded-lg border border-gray-200 p-4 text-start hover:border-gray-400 dark:border-gray-700 dark:hover:border-gray-500"
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
                        class="rounded-lg border border-gray-200 p-4 text-start hover:border-gray-400 dark:border-gray-700 dark:hover:border-gray-500"
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
                            class="rounded-lg border border-gray-200 p-4 text-start hover:border-gray-400 dark:border-gray-700 dark:hover:border-gray-500"
                            @click="choose(general, kind.examples)"
                        >
                            <Heading :text="kind.label" />
                            <Subheading class="mt-1" :text="__(':count entries built the same way', { count: kind.count })" />
                        </button>
                    </div>
                </template>

                <button
                    type="button"
                    class="w-full rounded-lg border border-dashed border-gray-300 p-4 text-start hover:border-gray-400 dark:border-gray-600 dark:hover:border-gray-500"
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
                        class="flex w-full items-center justify-between rounded-md px-3 py-2 text-start hover:bg-gray-50 dark:hover:bg-gray-800"
                        @click="open(item.id)"
                    >
                        <span class="truncate">{{ item.title }}</span>
                        <span class="text-sm text-gray-500">{{ item.type }} · {{ item.updated_at }}</span>
                    </button>
                </div>
            </div>

            <!-- The questionnaire -->
            <div v-else-if="step === 'brief'" class="mx-auto max-w-3xl">
                <div class="mb-6 flex items-start justify-between gap-6">
                    <div>
                        <Heading size="lg" :text="type.title" />
                        <Subheading class="mt-1" :text="type.description" />
                    </div>
                    <Button v-if="!nothingToChoose" size="sm" variant="ghost" :text="__('Change')" @click="type = null" />
                </div>

                <div class="mb-8 space-y-3 rounded-lg border border-gray-200 p-4 dark:border-gray-700">
                    <Heading :text="__('Quick brief')" />
                    <Subheading :text="__('Give it a title and anything you already know. Ghostwriter fills in the questions below with its best guess, for you to check and change.')" />
                    <Input v-model="quick.title" :placeholder="__('Working title')" :disabled="guessing" @keydown.enter.stop.prevent="quick.title.trim() && guess()" />
                    <Textarea v-model="quick.notes" elastic :rows="3" :disabled="guessing" :placeholder="__('Notes: the angle, who it is for, points to make, projects to mention…')" />
                    <div class="flex items-center justify-between gap-4">
                        <span class="text-sm text-gray-500">
                            {{ guessed ? __('Filled in below. Anything in [square brackets] needs you.') : __('Optional. You can also just answer the questions.') }}
                        </span>
                        <Button
                            :text="guessed ? __('Guess again') : __('Fill in the brief')"
                            :loading="guessing"
                            :disabled="!info.configured || guessing || !quick.title.trim()"
                            @click="guess"
                        />
                    </div>
                </div>

                <div class="space-y-6">
                    <Field
                        v-for="question in type.questions"
                        :key="question.handle"
                        :label="question.label"
                        :instructions="question.instructions ?? ''"
                        :required="question.required === true"
                        :error="errors[`answers.${question.handle}`]?.[0]"
                    >
                        <Select v-if="question.type === 'select'" v-model="answers[question.handle]" :options="options(question)" />
                        <Textarea v-else v-model="answers[question.handle]" :rows="rowsFor(question)" class="resize-y" />
                    </Field>
                </div>

                <Field
                    v-if="info.entries.length"
                    class="mt-6"
                    :label="__('Model it on')"
                    :instructions="__('Optional. Tick up to six entries and the draft follows how they are built. With none ticked, Ghostwriter goes by the brief and how this collection is usually written.')"
                >
                    <ExamplePicker v-model="examples" :entries="info.entries" />
                </Field>

                <div class="mt-8 flex items-center justify-between">
                    <span class="text-sm text-gray-500">{{ __('Short answers are fine. Ghostwriter asks for anything it still needs before it writes.') }}</span>
                    <Button variant="primary" :text="__('Start writing')" :disabled="!info.configured || busy" :loading="busy" @click="start" />
                </div>

                <div v-if="nothingToChoose && info.sessions.length" class="mt-10">
                    <Subheading :text="__('Or carry on with')" class="mb-2" />
                    <button
                        v-for="item in info.sessions"
                        :key="item.id"
                        type="button"
                        class="flex w-full items-center justify-between rounded-md px-3 py-2 text-start hover:bg-gray-50 dark:hover:bg-gray-800"
                        @click="open(item.id)"
                    >
                        <span class="truncate">{{ item.title }}</span>
                        <span class="text-sm text-gray-500">{{ item.updated_at }}</span>
                    </button>
                </div>
            </div>

            <!-- The conversation and the draft -->
            <div v-else class="grid h-full gap-6 lg:grid-cols-5">
                <div class="flex min-h-0 flex-col rounded-lg border border-gray-200 lg:col-span-2 dark:border-gray-700">
                    <div ref="chat" class="flex-1 space-y-3 overflow-y-auto p-4">
                        <div class="rounded-lg bg-gray-100 px-3 py-2 text-sm dark:bg-gray-800">
                            <button type="button" class="font-medium underline" @click="showBrief = !showBrief">
                                {{ showBrief ? __('Hide the brief') : __('Show the brief') }}
                            </button>
                            <div v-if="showBrief" class="mt-2 whitespace-pre-wrap">{{ session.messages[0]?.content }}</div>
                        </div>

                        <div
                            v-for="(entry, index) in conversation"
                            :key="index"
                            class="rounded-lg px-3 py-2 text-sm whitespace-pre-wrap"
                            :class="[
                                entry.role === 'user' ? 'ms-8 bg-gray-100 dark:bg-gray-800' : 'me-8 border',
                                entry.role !== 'user' && asking && index === conversation.length - 1
                                    ? 'border-amber-400 bg-amber-50 dark:border-amber-500 dark:bg-amber-950/40'
                                    : entry.role !== 'user' ? 'border-gray-200 dark:border-gray-700' : '',
                            ]"
                        ><span v-if="entry.role !== 'user' && asking && index === conversation.length - 1" class="mb-1 block text-xs font-semibold tracking-wide text-amber-700 uppercase dark:text-amber-400">{{ __('Ghostwriter needs your answer') }}</span>{{ entry.content }}<span
                                v-if="entry.draft"
                                class="mt-2 flex items-center gap-1.5 border-t border-gray-200 pt-2 text-xs font-medium text-green-700 dark:border-gray-700 dark:text-green-400"
                            ><svg class="size-3.5 shrink-0" viewBox="0 0 16 16" fill="none" aria-hidden="true"><path d="M3 8.5l3.2 3.2L13 4.8" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" /></svg>{{ draftNote(entry.draft) }}</span></div>

                        <div v-if="working" class="me-8 flex items-center gap-2.5 rounded-lg border border-gray-200 px-3 py-2 text-sm text-gray-500 dark:border-gray-700" role="status">
                            <svg class="size-4 shrink-0 animate-spin" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                <circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="3" class="opacity-25" />
                                <path d="M21 12a9 9 0 0 0-9-9" stroke="currentColor" stroke-width="3" stroke-linecap="round" />
                            </svg>
                            <span>{{ progress }}</span>
                            <span class="ms-auto tabular-nums">{{ elapsed }}</span>
                        </div>

                        <Alert v-if="session.status === 'failed'" variant="error" :heading="__('That did not work')" :text="session.error" />
                    </div>

                    <div class="space-y-2 border-t p-4" :class="asking ? 'border-amber-400 bg-amber-50 dark:border-amber-500 dark:bg-amber-950/40' : 'border-gray-200 dark:border-gray-700'">
                        <div v-if="asking" class="flex items-center gap-2 text-sm font-medium text-amber-800 dark:text-amber-300">
                            <span class="relative flex size-2.5">
                                <span class="absolute inline-flex size-full animate-ping rounded-full bg-amber-500 opacity-60"></span>
                                <span class="relative inline-flex size-2.5 rounded-full bg-amber-500"></span>
                            </span>
                            {{ session.draft ? __('Waiting on you. Answer above to carry on.') : __('Waiting on you. Answer the questions above and the draft follows.') }}
                        </div>
                        <Textarea
                            ref="composer"
                            v-model="message"
                            :rows="3"
                            :disabled="working"
                            :placeholder="asking ? __('Type your answers here. Short is fine; number them if it helps.') : session.draft ? __('Ask for a change…') : __('Answer the questions…')"
                            @keydown.meta.enter.stop.prevent="send"
                            @keydown.ctrl.enter.stop.prevent="send"
                        />
                        <div class="flex items-center justify-between">
                            <Button v-if="!session.editing" size="sm" variant="ghost" :text="__('Start over')" @click="startOver" />
                            <span v-else class="text-sm text-gray-500">{{ __('Working from the entry as last saved.') }}</span>
                            <Button :text="working ? __('Working…') : __('Send')" :loading="working" :disabled="working || !message.trim()" @click="send" />
                        </div>
                    </div>
                </div>

                <div class="flex min-h-0 flex-col rounded-lg border border-gray-200 lg:col-span-3 dark:border-gray-700">
                    <div class="flex items-center justify-between border-b border-gray-200 px-4 py-2.5 dark:border-gray-700">
                        <div class="text-sm text-gray-500">
                            {{ session.draft ? __(':count words', { count: session.words }) : __('Draft') }}
                        </div>
                        <div v-if="session.draft" class="flex gap-2">
                            <template v-if="editing">
                                <Button size="sm" variant="ghost" :text="__('Cancel')" @click="(editing = false), (raw = session.draft)" />
                                <Button size="sm" :text="__('Save changes')" :loading="busy" @click="saveDraft" />
                            </template>
                            <template v-else>
                                <Button size="sm" :text="__('Edit')" :disabled="working" @click="editing = true" />
                                <Button
                                    size="sm"
                                    variant="primary"
                                    :text="session.editing ? __('Use these changes') : __('Use this draft')"
                                    :disabled="working || !!session.draft_problem"
                                    :loading="busy"
                                    @click="apply"
                                />
                            </template>
                        </div>
                    </div>

                    <div class="flex-1 overflow-y-auto p-4">
                        <div v-if="!session.draft" class="py-24 text-center text-gray-500">
                            <template v-if="working">
                                <svg class="mx-auto mb-3 size-6 animate-spin" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                    <circle cx="12" cy="12" r="9" stroke="currentColor" stroke-width="3" class="opacity-25" />
                                    <path d="M21 12a9 9 0 0 0-9-9" stroke="currentColor" stroke-width="3" stroke-linecap="round" />
                                </svg>
                                {{ __('Ghostwriter is working. A draft usually takes a minute or two.') }}
                            </template>
                            <template v-else-if="asking">
                                <span class="mb-2 block text-base font-medium text-amber-700 dark:text-amber-400">{{ __('Ghostwriter has questions for you first') }}</span>
                                {{ __('They are in the conversation on the left. Answer them there and the draft will appear here.') }}
                                <span class="mt-3 block"><Button size="sm" :text="__('Just draft it with what you have')" @click="skipQuestions" /></span>
                            </template>
                            <template v-else>{{ __('No draft yet. Answer the questions in the conversation and the draft will appear here.') }}</template>
                        </div>

                        <template v-else>
                            <Alert v-if="session.draft_problem" variant="warning" :text="session.draft_problem" class="mb-4" />

                            <Textarea v-if="editing || session.draft_problem" v-model="raw" elastic :rows="24" class="font-mono text-sm" @focus="editing = true" />

                            <DraftPreview v-else :nodes="session.preview" />

                            <ImageSlots
                                v-if="session.images?.length && !session.draft_problem"
                                :images="session.images"
                                :tools="session.image_tools"
                                :search="findPhotos"
                                :choose="usePhoto"
                                :copy="copyImage"
                                class="mt-8"
                                @make="makeImage"
                            />
                        </template>
                    </div>
                </div>
            </div>
        </template>
    </div>
</template>
