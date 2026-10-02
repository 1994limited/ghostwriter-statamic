<!--
    Ghostwriter on the Control Panel dashboard: how set-up stands, pieces in
    progress, ideas waiting, and a way to start writing.
-->
<script>
import ghost from '../icon.js';
import { Badge, Button, Dropdown, DropdownItem, DropdownMenu, Panel } from '@statamic/cms/ui';

export default {
    components: { Badge, Button, Dropdown, DropdownItem, DropdownMenu, Panel },

    props: {
        title: { type: String, default: 'Ghostwriter' },
        inProgress: { type: Array, required: true },
        moreInProgress: { type: Number, default: 0 },
        planOpen: { type: Number, default: 0 },
        setup: { type: Object, required: true },
        configured: { type: Boolean, required: true },
        collections: { type: Array, required: true },
        urls: { type: Object, required: true },
    },

    computed: {
        ghost: () => ghost,

        percent() {
            return Math.round((this.setup.done / this.setup.total) * 100);
        },
    },

    methods: {
        stage(session) {
            return {
                failed: this.__('Failed'),
                working: this.__('Writing'),
                interview: this.__('Waiting on you'),
                draft: this.__('Draft ready'),
                in_form: this.__('In the form'),
                editing: this.__('Editing'),
            }[session.stage] ?? this.__('In progress');
        },
    },
};
</script>

<template>
    <Panel :heading="title" :icon="ghost">
        <div class="divide-y divide-gray-200 dark:divide-gray-700!">
            <a v-if="!setup.hidden && !setup.complete" :href="urls.setup" class="block px-4 py-3 hover:bg-gray-50! dark:hover:bg-gray-800!">
                <div class="flex items-center justify-between text-sm">
                    <span class="font-medium">{{ __('Get started') }} · {{ __(':done of :total', { done: setup.done, total: setup.total }) }}</span>
                    <span v-if="setup.next" class="truncate text-gray-500">{{ __('Next') }}: {{ setup.next.title }}</span>
                </div>
                <div class="mt-1.5 h-1 overflow-hidden rounded-full bg-gray-200 dark:bg-gray-700!">
                    <div class="h-full rounded-full" style="background: var(--gw-accent, #2b3a64)" :style="{ width: `${percent}%` }"></div>
                </div>
            </a>

            <div class="flex gap-6 px-4 py-3 text-sm">
                <a :href="urls.index" class="hover:underline!"><strong class="text-lg" style="color: var(--gw-accent, #2b3a64)">{{ inProgress.length + moreInProgress }}</strong> {{ __('in progress') }}</a>
                <a :href="urls.plan" class="hover:underline!"><strong class="text-lg" style="color: var(--gw-accent, #2b3a64)">{{ planOpen }}</strong> {{ __n('idea waiting|ideas waiting', planOpen) }}</a>
            </div>

            <ul v-if="inProgress.length">
                <li v-for="session in inProgress" :key="session.id">
                    <a :href="session.url" class="flex items-center justify-between gap-3 px-4 py-2 text-sm hover:bg-gray-50! dark:hover:bg-gray-800!">
                        <span class="min-w-0 flex-1 truncate">{{ session.title }}</span>
                        <Badge :text="stage(session)" :color="session.stage === 'failed' ? 'red' : session.stage === 'working' ? 'blue' : 'gray'" />
                    </a>
                </li>
                <li v-if="moreInProgress" class="px-4 py-2 text-xs text-gray-500">{{ __('and :count more', { count: moreInProgress }) }}</li>
            </ul>

            <div class="flex items-center justify-between gap-2 px-4 py-3">
                <Dropdown v-if="collections.length">
                    <template #trigger>
                        <Button size="sm" variant="primary" :icon="ghost" :text="__('Write something')" :disabled="!configured" />
                    </template>
                    <DropdownMenu>
                        <DropdownItem v-for="collection in collections" :key="collection.handle" :text="collection.title" :href="collection.url" />
                    </DropdownMenu>
                </Dropdown>
                <Button size="sm" variant="ghost" :href="urls.index" :text="__('Open Ghostwriter')" />
            </div>
        </div>
    </Panel>
</template>
