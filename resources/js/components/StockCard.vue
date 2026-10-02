<!--
    One stock image as an editor meets it on the field's badge and in the
    asset editor: the comp (signed-in editors only), its library and ID,
    where it stands, and what can be done: License (with the permission),
    or "Ask a manager to license" and Request licence; Refresh preview once
    its comp has expired; Download again and replace after a licence whose
    file didn't go in.
-->
<script>
import { Alert, Button } from '@statamic/cms/ui';
import LicenseConfirm from './LicenseConfirm.vue';
import { request } from '../stock/request.js';
import { put } from '../stock/store.js';

export default {
    components: { Alert, Button, LicenseConfirm },

    props: {
        record: { type: Object, required: true },
    },

    emits: ['changed'],

    data() {
        return { confirming: false, busy: false };
    },

    computed: {
        base() {
            return Statamic.$config.get('ghostwriter')?.url;
        },

        status() {
            const r = this.record;

            if (r.state === 'licensed') return r.replaced ? this.__('Licensed') : this.__('Licensed, but the file isn’t in place yet');
            if (r.state === 'licensing') return this.__('Being licensed');
            if (r.state === 'failed') return this.__('Licence failed · not licensed');
            if (r.state === 'removed') return this.__('Removed');
            if (r.comp_expired) return this.__('Preview expired');

            return this.__('Preview · not licensed');
        },
    },

    methods: {
        changed(summary) {
            put(summary);
            this.$emit('changed', summary);
        },

        async post(action) {
            this.busy = true;

            try {
                const result = await request(`${this.base}/stock/${this.record.id}/${action}`, { method: 'POST' });

                this.changed(result.stock);
                this.$toast.success(result.message);
            } catch (error) {
                if (error.response?.data?.stock) this.changed(error.response.data.stock);
                this.$toast.error(error.message);
            } finally {
                this.busy = false;
            }
        },
    },
};
</script>

<template>
    <div class="space-y-3" data-ghostwriter-stock-card>
        <div class="flex items-start gap-3 max-sm:flex-col">
            <a v-if="record.comp_url" :href="record.comp_url" target="_blank" rel="noopener" class="block shrink-0" :title="__('Open preview')">
                <img :src="record.comp_url" alt="" class="h-28 w-40 rounded-md border border-gray-200 object-cover dark:border-gray-700!" />
            </a>
            <img v-else-if="!record.unlicensed && record.asset_url" :src="record.asset_url" alt="" class="h-28 w-40 shrink-0 rounded-md border border-gray-200 object-cover dark:border-gray-700!" />
            <div v-else class="flex h-28 w-40 shrink-0 items-center justify-center rounded-md border border-dashed border-gray-300 px-2 text-center text-xs text-gray-500 dark:border-gray-700!">
                {{ record.state === 'preview' ? __('The preview has expired') : __('No preview') }}
            </div>
            <div class="min-w-0 space-y-1 text-sm">
                <div>
                    <span class="rounded px-1.5 py-0.5 text-xs font-medium" :class="record.unlicensed ? 'bg-amber-100 text-amber-900' : 'bg-green-100 text-green-900'">{{ status }}</span>
                </div>
                <div class="font-medium">{{ record.title }}</div>
                <div class="text-gray-500">{{ record.library_label }} · {{ record.external_id }}</div>
                <div v-if="record.editorial" class="text-xs text-amber-700 dark:text-amber-300!">{{ __('Editorial use only') }}<template v-if="record.restrictions">: {{ record.restrictions }}</template></div>
                <div v-if="record.licence" class="text-xs text-gray-500">{{ __('Order :order', { order: record.licence.order_id }) }}<template v-if="record.licence.cost"> · {{ record.licence.cost }}</template><template v-if="record.licence.licensed_by"> · {{ record.licence.licensed_by }}</template></div>
                <div v-if="record.credit_line" class="text-xs text-gray-500">{{ __('Credit') }}: {{ record.credit_line }}</div>
                <div v-if="record.requested && record.unlicensed" class="text-xs text-gray-500">{{ __('Licence requested by :name', { name: record.requested.by || __('someone') }) }}</div>
            </div>
        </div>

        <Alert v-if="record.error" variant="error" :text="record.error" />

        <div class="flex flex-wrap gap-2">
            <template v-if="record.state === 'preview' || record.state === 'failed'">
                <Button v-if="record.can_license" variant="primary" :text="__('License')" :disabled="busy" @click="confirming = true" />
                <template v-else>
                    <span class="self-center text-sm text-gray-600 dark:text-gray-300!">{{ __('Ask a manager to license') }}</span>
                    <Button :text="record.requested ? __('Licence requested') : __('Request licence')" :disabled="busy || !!record.requested" @click="post('request')" />
                </template>
                <Button v-if="record.may_refresh" :text="__('Refresh preview')" :disabled="busy" @click="post('refresh')" />
            </template>
            <Button v-if="record.state === 'licensed' && !record.replaced && record.can_license" variant="primary" :text="__('Download again and replace')" :disabled="busy" @click="post('replace')" />
            <Button v-if="record.comp_url" variant="ghost" :href="record.comp_url" target="_blank" :text="__('Open preview')" />
        </div>

        <LicenseConfirm v-model:open="confirming" :record="record" @licensed="changed" @changed="changed" />
    </div>
</template>
