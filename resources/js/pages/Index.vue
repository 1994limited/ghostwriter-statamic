<!--
    Ghostwriter's home: the voice guide, the collections it writes for with
    the kinds of content it has learned in each, and the pieces in progress.
    Writing itself happens on each collection's own create screen.
-->
<script>
import ghost from '../icon.js';
import { Head, Link, router } from '@statamic/cms/inertia';
import { Alert, Badge, Button, Dropdown, DropdownItem, DropdownMenu, Header, Heading, Modal, Panel, Subheading } from '@statamic/cms/ui';
import KindSuggestions from '../components/KindSuggestions.vue';
import LearnForm from '../components/LearnForm.vue';
import SetupAlert from '../components/SetupAlert.vue';

export default {
    components: { Alert, Badge, Button, Dropdown, DropdownItem, DropdownMenu, Head, Header, Heading, KindSuggestions, LearnForm, Link, Modal, Panel, SetupAlert, Subheading },

    props: {
        configured: { type: Boolean, required: true },
        provider: { type: String, required: true },
        settings_url: { type: String, default: null },
        voice: { type: Object, required: true },
        imagery: { type: Object, required: true },
        plan: { type: Object, required: true },
        collections: { type: Array, required: true },
        sessions: { type: Array, required: true },
        suggest_all_url: { type: String, default: null },
        setup: { type: Object, required: true },
        counts: { type: Object, required: true },
        stock: { type: Object, default: () => ({ previews: 0 }) },
        revisit: { type: Object, default: null },
    },

    data() {
        return { learning: null, info: null, timer: null, showFinished: false, hiddenSetup: this.setup.hidden, openKinds: {}, suggestingAll: false };
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

            this.info = data;
        },

        // Learning takes a minute: back to the dashboard, where the
        // collection's row shows it under way and says when it is done.
        async learn(options) {
            try {
                await this.$axios.post(this.learning.analyse_url, options);

                this.close();
                this.$toast.success(this.__('Learning. The new kind appears here in a minute or so.'));
                router.reload();
            } catch (error) {
                this.$toast.error(error.response?.data?.message ?? this.__('Something went wrong.'));
            }
        },

        reload() {
            router.reload();
        },

        async toggleSetup(hidden) {
            try {
                await this.$axios.post(this.setup.hide_url, { hidden });
                this.hiddenSetup = hidden;
                router.reload();
            } catch (error) {
                this.$toast.error(error.response?.data?.message ?? this.__('Something went wrong.'));
            }
        },

        suggestionsOf(collection) {
            return collection.suggested?.suggestions?.length ?? 0;
        },

        // Look over every collection for kinds at once.
        async suggestEverywhere() {
            this.suggestingAll = true;

            try {
                const { data } = await this.$axios.post(this.suggest_all_url);

                Object.entries(data.collections).forEach(([handle, state]) => {
                    const ref = this.$refs[`kinds-${handle}`];
                    (Array.isArray(ref) ? ref[0] : ref)?.apply(state);
                });
            } catch (error) {
                this.$toast.error(error.response?.data?.message ?? this.__('Something went wrong.'));
            } finally {
                this.suggestingAll = false;
            }
        },

        // Ask for kinds in one collection. Nothing is looked for by itself here.
        async suggest(collection) {
            try {
                const { data } = await this.$axios.post(collection.suggest_url);
                this.$refs[`kinds-${collection.handle}`]?.[0]?.apply(data) ?? this.$refs[`kinds-${collection.handle}`]?.apply(data);
            } catch (error) {
                this.$toast.error(error.response?.data?.message ?? this.__('Something went wrong.'));
            }
        },

        // Who started a piece and who last changed it, when conversations are shared.
        people(session) {
            return [
                session.started_by ? this.__('Started by :name', { name: session.started_by }) : null,
                session.touched_by ? this.__('last changed by :name', { name: session.touched_by }) : null,
            ].filter(Boolean).join(', ');
        },

        statusColor(session) {
            return { failed: 'red', working: 'blue', published: 'green', saved: 'green', changed: 'yellow', in_form: 'yellow' }[session.stage] ?? 'gray';
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
                changed: this.__('Changes in the form, not saved'),
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
    <Head :title="__('Overview')" />

    <div class="mx-auto max-w-5xl">
        <Header :title="__('Overview')" :icon="ghost">
            <Button v-if="settings_url" :href="settings_url" icon="cog" variant="ghost" :aria-label="__('Settings')" />
        </Header>

        <SetupAlert v-if="!configured" :provider="provider" />

        <!-- Get started, until it is complete; then a line saying so, until it is hidden -->
        <Panel v-if="!hiddenSetup && !setup.complete" class="mb-6">
            <div class="flex items-center justify-between gap-6 p-4">
                <div class="min-w-0">
                    <Heading :text="__('Get started') + ' · ' + __(':done of :total done', { done: setup.done, total: setup.total })" />
                    <Subheading v-if="setup.next" :text="__('Next: :title', { title: setup.next.title }) + (setup.next.optional ? ' (' + __('optional') + ')' : '')" />
                    <div class="mt-2 h-1 w-64 max-w-full overflow-hidden rounded-full bg-gray-200 dark:bg-gray-700!">
                        <div class="h-full rounded-full" style="background: var(--gw-accent, #2b3a64)" :style="{ width: `${Math.round((setup.done / setup.total) * 100)}%` }"></div>
                    </div>
                </div>
                <div class="flex shrink-0 gap-2">
                    <Button v-if="setup.can_toggle" size="sm" variant="ghost" :text="__('Hide')" @click="toggleSetup(true)" />
                    <Button size="sm" variant="primary" :href="setup.url + (setup.next ? '#step-' + setup.next.number : '')" :text="__('Continue')" />
                </div>
            </div>
        </Panel>
        <Panel v-else-if="!hiddenSetup" class="mb-6">
            <div class="flex items-center justify-between gap-6 px-4 py-3">
                <p class="min-w-0 text-sm">
                    <span class="font-medium">{{ __('You’re set up.') }}</span>
                    <span class="text-gray-500"> {{ setup.can_toggle ? __('Hide Get started whenever you like.') : __('Get started stays here until someone who looks after the settings hides it.') }}</span>
                </p>
                <div class="flex shrink-0 gap-2">
                    <Button size="sm" variant="ghost" :href="setup.url + (setup.next ? '#step-' + setup.next.number : '')" :text="setup.next ? __('Optional steps') : __('Get started')" />
                    <Button v-if="setup.can_toggle" size="sm" :text="__('Hide Get started')" @click="toggleSetup(true)" />
                </div>
            </div>
        </Panel>

        <!-- Four tiles: where things stand -->
        <div class="mb-6 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <Link :href="voice.url" class="rounded-lg border border-gray-200 p-4 hover:border-gray-400! dark:border-gray-700! dark:hover:border-gray-500!">
                <div class="text-xs font-medium tracking-wide text-gray-500 uppercase">{{ __('Voice guide') }}</div>
                <div class="mt-1 font-medium">{{ voice.exists ? __('Written') : __('Not written yet') }}</div>
                <div class="text-sm text-gray-500">{{ voice.exists ? __('Updated :when', { when: voice.updated_at }) : __('Everything written follows it') }}</div>
            </Link>
            <Link :href="imagery.url" class="rounded-lg border border-gray-200 p-4 hover:border-gray-400! dark:border-gray-700! dark:hover:border-gray-500!">
                <div class="text-xs font-medium tracking-wide text-gray-500 uppercase">{{ __('Image style') }}</div>
                <div class="mt-1 font-medium">{{ imagery.exists ? __('Written') : __('Not written yet') }}</div>
                <div class="text-sm text-gray-500">{{ __('How your pictures look, in words') }}</div>
            </Link>
            <Link :href="plan.url" class="rounded-lg border border-gray-200 p-4 hover:border-gray-400! dark:border-gray-700! dark:hover:border-gray-500!">
                <div class="text-xs font-medium tracking-wide text-gray-500 uppercase">{{ __('Content plan') }}</div>
                <div class="mt-1 font-medium"><span class="text-xl" style="color: var(--gw-accent, #2b3a64)">{{ counts.ideas }}</span> {{ __n('idea waiting|ideas waiting', counts.ideas) }}</div>
                <div class="text-sm text-gray-500">{{ __('What the site is missing') }}</div>
            </Link>
            <a href="#in-progress" class="rounded-lg border border-gray-200 p-4 hover:border-gray-400! dark:border-gray-700! dark:hover:border-gray-500!">
                <div class="text-xs font-medium tracking-wide text-gray-500 uppercase">{{ __('In progress') }}</div>
                <div class="mt-1 font-medium"><span class="text-xl" style="color: var(--gw-accent, #2b3a64)">{{ counts.in_progress }}</span> {{ __n('piece|pieces', counts.in_progress) }}</div>
                <div class="text-sm text-gray-500">{{ __('Drafts and edits under way') }}</div>
            </a>
        </div>

        <!-- Content to revisit: the pages worth a look, found without AI -->
        <Link
            v-if="revisit"
            :href="revisit.url"
            class="mb-6 flex items-start justify-between gap-4 rounded-lg border border-gray-200 p-4 hover:border-gray-400! dark:border-gray-700! dark:hover:border-gray-500!"
            data-ghostwriter-revisit-tile
        >
            <div class="min-w-0">
                <div class="text-xs font-medium tracking-wide text-gray-500 uppercase">{{ __('Content to revisit') }}</div>
                <div class="mt-1 font-medium"><span class="text-xl" style="color: var(--gw-accent, #2b3a64)">{{ revisit.worth }}</span> {{ __n('page worth a look|pages worth a look', revisit.worth) }}</div>
                <div v-if="revisit.top.length" class="mt-1 truncate text-sm text-gray-500">{{ revisit.top.map((row) => `${row.title} (${row.reason})`).join(' · ') }}</div>
                <div v-else class="text-sm text-gray-500">{{ __('Dates, links, alt text and empty fields, checked without AI.') }}</div>
            </div>
            <span class="shrink-0 text-sm underline">{{ __('Open the list') }}</span>
        </Link>

        <!-- Stock previews still to license: only when there are some -->
        <Link
            v-if="stock.previews > 0"
            :href="stock.url"
            class="mb-6 flex items-center justify-between gap-4 rounded-lg border p-4"
            :class="stock.warning ? 'border-amber-400 bg-amber-50 hover:border-amber-500! dark:border-amber-700! dark:bg-amber-950/40!' : 'border-gray-200 hover:border-gray-400! dark:border-gray-700! dark:hover:border-gray-500!'"
            data-ghostwriter-stock-tile
        >
            <div>
                <div class="text-xs font-medium tracking-wide text-gray-500 uppercase">{{ __('Stock images') }}</div>
                <div class="mt-1 font-medium"><span class="text-xl" style="color: var(--gw-accent, #2b3a64)">{{ stock.previews }}</span> {{ __n('stock preview to license|stock previews to license', stock.previews) }}</div>
                <div class="text-sm" :class="stock.warning ? 'text-amber-800 dark:text-amber-300!' : 'text-gray-500'">{{ stock.warning ? __('Some are on live pages or their preview has expired. License them, or choose other images.') : __('Pages holding a preview can’t be published until it is licensed.') }}</div>
            </div>
            <span class="shrink-0 text-sm underline">{{ __('Open Stock images') }}</span>
        </Link>

        <!-- Collections, as one list -->
        <Panel :heading="__('Collections')" class="mb-6">
            <div v-if="collections.length > 1" class="flex items-center justify-between gap-4 border-b border-gray-200 px-4 py-2 dark:border-gray-700!">
                <span class="text-sm text-gray-500">{{ __('Ghostwriter can look over each collection for kinds of content worth teaching.') }}</span>
                <Button
                    size="sm"
                    :text="__('Suggest kinds everywhere')"
                    :loading="suggestingAll"
                    :disabled="!configured || suggestingAll || collections.every((collection) => collection.suggested?.status === 'working')"
                    @click="suggestEverywhere"
                />
            </div>
            <div class="divide-y divide-gray-200 dark:divide-gray-700!">
                <div v-for="collection in collections" :key="collection.handle" class="p-4">
                    <div class="flex items-center justify-between gap-4">
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-baseline gap-x-3">
                                <Heading :text="__(collection.title)" />
                                <span class="text-sm text-gray-500">{{ __n(':count entry|:count entries', collection.entries) }} · {{ __n(':count kind|:count kinds', collection.types.length) }}</span>
                            </div>
                            <div v-if="collection.types.length" class="mt-1.5 flex flex-wrap gap-1.5">
                                <Link v-for="type in collection.types" :key="type.edit_url" :href="type.edit_url" :title="type.description" class="rounded-md border border-gray-200 px-2 py-0.5 text-xs hover:border-gray-400! dark:border-gray-700! dark:hover:border-gray-500!">{{ type.title }}</Link>
                            </div>
                        </div>
                        <div class="flex shrink-0 gap-2">
                            <Dropdown>
                                <template #trigger>
                                    <Button size="sm" :text="__('Kinds')" />
                                </template>
                                <DropdownMenu>
                                    <DropdownItem :text="__('Teach a kind')" @click="add(collection)" />
                                    <DropdownItem :text="__('Suggest kinds')" :disabled="!configured || collection.suggested.status === 'working'" @click="suggest(collection)" />
                                </DropdownMenu>
                            </Dropdown>
                            <Button size="sm" variant="primary" :icon="ghost" :href="collection.url" :text="__('Write')" />
                        </div>
                    </div>

                    <!-- Suggestions fold into one line until opened -->
                    <button
                        v-if="suggestionsOf(collection) && !openKinds[collection.handle]"
                        type="button"
                        class="mt-2 text-sm font-medium hover:underline!"
                        style="color: var(--gw-accent, #2b3a64)"
                        @click="openKinds[collection.handle] = true"
                    >{{ __n(':count suggested kind to review|:count suggested kinds to review', suggestionsOf(collection)) }} →</button>
                    <KindSuggestions
                        v-show="openKinds[collection.handle] || !suggestionsOf(collection) || ['working', 'failed'].includes(collection.state?.status)"
                        :ref="`kinds-${collection.handle}`"
                        :collection="collection"
                        :configured="configured"
                        @learned="reload"
                    />
                </div>

                <p v-if="!collections.length" class="p-4 text-sm text-gray-500">{{ __('No collections are switched on. Choose them in Settings.') }}</p>
            </div>
        </Panel>

        <div id="in-progress"></div>
        <Panel :heading="__('In progress')" :subheading="__('A piece leaves this list once its entry has been saved.')">
            <p v-if="!inProgress.length" class="px-4 py-3 text-sm text-gray-500">{{ __('Nothing being written right now.') }}</p>
            <ul v-else class="divide-y divide-gray-200 dark:divide-gray-700!">
                <li v-for="session in inProgress" :key="session.id" class="flex items-center gap-2 pe-3 hover:bg-gray-50! dark:hover:bg-gray-800!">
                    <component :is="session.url ? 'Link' : 'div'" :href="session.url" class="flex min-w-0 flex-1 items-center justify-between gap-4 px-4 py-3">
                        <div class="min-w-0">
                            <div class="truncate font-medium">{{ session.title }}</div>
                            <div class="text-sm text-gray-500">{{ [session.type, people(session), session.updated_at].filter(Boolean).join(' · ') }}</div>
                            <div v-if="session.waiting_on" class="text-sm text-gray-500">{{ __(':name is waiting on Ghostwriter', { name: session.waiting_on }) }}</div>
                        </div>
                        <Badge :color="statusColor(session)" :text="statusText(session)" />
                    </component>
                    <Button v-if="session.delete_url" size="sm" variant="ghost" :text="__('Remove')" @click="remove(session)" />
                </li>
            </ul>
        </Panel>

        <div v-if="finished.length" class="mt-4">
            <Button size="sm" variant="ghost" :text="showFinished ? __('Hide finished') : __('Show :count finished', { count: finished.length })" @click="showFinished = !showFinished" />

            <Panel v-if="showFinished" class="mt-3">
                <ul class="divide-y divide-gray-200 dark:divide-gray-700!">
                    <li v-for="session in finished" :key="session.id" class="flex items-center gap-2 pe-3">
                        <component :is="session.entry_url ? 'Link' : 'div'" :href="session.entry_url" class="flex min-w-0 flex-1 items-center justify-between gap-4 px-4 py-3">
                            <div class="min-w-0">
                                <div class="truncate font-medium">{{ session.title }}</div>
                                <div class="text-sm text-gray-500">{{ session.type }} · {{ session.updated_at }}</div>
                            </div>
                            <Badge :color="statusColor(session)" :text="statusText(session)" />
                        </component>
                        <Button v-if="session.delete_url" size="sm" variant="ghost" :text="__('Remove')" @click="remove(session)" />
                    </li>
                </ul>
            </Panel>
        </div>

        <p v-if="hiddenSetup && setup.can_toggle" class="mt-8 text-center text-sm text-gray-500">
            <button type="button" class="hover:underline!" @click="toggleSetup(false)">{{ __('Show Get started') }}</button>
        </p>

        <Modal v-model:open="open" :title="learning ? __('A kind of content in :collection', { collection: learning.title }) : ''" :icon="ghost">
            <div v-if="info" class="p-1">
                <SetupAlert v-if="!info.configured" :provider="info.provider" />
                <Alert v-if="info.state.status === 'failed'" variant="error" :text="info.state.error" class="mb-4" />
                <Subheading
                    class="mb-4"
                    :text="__('Worth doing for something you write often. It reads the entries you point it at and writes a brief of its own for that kind, with questions that fit. This takes about a minute and happens once.')"
                />
                <LearnForm :entries="info.entries" :disabled="!info.configured" :loading="working" @learn="learn" />
            </div>
            <div v-else class="py-8 text-center text-gray-500">{{ __('Loading…') }}</div>
        </Modal>
    </div>
</template>
