<!--
    The draft's image fields. For each, an image can be made (where the site
    has a provider that makes images), modelled on the ones the same field
    holds on other entries, or a free-to-use photograph can be searched for.
    Nothing is made or downloaded until asked for.
-->
<script>
import { Button, Heading, Input, Subheading } from '@statamic/cms/ui';

export default {
    components: { Button, Heading, Input, Subheading },

    props: {
        images: { type: Array, required: true },
        tools: { type: Object, required: true },
        search: { type: Function, required: true },
        choose: { type: Function, required: true },
        copy: { type: Function, required: true },
    },

    emits: ['make'],

    data() {
        return { directions: {}, sources: {}, found: {}, more: {}, pools: {}, searching: null, choosing: null };
    },

    methods: {
        // Photographs searched for here, or else the ones Ghostwriter
        // offered with the draft.
        offered(image) {
            const from = this.pool(image);

            if (this.found[from.key]) return this.found[from.key];

            return from.options?.length ? { query: from.query, photos: from.options } : null;
        },

        // The field whose photographs this one is choosing from: its own,
        // unless the set offered for another field was asked for.
        pool(image) {
            return this.images.find((other) => other.key === this.pools[image.key]) ?? image;
        },

        // Other fields with photographs to choose from.
        otherSets(image) {
            return this.images.filter((other) => other.key !== image.key && (this.found[other.key]?.photos.length || other.options?.length));
        },

        // Other fields that already have an image this one could share.
        filled(image) {
            return this.images.filter((other) => other.key !== image.key && other.status === 'done' && other.url && other.url !== image.url);
        },

        // The best three, until more are asked for.
        shown(image) {
            const photos = this.offered(image).photos;

            return this.more[image.key] ? photos : photos.slice(0, 3);
        },

        direction(image) {
            return this.directions[image.key] ?? image.direction ?? '';
        },

        pick(image, event) {
            this.sources[image.key] = event.target.files[0] ?? null;
        },

        make(image) {
            this.$emit('make', { key: image.key, direction: this.direction(image), source: this.sources[image.key] ?? null });
        },

        async find(image) {
            this.searching = image.key;
            this.found[image.key] = await this.search(image.key, this.direction(image));
            this.more[image.key] = false;
            this.pools[image.key] = image.key;
            this.searching = null;
        },

        async use(image, photo) {
            this.choosing = photo.id;

            await this.choose(image.key, photo);

            this.choosing = null;
        },
    },
};
</script>

