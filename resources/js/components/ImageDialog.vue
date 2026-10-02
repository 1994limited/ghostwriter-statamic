<!--
    The dialog behind the Ghostwriter button on an assets field: find a
    photograph, or have a picture made, for that one field. Photographs are
    ranked by the words around the field and, where the site's other entries
    have pictures in the same place, by those too; a made picture follows
    them. The chosen image goes straight into the field.
-->
<script>
import ghost from '../icon.js';
import { Alert, Button, Input, Modal, Subheading, Textarea } from '@statamic/cms/ui';

export default {
    components: { Alert, Button, Input, Modal, Subheading, Textarea },

    props: {
        baseUrl: { type: String, required: true },
        collection: { type: String, required: true },
        blueprint: { type: String, default: null },
        entry: { type: String, default: null },
        // The publish container, for the words around the field.
        form: { type: Object, required: true },
    },

    data() {
        return {
            open: false,
            tools: null,
            // The field action's context: path, set, update(), updateMeta(), value.
            field: null,
            tab: 'find',
            words: '',
            direction: '',
            source: null,
            request: null,
            busy: false,
            more: false,
            // Thumbnails that would not load, left out of the grid.
            broken: {},
            timer: null,
        };
    },

    computed: {
        ghost: () => ghost,

        working() {
            return this.request?.status === 'working';
        },

        shown() {
            const options = this.request?.options ?? [];

            return this.more ? options : options.slice(0, 3);
        },

        // Whether a model compared these with the page (and any images
        // already in this place), or they are just the top result of each search.
        judged() {
            return Boolean(this.request?.judged);
        },

        tabs() {
            return [
                this.tools?.find && { key: 'find', label: this.__('Find a photo') },
                this.tools?.make && { key: 'make', label: this.__('Make one') },
            ].filter(Boolean);
        },
    },

    created() {
        // The field action on every assets field raises this with its context.
        Statamic.$events.$on('ghostwriter.image', this.launch);
    },

    beforeUnmount() {
        Statamic.$events.$off('ghostwriter.image', this.launch);
        clearTimeout(this.timer);
    },

    methods: {
        async launch(field) {
            this.field = field;
            this.request = null;
            this.words = '';
            this.direction = '';
            this.source = null;
            this.more = false;
            this.open = true;

            if (!this.tools) {
                try {
                    this.tools = (await this.$axios.get(`${this.baseUrl}/images/tools`)).data;
                } catch (error) {
                    this.tools = { find: false, make: false };
                }
            }

            if (!this.tabs.find((tab) => tab.key === this.tab)) this.tab = this.tabs[0]?.key ?? 'find';
        },

        // What the form says around the field: the block it sits in, and the page.
        slot() {
            const values = this.form.values ?? {};
            const steps = this.field.path.split('.');
            const block = steps.length > 2 ? steps.slice(0, -1).reduce((node, step) => node?.[step], values) : null;

            return {
                collection: this.collection,
                blueprint: this.blueprint,
                path: this.field.path,
                set: block?.type ?? null,
                entry: this.entry,
                title: String(values.title ?? ''),
                block_text: block ? this.text(block).slice(0, 6000) : '',
                page_text: this.text(values).slice(0, 6000),
            };
        },

        // The words in a value, however it is nested: strings, and the text in Bard nodes.
        text(value) {
            if (typeof value === 'string') return /^(entry::|asset::|https?:|#)/.test(value) || value.length < 3 ? '' : value;
            if (Array.isArray(value)) return value.map((item) => this.text(item)).filter(Boolean).join('\n');
            if (value && typeof value === 'object') {
                if (value.type === 'text' && typeof value.text === 'string') return value.text;

                return Object.entries(value)
                    .filter(([key]) => !['id', 'type', 'enabled', 'attrs', 'marks'].includes(key))
                    .map(([, item]) => this.text(item))
                    .filter(Boolean)
                    .join('\n');
            }

            return '';
        },

        async start(mode) {
            const form = new FormData();

            Object.entries(this.slot()).forEach(([key, value]) => value !== null && form.append(key, value));
            form.append('mode', mode);

            if (mode === 'find') form.append('words', this.words);
            if (mode === 'make') {
                form.append('direction', this.direction);
                if (this.source) form.append('source', this.source);
            }

            this.busy = true;
            this.more = false;

            try {
                this.receive((await this.$axios.post(`${this.baseUrl}/images`, form)).data);
            } catch (error) {
                this.fail(error);
            } finally {
                this.busy = false;
            }
        },

        receive(data) {
            this.request = data;

            clearTimeout(this.timer);

            if (data.status === 'working') {
                this.timer = setTimeout(async () => {
                    try {
                        this.receive((await this.$axios.get(data.status_url)).data);
                    } catch (error) {
                        this.fail(error);
                    }
                }, 2500);
            }
        },

        async use(photo = null) {
            this.busy = true;

            try {
                const { data } = await this.$axios.post(this.request.use_url, {
                    source: photo?.source,
                    photo: photo?.id,
                    term: photo?.term,
                    current: this.current(),
                });

                this.place(data);
            } catch (error) {
                this.fail(error);
            } finally {
                this.busy = false;
            }
        },

        current() {
            const value = this.field.value;

            return Array.isArray(value) ? value : value ? [value] : [];
        },

        // Into the field: meta first, so the field can show the asset the
        // moment its value arrives.
        // A field already holding as many images as it takes is left alone;
        // the image is in the container, and the person is told so.
        place({ value, meta, full, message }) {
            if (full) {
                this.open = false;
                this.$toast.error(message, { duration: 10000 });

                return;
            }

            this.field.updateMeta({ ...(this.field.meta ?? {}), ...meta });
            this.field.update(value);
            this.open = false;
            this.$toast.success(this.__('Image added. Save to keep it.'));
        },

        fail(error) {
            const first = Object.values(error.response?.data?.errors ?? {})[0]?.[0];

            this.$toast.error(first ?? error.response?.data?.message ?? this.__('Something went wrong.'));
        },
    },
};
</script>

<template>
    <Modal v-model:open="open" class="max-w-3xl!" :title="field ? __('Image for :label', { label: field.label }) : __('Ghostwriter')" :icon="ghost">
        <div v-if="field" class="p-1">
            <div v-if="tabs.length > 1" class="mb-4 flex gap-1 border-b border-gray-200 dark:border-gray-700!">
                <button
                    v-for="item in tabs"
                    :key="item.key"
                    type="button"
                    class="-mb-px border-b-2 px-3 py-2 text-sm"
                    :class="tab === item.key ? 'border-gray-900 font-medium dark:border-white!' : 'border-transparent text-gray-500'"
                    @click="(tab = item.key), (request = null)"
                >{{ item.label }}</button>
            </div>

            <Alert v-if="tools && !tabs.length" variant="warning" :text="__('No image tools are available. Add OPENAI_API_KEY or GEMINI_API_KEY to your .env file to make pictures, or add a photo library key (or turn Openverse back on) to find photographs.')" />

            <!-- Find a photograph -->
            <div v-if="tab === 'find' && tools?.find" class="space-y-3">
                <Subheading :text="tools.suggests ? __('Leave the words blank and Ghostwriter chooses what to search for from the page. The photos that best suit the page’s words, and the images already in this place on other entries when there are any, come first.') : __('Type what to search for. Separate several searches with semicolons.')" />
                <div class="flex gap-2">
                    <Input v-model="words" class="flex-1" :placeholder="__('e.g. mended pottery gold; restored classic car')" :disabled="working" @keydown.enter.stop.prevent="start('find')" />
                    <Button :text="working ? __('Looking…') : __('Find photos')" variant="primary" :loading="working || busy" :disabled="working || busy" @click="start('find')" />
                </div>
                <Alert v-if="request?.status === 'failed'" variant="error" :text="request.error" />
                <p v-if="working" class="text-sm text-gray-500" role="status"><span class="animate-pulse">{{ __('Choosing searches, running them, and comparing the results with the page and any images already in this place…') }}</span></p>
                <Alert v-if="working && request.waiting" variant="warning" :text="request.waiting" role="status" />
                <template v-if="request?.status === 'done' && request.mode === 'find'">
                    <p class="text-sm text-gray-500">{{ __('Searched for: :terms. Choose one to use it.', { terms: request.terms.join('; ') }) }}</p>
                    <p v-if="!request.options.length" class="text-sm">{{ __('Nothing found. Try other words.') }}</p>
                    <p v-else-if="request.none_fit" class="text-sm text-gray-500">{{ __('None of these fitted the page, even after a second round of searches. These are the top results; try other words for a better match.') }}</p>
                    <p v-else-if="judged && !request.with_references" class="text-sm text-gray-500">{{ __('Compared with the page; there are no other images here to match.') }}</p>
                    <p v-else-if="!judged" class="text-sm text-gray-500">{{ __('These are the top results of each search. They weren’t compared with the page.') }}</p>
                    <div class="grid grid-cols-3 items-start gap-3">
                        <div
                            v-for="photo in shown"
                            v-show="!broken[photo.source + photo.id]"
                            :key="photo.source + photo.id"
                            class="relative flex flex-col overflow-hidden rounded-md border border-gray-200 hover:border-gray-500! dark:border-gray-700!"
                        >
                            <button type="button" class="block text-start disabled:opacity-50!" :disabled="busy" :title="[photo.reason || photo.alt, `${photo.credit} · ${photo.licence}`].filter(Boolean).join('\n')" @click="use(photo)">
                                <span v-if="photo.picked" class="absolute top-1.5 left-1.5 rounded bg-white/90 px-1.5 py-0.5 text-[11px] font-medium" style="color: var(--gw-ink, #2b3a64)">{{ __('Best match') }}</span>
                                <img :src="photo.thumb" :alt="photo.alt || ''" loading="lazy" class="block aspect-[4/3] w-full object-cover" @error="broken[photo.source + photo.id] = true" />
                                <span class="block truncate px-1.5 pt-1 text-xs font-medium">“{{ photo.term }}”</span>
                            </button>
                            <a v-if="/^https?:\/\//i.test(photo.credit_url ?? '')" :href="photo.credit_url" target="_blank" rel="noopener noreferrer" class="block truncate px-1.5 text-xs text-gray-500 hover:underline!" :title="__('See it on :source', { source: photo.credit })">{{ photo.credit }} · {{ photo.licence }}</a>
                            <span v-else class="block truncate px-1.5 text-xs text-gray-500">{{ photo.credit }} · {{ photo.licence }}</span>
                            <div class="px-1.5 py-1.5">
                                <Button size="xs" :text="__('Use this')" :disabled="busy" @click="use(photo)" />
                            </div>
                        </div>
                    </div>
                    <Button v-if="request.options.length > 3" size="sm" variant="ghost" :text="more ? __('Show the best three') : __('View :count more', { count: request.options.length - 3 })" @click="more = !more" />
                </template>
            </div>

            <!-- Make a picture -->
            <div v-if="tab === 'make' && tools?.make" class="space-y-3">
                <Subheading :text="__('Made in the style of the images already in this place on your other entries. Add an image of your own, such as a logo or a product shot, for it to be built around.')" />
                <Textarea v-model="direction" :rows="2" :placeholder="__('What should it show? Leave blank to go by the page.')" :disabled="working" />
                <label class="block text-sm text-gray-500">
                    {{ __('Use my own image in it') }}:
                    <input type="file" accept="image/png,image/jpeg,image/webp" class="ms-1 text-sm" :disabled="working" @change="source = $event.target.files[0] ?? null" />
                </label>
                <Alert v-if="request?.status === 'failed'" variant="error" :text="request.error" />
                <p v-if="working" class="text-sm text-gray-500" role="status"><span class="animate-pulse">{{ __('Making the picture. This can take a minute or two.') }}</span></p>
                <Alert v-if="working && request.waiting" variant="warning" :text="request.waiting" role="status" />
                <div v-if="request?.status === 'done' && request.mode === 'make'" class="space-y-2">
                    <img :src="request.preview_url" alt="" class="block h-auto w-full rounded-md border border-gray-200 dark:border-gray-700!" />
                    <div class="flex justify-end gap-2">
                        <Button :text="__('Make another')" :disabled="busy" @click="start('make')" />
                        <Button variant="primary" :text="__('Use this')" :loading="busy" :disabled="busy" @click="use()" />
                    </div>
                </div>
                <div v-else class="flex justify-end">
                    <Button variant="primary" :text="working ? __('Making the image…') : __('Make the picture')" :loading="working || busy" :disabled="working || busy" @click="start('make')" />
                </div>
            </div>

        </div>
    </Modal>
</template>
