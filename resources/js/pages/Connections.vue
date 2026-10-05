<!--
    Settings → Connections: a card for every service Ghostwriter uses, in
    Writing, Images and Stock photos. Each says where it stands (connected
    with the key's last four characters, not set up, set in .env, or a key
    that stopped working) and sets up in place: open the service's page,
    follow two or three steps, paste, Check & save. Words come from core
    (Connections\Strings), in the editor's language.
-->
<script>
import ghost from '../icon.js';
import { Head } from '@statamic/cms/inertia';
import { Badge, Button, Header, Input, Modal, Panel } from '@statamic/cms/ui';
import { request } from '../stock/request.js';

export default {
    components: { Badge, Button, Head, Header, Input, Modal, Panel },

    props: {
        groups: { type: Array, required: true },
        strings: { type: Object, required: true },
        environment: { type: String, required: true },
        stored_in: { type: String, default: 'database' },
        urls: { type: Object, required: true },
    },

    data() {
        return {
            current: this.groups.map((group) => ({ ...group, cards: [...group.cards] })),
            open: null,
            values: {},
            error: null,
            busy: null,
            confirming: null,
        };
    },

    computed: {
        ghost: () => ghost,
    },

    watch: {
        groups(groups) {
            this.current = groups.map((group) => ({ ...group, cards: [...group.cards] }));
        },
    },

    methods: {
        t(key, params = {}) {
            let text = this.strings[key] ?? key;

            Object.keys(params)
                .sort((a, b) => b.length - a.length)
                .forEach((name) => {
                    text = text.split(`:${name}`).join(params[name]);
                });

            return text;
        },

        color(card) {
            return { connected: 'green', env: 'blue', config: 'blue', broken: 'red', no_key: 'green' }[card.status.state] ?? 'default';
        },

        canSetUp(card) {
            return card.needs_key && card.status.state !== 'env';
        },

        kept(card) {
            return ['connected', 'broken'].includes(card.status.state);
        },

        setUp(card) {
            this.open = card.id;
            this.error = null;
            this.values = Object.fromEntries(card.fields.map((field) => [field.name, '']));
            this.$nextTick(() => document.getElementById(`gw-connection-${card.id}-${card.fields[0].name}`)?.focus());
        },

        cancel() {
            const id = this.open;
            this.open = null;
            this.values = {};
            this.error = null;
            this.$nextTick(() => document.querySelector(`[data-ghostwriter-connection="${id}"] [data-ghostwriter-connection-setup]`)?.focus());
        },

        replace(card) {
            this.current = this.current.map((group) => ({ ...group, cards: group.cards.map((c) => (c.id === card.id ? card : c)) }));
        },

        async save(card) {
            this.busy = card.id;
            this.error = null;

            try {
                const result = await request(`${this.urls.base}/${card.id}`, { method: 'POST', body: { fields: this.values } });
                this.replace(result.card);
                this.open = null;
                this.values = {};
                this.$toast.success(result.message);
            } catch (error) {
                this.error = error.message;
            } finally {
                this.busy = null;
            }
        },

        async disconnect() {
            const card = this.confirming;
            this.busy = card.id;

            try {
                const result = await request(`${this.urls.base}/${card.id}/disconnect`, { method: 'POST' });
                this.replace(result.card);
                this.$toast.success(result.message);
            } catch (error) {
                this.$toast.error(error.message);
            } finally {
                this.busy = null;
                this.confirming = null;
            }
        },

        async disconnectAccount(card) {
            this.busy = card.id + ':account';

            try {
                const result = await request(card.oauth_links.disconnect_url, { method: 'POST' });
                const fresh = await request(`${this.urls.base}/status`);
                this.current = fresh.groups;
                this.$toast.success(result.message);
            } catch (error) {
                this.$toast.error(error.message);
            } finally {
                this.busy = null;
            }
        },
    },
};
</script>

