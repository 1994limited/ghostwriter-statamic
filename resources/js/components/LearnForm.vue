<!--
    Teaches Ghostwriter a kind of content it will be asked for often, so that
    kind gets its own brief. With nothing ticked it studies the collection's
    newest entries; tick the ones this kind should be modelled on.
-->
<script>
import { Button, Field, Input } from '@statamic/cms/ui';
import ExamplePicker from './ExamplePicker.vue';

export default {
    components: { Button, ExamplePicker, Field, Input },

    props: {
        entries: { type: Array, required: true },
        disabled: { type: Boolean, default: false },
        loading: { type: Boolean, default: false },
    },

    emits: ['learn'],

    data() {
        return { title: '', picked: [] };
    },
};
</script>

<template>
    <div class="space-y-5 text-start">
        <Field :label="__('What is this kind of content called?')" :instructions="__('For example “Case study” or “Service page”. Leave blank and Ghostwriter will name it.')">
            <Input v-model="title" :disabled="disabled" />
        </Field>

        <Field
            v-if="entries.length"
            :label="__('Model it on')"
            :instructions="__('Tick up to six entries that are good examples. Leave them all unticked to use the newest published entries.')"
        >
            <ExamplePicker v-model="picked" :entries="entries" :disabled="disabled" />
        </Field>

        <Button
            variant="primary"
            :text="loading ? __('Reading the entries…') : __('Learn this')"
            :disabled="disabled || loading"
            :loading="loading"
            @click="$emit('learn', { title: title.trim() || null, examples: picked })"
        />
    </div>
</template>
