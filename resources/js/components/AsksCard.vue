<!--
    The writer's questions before it drafts (core's Studio\Asks): a short
    intro, then one labelled box per question, each with Skip.

      live       waiting on the answers: a box per question (radios for
                 set answers), ⌘↵ in any box sends them all
      answered   read-only, each question with the answer given

    A labelled region, so a screen reader can find it and jump to it. The
    answers live in the panel, which sends them with its Send button.
-->
<script>
import { Field, Radio, RadioGroup, Textarea } from '@statamic/cms/ui';

let count = 0;

export default {
    components: { Field, Radio, RadioGroup, Textarea },

    props: {
        // {intro, answered, questions: [{id, question, hint, kind, options, optional, answer}]}
        asked: { type: Object, required: true },
        live: { type: Boolean, default: false },
        disabled: { type: Boolean, default: false },
        // Who answered, when the conversation is shared.
        answeredBy: { type: String, default: null },
        // The answers so far and the questions skipped, by id (the panel's).
        answers: { type: Object, default: () => ({}) },
        skipped: { type: Object, default: () => ({}) },
    },

    emits: ['answer', 'skip', 'send'],

    data() {
        count += 1;

        return { uid: `gw-asks-${count}` };
    },

    methods: {
        options(question) {
            return question.options.map((option) => ({ value: option, label: option }));
        },

        // Called by the panel when the questions arrive.
        focus() {
            this.$el.querySelector('textarea, input[type="radio"]')?.focus();
        },
    },
};
</script>

<template>
    <section
        :aria-labelledby="`${uid}-heading`"
        class="space-y-3 rounded-lg border px-3 py-2.5 text-sm sm:me-8"
        :class="live ? 'border-amber-400 bg-amber-50 dark:border-amber-500! dark:bg-amber-950/40!' : 'border-gray-200 dark:border-gray-700!'"
        data-ghostwriter-asks
    >
        <h3
            :id="`${uid}-heading`"
            class="text-xs font-semibold tracking-wide uppercase"
            :class="live ? 'text-amber-700 dark:text-amber-400!' : 'text-gray-500'"
        >
            {{ live ? __('Ghostwriter needs your answer') : 'Ghostwriter' }}
        </h3>
        <p v-if="asked.intro">{{ asked.intro }}</p>

        <ol class="space-y-3 ps-5" style="list-style: decimal">
            <li v-for="question in asked.questions" :key="question.id" class="ps-1 marker:font-semibold marker:text-gray-500">
                <template v-if="live">
                    <div class="space-y-1.5" :class="skipped[question.id] ? 'opacity-60' : ''">
                        <Field
                            :id="`${uid}-${question.id}`"
                            :label="question.question + (question.optional ? ` (${__('optional')})` : '')"
                            :instructions="question.hint"
                        >
                            <p v-if="skipped[question.id]" class="text-xs text-gray-500 italic">{{ __('Skipped') }}</p>
                            <RadioGroup
                                v-else-if="question.kind === 'choice'"
                                :model-value="answers[question.id] ?? null"
                                :name="`${uid}-${question.id}`"
                                appearance="inline"
                                @update:model-value="(value) => $emit('answer', question.id, value)"
                                @keydown.meta.enter.stop.prevent="$emit('send')"
                                @keydown.ctrl.enter.stop.prevent="$emit('send')"
                            >
                                <Radio v-for="option in options(question)" :key="option.value" :value="option.value" :label="option.label" :disabled="disabled" />
                            </RadioGroup>
                            <Textarea
                                v-else
                                :id="`${uid}-${question.id}`"
                                :model-value="answers[question.id] ?? ''"
                                :rows="1"
                                elastic
                                :disabled="disabled"
                                @update:model-value="(value) => $emit('answer', question.id, value)"
                                @keydown.meta.enter.stop.prevent="$emit('send')"
                                @keydown.ctrl.enter.stop.prevent="$emit('send')"
                            />
                        </Field>
                        <div class="flex justify-end">
                            <button
                                type="button"
                                class="text-xs text-gray-600 underline underline-offset-2 hover:text-gray-900 dark:text-gray-400! dark:hover:text-gray-200!"
                                :aria-pressed="skipped[question.id] ? 'true' : 'false'"
                                :aria-label="skipped[question.id] ? __('Answer: :question', { question: question.question }) : __('Skip: :question', { question: question.question })"
                                :disabled="disabled"
                                @click="$emit('skip', question.id, !skipped[question.id])"
                            >
                                {{ skipped[question.id] ? __('Answer it') : __('Skip') }}
                            </button>
                        </div>
                    </div>
                </template>
                <template v-else>
                    <div class="font-medium">{{ question.question }}</div>
                    <div class="whitespace-pre-line" :class="question.answer === null ? 'text-gray-500 italic' : ''">
                        {{ question.answer ?? (asked.answered ? __('Skipped') : __('Not answered')) }}
                    </div>
                </template>
            </li>
        </ol>

        <p v-if="!live && asked.answered && answeredBy" class="text-xs text-gray-500">{{ __('Answered by :name', { name: answeredBy }) }}</p>
    </section>
</template>
