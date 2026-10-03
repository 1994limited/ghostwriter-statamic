<!--
    The brief, in the conversation: what Ghostwriter filled in from the
    quick details (or a plan idea), for the person to check and change.

      proposed   every answer open to change, with "Looks right, start
                 writing" and "Try again"
      agreed     collapsed to "Show the brief"; opened, it stays editable
                 and is saved with "Save the brief"

    A labelled region, so a screen reader can find it and jump to it.
-->
<script>
import { Button, Field, Input, Select, Textarea } from '@statamic/cms/ui';
import ExamplePicker from './ExamplePicker.vue';

let count = 0;

export default {
    components: { Button, ExamplePicker, Field, Input, Select, Textarea },

    props: {
        brief: { type: Object, required: true },
        questions: { type: Array, default: () => [] },
        entries: { type: Array, default: () => [] },
        // Nothing can be changed while Ghostwriter works on the piece.
        disabled: { type: Boolean, default: false },
        // Which button is waiting on the server: 'agree', 'try-again' or 'save'.
        pending: { type: String, default: null },
        errors: { type: Object, default: () => ({}) },
    },

    emits: ['agree', 'try-again', 'save'],

    data() {
        count += 1;

        return {
            uid: `gw-brief-${count}`,
            open: false,
            title: '',
            answers: {},
            examples: [],
        };
    },

    computed: {
        agreed() {
            return this.brief.agreed === true;
        },

        // Changed since it was filled in (or last saved).
        changed() {
            return this.title !== (this.brief.title ?? '')
                || JSON.stringify(this.examples) !== JSON.stringify(this.brief.examples ?? [])
                || this.questions.some((question) => (this.answers[question.handle] ?? '') !== (this.brief.answers?.[question.handle] ?? ''));
        },
    },

    watch: {
        // A new card (filled in, tried again, saved): start from it. The
        // person's own changes stay while the same card is showing.
        brief: {
            immediate: true,
            handler(brief, before) {
                if (before && brief.attempt === before.attempt && brief.agreed === before.agreed && this.changed && !this.pending) return;

                this.reset();
            },
        },
    },

    methods: {
        reset() {
            this.title = this.brief.title ?? '';
            this.answers = Object.fromEntries(this.questions.map((question) => [question.handle, this.brief.answers?.[question.handle] ?? '']));
            this.examples = [...(this.brief.examples ?? [])];
        },

        card() {
            return { title: this.title, answers: { ...this.answers }, examples: [...this.examples] };
        },

        options(question) {
            return Object.entries(question.options ?? {}).map(([value, label]) => ({ value, label }));
        },

        // Tall enough to show the whole answer; past a screenful it scrolls.
        rowsFor(question) {
            const lines = String(this.answers[question.handle] ?? '')
                .split('\n')
                .reduce((total, line) => total + Math.max(1, Math.ceil(line.length / 60)), 0);

            return Math.min(Math.max(lines, question.type === 'text' ? 1 : 2), 12);
        },

        // Called by the panel when the card arrives, so keyboard and
        // screen-reader users land on it.
        focus() {
            this.$refs.heading?.focus();
        },
    },
};
</script>

<template>
    <section
        :id="uid"
        :aria-labelledby="`${uid}-heading`"
        class="rounded-lg border text-sm sm:me-8"
        :class="agreed ? 'border-gray-200 dark:border-gray-700!' : 'border-blue-300 bg-blue-50/40 dark:border-blue-700! dark:bg-blue-950/30!'"
    >
        <!-- Agreed: collapsed to "Show the brief" -->
        <div v-if="agreed" class="flex items-center justify-between gap-3 px-3 py-2">
            <h3 :id="`${uid}-heading`" ref="heading" tabindex="-1" class="truncate font-medium focus:outline-none">
                {{ __('Brief') }}<span v-if="brief.title" class="font-normal text-gray-500"> · {{ brief.title }}</span>
            </h3>
            <button
                type="button"
                class="shrink-0 font-medium underline"
                :aria-expanded="open ? 'true' : 'false'"
                :aria-controls="`${uid}-body`"
                @click="(open = !open), open || reset()"
            >
                {{ open ? __('Hide the brief') : __('Show the brief') }}
            </button>
        </div>
        <h3 v-else :id="`${uid}-heading`" ref="heading" tabindex="-1" class="px-3 pt-3 font-semibold focus:outline-none">{{ __('Brief') }}</h3>

        <div v-if="!agreed || open" :id="`${uid}-body`" class="space-y-4 p-3" :class="agreed ? 'border-t border-gray-200 dark:border-gray-700!' : ''">
            <Field :label="__('Working title')" :id="`${uid}-title`">
                <Input v-model="title" :id="`${uid}-title`" :disabled="disabled" />
            </Field>

            <Field
                v-for="question in questions"
                :key="question.handle"
                :id="`${uid}-${question.handle}`"
                :label="question.label"
                :instructions="question.instructions ?? ''"
                :required="question.required === true"
                :error="errors[`answers.${question.handle}`]?.[0]"
            >
                <Select v-if="question.type === 'select'" v-model="answers[question.handle]" :id="`${uid}-${question.handle}`" :options="options(question)" :disabled="disabled" />
                <Textarea v-else v-model="answers[question.handle]" :id="`${uid}-${question.handle}`" :rows="rowsFor(question)" class="resize-y" :disabled="disabled" />
            </Field>

            <Field v-if="entries.length" :label="__('Model it on')">
                <ExamplePicker v-model="examples" :entries="entries" :disabled="disabled" />
            </Field>

            <div v-if="!agreed" class="flex flex-wrap items-center justify-end gap-2">
                <Button
                    :text="__('Try again')"
                    :loading="pending === 'try-again'"
                    :disabled="disabled || !!pending"
                    @click="$emit('try-again', card())"
                />
                <Button
                    variant="primary"
                    :text="__('Looks right, start writing')"
                    :loading="pending === 'agree'"
                    :disabled="disabled || !!pending"
                    @click="$emit('agree', card())"
                />
            </div>
            <div v-else class="flex flex-wrap items-center justify-end gap-2">
                <Button size="sm" variant="ghost" :text="__('Cancel')" :disabled="!!pending" @click="(open = false), reset()" />
                <Button
                    size="sm"
                    :text="__('Save the brief')"
                    :loading="pending === 'save'"
                    :disabled="disabled || !!pending || !changed"
                    @click="$emit('save', card())"
                />
            </div>
        </div>
    </section>
</template>
