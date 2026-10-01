<!--
    Ghostwriter's home: the voice guide, the collections it writes for with
    the kinds of content it has learned in each, and the pieces in progress.
    Writing itself happens on each collection's own create screen.
-->
<script>
import ghost from '../icon.js';
import { Head, Link, router } from '@statamic/cms/inertia';
import { Alert, Badge, Button, Header, Heading, Modal, Panel, Subheading } from '@statamic/cms/ui';
import KindSuggestions from '../components/KindSuggestions.vue';
import LearnForm from '../components/LearnForm.vue';
import SetupAlert from '../components/SetupAlert.vue';

export default {
    components: { Alert, Badge, Button, Head, Header, Heading, KindSuggestions, LearnForm, Link, Modal, Panel, SetupAlert, Subheading },

    props: {
        configured: { type: Boolean, required: true },
        provider: { type: String, required: true },
        settings_url: { type: String, default: null },
        voice: { type: Object, required: true },
        imagery: { type: Object, required: true },
        plan: { type: Object, required: true },
        collections: { type: Array, required: true },
        sessions: { type: Array, required: true },
        auto_kinds: { type: Boolean, default: true },
    },

    data() {
        return { learning: null, info: null, timer: null, showFinished: false };
    },

    computed: {
        ghost: () => ghost,

        open: {
            get() {
                return this.learning !== null;
            },
            set(value) {
                if (!value) this.close();
            },
        },

        working() {
            return this.info?.state.status === 'working';
        },

        inProgress() {
            return this.sessions.filter((session) => !session.finished);
        },

        finished() {
            return this.sessions.filter((session) => session.finished);
        },
    },

    beforeUnmount() {
        clearTimeout(this.timer);
    },

    methods: {
        async add(collection) {
            this.learning = collection;
            this.info = null;

            await this.refresh();
        },

        close() {
            clearTimeout(this.timer);
            this.learning = null;
        },

        async refresh() {
            const { data } = await this.$axios.get(this.learning.api_url);
            const wasWorking = this.working;

            this.info = data;

            if (data.state.status === 'working') {
                this.timer = setTimeout(() => this.refresh(), 2500);
            } else if (wasWorking && data.state.status === 'idle') {
                this.close();
                this.$toast.success(this.__('Learned. Check the questions and guidance, then start writing.'));
                router.reload();
            }
        },

        async learn(options) {
            try {
                const { data } = await this.$axios.post(this.learning.analyse_url, options);

                this.info = data;
                this.timer = setTimeout(() => this.refresh(), 2500);
            } catch (error) {
                this.$toast.error(error.response?.data?.message ?? this.__('Something went wrong.'));
            }
        },

        reload() {
            router.reload();
        },

        // Ask for kinds now, rather than waiting for the automatic check.
        async suggest(collection) {
            try {
                const { data } = await this.$axios.post(collection.suggest_url);
                this.$refs[`kinds-${collection.handle}`]?.[0]?.apply(data) ?? this.$refs[`kinds-${collection.handle}`]?.apply(data);
            } catch (error) {
                this.$toast.error(error.response?.data?.message ?? this.__('Something went wrong.'));
            }
        },

        statusColor(session) {
            return { failed: 'red', working: 'blue', published: 'green', saved: 'green', changed: 'green', in_form: 'yellow' }[session.stage] ?? 'gray';
        },

        statusText(session) {
            return {
                failed: this.__('Failed'),
                working: this.__('Writing'),
                interview: this.__('Waiting on you'),
                draft: this.__('Draft ready'),
                in_form: this.__('In the form, not saved'),
                saved: this.__('Saved as a draft entry'),
                published: this.__('Published'),
                editing: this.__('Editing'),
                changed: this.__('Changes added'),
            }[session.stage];
        },

        // Takes the conversation and draft off the list. The entry, if there is one, is untouched.
        async remove(session) {
            if (!confirm(this.__('Remove “:title” from Ghostwriter? The conversation and its draft are deleted. Any entry already saved is not touched.', { title: session.title }))) return;

            try {
                await this.$axios.delete(session.delete_url);
                router.reload();
            } catch (error) {
                this.$toast.error(error.response?.data?.message ?? this.__('Something went wrong.'));
            }
        },
    },
};
</script>

