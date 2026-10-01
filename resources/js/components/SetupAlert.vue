<!--
    Shown on every screen while the configured provider has no API key, since
    nothing that calls a model can work until one is set.
-->
<script>
import { Alert } from '@statamic/cms/ui';

const KEYS = { anthropic: 'ANTHROPIC_API_KEY', openai: 'OPENAI_API_KEY', gemini: 'GEMINI_API_KEY' };

export default {
    components: { Alert },

    props: {
        provider: { type: String, required: true },
    },

    computed: {
        text() {
            const key = KEYS[this.provider] ?? `the API key for "${this.provider}"`;

            return this.__('Add :key to your .env file, then reload this page. Ghostwriter cannot write anything until it is set.', { key });
        },
    },
};
</script>

<template>
    <Alert variant="warning" :heading="__('No API key yet')" :text="text" class="mb-6" />
</template>
