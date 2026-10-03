<!--
    The confirm step of License & replace (design §8.3): the photo, the
    licence option (a select when there are several), what it costs from
    the site's own account, any editorial restrictions and seat notices,
    and the credit line that will be stored. Nothing is bought until the
    person presses License & replace, and then only once.
-->
<script>
import ghost from '../icon.js';
import { Alert, Button, Modal } from '@statamic/cms/ui';
import { request } from '../stock/request.js';

export default {
    components: { Alert, Button, Modal },

    props: {
        record: { type: Object, default: null },
        open: { type: Boolean, default: false },
    },

    emits: ['update:open', 'licensed', 'changed'],

    data() {
        return {
            confirm: null,
            option: null,
            loading: false,
            busy: false,
            error: null,
            connectUrl: null,
        };
    },

    computed: {
        ghost: () => ghost,

        base() {
            return Statamic.$config.get('ghostwriter')?.url;
        },

        chosen() {
            return this.confirm?.options.find((option) => option.option === this.option) ?? null;
        },
    },

    watch: {
        open: {
            immediate: true,
            handler(open) {
                if (open) this.load();
            },
        },
    },

    methods: {
        async load() {
            this.loading = true;
            this.error = null;
            this.confirm = null;

            try {
                this.confirm = await request(`${this.base}/stock/${this.record.id}/quotes`);
                this.option = this.confirm.options[0]?.option ?? null;
            } catch (error) {
                this.error = error.message;
                this.connectUrl = error.response?.data?.connect_url ?? null;
            } finally {
                this.loading = false;
            }
        },

        async license() {
            this.busy = true;
            this.error = null;

            try {
                const result = await request(`${this.base}/stock/${this.record.id}/license`, { method: 'POST', body: { option: this.option } });

                this.$emit('licensed', result.stock);
                this.$emit('update:open', false);
                this.$toast.success(result.message);

                if (result.notice) this.$toast.info(result.notice, { duration: 10000 });
            } catch (error) {
                const data = error.response?.data ?? {};

                this.error = data.message ?? error.message;
                this.connectUrl = data.connect_url ?? null;

                if (data.stock) this.$emit('changed', data.stock);

                // The price changed: show the new one and ask again.
                if (data.quote) {
                    this.confirm.options = this.confirm.options.map((option) => (option.option === data.quote.option ? data.quote : option));
                }
            } finally {
                this.busy = false;
            }
        },
    },
};
</script>

<template>
    <Modal :open="open" class="max-w-xl!" :title="__('License & replace')" :icon="ghost" @update:open="$emit('update:open', $event)">
        <div v-if="record" class="space-y-4 p-1" data-ghostwriter-license-confirm>
            <div class="flex items-start gap-3">
                <img v-if="record.comp_url" :src="record.comp_url" alt="" class="h-20 w-28 shrink-0 rounded-md border border-gray-200 object-cover dark:border-gray-700!" />
                <div class="min-w-0 text-sm">
                    <div class="font-medium">{{ record.title }}</div>
                    <div class="text-gray-500">{{ record.library_label }} · {{ record.external_id }}</div>
                </div>
            </div>

            <p v-if="loading" class="text-sm text-gray-500" role="status">{{ __('Asking :library for the licence options…', { library: record.library_label }) }}</p>

            <template v-if="confirm">
                <label v-if="confirm.options.length > 1" class="block text-sm">
                    <span class="mb-1 block font-medium">{{ __('Licence') }}</span>
                    <select v-model="option" class="h-9 w-full rounded-lg border border-gray-300 bg-white px-2 text-sm text-gray-900 dark:border-gray-700! dark:bg-gray-900! dark:text-gray-100!" :disabled="busy">
                        <option v-for="item in confirm.options" :key="item.option" :value="item.option">{{ item.name }}</option>
                    </select>
                </label>
                <p v-else-if="chosen" class="text-sm"><span class="font-medium">{{ __('Licence') }}:</span> {{ chosen.name }}</p>

                <p v-if="chosen" class="text-base font-medium" data-ghostwriter-cost>{{ chosen.cost }}</p>

                <Alert v-if="confirm.restrictions" variant="warning" :text="confirm.restrictions" />
                <Alert v-for="notice in chosen?.notices ?? []" :key="notice" variant="default" :text="notice" />
                <p v-if="confirm.already_used" class="text-sm text-gray-500">{{ confirm.already_used }}</p>

                <div v-if="confirm.credit_line" class="rounded-md bg-gray-50 p-3 text-sm dark:bg-gray-800!">
                    <div><span class="font-medium">{{ __('Credit') }}:</span> {{ confirm.credit_line }}</div>
                    <div class="mt-1 text-gray-500">{{ confirm.credit_note }}</div>
                </div>
            </template>

            <Alert v-if="error" variant="error" :text="error" />
            <p v-if="connectUrl" class="text-sm"><a :href="connectUrl" class="underline">{{ __('Connect again in Settings') }}</a></p>

            <div class="flex justify-end gap-2">
                <Button :text="__('Cancel')" :disabled="busy" @click="$emit('update:open', false)" />
                <Button variant="primary" :text="__('License & replace')" :loading="busy" :disabled="busy || loading || !option" @click="license" />
            </div>
        </div>
    </Modal>
</template>