<template>
    <div class="border-t border-gray-200 pt-6 dark:border-gray-700!" data-gw-images>
        <Heading :text="__('Images')" />
        <Subheading
            class="mb-4"
            :text="
                tools.generate
                    ? __('Have one made to match the images already used in the same place on your other entries, or find a free photograph. They are added to the form with the draft.')
                    : __('Find a free photograph for each. They are added to the form with the draft.')
            "
        />

        <div v-for="image in images" :key="image.key" class="mb-4 rounded-lg border border-gray-200 p-3 dark:border-gray-700!">
            <div class="flex gap-4">
                <div class="flex min-h-28 w-44 shrink-0 items-center justify-center self-start overflow-hidden rounded-md border border-dashed border-gray-300 text-center text-xs text-gray-500 dark:border-gray-600!">
                    <img v-if="image.url && image.status !== 'working'" :src="image.url" :alt="image.label" class="block h-auto w-full" />
                    <span v-else-if="image.status === 'working'" class="animate-pulse">{{ __('Making the image…') }}</span>
                    <span v-else>{{ __('No image yet') }}</span>
                </div>

                <div class="min-w-0 flex-1 space-y-2">
                    <div class="font-medium">{{ image.label }}</div>

                    <Input
                        :model-value="direction(image)"
                        :disabled="image.status === 'working'"
                        :placeholder="tools.generate ? __('What should it show? Also used as the search.') : __('What to search for, e.g. “harbour at dusk”')"
                        @update:model-value="(value) => (directions[image.key] = value)"
                        @keydown.enter.stop.prevent="find(image)"
                    />

                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <label v-if="tools.generate" class="text-sm text-gray-500">
                            {{ __('Use my own image in it, such as a logo') }}:
                            <input type="file" accept="image/png,image/jpeg,image/webp" class="ms-1 text-sm" :disabled="image.status === 'working'" @change="pick(image, $event)" />
                        </label>
                        <span v-else />

                        <div class="flex gap-2">
                            <Button
                                v-if="tools.search.length"
                                size="sm"
                                :text="__('Find a photo')"
                                :loading="searching === image.key"
                                :disabled="image.status === 'working'"
                                @click="find(image)"
                            />
                            <Button
                                v-if="tools.generate"
                                size="sm"
                                :text="image.url ? __('Make another') : __('Make image')"
                                :loading="image.status === 'working'"
                                :disabled="image.status === 'working'"
                                @click="make(image)"
                            />
                        </div>
                    </div>

                    <div v-if="filled(image).length" class="flex flex-wrap items-center gap-2 text-sm text-gray-500">
                        {{ __('Use the same image as') }}
                        <Button v-for="other in filled(image)" :key="other.key" size="sm" variant="ghost" :text="other.label" @click="copy(image.key, other.key)" />
                    </div>

                    <p v-if="image.status === 'failed'" class="text-sm text-red-600">{{ image.error }}</p>
                    <p v-else-if="image.credit" class="text-sm text-gray-500">{{ __('Photo') }}: {{ image.credit }}</p>
                    <p v-else-if="tools.generate && !image.references" class="text-sm text-gray-500">{{ __('No other entry has an image here yet, so there is no style to match.') }}</p>
                </div>
            </div>

            <div v-if="offered(image) || otherSets(image).length" class="mt-3 border-t border-gray-200 pt-3 dark:border-gray-700!">
                <div v-if="otherSets(image).length" class="mb-2 flex flex-wrap items-center gap-2 text-sm text-gray-500">
                    {{ __('Choose from the photos for') }}
                    <Button size="sm" :variant="pool(image).key === image.key ? 'default' : 'ghost'" :text="__('This field')" @click="pools[image.key] = image.key" />
                    <Button
                        v-for="other in otherSets(image)"
                        :key="other.key"
                        size="sm"
                        :variant="pool(image).key === other.key ? 'default' : 'ghost'"
                        :text="other.label"
                        @click="pools[image.key] = other.key"
                    />
                </div>
            </div>
            <div v-if="offered(image)" class="mt-3">
                <p v-if="!offered(image).photos.length" class="text-sm text-gray-500">
                    {{ __('Nothing found for “:query”. Try other words.', { query: offered(image).query }) }}
                </p>
                <p v-else class="mb-2 text-sm text-gray-500">{{ __('Pick one to use it. Searched for: :query', { query: offered(image).query }) }}</p>
                <div v-if="offered(image).photos.length" class="grid grid-cols-3 items-start gap-3">
                    <div
                        v-for="photo in shown(image)"
                        :key="photo.source + photo.id"
                        class="flex flex-col overflow-hidden rounded-md border border-gray-200 hover:border-gray-500! dark:border-gray-700!"
                        :class="{ 'animate-pulse': choosing === photo.id }"
                    >
                        <button type="button" class="block text-start" :disabled="choosing !== null" :title="`${photo.credit} · ${photo.licence}`" @click="use(image, photo)">
                            <img :src="photo.thumb" alt="" loading="lazy" class="block aspect-[4/3] w-full object-cover" />
                            <span v-if="photo.term" class="block truncate px-1.5 pt-1 text-xs font-medium">“{{ photo.term }}”</span>
                            <span class="block truncate px-1.5 text-xs text-gray-500">{{ photo.credit }}</span>
                        </button>
                        <div class="px-1.5 py-1.5">
                            <Button size="xs" :text="__('Use this')" :disabled="choosing !== null" @click="use(image, photo)" />
                        </div>
                    </div>
                </div>
                <Button
                    v-if="offered(image).photos.length > 3"
                    class="mt-3"
                    size="sm"
                    variant="ghost"
                    :text="more[image.key] ? __('Show the best three') : __('View :count more', { count: offered(image).photos.length - 3 })"
                    @click="more[image.key] = !more[image.key]"
                />
            </div>
        </div>
    </div>
</template>
