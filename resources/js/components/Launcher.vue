<!--
    Sits on an entry's create or edit form. A button beside Save opens the Ghostwriter
    panel in a slide-over; when a draft is applied, its values are written into
    the form underneath, which the person then reviews and saves as usual.
-->
<script>
import ghost from '../icon.js';
import { Button, Stack } from '@statamic/cms/ui';
import Panel from './Panel.vue';

export default {
    components: { Button, Panel, Stack },

    props: {
        collection: { type: String, required: true },
        // The entry being edited; null on a create form.
        entry: { type: String, default: null },
        form: { type: Object, required: true },
        baseUrl: { type: String, required: true },
    },

    data() {
        const query = new URLSearchParams(window.location.search);
        const requested = query.get('ghostwriter');

        return {
            // The form's blueprint, on collections that have more than one.
            blueprint: query.get('blueprint'),
            // An idea from the content plan to start from.
            idea: query.get('idea'),
            open: requested !== null,
            starting: false,
            // "new" opens a fresh panel; anything else is a session to resume.
            resume: requested && requested !== 'new' ? requested : null,
            slot: null,
        };
    },

    mounted() {
        // The publish form owns its header, so the button is teleported into
        // a slot placed just before the save button. If the header cannot be
        // found the button floats in the corner instead.
        const save = document.querySelector('header[data-ui-header] [data-ui-button-group]')?.parentElement;

        if (save?.parentElement) {
            const slot = document.createElement('div');
            slot.dataset.ghostwriter = 'launcher';
            save.parentElement.insertBefore(slot, save);
            this.slot = slot;
        }
    },

    beforeUnmount() {
        this.slot?.remove();
    },

    computed: {
        ghost: () => ghost,

        label() {
            return this.entry ? this.__('Edit with Ghostwriter') : this.__('Write with Ghostwriter');
        },
    },

    methods: {
        // On an existing entry, the conversation starts from the entry as it
        // was last saved.
        async launch() {
            if (!this.entry) {
                this.open = true;

                return;
            }

            this.starting = true;

            try {
                const { data } = await this.$axios.post(`${this.baseUrl}/entries/${this.entry}/session`);

                this.resume = data.id;
                this.open = true;
            } catch (error) {
                this.$toast.error(error.response?.data?.message ?? this.__('Something went wrong.'));
            } finally {
                this.starting = false;
            }
        },

        apply({ values, meta, notes }) {
            // Field by field, so everything the draft does not cover keeps the
            // value and meta it already had. Meta goes first: fields such as
            // Bard and Replicator read it as soon as their value arrives.
            Object.entries(meta).forEach(([handle, value]) => this.form.setFieldMeta(handle, value));
            Object.entries(values).forEach(([handle, value]) => this.form.setFieldValue(handle, value));

            this.open = false;

            this.$toast.success(this.entry ? this.__('Changes added to the form. Check them over, then save.') : this.__('Draft added to the form. Check it over, then save.'));

            notes.forEach((note) => this.$toast.info(note));
        },
    },
};
</script>

<template>
    <div>
        <Teleport v-if="slot" :to="slot">
            <Button :icon="ghost" :text="label" :loading="starting" @click="launch" />
        </Teleport>

        <div v-else class="fixed end-6 bottom-6 z-10">
            <Button :icon="ghost" :text="label" :loading="starting" @click="launch" />
        </div>

        <Stack v-model:open="open" :title="__('Ghostwriter')" :icon="ghost" size="full">
            <Panel v-if="open" :collection="collection" :blueprint="blueprint" :base-url="baseUrl" :resume="resume" :idea="idea" :entry="entry" @apply="apply" />
        </Stack>
    </div>
</template>
