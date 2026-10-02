<!--
    The stock photo panel in the asset editor: the asset's ledger record,
    read-only, with License or Request licence.
-->
<script>
import { FieldtypeMixin } from '@statamic/cms';
import StockCard from './StockCard.vue';

export default {
    mixins: [FieldtypeMixin],

    components: { StockCard },

    data() {
        return { record: this.meta?.record ?? null };
    },
};
</script>

<template>
    <div data-ghostwriter-asset-stock>
        <StockCard v-if="record" :record="record" @changed="record = $event" />
        <p v-else class="text-sm text-gray-500">{{ __('This image is not in Ghostwriter’s stock image ledger.') }}</p>
    </div>
</template>
