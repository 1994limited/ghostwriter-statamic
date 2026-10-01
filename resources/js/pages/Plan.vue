<!--
    The content plan: ideas for entries the site does not have yet. Ghostwriter
    suggests them from what is missing, anyone can add their own, and each one
    opens straight into a brief that fills itself in.
-->
<script>
import ghost from '../icon.js';
import { Head } from '@statamic/cms/inertia';
import { Alert, Badge, Button, Checkbox, Field, Header, Heading, Input, Modal, Panel, Select, Subheading, Textarea } from '@statamic/cms/ui';
import SetupAlert from '../components/SetupAlert.vue';

export default {
    components: { Alert, Badge, Button, Checkbox, Field, Head, Header, Heading, Input, Modal, Panel, Select, SetupAlert, Subheading, Textarea },

    props: {
        configured: { type: Boolean, required: true },
        provider: { type: String, required: true },
        collections: { type: Array, required: true },
        plan: { type: Object, required: true },
        urls: { type: Object, required: true },
    },

    data() {
        return {
            current: this.plan,
            selected: this.collections.map((collection) => collection.handle),
            steer: '',
            adding: { title: '', collection: this.collections[0]?.handle ?? '', notes: '' },
            showDone: false,
            // Which of the suggestions waiting to be looked over are ticked.
            chosen: [],
            timer: null,
        };
    },

    computed: {
        ghost: () => ghost,

        working() {
            return this.current.status === 'working';
        },

        // Open ideas, under the collection each would be written in.
        groups() {
            return this.collections
                .map((collection) => ({ ...collection, ideas: this.current.ideas.filter((idea) => idea.collection === collection.handle && idea.status === 'open') }))
                .filter((group) => group.ideas.length);
        },

        // Started but not yet a saved entry: the ones to pick back up.
        started() {
            return this.current.ideas.filter((idea) => idea.status === 'drafted' && !idea.finished);
        },

        open() {
            return this.current.ideas.filter((idea) => idea.status === 'open');
        },

        done() {
            return this.current.ideas.filter((idea) => idea.status === 'dismissed' || (idea.status === 'drafted' && idea.finished));
        },

        reviewing: {
            get() {
                return this.current.pending.length > 0;
            },
            set(open) {
                if (!open) this.decide(true);
            },
        },

        options() {
            return this.collections.map((collection) => ({ value: collection.handle, label: collection.title }));
        },
    },

    mounted() {
        if (this.working) this.poll();
    },

    beforeUnmount() {
        clearTimeout(this.timer);
    },

    methods: {
        apply(data) {
            const finished = this.working && data.status === 'idle';

            this.current = data;

            // Fresh suggestions: everything ticked to start with.
            if (data.pending.length && !this.chosen.length) this.chosen = data.pending.map((idea, i) => i);
            if (!data.pending.length) this.chosen = [];

            if (data.status === 'working') this.poll();
            if (finished && !data.pending.length) this.$toast.info(this.__('Nothing new to suggest this time.'));
        },

        poll() {
            clearTimeout(this.timer);

            this.timer = setTimeout(async () => {
                try {
                    this.apply((await this.$axios.get(this.urls.status)).data);
                } catch (error) {
                    this.poll();
                }
            }, 2500);
        },

        async request(method, url, payload) {
            try {
                this.apply((await this.$axios[method](url, payload)).data);

                return true;
            } catch (error) {
                const first = Object.values(error.response?.data?.errors ?? {})[0]?.[0];

                this.$toast.error(first ?? error.response?.data?.message ?? this.__('Something went wrong.'));

                return false;
            }
        },

        stageText(stage) {
            return {
                failed: this.__('Something went wrong last time'),
                working: this.__('Ghostwriter is writing'),
                interview: this.__('Waiting on your answers'),
                draft: this.__('Draft ready to use'),
                in_form: this.__('Put in the form, not saved'),
                saved: this.__('Saved as a draft entry'),
                published: this.__('Published'),
            }[stage] ?? this.__('Started');
        },

        suggest() {
            return this.request('post', this.urls.suggest, { collections: this.selected, steer: this.steer });
        },

        async add() {
            if (await this.request('post', this.urls.ideas, this.adding)) {
                this.adding = { ...this.adding, title: '', notes: '' };
            }
        },

        tick(i, on) {
            this.chosen = on ? [...this.chosen, i] : this.chosen.filter((n) => n !== i);
        },

        // Ticked suggestions join the plan; unticked ones are kept as
        // dismissed so they are not suggested again. Closing the box without
        // deciding drops them all.
        async decide(discard = false) {
            const chosen = discard ? [] : this.chosen;

            if (await this.request('post', this.urls.accept, { chosen, discard })) {
                if (!discard) this.$toast.success(this.__(':count added to the plan.', { count: chosen.length }));
            }
        },

        // Started pieces stay: they belong to a conversation, not the list.
        clear(status) {
            const count = this.current.ideas.filter((idea) => idea.status === status).length;
            const question = status === 'open'
                ? this.__('Remove all :count ideas from the list? Started and dismissed ones stay. This cannot be undone.', { count })
                : this.__('Delete all :count dismissed ideas? Ghostwriter will no longer know not to suggest them again.', { count });

            if (!confirm(question)) return;

            return this.request('delete', this.urls.clear, { data: { status } });
        },

        mark(idea, status) {
            return this.request('patch', idea.update_url, { status });
        },

        remove(idea) {
            return this.request('delete', idea.update_url);
        },
    },
};
</script>

