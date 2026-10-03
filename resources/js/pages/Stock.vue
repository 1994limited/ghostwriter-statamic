<!--
    Stock images: every photo Ghostwriter put into the site from a photo
    library, free or paid, in tabs. Each row shows the image, its library
    and ID, where it is used, its state, cost, who licensed it and when,
    its credit line and restrictions, with License, Reconcile, Remove
    preview and the licence record to download. CSV for finance and audits.
-->
<script>
import ghost from '../icon.js';
import { Head, Link, router } from '@statamic/cms/inertia';
import { Badge, Button, Header, Panel } from '@statamic/cms/ui';
import LicenseConfirm from '../components/LicenseConfirm.vue';
import { request } from '../stock/request.js';

export default {
    components: { Badge, Button, Head, Header, LicenseConfirm, Link, Panel },

    props: {
        tab: { type: String, required: true },
        tabs: { type: Array, required: true },
        records: { type: Array, required: true },
        page: { type: Number, default: 1 },
        pages: { type: Number, default: 1 },
        csv_url: { type: String, required: true },
        can_license: { type: Boolean, default: false },
    },

    data() {
        return { rows: [...this.records], licensing: null, busy: null };
    },

    computed: {
        ghost: () => ghost,

        router: () => router,

        base() {
            return Statamic.$config.get('ghostwriter')?.url;
        },

        labels() {
            return { previews: this.__('Previews'), licensed: this.__('Licensed'), failed: this.__('Failed'), all: this.__('All') };
        },
    },

    watch: {
        records(records) {
            this.rows = [...records];
        },
    },

    methods: {
        state(row) {
            if (row.state === 'preview') return row.comp_expired ? this.__('Preview expired') : this.__('Preview · not licensed');
            if (row.state === 'licensing') return this.__('Being licensed');
            if (row.state === 'licensed') return row.replaced ? this.__('Licensed') : this.__('Licensed, file not in place');
            if (row.state === 'failed') return this.__('Failed');

            return this.__('Removed');
        },

        color(row) {
            return { preview: 'amber', licensing: 'amber', licensed: 'green', failed: 'red', removed: 'default' }[row.state] ?? 'default';
        },

        changed(summary) {
            this.rows = this.rows.map((row) => (row.id === summary.id ? summary : row));
        },

        async act(row, action) {
            this.busy = row.id + action;

            try {
                const result = await request(`${this.base}/stock/${row.id}/${action}`, { method: 'POST' });

                this.changed(result.stock);
                this.$toast.success(result.message);
            } catch (error) {
                if (error.response?.data?.stock) this.changed(error.response.data.stock);
                this.$toast.error(error.message);
            } finally {
                this.busy = null;
            }
        },

        date(value) {
            return value ? new Date(value).toLocaleDateString(undefined, { day: 'numeric', month: 'short', year: 'numeric' }) : '';
        },
    },
};
</script>

