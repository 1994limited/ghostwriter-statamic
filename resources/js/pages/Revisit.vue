<!--
    Content to revisit: published pages ranked by free checks (dates,
    links, alt text, empty fields and age), with why, a priority (a bar
    and a word, so it isn't colour alone) and Review, which opens the page
    with Suggest edits ready to run. No AI until someone reviews a page.
    A real table on wide screens, cards on a phone.
-->
<script>
import ghost from '../icon.js';
import { Head, Link, router } from '@statamic/cms/inertia';
import { Alert, Button, Dropdown, DropdownItem, DropdownMenu, Header, Panel } from '@statamic/cms/ui';
import { request } from '../stock/request.js';

export default {
    components: { Alert, Button, Dropdown, DropdownItem, DropdownMenu, Head, Header, Link, Panel },

    props: {
        tiles: { type: Array, required: true },
        tile: { type: String, default: null },
        collection: { type: String, default: null },
        collections: { type: Array, default: () => [] },
        all_url: { type: String, required: true },
        rows: { type: Array, required: true },
        page: { type: Number, default: 1 },
        pages: { type: Number, default: 1 },
        total: { type: Number, default: 0 },
        note: { type: String, default: '' },
        empty: { type: String, default: '' },
        reading: { type: Boolean, default: false },
        last_run: { type: String, default: null },
        external_links: { type: Boolean, default: false },
        settings_url: { type: String, default: null },
        snooze_url: { type: String, required: true },
    },

    data() {
        return { list: [...this.rows], said: '' };
    },

    computed: {
        ghost: () => ghost,
    },

    watch: {
        rows(rows) {
            this.list = [...rows];
        },
    },

    methods: {
        shown(row) {
            return row.reasons.slice(0, 3);
        },

        more(row) {
            return Math.max(0, row.reasons.length - 3);
        },

        pageUrl(n) {
            const url = new URL(window.location.href);
            url.searchParams.set('page', n);

            return url.toString();
        },

        async snooze(row) {
            try {
                await request(this.snooze_url, { method: 'POST', body: { key: row.key } });
                this.list = this.list.filter((other) => other.key !== row.key);
                this.said = this.__(':title is snoozed for 90 days.', { title: row.title });
                Statamic.$toast.success(this.said);
            } catch (error) {
                Statamic.$toast.error(error.message);
            }
        },
    },
};
</script>

<template>
    <Head :title="__('Content to revisit')" />

    <div class="mx-auto max-w-6xl" data-ghostwriter-revisit>
        <Header :title="__('Content to revisit')" :icon="ghost" />

        <p class="mb-4 text-sm text-gray-500">{{ note }}</p>

        <Alert v-if="reading" variant="default" class="mb-4" :text="__('Looking through your pages for the first time. This takes a minute or two on a large site; reload to see the list.')" />

        <nav class="mb-4 grid grid-cols-2 gap-3 md:grid-cols-4" :aria-label="__('Show')">
            <Link
                v-for="item in tiles"
                :key="item.key"
                :href="item.url"
                :aria-current="item.key === tile ? 'true' : null"
                class="rounded-xl border bg-white p-3 hover:border-gray-400! dark:bg-gray-850! dark:hover:border-gray-500!"
                :class="item.key === tile ? 'border-indigo-500! ring-1 ring-indigo-500' : 'border-gray-200 dark:border-gray-700!'"
                data-ghostwriter-revisit-tile
            >
                <span class="block text-2xl font-medium tabular-nums">{{ item.count }}</span>
                <span class="text-xs text-gray-500">{{ item.label }}</span>
            </Link>
        </nav>

        <div v-if="collections.length > 1" class="mb-3 flex flex-wrap items-center gap-2 text-sm">
            <span class="text-gray-500">{{ __('In') }}</span>
            <Link :href="all_url" class="rounded-full border px-2.5 py-0.5" :class="collection ? 'border-gray-200 text-gray-500 dark:border-gray-700!' : 'border-gray-900 font-medium dark:border-white!'">{{ __('All collections') }}</Link>
            <Link v-for="item in collections" :key="item.handle" :href="item.url" class="rounded-full border px-2.5 py-0.5" :class="item.handle === collection ? 'border-gray-900 font-medium dark:border-white!' : 'border-gray-200 text-gray-500 dark:border-gray-700!'">{{ item.title }}</Link>
        </div>

        <Panel>
            <p v-if="!list.length" class="p-6 text-sm text-gray-500">{{ empty }}</p>

            <table v-else class="w-full text-sm max-md:hidden">
                <caption class="sr-only">{{ __('Pages to revisit, most important first') }}</caption>
                <thead class="text-start text-xs tracking-wide text-gray-500 uppercase">
                    <tr class="border-b border-gray-200 dark:border-gray-700!">
                        <th scope="col" class="px-4 py-2 text-start font-medium">{{ __('Page') }}</th>
                        <th scope="col" class="px-4 py-2 text-start font-medium whitespace-nowrap">{{ __('Last updated') }}</th>
                        <th scope="col" class="px-4 py-2 text-start font-medium">{{ __('Why') }}</th>
                        <th scope="col" class="px-4 py-2 text-start font-medium">{{ __('Priority') }}</th>
                        <th scope="col" class="px-4 py-2"><span class="sr-only">{{ __('Actions') }}</span></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 dark:divide-gray-700!">
                    <tr v-for="row in list" :key="row.key" data-ghostwriter-revisit-row>
                        <td class="px-4 py-3">
                            <span class="block font-medium">{{ row.title }}</span>
                            <span class="text-xs text-gray-500">{{ row.collection }}</span>
                        </td>
                        <td class="px-4 py-3 whitespace-nowrap text-gray-600 dark:text-gray-300!"><time :datetime="row.updated_iso">{{ row.updated }}</time></td>
                        <td class="px-4 py-3">
                            <ul class="flex flex-wrap gap-1" :aria-label="__('Why')">
                                <li v-for="reason in shown(row)" :key="reason.kind" class="rounded-md px-1.5 py-0.5 text-xs" :class="{ 'bg-red-50 text-red-800 dark:bg-red-950/40! dark:text-red-200!': reason.severity === 'high', 'bg-amber-50 text-amber-800 dark:bg-amber-950/40! dark:text-amber-200!': reason.severity === 'medium', 'bg-gray-100 text-gray-700 dark:bg-gray-800! dark:text-gray-300!': reason.severity === 'low' }">{{ reason.text }}</li>
                                <li v-if="more(row)" class="px-1 py-0.5 text-xs text-gray-500">+{{ more(row) }}</li>
                            </ul>
                        </td>
                        <td class="px-4 py-3">
                            <div class="flex items-center gap-2">
                                <span class="h-1.5 w-16 overflow-hidden rounded-full bg-gray-200 dark:bg-gray-700!" aria-hidden="true"><span class="block h-full rounded-full bg-amber-500" :style="{ width: `${Math.min(100, row.score)}%` }"></span></span>
                                <span class="text-xs">{{ row.priority_label }}</span>
                            </div>
                        </td>
                        <td class="px-4 py-3">
                            <div class="flex items-center justify-end gap-1">
                                <Button v-if="row.review_url" :href="row.review_url" size="sm" variant="primary" :icon="ghost" :text="__('Review')" :aria-label="__('Review :title', { title: row.title })" :title="__('Opens the page ready to review. Reviewing uses Ghostwriter.')" />
                                <Dropdown align="end">
                                    <template #trigger>
                                        <Button size="sm" variant="ghost" icon="dots" :aria-label="__('More for :title', { title: row.title })" />
                                    </template>
                                    <DropdownMenu>
                                        <DropdownItem :text="__('Snooze for 90 days')" @click="snooze(row)" />
                                        <DropdownItem v-if="row.edit_url" :text="__('Open without reviewing')" :href="row.edit_url" />
                                    </DropdownMenu>
                                </Dropdown>
                            </div>
                        </td>
                    </tr>
                </tbody>
            </table>

            <ul v-if="list.length" class="divide-y divide-gray-200 md:hidden dark:divide-gray-700!">
                <li v-for="row in list" :key="row.key" class="space-y-2 p-4" data-ghostwriter-revisit-card>
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <span class="block font-medium">{{ row.title }}</span>
                            <span class="text-xs text-gray-500">{{ row.collection }}<template v-if="row.updated"> · {{ row.updated }}</template></span>
                        </div>
                        <span class="shrink-0 text-xs">{{ row.priority_label }}</span>
                    </div>
                    <ul class="flex flex-wrap gap-1" :aria-label="__('Why')">
                        <li v-for="reason in row.reasons" :key="reason.kind" class="rounded-md bg-gray-100 px-1.5 py-0.5 text-xs text-gray-700 dark:bg-gray-800! dark:text-gray-300!">{{ reason.text }}</li>
                    </ul>
                    <div class="flex flex-wrap gap-2">
                        <Button v-if="row.review_url" :href="row.review_url" size="sm" variant="primary" :icon="ghost" :text="__('Review')" :aria-label="__('Review :title', { title: row.title })" />
                        <Button size="sm" :text="__('Snooze for 90 days')" @click="snooze(row)" />
                    </div>
                </li>
            </ul>
        </Panel>

        <nav v-if="pages > 1" class="mt-4 flex items-center justify-between text-sm" :aria-label="__('Pages')">
            <Link v-if="page > 1" :href="pageUrl(page - 1)">← {{ __('Previous') }}</Link><span v-else></span>
            <span class="text-gray-500">{{ __('Page :page of :pages', { page, pages }) }}</span>
            <Link v-if="page < pages" :href="pageUrl(page + 1)">{{ __('Next') }} →</Link><span v-else></span>
        </nav>

        <p class="mt-6 text-xs text-gray-500">
            <template v-if="last_run">{{ __('Checked :ago.', { ago: last_run }) }} </template>
            {{ external_links ? __('Links to other sites are checked once a week.') : __('Links to other sites aren\'t checked (off in the settings).') }}
            <a v-if="settings_url" :href="settings_url" class="underline">{{ __('Settings') }}</a>
        </p>
        <p class="sr-only" aria-live="polite">{{ said }}</p>
    </div>
</template>
