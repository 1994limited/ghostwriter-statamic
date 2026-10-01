<!--
    Get started: seven steps from installed to writing, each done from this
    page. The step list on the left shows what is done, what is to do and
    what is optional; the current step fills the rest. Nothing is ticked by
    hand: every step reads the state of the site, and the page polls while
    anything is working.
-->
<script>
import ghost from '../icon.js';
import { Head, router } from '@statamic/cms/inertia';
import { Alert, Button, Checkbox, Header, Heading, Panel, Subheading, Textarea } from '@statamic/cms/ui';
import SetupAlert from '../components/SetupAlert.vue';

export default {
    components: { Alert, Button, Checkbox, Head, Header, Heading, Panel, SetupAlert, Subheading, Textarea },

    props: {
        configured: { type: Boolean, required: true },
        provider: { type: String, required: true },
        steps: { type: Array, required: true },
        details: { type: Object, required: true },
        progress: { type: Object, required: true },
        urls: { type: Object, required: true },
    },

    data() {
        const fromHash = Number((window.location.hash.match(/^#step-(\d+)$/) ?? [])[1]);
        const firstToDo = this.steps.findIndex((step) => !step.done);

        return {
            current: { steps: this.steps, details: this.details, progress: this.progress },
            index: fromHash >= 1 && fromHash <= this.steps.length ? fromHash - 1 : Math.max(0, firstToDo),
            busy: null,
            timer: null,
            steer: '',
        };
    },

    computed: {
        ghost: () => ghost,

        step() {
            return this.current.steps[this.index];
        },

        working() {
            return this.current.steps.some((step) => step.working) || this.current.details.plan.status === 'working';
        },

        percent() {
            return Math.round((this.current.progress.done / this.current.progress.total) * 100);
        },

        kinds() {
            return this.current.details.kinds;
        },
    },

    watch: {
        index(value) {
            history.replaceState(null, '', `#step-${value + 1}`);
        },
    },

    mounted() {
        history.replaceState(null, '', `#step-${this.index + 1}`);
        if (this.working) this.poll();
    },

    beforeUnmount() {
        clearTimeout(this.timer);
    },

    methods: {
        status(step) {
            if (step.working) return { text: this.__('Working…'), color: 'blue' };
            if (step.done) return { text: this.__('Done'), color: 'green' };
            if (step.optional) return { text: this.__('Optional'), color: 'gray' };

            return { text: this.__('To do'), color: 'yellow' };
        },

        poll() {
            clearTimeout(this.timer);

            this.timer = setTimeout(async () => {
                try {
                    const { data } = await this.$axios.get(this.urls.status);

                    this.current = { steps: data.steps, details: data.details, progress: data.progress };
                } catch (error) {
                    // Try again on the next tick.
                }

                if (this.working) this.poll();
            }, 2500);
        },

        async post(url, payload = {}) {
            this.busy = url;

            try {
                await this.$axios.post(url, payload);
                const { data } = await this.$axios.get(this.urls.status);
                this.current = { steps: data.steps, details: data.details, progress: data.progress };
                if (this.working) this.poll();
            } catch (error) {
                const first = Object.values(error.response?.data?.errors ?? {})[0]?.[0];
                this.$toast.error(first ?? error.response?.data?.message ?? this.__('Something went wrong.'));
            } finally {
                this.busy = null;
            }
        },

        act(action) {
            if (action.type === 'link') {
                router.visit(action.url);
            } else if (action.type === 'post') {
                this.post(action.url, action.data ?? {});
            } else if (action.type === 'suggest_kinds') {
                this.kinds.forEach((collection) => this.post(collection.suggest_url));
            }
        },

        async hide() {
            if (!confirm(this.__('Hide Get started? It leaves the navigation and the dashboard. A link at the foot of the dashboard brings it back.'))) return;

            await this.$axios.post(this.urls.hide, { hidden: true });
            router.visit(this.urls.index);
        },

        next() {
            if (this.index < this.current.steps.length - 1) this.index++;
        },

        back() {
            if (this.index > 0) this.index--;
        },
    },
};
</script>

<template>
    <Head :title="__('Get started')" />

    <div class="mx-auto max-w-6xl">
        <Header :title="__('Get started with Ghostwriter')" :icon="ghost">
            <Button :href="urls.index" :text="__('Dashboard')" variant="ghost" />
            <Button :text="__('Hide Get started')" variant="ghost" @click="hide" />
        </Header>

        <p class="mb-6 text-gray-500">{{ __('A few steps take Ghostwriter from installed to writing in your voice. Each can be done here, skipped, or redone at any time.') }}</p>

        <div class="grid gap-6 lg:grid-cols-3">
            <!-- The step list -->
            <Panel>
                <div class="border-b border-gray-200 p-4 dark:border-gray-700!">
                    <div class="mb-1.5 flex justify-between text-sm">
                        <span class="font-medium">{{ __(':done of :total done', { done: current.progress.done, total: current.progress.total }) }}</span>
                        <span class="text-gray-500">{{ percent }}%</span>
                    </div>
                    <div class="h-1.5 overflow-hidden rounded-full bg-gray-200 dark:bg-gray-700!">
                        <div class="h-full rounded-full transition-all" style="background: var(--gw-accent, #2b3a64)" :style="{ width: `${percent}%` }"></div>
                    </div>
                </div>
                <ol>
                    <li v-for="(item, i) in current.steps" :key="item.key">
                        <button
                            type="button"
                            class="flex w-full items-center gap-3 px-4 py-3 text-start hover:bg-gray-50! dark:hover:bg-gray-800!"
                            :class="{ 'bg-gray-50 dark:bg-gray-800!': i === index }"
                            :aria-current="i === index ? 'step' : null"
                            @click="index = i"
                        >
                            <span
                                class="flex size-6 shrink-0 items-center justify-center rounded-full text-xs font-medium"
                                :class="item.done ? 'text-white' : 'border border-gray-300 text-gray-500 dark:border-gray-600!'"
                                :style="item.done ? 'background: var(--gw-accent, #2b3a64)' : null"
                            >{{ item.done ? '✓' : i + 1 }}</span>
                            <span class="min-w-0 flex-1 truncate text-sm" :class="{ 'font-medium': i === index }">{{ item.title }}</span>
                            <span class="shrink-0 text-xs" :class="{ 'text-green-700 dark:text-green-400!': item.done, 'text-blue-700 dark:text-blue-400!': item.working, 'text-gray-500': !item.done && !item.working }">{{ status(item).text }}</span>
                        </button>
                    </li>
                </ol>
            </Panel>

            <!-- The current step -->
            <Panel class="lg:col-span-2">
                <div class="p-6">
                    <div class="mb-1 text-xs font-medium tracking-wide text-gray-500 uppercase">{{ __('Step :n of :total', { n: index + 1, total: current.steps.length }) }}<span v-if="step.optional"> · {{ __('Optional') }}</span></div>
                    <Heading size="lg" :text="step.title" />
                    <p class="mt-2 max-w-2xl">{{ step.text }}</p>
                    <p v-if="step.detail" class="mt-2 text-sm" :class="step.detail && !step.done && !step.working && step.key !== 'kinds' ? 'text-red-600' : 'text-gray-500'">{{ step.detail }}</p>

                    <SetupAlert v-if="step.action?.needs_key && !configured" :provider="provider" class="mt-4" />

                    <!-- Step 1: the key -->
                    <div v-if="step.key === 'key'" class="mt-4 rounded-md border border-gray-200 p-4 text-sm dark:border-gray-700!">
                        <p>{{ __('Provider') }}: <strong>{{ current.details.provider }}</strong><span v-if="current.details.key_name"> · {{ __('key') }}: <code>{{ current.details.key_name }}</code></span></p>
                        <p class="mt-1 text-gray-500">{{ __('Change the provider in the settings. Keys only ever live in .env.') }}</p>
                    </div>

                    <!-- Step 2: collections -->
                    <div v-if="step.key === 'collections'" class="mt-4 space-y-1.5">
                        <div v-for="collection in current.details.collections" :key="collection.handle" class="flex items-center justify-between rounded-md border border-gray-200 px-3 py-2 text-sm dark:border-gray-700!">
                            <span>{{ collection.title }} <span class="text-gray-500">· {{ __(':count published', { count: collection.entries }) }}</span></span>
                            <span class="text-xs text-gray-500">
                                <span v-if="collection.write_for">{{ __('Writes here') }}</span><span v-else>{{ __('Not writing here') }}</span>
                                · <span v-if="collection.voice">{{ __('Read for the voice') }}</span><span v-else>{{ __('Not read for the voice') }}</span>
                            </span>
                        </div>
                        <p v-if="!current.details.can_change_settings" class="text-sm text-gray-500">{{ __('Someone who can edit the addon’s settings chooses these.') }}</p>
                    </div>

                    <!-- Steps 3 and 5: the guides -->
                    <div v-if="step.key === 'voice' || step.key === 'imagery'" class="mt-4">
                        <div v-if="current.details[step.key].exists" class="gw-prose rounded-md border border-gray-200 p-4 text-sm dark:border-gray-700!" v-html="current.details[step.key].excerpt" />
                        <p v-if="current.details[step.key].scanned" class="mt-2 text-xs text-gray-500">{{ __('Written from :count samples.', { count: current.details[step.key].scanned }) }}</p>
                    </div>

                    <!-- Step 4: kinds, with the suggestions inline -->
                    <div v-if="step.key === 'kinds'" class="mt-4 space-y-4">
                        <div v-for="collection in kinds" :key="collection.handle" class="rounded-md border border-gray-200 p-3 dark:border-gray-700!">
                            <div class="flex items-center justify-between">
                                <Heading :text="collection.title" />
                                <div class="flex gap-2">
                                    <Button v-if="collection.suggestions.length > 1" size="sm" variant="ghost" :text="__('Learn all :count', { count: collection.suggestions.length })" :disabled="!configured || collection.learning.status === 'working' || !!busy" @click="post(collection.learn_all_url)" />
                                    <Button size="sm" variant="ghost" :text="__('Suggest kinds')" :disabled="!configured || collection.state === 'working' || !!busy" :loading="busy === collection.suggest_url" @click="post(collection.suggest_url)" />
                                </div>
                            </div>
                            <Alert v-if="collection.error" variant="error" :text="collection.error" class="mt-2" />
                            <p v-if="collection.state === 'working'" class="mt-2 text-sm text-gray-500"><span class="animate-pulse">{{ __('Looking over the entries…') }}</span></p>
                            <p v-else-if="collection.learning.status === 'working'" class="mt-2 text-sm text-gray-500"><span class="animate-pulse">{{ __('Learning… about a minute per kind.') }}</span></p>
                            <div v-if="collection.types.length" class="mt-2 flex flex-wrap gap-1.5">
                                <a v-for="type in collection.types" :key="type.url" :href="type.url" class="rounded-md border border-gray-200 px-2 py-0.5 text-xs hover:border-gray-400! dark:border-gray-700!">{{ type.title }}</a>
                            </div>
                            <div v-for="suggestion in collection.suggestions" :key="suggestion.id" class="mt-2 flex items-start gap-3 rounded-md bg-gray-50 px-3 py-2 dark:bg-gray-800!">
                                <div class="min-w-0 flex-1">
                                    <div class="font-medium">{{ suggestion.title }}</div>
                                    <p class="text-sm">{{ suggestion.description }}</p>
                                    <p class="text-xs text-gray-500">{{ suggestion.why }}</p>
                                </div>
                                <Button size="sm" :text="__('Learn this')" :disabled="!configured || collection.learning.status === 'working' || !!busy" :loading="busy === suggestion.learn_url" @click="post(suggestion.learn_url)" />
                                <Button size="sm" variant="ghost" :text="__('Not this')" :disabled="!!busy" @click="post(suggestion.dismiss_url)" />
                            </div>
                        </div>
                    </div>

                    <!-- Step 6: the plan -->
                    <div v-if="step.key === 'plan'" class="mt-4 space-y-2">
                        <Alert v-if="current.details.plan.error" variant="error" :text="current.details.plan.error" />
                        <p class="text-sm text-gray-500">{{ __(':ideas ideas on the plan, :pending suggestions waiting to be looked over.', { ideas: current.details.plan.ideas, pending: current.details.plan.pending }) }}</p>
                        <Textarea v-if="!step.done" v-model="steer" :rows="2" :placeholder="__('Optional: anything to steer it. “More for agencies.”')" />
                    </div>

                    <!-- Step 7: write -->
                    <div v-if="step.key === 'write' && step.action" class="mt-4 flex flex-wrap gap-2">
                        <Button v-for="option in step.action.options" :key="option.url" :href="option.url" :icon="ghost" :text="option.label" />
                    </div>

                    <div class="mt-6 flex items-center justify-between border-t border-gray-200 pt-4 dark:border-gray-700!">
                        <Button variant="ghost" :text="__('Back')" :disabled="index === 0" @click="back" />
                        <div class="flex gap-2">
                            <Button
                                v-if="step.action && step.action.type !== 'write'"
                                :variant="step.done ? 'default' : 'primary'"
                                :text="step.action.label"
                                :disabled="(step.action.needs_key && !configured) || step.working || !!busy"
                                :loading="step.working || busy === step.action.url"
                                @click="step.key === 'plan' && step.action.type === 'post' ? post(step.action.url, { steer }) : act(step.action)"
                            />
                            <Button v-if="index < current.steps.length - 1" :variant="step.done ? 'primary' : 'default'" :text="step.done ? __('Next') : __('Skip')" @click="next" />
                            <Button v-else :href="urls.index" variant="primary" :text="__('Finish')" />
                        </div>
                    </div>
                </div>
            </Panel>
        </div>
    </div>
</template>
