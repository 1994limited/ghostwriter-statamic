<!--
    Draws a draft as the server laid it out: each field under its label, and
    page-builder blocks in order under their names.
-->
<script>
export default {
    name: 'DraftPreview',

    props: {
        nodes: { type: Array, required: true },
        nested: { type: Boolean, default: false },
    },
};
</script>

<template>
    <div :class="nested ? 'space-y-3' : 'space-y-5'">
        <div v-for="node in nodes" :key="node.handle">
            <div class="mb-1 text-xs font-medium tracking-wide text-gray-500 uppercase">{{ node.label }}</div>

            <div v-if="node.kind === 'html'" class="gw-prose" v-html="node.html" />

            <ul v-else-if="node.kind === 'list'" class="flex flex-wrap gap-1.5">
                <li v-for="item in node.items" :key="item" class="rounded-md border border-gray-200 px-2 py-0.5 text-sm dark:border-gray-700">{{ item }}</li>
            </ul>

            <div v-else-if="node.kind === 'blocks'" class="space-y-3">
                <div v-for="(block, index) in node.items" :key="index" class="rounded-lg border border-gray-200 dark:border-gray-700">
                    <div class="flex items-center justify-between border-b border-gray-200 px-3 py-1.5 text-sm font-medium dark:border-gray-700">
                        <span>{{ block.label }}</span>
                        <span v-if="!block.known" class="text-red-600">{{ __('Unknown block, will be left out') }}</span>
                    </div>
                    <div v-if="block.fields.length" class="p-3">
                        <DraftPreview :nodes="block.fields" nested />
                    </div>
                    <div v-else-if="block.known" class="px-3 py-2 text-sm text-gray-500">{{ __('Uses its usual settings.') }}</div>
                </div>
            </div>

            <div v-else-if="node.kind === 'rows'" class="space-y-2">
                <div v-for="(row, index) in node.items" :key="index" class="rounded-md border border-gray-200 p-2.5 dark:border-gray-700">
                    <DraftPreview :nodes="row" nested />
                </div>
            </div>

            <div v-else-if="node.kind === 'group'" class="border-s-2 border-gray-200 ps-3 dark:border-gray-700">
                <DraftPreview :nodes="node.fields" nested />
            </div>

            <div v-else class="whitespace-pre-wrap">{{ node.text }}</div>
        </div>
    </div>
</template>
