<!--
    The tone of voice guide: generate it from published content, edit it by
    hand, or refine it by asking for changes. Generating and refining run in
    the background, so this screen polls the status endpoint until they finish.
-->
<script>
import { Head } from '@statamic/cms/inertia';
import { Alert, Button, Checkbox, Header, Panel, PublishContainer, Subheading, Textarea } from '@statamic/cms/ui';
import SetupAlert from '../components/SetupAlert.vue';

export default {
    components: { Alert, Button, Checkbox, Head, Header, Panel, PublishContainer, SetupAlert, Subheading, Textarea },

    props: {
        blueprint: { type: Object, required: true },
        meta: { type: Object, required: true },
        configured: { type: Boolean, required: true },
        provider: { type: String, required: true },
        collections: { type: Array, required: true },
        state: { type: Object, required: true },
        urls: { type: Object, required: true },
        // The image style guide uses this screen too, with its own wording.
        labels: { type: Object, default: () => ({}) },
    },

    data() {
        return {
            current: this.state,
            // The markdown field lives in a publish container, which works
            // on a values object keyed by field handle.
            values: { document: this.state.document },
            selected: this.collections.filter((c) => c.selected && c.entries > 0).map((c) => c.handle),
            message: '',
            saving: false,
            timer: null,
        };
    },

    computed: {
        working() {
            return this.current.status === 'working';
        },

        text() {
            return {
                title: 'Voice guide',
                saved: 'Voice guide saved',
                scanning: 'Reading your published content and writing the guide. This takes a minute or so.',
                empty: 'No guide yet. Choose which collections to read, then generate it.',
                read: 'Ghostwriter reads the newest published entries from each collection you tick.',
                generate: 'Generate from your content',
                regenerate: 'Read the site again',
                confirm: 'Read the site again and replace the current guide? Any edits you have made to it will be lost.',
                scanned: 'Last written from :count entries.',
                ...this.labels,
            };
        },

        document: {
            get() {
                return this.values.document ?? '';
            },
            set(value) {
                this.values = { ...this.values, document: value };
            },
        },

        dirty() {
            return this.document.trim() !== (this.current.document ?? '').trim();
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
            const edited = this.dirty;

            this.current = data;

            // A finished scan or refinement replaces the text; an unsaved
            // hand edit is only overwritten when the guide itself changed.
            if (!edited || data.status !== 'working') this.document = data.document;

            if (data.status === 'working') this.poll();
        },

        poll() {
            clearTimeout(this.timer);

            this.timer = setTimeout(async () => {
                try {
                    const { data } = await this.$axios.get(this.urls.status);
                    this.apply(data);
                } catch (error) {
                    this.poll();
                }
            }, 2500);
        },

        async scan() {
            if (this.current.exists && !confirm(this.__(this.text.confirm))) {
                return;
            }

            await this.send(this.urls.scan, { collections: this.selected });
        },

        async refine() {
            if (!this.message.trim()) return;

            const message = this.message;
            this.message = '';

            await this.send(this.urls.refine, { message });
        },

        async send(url, payload) {
            try {
                const { data } = await this.$axios.post(url, payload);
                this.apply(data);
            } catch (error) {
                this.$toast.error(error.response?.data?.message ?? this.__('Something went wrong.'));
            }
        },

        async save() {
            this.saving = true;

            try {
                const { data } = await this.$axios.patch(this.urls.update, { document: this.document });
                this.current = data;
                this.document = data.document;
                this.$toast.success(this.__(this.text.saved));
            } catch (error) {
                this.$toast.error(error.response?.data?.message ?? this.__('Could not save the guide.'));
            } finally {
                this.saving = false;
            }
        },
    },
};
</script>

<template>
    <Head :title="__(text.title)" />

    <div class="mx-auto max-w-6xl">
        <Header :title="__(text.title)" icon="text-formatting-quotation">
            <Button :href="urls.index" :text="__('Back')" variant="ghost" />
            <Button :text="__('Save')" variant="primary" :disabled="!dirty || saving || working" :loading="saving" @click="save" />
        </Header>

        <SetupAlert v-if="!configured" :provider="provider" />

        <Alert v-if="current.status === 'failed'" variant="error" :heading="__('That did not work')" :text="current.error" class="mb-6" />

        <div class="grid gap-6 lg:grid-cols-3">
            <div class="lg:col-span-2">
                <Panel :heading="__('The guide')" :subheading="current.updated_at ? __('Updated :when', { when: current.updated_at }) : null">
                    <div class="p-4">
                        <div v-if="working && current.task === 'scan'" class="py-16 text-center text-gray-500">
                            {{ __(text.scanning) }}
                        </div>
                        <div v-else-if="!current.exists" class="py-16 text-center text-gray-500">
                            {{ __(text.empty) }}
                        </div>
                        <PublishContainer
                            v-else
                            name="ghostwriter-voice"
                            :blueprint="blueprint"
                            v-model="values"
                            :meta="meta"
                            :read-only="working"
                            :track-dirty-state="false"
                        />
                    </div>
                </Panel>
            </div>

            <div class="space-y-6">
                <Panel :heading="current.exists ? __(text.regenerate) : __(text.generate)">
                    <div class="space-y-3 p-4">
                        <Subheading :text="__(text.read)" />
                        <div v-for="collection in collections" :key="collection.handle">
                            <Checkbox
                                :model-value="selected.includes(collection.handle)"
                                :label="`${collection.title} (${collection.entries})`"
                                :disabled="collection.entries === 0 || working"
                                @update:model-value="(on) => (selected = on ? [...selected, collection.handle] : selected.filter((h) => h !== collection.handle))"
                            />
                        </div>
                        <Button
                            class="w-full"
                            :variant="current.exists ? 'default' : 'primary'"
                            :text="current.exists ? __('Rescan and rewrite') : __('Generate the guide')"
                            :disabled="!configured || working || selected.length === 0"
                            :loading="working && current.task === 'scan'"
                            @click="scan"
                        />
                        <p v-if="current.scanned.length" class="text-sm text-gray-500">
                            {{ __(text.scanned, { count: current.scanned.length }) }}
                        </p>
                    </div>
                </Panel>

                <Panel v-if="current.exists && urls.refine" :heading="__('Ask for a change')">
                    <div class="space-y-3 p-4">
                        <div v-if="current.messages.length" class="max-h-72 space-y-2 overflow-y-auto">
                            <div
                                v-for="(entry, index) in current.messages"
                                :key="index"
                                class="rounded-lg px-3 py-2 text-sm whitespace-pre-wrap"
                                :class="entry.role === 'user' ? 'ms-6 bg-gray-100 dark:bg-gray-800' : 'me-6 border border-gray-200 dark:border-gray-700'"
                            >{{ entry.content }}</div>
                        </div>
                        <Textarea v-model="message" :rows="3" :disabled="working" :placeholder="__('e.g. We never say “solutions”. Add that.')" />
                        <Button
                            class="w-full"
                            :text="__('Update the guide')"
                            :disabled="!configured || working || !message.trim() || dirty"
                            :loading="working && current.task === 'refine'"
                            @click="refine"
                        />
                        <p v-if="dirty" class="text-sm text-gray-500">{{ __('Save your edits first.') }}</p>
                    </div>
                </Panel>
            </div>
        </div>
    </div>
</template>