<template>
    <Head :title="__('Content plan')" />

    <div class="mx-auto max-w-6xl">
        <Header :title="__('Content plan')" :icon="ghost">
            <Button :href="urls.index" :text="__('Back')" variant="ghost" />
            <Button v-if="open.length" :text="__('Clear the list')" variant="ghost" @click="clear('open')" />
        </Header>

        <SetupAlert v-if="!configured" :provider="provider" />

        <Alert v-if="current.status === 'failed'" variant="error" :heading="__('That did not work')" :text="current.error" class="mb-6" />

        <Modal v-model:open="reviewing" :title="__('Ghostwriter suggests')" :icon="ghost">
            <div class="space-y-3 p-1">
                <Subheading :text="__('Tick the ones worth writing. Unticked ones are kept as dismissed, so they are not suggested again.')" />
                <div v-for="(idea, i) in current.pending" :key="i" class="rounded-lg border border-gray-200 p-3 dark:border-gray-700!">
                    <Checkbox :model-value="chosen.includes(i)" :label="idea.title" @update:model-value="(on) => tick(i, on)" />
                    <div class="ms-6 mt-1 text-xs text-gray-500">{{ idea.collection_title }}<template v-if="idea.type_title"> · {{ idea.type_title }}</template></div>
                    <p v-if="idea.why" class="ms-6 mt-1 text-sm">{{ idea.why }}</p>
                    <p v-if="idea.notes" class="ms-6 mt-1 text-sm whitespace-pre-line text-gray-500">{{ idea.notes }}</p>
                </div>
                <div class="flex items-center justify-between pt-2">
                    <Button variant="ghost" :text="__('Drop them all')" @click="decide(true)" />
                    <Button variant="primary" :text="chosen.length ? __('Add :count to the plan', { count: chosen.length }) : __('Add none, dismiss the rest')" @click="decide(false)" />
                </div>
            </div>
        </Modal>

        <div class="grid gap-6 lg:grid-cols-3">
            <div class="space-y-6 lg:col-span-2">
                <Panel v-if="started.length" :heading="__('In progress')" :subheading="__('Started, and not yet saved as an entry.')">
                    <div class="divide-y divide-gray-200 dark:divide-gray-700!">
                        <div v-for="idea in started" :key="idea.id" class="flex items-center gap-4 p-4">
                            <div class="min-w-0 flex-1">
                                <Heading :text="idea.title" />
                                <p class="mt-1 text-sm text-gray-500">{{ idea.collection_title }} · {{ stageText(idea.stage) }}</p>
                            </div>
                            <Button v-if="idea.resume_url" size="sm" variant="primary" :icon="ghost" :href="idea.resume_url" :text="__('Resume')" />
                            <Button size="sm" variant="ghost" :text="__('Back to ideas')" @click="mark(idea, 'open')" />
                        </div>
                    </div>
                </Panel>

                <Panel v-if="!groups.length && !started.length" :heading="__('Ideas')">
                    <div class="px-4 py-16 text-center text-gray-500">
                        {{ working ? __('Reading what the site has and looking for what is missing. This takes a minute or so.') : __('Nothing on the plan yet. Ask Ghostwriter what is missing, or add an idea of your own.') }}
                    </div>
                </Panel>

                <Panel v-for="group in groups" :key="group.handle" :heading="__(group.title)" :subheading="__(':count to write', { count: group.ideas.length })">
                    <div class="divide-y divide-gray-200 dark:divide-gray-700!">
                        <div v-for="idea in group.ideas" :key="idea.id" class="flex items-start gap-4 p-4">
                            <div class="min-w-0 flex-1">
                                <div class="flex flex-wrap items-center gap-2">
                                    <Heading :text="idea.title" />
                                    <Badge v-if="idea.type_title" :text="idea.type_title" />
                                    <Badge v-if="idea.source === 'suggested'" color="blue" :text="__('Suggested')" />
                                </div>
                                <p v-if="idea.why" class="mt-1 text-sm">{{ idea.why }}</p>
                                <p v-if="idea.notes" class="mt-1 text-sm whitespace-pre-line text-gray-500">{{ idea.notes }}</p>
                            </div>
                            <div class="flex shrink-0 flex-col items-end gap-2">
                                <Button v-if="idea.draft_url" size="sm" variant="primary" :icon="ghost" :href="idea.draft_url" :text="__('Draft this')" />
                                <Button size="sm" variant="ghost" :text="__('Not this one')" @click="mark(idea, 'dismissed')" />
                            </div>
                        </div>
                    </div>
                </Panel>

                <div v-if="done.length">
                    <Button size="sm" variant="ghost" :text="showDone ? __('Hide finished and dismissed') : __('Show :count finished and dismissed', { count: done.length })" @click="showDone = !showDone" />

                    <Panel v-if="showDone" class="mt-3">
                        <div class="divide-y divide-gray-200 dark:divide-gray-700!">
                            <div v-for="idea in done" :key="idea.id" class="flex items-center gap-4 px-4 py-3">
                                <div class="min-w-0 flex-1">
                                    <span :class="{ 'line-through': idea.status === 'dismissed' }">{{ idea.title }}</span>
                                    <span class="ms-2 text-sm text-gray-500">{{ idea.collection_title }}</span>
                                </div>
                                <Badge :color="idea.status === 'drafted' ? 'green' : 'gray'" :text="idea.status === 'drafted' ? stageText(idea.stage) : __('Dismissed')" />
                                <Button v-if="idea.entry_url" size="sm" variant="ghost" :href="idea.entry_url" :text="__('Open entry')" />
                                <Button size="sm" variant="ghost" :text="__('Put back')" @click="mark(idea, 'open')" />
                                <Button size="sm" variant="ghost" :text="__('Delete')" @click="remove(idea)" />
                            </div>
                        </div>
                        <div v-if="done.some((idea) => idea.status === 'dismissed')" class="px-4 py-3">
                            <Button size="sm" variant="ghost" :text="__('Delete all dismissed')" @click="clear('dismissed')" />
                        </div>
                    </Panel>
                </div>
            </div>

            <div class="space-y-6">
                <Panel :heading="__('Ask what is missing')">
                    <div class="space-y-3 p-4">
                        <Subheading :text="__('Ghostwriter reads everything in the collections you tick, and what is already on the plan, and suggests entries the site does not have. Ideas you dismiss tell it what not to suggest again.')" />
                        <div v-for="collection in collections" :key="collection.handle">
                            <Checkbox
                                :model-value="selected.includes(collection.handle)"
                                :label="__(collection.title)"
                                :disabled="working"
                                @update:model-value="(on) => (selected = on ? [...selected, collection.handle] : selected.filter((handle) => handle !== collection.handle))"
                            />
                        </div>
                        <Textarea v-model="steer" :rows="2" :disabled="working" :placeholder="__('Optional: anything to steer it. “More for agencies.” “Ecommerce.”')" />
                        <Button
                            class="w-full"
                            variant="primary"
                            :text="working ? __('Looking…') : __('Suggest ideas')"
                            :disabled="!configured || working || !selected.length"
                            :loading="working"
                            @click="suggest"
                        />
                    </div>
                </Panel>

                <Panel :heading="__('Add your own')">
                    <div class="space-y-3 p-4">
                        <Input v-model="adding.title" :placeholder="__('Working title')" />
                        <Select v-model="adding.collection" :options="options" />
                        <Textarea v-model="adding.notes" :rows="3" :placeholder="__('Notes: the angle, who it is for, points to make…')" />
                        <Button class="w-full" :text="__('Add to the plan')" :disabled="!adding.title.trim() || !adding.collection" @click="add" />
                    </div>
                </Panel>
            </div>
        </div>
    </div>
</template>