<template>
    <Head :title="__('Stock images')" />

    <div class="mx-auto max-w-6xl">
        <Header :title="__('Stock images')" :icon="ghost">
            <Button :href="csv_url" :text="__('Export CSV')" icon="download" />
        </Header>

        <p class="mb-4 text-sm text-gray-500">{{ __('Every photo Ghostwriter put into the site from a photo library: where it is used, its licence and credit. Records are kept for good, even after an image is deleted.') }}</p>

        <div class="mb-4 flex flex-wrap gap-1 border-b border-gray-200 dark:border-gray-700!" role="tablist">
            <Link
                v-for="item in tabs"
                :key="item.key"
                :href="item.url"
                role="tab"
                :aria-selected="item.key === tab"
                class="-mb-px border-b-2 px-3 py-2 text-sm"
                :class="item.key === tab ? 'border-gray-900 font-medium dark:border-white!' : 'border-transparent text-gray-500'"
            >{{ labels[item.key] }} <span class="text-gray-500">{{ item.count }}</span></Link>
        </div>

        <Panel>
            <p v-if="!rows.length" class="p-6 text-sm text-gray-500">{{ tab === 'previews' ? __('No previews waiting for a licence.') : __('Nothing here yet.') }}</p>

            <div v-else class="divide-y divide-gray-200 dark:divide-gray-700!">
                <div v-for="row in rows" :key="row.id" class="flex gap-4 p-4 max-md:flex-col" data-ghostwriter-stock-row>
                    <img v-if="row.comp_url || row.asset_url" :src="row.unlicensed && row.comp_url ? row.comp_url : row.asset_url" alt="" class="h-20 w-28 shrink-0 rounded-md border border-gray-200 object-cover dark:border-gray-700!" />
                    <div v-else class="h-20 w-28 shrink-0 rounded-md border border-dashed border-gray-300 dark:border-gray-700!"></div>

                    <div class="min-w-0 flex-1 space-y-1 text-sm">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="font-medium">{{ row.title }}</span>
                            <Badge :color="color(row)" :text="state(row)" size="sm" />
                            <Badge v-if="row.requested && row.unlicensed" color="blue" size="sm" :text="__('Licence requested by :name', { name: row.requested.by || __('someone') })" />
                            <Badge v-if="row.editorial" color="amber" size="sm" :text="__('Editorial')" />
                        </div>
                        <div class="text-gray-500">{{ row.library_label }} · {{ row.external_id }} · {{ __('Added :date by :name', { date: date(row.inserted_at), name: row.inserted_by || __('someone') }) }}</div>
                        <div v-if="row.usages.length" class="text-gray-600 dark:text-gray-300!">
                            {{ __('Used on') }}:
                            <template v-for="(usage, i) in row.usages" :key="usage.owner + usage.field">
                                <a v-if="usage.url" :href="usage.url" class="underline">{{ usage.title }}</a><span v-else>{{ usage.title }}</span>
                                ({{ usage.field }}<template v-if="usage.live">, {{ __('published') }}</template>)<template v-if="i < row.usages.length - 1">, </template>
                            </template>
                        </div>
                        <div v-else class="text-gray-500">{{ __('Not used on any page') }}</div>
                        <div v-if="row.licence" class="text-gray-500">{{ __('Order :order', { order: row.licence.order_id }) }}<template v-if="row.licence.cost"> · {{ row.licence.cost }}</template> · {{ __('Licensed :date by :name', { date: date(row.licence.licensed_at), name: row.licence.licensed_by || __('someone') }) }}</div>
                        <div v-if="row.credit_line" class="text-gray-500">{{ __('Credit') }}: {{ row.credit_line }}</div>
                        <div v-if="row.restrictions" class="text-amber-700 dark:text-amber-300!">{{ row.restrictions }}</div>
                        <div v-if="row.error" class="text-red-700 dark:text-red-400!">{{ row.error }}</div>
                    </div>

                    <div class="flex shrink-0 flex-wrap content-start gap-2 md:w-56 md:justify-end">
                        <Button v-if="row.paid && (row.state === 'preview' || row.state === 'failed') && can_license" size="sm" variant="primary" :text="__('License')" @click="licensing = row" />
                        <Button v-if="row.state === 'licensing' && can_license" size="sm" :text="__('Reconcile')" :loading="busy === row.id + 'reconcile'" @click="act(row, 'reconcile')" />
                        <Button v-if="row.state === 'licensed' && !row.replaced && can_license" size="sm" :text="__('Download again and replace')" :loading="busy === row.id + 'replace'" @click="act(row, 'replace')" />
                        <Button v-if="(row.state === 'preview' || row.state === 'failed') && !row.usages.length" size="sm" variant="ghost" :text="__('Remove preview')" :loading="busy === row.id + 'remove'" @click="act(row, 'remove')" />
                        <Button size="sm" variant="ghost" :href="`${base}/stock/${row.id}/record`" :text="__('Licence record')" />
                    </div>
                </div>
            </div>
        </Panel>

        <div v-if="pages > 1" class="mt-4 flex justify-center gap-2">
            <Button v-if="page > 1" size="sm" :text="__('Previous')" @click="router.get(`${base}/stock`, { tab, page: page - 1 })" />
            <span class="self-center text-sm text-gray-500">{{ __(':page of :pages', { page, pages }) }}</span>
            <Button v-if="page < pages" size="sm" :text="__('Next')" @click="router.get(`${base}/stock`, { tab, page: page + 1 })" />
        </div>

        <LicenseConfirm :open="licensing !== null" :record="licensing" @update:open="licensing = $event ? licensing : null" @licensed="changed" @changed="changed" />
    </div>
</template>
