<!--
    The dialog behind the Ghostwriter button on an assets field: find a
    photograph, have a picture made, or compose a logo card, for that one
    field, matched to the pictures already in the same place on the site's
    other entries. The chosen image goes straight into the field.
-->
<script>
import ghost from '../icon.js';
import { Alert, Button, Checkbox, Input, Modal, Subheading, Textarea } from '@statamic/cms/ui';

export default {
    components: { Alert, Button, Checkbox, Input, Modal, Subheading, Textarea },

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
            logo: null,
            colour: '',
            colourTo: '',
            white: true,
            request: null,
            busy: false,
            more: false,
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

        tabs() {
            return [
                this.tools?.find && { key: 'find', label: this.__('Find a photo') },
                this.tools?.make && { key: 'make', label: this.__('Make one') },
                this.tools?.logo_card && { key: 'logo', label: this.__('Logo card') },
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
            this.logo = null;
            this.more = false;
            this.open = true;

            if (!this.tools) {
                try {
                    this.tools = (await this.$axios.get(`${this.baseUrl}/images/tools`)).data;
                } catch (error) {
                    this.tools = { find: false, make: false, logo_card: false };
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

        async makeLogo() {
            if (!this.logo) return;

            const form = new FormData();

            Object.entries(this.slot()).forEach(([key, value]) => value !== null && form.append(key, value));
            form.append('logo', this.logo);
            form.append('colour', this.colour);
            form.append('colour_to', this.colourTo);
            form.append('white', this.white ? '1' : '0');
            this.current().forEach((id) => form.append('current[]', id));

            this.busy = true;

            try {
                this.place((await this.$axios.post(`${this.baseUrl}/images/logo`, form)).data);
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
        place({ value, meta, asset, full, message }) {
            if (full) {
                this.open = false;
                this.$toast.error(message, { duration: 10000 });

                return;
            }

            this.field.updateMeta({ ...(this.field.meta ?? {}), ...meta });
            this.field.update(value);
            this.open = false;
            this.$toast.success(this.__('Added to the field: :title', { title: asset.title || asset.path }));
        },

        fail(error) {
            const first = Object.values(error.response?.data?.errors ?? {})[0]?.[0];

            this.$toast.error(first ?? error.response?.data?.message ?? this.__('Something went wrong.'));
        },
    },
};
</script>

<template>
    <Modal v-model:open="open" :title="field ? __('An image for :field', { field: field.label }) : __('Ghostwriter')" :icon="ghost">
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

            <Alert v-if="!tabs.length" variant="warning" :text="__('No image tools are switched on. Add OPENAI_API_KEY or GEMINI_API_KEY to make pictures, or turn on Openverse in the settings to find photographs.')" />

            <!-- Find a photograph -->
            <div v-if="tab === 'find' && tools?.find" class="space-y-3">
                <Subheading :text="tools.suggests ? __('Leave the words blank and Ghostwriter chooses what to search for from the page. The best matches against the images already in this place come first.') : __('Type what to search for. Separate several searches with semicolons.')" />
                <div class="flex gap-2">
                    <Input v-model="words" class="flex-1" :placeholder="__('e.g. mended pottery gold; restored classic car')" :disabled="working" @keydown.enter.stop.prevent="start('find')" />
                    <Button :text="working ? __('Looking…') : __('Find photos')" variant="primary" :loading="working || busy" :disabled="working || busy" @click="start('find')" />
                </div>
                <Alert v-if="request?.status === 'failed'" variant="error" :text="request.error" />
                <p v-if="working" class="text-sm text-gray-500"><span class="animate-pulse">{{ __('Choosing searches, running them, and comparing the results with the images already here…') }}</span></p>
                <template v-if="request?.status === 'done' && request.mode === 'find'">
                    <p class="text-sm text-gray-500">{{ __('Searched for: :terms. Click one to use it.', { terms: request.terms.join('; ') }) }}</p>
                    <p v-if="!request.options.length" class="text-sm">{{ __('Nothing found. Try other words.') }}</p>
                    <div class="grid grid-cols-2 items-start gap-3 md:grid-cols-3">
                        <button
                            v-for="photo in shown"
                            :key="photo.source + photo.id"
                            type="button"
                            class="relative overflow-hidden rounded-md border border-gray-200 text-start hover:border-gray-500! disabled:opacity-50! dark:border-gray-700!"
                            :disabled="busy"
                            :title="`${photo.credit} · ${photo.licence}`"
                            @click="use(photo)"
                        >
                            <span v-if="photo.picked" class="absolute top-1.5 left-1.5 rounded bg-white/90 px-1.5 py-0.5 text-[11px] font-medium" style="color: var(--gw-ink, #2b3a64)">{{ __('Best match') }}</span>
                            <img :src="photo.thumb" alt="" loading="lazy" class="block h-auto w-full" />
                            <span class="block truncate px-1.5 pt-1 text-xs font-medium">“{{ photo.term }}”</span>
                            <span class="block truncate px-1.5 py-1 text-xs text-gray-500">{{ photo.credit }}</span>
                        </button>
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
                <div v-if="request?.status === 'done' && request.mode === 'make'" class="space-y-2">
                    <img :src="request.preview_url" alt="" class="block h-auto w-full rounded-md border border-gray-200 dark:border-gray-700!" />
                    <div class="flex justify-end gap-2">
                        <Button :text="__('Make another')" :disabled="busy" @click="start('make')" />
                        <Button variant="primary" :text="__('Use this')" :loading="busy" :disabled="busy" @click="use()" />
                    </div>
                </div>
                <div v-else class="flex justify-end">
                    <Button variant="primary" :text="working ? __('Making the image…') : __('Make image')" :loading="working || busy" :disabled="working || busy" @click="start('make')" />
                </div>
            </div>

            <!-- A logo on a coloured ground -->
            <div v-if="tab === 'logo' && tools?.logo_card" class="space-y-3">
                <Subheading :text="__('A logo centred on a flat or gradient colour, drawn in code so it comes out exactly as it went in, at the size the images here already are.')" />
                <label class="block text-sm">
                    {{ __('Logo (SVG or transparent PNG)') }}:
                    <input type="file" accept="image/svg+xml,image/png,image/webp" class="ms-1 text-sm" @change="logo = $event.target.files[0] ?? null" />
                </label>
                <div class="grid gap-3 md:grid-cols-2">
                    <Input v-model="colour" :placeholder="__('Background, e.g. #ff2d20. Blank uses the logo’s own colour.')" />
                    <Input v-model="colourTo" :placeholder="__('Second colour for a gradient (optional)')" />
                </div>
                <Checkbox v-model="white" :label="__('Turn the logo white')" />
                <div class="flex justify-end">
                    <Button variant="primary" :text="__('Make the card')" :loading="busy" :disabled="busy || !logo" @click="makeLogo" />
                </div>
            </div>
        </div>
    </Modal>
</template>