<template>
    <Head :title="__('Ghostwriter')" />

    <div class="mx-auto max-w-5xl">
        <Header :title="__('Ghostwriter')" :icon="ghost">
            <Button v-if="settings_url" :href="settings_url" :text="__('Settings')" icon="cog" variant="ghost" />
            <Button :href="plan.url" :text="plan.open ? __('Content plan (:count)', { count: plan.open }) : __('Content plan')" icon="list" />
            <Button :href="imagery.url" :text="__('Image style')" icon="media-image-picture-orientation" />
            <Button :href="voice.url" :text="__('Voice guide')" icon="text-formatting-quotation" />
        </Header>

        <SetupAlert v-if="!configured" :provider="provider" />

        <Panel :heading="__('Tone of voice')" class="mb-6">
            <div class="flex items-center justify-between gap-6 p-4">
                <div>
                    <Heading v-if="voice.exists" :text="__('Your voice guide is in place')" />
                    <Heading v-else :text="__('Start by teaching Ghostwriter your voice')" />
                    <Subheading v-if="voice.exists" :text="__('Last updated :when. Everything written here follows it.', { when: voice.updated_at })" />
                    <Subheading v-else :text="__('It reads what you have already published and writes a guide to how you sound. New content is then written to match.')" />
                </div>
                <Button :href="voice.url" :variant="voice.exists ? 'default' : 'primary'" :text="voice.exists ? __('Review') : __('Create the voice guide')" />
            </div>
        </Panel>

        <Panel
            :heading="__('Collections')"
            :subheading="__('Writing opens the collection’s own create screen with Ghostwriter on it, and the draft fills in the form. It works on every collection as it is; kinds you teach it get a brief of their own.')"
            class="mb-6"
        >
            <div class="divide-y divide-gray-200 dark:divide-gray-700">
                <div v-for="collection in collections" :key="collection.handle" class="p-4">
                    <div class="flex items-center justify-between gap-4">
                        <div>
                            <Heading size="lg" :text="__(collection.title)" />
                            <Subheading :text="__(':count entries', { count: collection.entries })" />
                        </div>
                        <div class="flex gap-2">
                            <Button size="sm" variant="ghost" :text="__('Suggest kinds')" :disabled="!configured || collection.suggested.status === 'working'" @click="suggest(collection)" />
                            <Button size="sm" :text="__('Teach it a kind')" @click="add(collection)" />
                            <Button size="sm" variant="primary" :icon="ghost" :href="collection.url" :text="__('Write')" />
                        </div>
                    </div>

                    <KindSuggestions :ref="`kinds-${collection.handle}`" :collection="collection" :configured="configured" @learned="reload" />

                    <ul v-if="collection.types.length" class="mt-3 space-y-1.5">
                        <li v-for="type in collection.types" :key="type.edit_url">
                            <Link :href="type.edit_url" class="flex items-center justify-between gap-4 rounded-md border border-gray-200 px-3 py-2 hover:border-gray-400 dark:border-gray-700 dark:hover:border-gray-500">
                                <div class="min-w-0">
                                    <span class="font-medium">{{ type.title }}</span>
                                    <span class="ms-2 text-sm text-gray-500">{{ type.description }}</span>
                                </div>
                                <span class="shrink-0 text-sm text-gray-500">
                                    {{ type.examples ? __('Modelled on :count entries', { count: type.examples }) : __('From the newest entries') }}
                                </span>
                            </Link>
                        </li>
                    </ul>
                    <p v-else class="mt-3 text-sm text-gray-500">{{ __('Ready to write with a general brief. Teach it a kind of content you write often to give that kind questions of its own.') }}</p>
                </div>

                <p v-if="!collections.length" class="p-4 text-sm text-gray-500">{{ __('No collections are switched on. Choose them in Settings.') }}</p>
            </div>
        </Panel>

        <Panel v-if="inProgress.length" :heading="__('In progress')" :subheading="__('A piece leaves this list once its entry has been saved.')">
            <ul class="divide-y divide-gray-200 dark:divide-gray-700">
                <li v-for="session in inProgress" :key="session.id" class="flex items-center gap-2 pe-3 hover:bg-gray-50 dark:hover:bg-gray-800">
                    <component :is="session.url ? 'Link' : 'div'" :href="session.url" class="flex min-w-0 flex-1 items-center justify-between gap-4 px-4 py-3">
                        <div class="min-w-0">
                            <div class="truncate font-medium">{{ session.title }}</div>
                            <div class="text-sm text-gray-500">{{ session.type }} · {{ session.updated_at }}</div>
                        </div>
                        <Badge :color="statusColor(session)" :text="statusText(session)" />
                    </component>
                    <Button size="sm" variant="ghost" :text="__('Remove')" @click="remove(session)" />
                </li>
            </ul>
        </Panel>

        <div v-if="finished.length" class="mt-4">
            <Button size="sm" variant="ghost" :text="showFinished ? __('Hide finished') : __('Show :count finished', { count: finished.length })" @click="showFinished = !showFinished" />

            <Panel v-if="showFinished" class="mt-3">
                <ul class="divide-y divide-gray-200 dark:divide-gray-700">
                    <li v-for="session in finished" :key="session.id" class="flex items-center gap-2 pe-3">
                        <component :is="session.entry_url ? 'Link' : 'div'" :href="session.entry_url" class="flex min-w-0 flex-1 items-center justify-between gap-4 px-4 py-3">
                            <div class="min-w-0">
                                <div class="truncate font-medium">{{ session.title }}</div>
                                <div class="text-sm text-gray-500">{{ session.type }} · {{ session.updated_at }}</div>
                            </div>
                            <Badge :color="statusColor(session)" :text="statusText(session)" />
                        </component>
                        <Button size="sm" variant="ghost" :text="__('Remove')" @click="remove(session)" />
                    </li>
                </ul>
            </Panel>
        </div>

        <Modal v-model:open="open" :title="learning ? __('A kind of content in :collection', { collection: learning.title }) : ''" :icon="ghost">
            <div v-if="info" class="p-1">
                <SetupAlert v-if="!info.configured" :provider="info.provider" />
                <Alert v-if="info.state.status === 'failed'" variant="error" :text="info.state.error" class="mb-4" />
                <LearnForm :entries="info.entries" :disabled="!info.configured" :loading="working" @learn="learn" />
            </div>
            <div v-else class="py-8 text-center text-gray-500">{{ __('Loading…') }}</div>
        </Modal>
    </div>
</template>