<template>
    <Head :title="t('title')" />

    <div class="mx-auto max-w-4xl" data-ghostwriter-connections>
        <Header :title="t('title')" :icon="ghost" />

        <p class="mb-2 text-sm text-gray-600 dark:text-gray-300!">{{ t('intro') }}</p>
        <p class="mb-4 text-sm text-gray-500">{{ t('privacy') }}</p>

        <div role="note" class="mb-6 rounded-lg border border-gray-200 bg-gray-50 px-4 py-3 text-sm dark:border-gray-700! dark:bg-gray-900!" data-ghostwriter-environment>
            <strong>{{ t('environment', { environment }) }}</strong>
            {{ t('environment.note') }}
        </div>

        <section v-for="group in current" :key="group.id" class="mb-8" :aria-labelledby="`gw-group-${group.id}`">
            <h2 :id="`gw-group-${group.id}`" class="mb-1 text-base font-semibold">{{ group.title }}</h2>
            <p class="mb-3 text-sm text-gray-500">{{ group.intro }}</p>

            <Panel>
                <div class="divide-y divide-gray-200 dark:divide-gray-700!">
                    <article
                        v-for="card in group.cards"
                        :key="card.id"
                        class="p-4"
                        :aria-labelledby="`gw-card-${card.id}`"
                        :data-ghostwriter-connection="card.id"
                        :data-state="card.status.state"
                    >
                        <div class="flex flex-wrap items-start justify-between gap-3">
                            <div class="min-w-0 flex-1 space-y-1">
                                <div class="flex flex-wrap items-center gap-2">
                                    <h3 :id="`gw-card-${card.id}`" class="font-medium">{{ card.name }}</h3>
                                    <Badge :color="color(card)" :text="card.status.label" size="sm" data-ghostwriter-connection-status />
                                    <Badge v-if="card.makes_images" size="sm" :text="t('makes-images')" />
                                </div>
                                <p class="text-sm text-gray-600 dark:text-gray-300!">{{ card.about }}</p>
                                <p v-if="card.help" class="text-sm" :class="card.status.broken ? 'text-red-700 dark:text-red-400!' : 'text-gray-500'" data-ghostwriter-connection-help>{{ card.help }}</p>
                            </div>

                            <div v-if="canSetUp(card) && open !== card.id" class="flex shrink-0 flex-wrap gap-2">
                                <Button
                                    v-if="!kept(card)"
                                    size="sm"
                                    variant="primary"
                                    :text="t('action.setup')"
                                    :aria-label="`${t('action.setup')}: ${card.name}`"
                                    data-ghostwriter-connection-setup
                                    @click="setUp(card)"
                                />
                                <template v-else>
                                    <Button size="sm" :text="t('action.replace')" :aria-label="`${t('action.replace')}: ${card.name}`" data-ghostwriter-connection-setup @click="setUp(card)" />
                                    <Button size="sm" variant="ghost" :text="t('action.disconnect')" :aria-label="`${t('action.disconnect')}: ${card.name}`" data-ghostwriter-connection-disconnect @click="confirming = card" />
                                </template>
                            </div>
                        </div>

                        <!-- Sign in instead, where the service can. -->
                        <div v-if="card.oauth === 'key' && card.oauth_links && !kept(card) && open !== card.id" class="mt-3 flex flex-wrap items-center gap-2 text-sm text-gray-600 dark:text-gray-300!">
                            <span>{{ t('oauth.key', { service: card.name }) }}</span>
                            <Button size="sm" :href="card.oauth_links.connect_url" :text="t('oauth.key.button', { service: card.name })" />
                        </div>

                        <div v-if="card.oauth === 'account' && card.oauth_links" class="mt-3 space-y-1 text-sm" data-ghostwriter-connection-account>
                            <p v-if="card.oauth_links.needs_key" class="text-gray-500">{{ t('oauth.account.needs-key') }}</p>
                            <div v-else class="flex flex-wrap items-center gap-2">
                                <span class="text-gray-600 dark:text-gray-300!">{{ t('oauth.account', { service: card.name }) }}</span>
                                <Badge size="sm" :color="card.oauth_links.connected ? 'green' : 'default'" :text="card.oauth_links.connected ? t('oauth.account.connected') : t('oauth.account.not-connected')" />
                                <Button v-if="!card.oauth_links.connected" size="sm" :href="card.oauth_links.connect_url" :text="t('oauth.account.connect')" />
                                <Button v-else size="sm" variant="ghost" :text="t('oauth.account.disconnect')" :loading="busy === card.id + ':account'" @click="disconnectAccount(card)" />
                            </div>
                            <p v-if="!card.oauth_links.needs_key && !card.oauth_links.connected && card.oauth_links.callback" class="text-xs text-gray-500">
                                {{ t('oauth.account.callback', { service: card.name }) }} <code>{{ card.oauth_links.callback }}</code>
                            </p>
                        </div>

                        <!-- The guided set-up. -->
                        <form
                            v-if="open === card.id"
                            class="mt-4 space-y-4 rounded-lg border border-gray-200 p-4 dark:border-gray-700!"
                            :aria-label="kept(card) ? t('panel.replace', { service: card.name }) : t('panel.title', { service: card.name })"
                            data-ghostwriter-connection-panel
                            @submit.prevent="save(card)"
                            @keydown.esc="cancel"
                        >
                            <div class="flex flex-wrap items-center gap-2">
                                <Button :href="card.key_url" target="_blank" rel="noopener noreferrer" icon="external-link" :text="t('action.open', { service: card.name })" data-ghostwriter-connection-open />
                                <span class="text-xs text-gray-500">{{ t('panel.opens') }}</span>
                            </div>

                            <ol class="list-decimal space-y-1 pl-5 text-sm">
                                <li v-for="(step, i) in card.steps" :key="i">{{ step }}</li>
                            </ol>

                            <div v-for="field in card.fields" :key="field.name" class="space-y-1">
                                <label :for="`gw-connection-${card.id}-${field.name}`" class="block text-sm font-medium">{{ field.label }}</label>
                                <Input
                                    :id="`gw-connection-${card.id}-${field.name}`"
                                    v-model="values[field.name]"
                                    type="password"
                                    autocomplete="off"
                                    spellcheck="false"
                                    :placeholder="t('panel.paste')"
                                    :aria-describedby="`gw-connection-${card.id}-private`"
                                    :data-ghostwriter-connection-field="field.name"
                                />
                            </div>
                            <p :id="`gw-connection-${card.id}-private`" class="text-xs text-gray-500">{{ t('panel.private') }}</p>

                            <p v-if="error" role="alert" class="text-sm text-red-700 dark:text-red-400!" data-ghostwriter-connection-error>{{ error }}</p>

                            <div class="flex flex-wrap gap-2">
                                <Button type="submit" variant="primary" :loading="busy === card.id" :text="busy === card.id ? t('action.checking') : t('action.check')" data-ghostwriter-connection-save />
                                <Button variant="ghost" :text="t('action.cancel')" @click="cancel" />
                            </div>
                        </form>
                    </article>
                </div>
            </Panel>
        </section>

        <Modal :open="confirming !== null" :title="confirming ? t('disconnect.title', { service: confirming.name }) : ''" @update:open="confirming = $event ? confirming : null">
            <div v-if="confirming" class="space-y-4 p-1" data-ghostwriter-connection-confirm>
                <p class="text-sm">{{ t('disconnect.body', { service: confirming.name }) }}</p>
                <div class="flex justify-end gap-2">
                    <Button variant="ghost" :text="t('action.cancel')" @click="confirming = null" />
                    <Button variant="danger" :loading="busy === confirming.id" :text="t('action.disconnect')" data-ghostwriter-connection-confirm-disconnect @click="disconnect" />
                </div>
            </div>
        </Modal>
    </div>
</template>
