<!--
    Opened from the "Preview · not licensed" badge beside an assets field's
    Ghostwriter button: the stock preview in that field, with License or
    Request licence.
-->
<script>
import ghost from '../icon.js';
import { Modal } from '@statamic/cms/ui';
import StockCard from './StockCard.vue';
import { stock } from '../stock/store.js';

export default {
    components: { Modal, StockCard },

    data() {
        return { open: false, key: null };
    },

    computed: {
        ghost: () => ghost,

        record() {
            return this.key ? stock.assets[this.key] : null;
        },
    },

    created() {
        Statamic.$events.$on('ghostwriter.stock.open', this.show);
    },

    beforeUnmount() {
        Statamic.$events.$off('ghostwriter.stock.open', this.show);
    },

    methods: {
        show({ key }) {
            this.key = key;
            this.open = true;
        },

        changed(summary) {
            if (!summary.unlicensed) this.open = false;
        },
    },
};
</script>

<template>
    <Modal v-model:open="open" class="max-w-xl!" :title="__('Stock photo')" :icon="ghost">
        <div v-if="record" class="p-1">
            <StockCard :record="record" @changed="changed" />
        </div>
    </Modal>
</template>
