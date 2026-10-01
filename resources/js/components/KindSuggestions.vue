<!--
    Kinds of content Ghostwriter thinks a collection holds, for a person to
    have taught ("Learn this") or turn down ("Not this"). Polls while the
    collection is being looked over or a kind is being learned.
-->
<script>
import { Alert, Button, Heading, Subheading } from '@statamic/cms/ui';

export default {
    components: { Alert, Button, Heading, Subheading },

    props: {
        // The collection row from the dashboard: kinds_url, suggest_url,
        // learn_all_url, suggested {status, error, suggestions}, state.
        collection: { type: Object, required: true },
        configured: { type: Boolean, required: true },
    },

    emits: ['learned'],

    data() {
        return { current: this.collection.suggested, learning: this.collection.state?.status === 'working', busy: null, timer: null };
    },

    computed: {
        looking() {
            return this.current.status === 'working';
        },
    },

    mounted() {
        if (this.looking || this.learning) this.poll();
    },

    beforeUnmount() {
        clearTimeout(this.timer);
    },

    methods: {
        poll() {
            clearTimeout(this.timer);

            this.timer = setTimeout(async () => {
                try {
                    const { data } = await this.$axios.get(this.collection.kinds_url);
                    this.apply(data);
                } catch (error) {
                    this.poll();
                }
            }, 2500);
        },

        apply(data) {
            const wasLearning = this.learning;

            this.current = { status: data.kinds.status, error: data.kinds.error, suggestions: data.kinds.suggestions };

            if (data.state) this.learning = data.state.status === 'working';

            if (this.looking || this.learning) {
                this.poll();
            } else if (wasLearning) {
                // A kind has been learned: the dashboard's list of kinds is stale.
                this.$emit('learned');
            }
        },

        async send(url) {
            this.busy = url;

            try {
                const { data } = await this.$axios.post(url);
                this.apply(data);
            } catch (error) {
                this.$toast.error(error.response?.data?.message ?? this.__('Something went wrong.'));
            } finally {
                this.busy = null;
            }
        },

        async learnAll() {
            if (!confirm(this.__('Learn all :count suggested kinds, one after another? This takes about a minute each.', { count: this.current.suggestions.length }))) return;

            await this.send(this.collection.learn_all_url);
        },
    },
};
</script>

<template>
    <div v-if="looking || learning || current.error || current.suggestions.length" class="mt-3">
        <Alert v-if="current.error" variant="error" :text="current.error" class="mb-2" />

        <p v-if="looking" class="text-sm text-gray-500">
            <span class="animate-pulse">{{ __('Looking over the entries for kinds of content…') }}</span>
        </p>
        <p v-else-if="learning" class="text-sm text-gray-500">
            <span class="animate-pulse">{{ __('Learning… about a minute per kind.') }}</span>
        </p>

        <template v-if="current.suggestions.length">
            <div class="mb-2 flex items-center justify-between">
                <Subheading :text="__(':count suggested kinds to review', { count: current.suggestions.length })" />
                <Button v-if="current.suggestions.length > 1" size="sm" variant="ghost" :text="__('Learn all :count', { count: current.suggestions.length })" :disabled="!configured || learning || !!busy" @click="learnAll" />
            </div>
            <div class="space-y-2">
                <div v-for="suggestion in current.suggestions" :key="suggestion.id" class="flex items-start gap-4 rounded-md border border-gray-200 px-3 py-2 dark:border-gray-700!">
                    <div class="min-w-0 flex-1">
                        <Heading :text="suggestion.title" />
                        <p class="text-sm">{{ suggestion.description }}</p>
                        <p class="mt-0.5 text-sm text-gray-500">{{ suggestion.why }}</p>
                        <p v-if="suggestion.titles.length" class="mt-0.5 truncate text-xs text-gray-500">{{ __('For example') }}: {{ suggestion.titles.join(' · ') }}</p>
                    </div>
                    <div class="flex shrink-0 gap-1">
                        <Button size="sm" :text="__('Learn this')" :disabled="!configured || learning || !!busy" :loading="busy === suggestion.learn_url" @click="send(suggestion.learn_url)" />
                        <Button size="sm" variant="ghost" :text="__('Not this')" :disabled="!!busy" @click="send(suggestion.dismiss_url)" />
                    </div>
                </div>
            </div>
        </template>
    </div>
</template>
