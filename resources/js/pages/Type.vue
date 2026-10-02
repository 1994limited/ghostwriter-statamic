<!--
    Edits one content type on an ordinary publish form: its name, the brief's
    questions, the guidance given to the writer and the entries it is
    modelled on.
-->
<script>
import ghost from '../icon.js';
import { Head, router } from '@statamic/cms/inertia';
import { Badge, Button, Header, PublishContainer } from '@statamic/cms/ui';

export default {
    computed: {
        ghost: () => ghost,
    },

    components: { Badge, Button, Head, Header, PublishContainer },

    props: {
        type: { type: Object, required: true },
        blueprint: { type: Object, required: true },
        values: { type: Object, required: true },
        meta: { type: Object, required: true },
        urls: { type: Object, required: true },
    },

    data() {
        return { current: this.values, errors: {}, saving: false };
    },

    methods: {
        async save() {
            this.saving = true;
            this.errors = {};

            try {
                await this.$axios.patch(this.urls.update, this.current);
                this.$toast.success(this.__('Saved'));
            } catch (error) {
                this.errors = error.response?.data?.errors ?? {};
                this.$toast.error(error.response?.data?.message ?? this.__('Could not save.'));
            } finally {
                this.saving = false;
            }
        },

        async remove() {
            if (!confirm(this.__('Delete this kind of content? Entries already written are not affected.'))) return;

            try {
                await this.$axios.delete(this.urls.destroy);
            } catch (error) {
                this.$toast.error(error.response?.data?.message ?? this.__('Could not delete it.'));

                return;
            }

            this.$toast.success(this.__('Kind deleted'));
            router.visit(this.urls.index);
        },
    },
};
</script>

<template>
    <Head :title="type.title" />

    <div class="mx-auto max-w-4xl">
        <Header :title="type.title" :icon="ghost">
            <Badge :text="type.collection" />
            <Button :href="urls.index" :text="__('Back')" variant="ghost" />
            <Button :text="__('Delete')" variant="ghost" @click="remove" />
            <Button :text="__('Save')" variant="primary" :disabled="saving" :loading="saving" @click="save" />
        </Header>

        <PublishContainer name="ghostwriter-type" :blueprint="blueprint" v-model="current" :meta="meta" :errors="errors" :track-dirty-state="false" />
    </div>
</template>
