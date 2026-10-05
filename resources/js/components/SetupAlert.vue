<!--
    Shown on every screen while the configured provider has no API key, since
    nothing that calls a model can work until one is set.
-->
<script>
import { Alert, Button } from '@statamic/cms/ui';

const KEYS = { anthropic: 'ANTHROPIC_API_KEY', openai: 'OPENAI_API_KEY', gemini: 'GEMINI_API_KEY', openrouter: 'OPENROUTER_API_KEY' };
const NAMES = { anthropic: 'Anthropic', openai: 'OpenAI', gemini: 'Gemini', openrouter: 'OpenRouter' };

export default {
    components: { Alert, Button },

    props: {
        provider: { type: String, required: true },
    },

    computed: {
        text() {
            const key = KEYS[this.provider] ?? `the API key for "${this.provider}"`;
            const service = NAMES[this.provider] ?? this.provider;

            return this.__('Set up :service in Connections (or set :key in .env). Ghostwriter cannot write anything until then.', { service, key });
        },

        url() {
            const base = Statamic.$config.get('ghostwriter')?.url;

            return base ? `${base}/connections` : null;
        },
    },
};
</script>

<template>
    <Alert variant="warning" :heading="__('No API key yet')" class="mb-6">
        <p>{{ text }}</p>
        <Button v-if="url" :href="url" size="sm" class="mt-2" :text="__('Set up in Connections')" />
    </Alert>
</template>
