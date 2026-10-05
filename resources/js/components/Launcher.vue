<!--
    Sits on an entry's create or edit form. A button beside Save opens the Ghostwriter
    panel in a slide-over; when a draft is applied, its values are written into
    the form underneath, which the person then reviews and saves as usual. Any
    notes on the draft are listed in a notice above the form until it is closed.

    The menu beside the button carries one count: what is left to finish
    plus the suggestions to review, amber while anything is left to finish
    (that stops publishing), plain with only suggestions, none at 0. It lists
    Finish this page and Review suggestions with their counts, each only
    while it has one, then Suggest edits (the button itself isn't repeated;
    with nothing in the menu, the chevron goes). The counts come from the
    guides (Finish.vue and Suggest.vue, the same live lists as their docks)
    as `ghostwriter.counts` events; the rows open them with
    `ghostwriter.finish.show` and `ghostwriter.suggest.show`.
-->
<script>
import ghost from '../icon.js';
import { Alert, Badge, Button, ButtonGroup, Dropdown, DropdownItem, DropdownLabel, DropdownMenu, DropdownSeparator, Stack } from '@statamic/cms/ui';
import Panel from './Panel.vue';

export default {
    components: { Alert, Badge, Button, ButtonGroup, Dropdown, DropdownItem, DropdownLabel, DropdownMenu, DropdownSeparator, Panel, Stack },

    props: {
        collection: { type: String, required: true },
        // The entry being edited; null on a create form.
        entry: { type: String, default: null },
        form: { type: Object, required: true },
        baseUrl: { type: String, required: true },
    },

    data() {
        const query = new URLSearchParams(window.location.search);
        // "suggest" is Suggest edits' (Content to revisit's Review), not a piece.
        const requested = query.get('ghostwriter') === 'suggest' ? null : query.get('ghostwriter');

        return {
            // The form's blueprint, on collections that have more than one.
            blueprint: query.get('blueprint'),
            // An idea from the content plan to start from.
            idea: query.get('idea'),
            open: requested !== null,
            starting: false,
            // "new" opens a fresh panel; anything else is a session to resume.
            resume: requested && requested !== 'new' ? requested : null,
            slot: null,
            // The notes on the draft last used: { heading, notes }, shown
            // above the form until closed.
            notice: null,
            noticeSlot: null,
            // The menu's counts, from the guides: { finish, suggestions, reviewing }.
            counts: { finish: 0, suggestions: 0, reviewing: false },
        };
    },

    created() {
        this.onCounts = (counts) => (this.counts = { ...this.counts, ...counts });
        Statamic.$events.$on('ghostwriter.counts', this.onCounts);
    },

    mounted() {
        // The publish form owns its header, so the button is teleported into
        // a slot placed just before the save button. If the header cannot be
        // found the button floats in the corner instead.
        const save = document.querySelector('header[data-ui-header] [data-ui-button-group]')?.parentElement;

        if (save?.parentElement) {
            const slot = document.createElement('div');
            slot.dataset.ghostwriter = 'launcher';
            save.parentElement.insertBefore(slot, save);
            this.slot = slot;
        }

        // The notes go just under the page header, above the form. Without
        // the header they float in the corner instead.
        const header = document.querySelector('header[data-ui-header]');

        if (header?.parentElement) {
            const slot = document.createElement('div');
            slot.dataset.ghostwriter = 'notice';
            header.after(slot);
            this.noticeSlot = slot;
        }
    },

    beforeUnmount() {
        Statamic.$events.$off('ghostwriter.counts', this.onCounts);
        this.slot?.remove();
        this.noticeSlot?.remove();
    },

    computed: {
        ghost: () => ghost,

        label() {
            return this.entry ? this.__('Edit with Ghostwriter') : this.__('Write with Ghostwriter');
        },

        // Whether the menu has a count row to show.
        rows() {
            return !!(this.counts.finish || this.counts.suggestions || this.counts.reviewing);
        },

        total() {
            return this.counts.finish + this.counts.suggestions;
        },

        // The count, read out: "10 items: 3 to finish, 7 suggestions".
        totalLabel() {
            const { finish, suggestions } = this.counts;
            const toFinish = this.__(':count to finish', { count: finish });
            const suggested = suggestions === 1 ? this.__('1 suggestion') : this.__(':count suggestions', { count: suggestions });

            if (finish && suggestions) return this.__(':count items: :finish, :suggestions', { count: finish + suggestions, finish: toFinish, suggestions: suggested });

            return finish ? toFinish : suggested;
        },

        menuLabel() {
            const name = this.entry ? this.__('More ways to edit with Ghostwriter') : this.__('More from Ghostwriter');

            return this.total ? `${name} (${this.totalLabel})` : name;
        },
    },

    methods: {
        // What the form holds now, unsaved typing included: editing starts
        // from it, and changes are put back over it.
        formValues() {
            return JSON.parse(JSON.stringify(this.form.values ?? {}));
        },

        // Suggest edits, from the menu: its confirm says what it costs first.
        suggest() {
            Statamic.$events.$emit('ghostwriter.suggest.open');
        },

        // "Finish this page" or "Review suggestions": that guide, open.
        show(guide) {
            Statamic.$events.$emit(`ghostwriter.${guide}.show`);
        },

        // On an existing entry, the conversation starts from the entry as
        // its form holds it now.
        async launch() {
            if (!this.entry) {
                this.open = true;

                return;
            }

            this.starting = true;

            try {
                const { data } = await this.$axios.post(`${this.baseUrl}/entries/${this.entry}/session`, { values: this.formValues() });

                this.resume = data.id;
                this.open = true;
            } catch (error) {
                this.$toast.error(error.response?.data?.message ?? this.__('Something went wrong.'));
            } finally {
                this.starting = false;
            }
        },

        // The piece open in the panel. On a create screen, the button
        // carries on with it after "Use this draft" or a close, as Craft
        // does, and the address names it so a reload carries on too.
        // Editing an entry starts from the form each time instead.
        remember(id) {
            if (this.entry) return;

            this.resume = id;
            this.idea = null;

            try {
                const url = new URL(window.location.href);

                if (id) url.searchParams.set('ghostwriter', id);
                else url.searchParams.delete('ghostwriter');

                url.searchParams.delete('idea');
                window.history.replaceState(window.history.state, '', url);
            } catch (error) {
                // The address just isn't updated.
            }
        },

        // A draft starts unpublished, so the entry can be saved straight
        // away (a page holding an unlicensed stock preview couldn't be
        // published anyway) and an AI draft never goes live by accident.
        // Only on a new entry, or one that isn't published: switching off
        // a live entry would take it offline. It is the form's own toggle,
        // so the editor sees it off and can switch it on.
        startUnpublished() {
            if (!Statamic.$config.get('ghostwriter')?.drafts_unpublished) return false;
            if (this.entry && this.form.values?.published !== false) return false;

            this.form.setFieldValue('published', false);

            return true;
        },

        // The address for a new entry (the Search section's): into
        // Statamic's own slug field, after the title. The field makes a slug
        // from a new title a moment later (a request), which would replace
        // it, so it is set again once that has settled, unless the editor
        // is in the field by then.
        setSlug(slug) {
            const set = () => {
                const field = document.getElementById('field_slug');

                if (field && field.contains(document.activeElement)) return;
                if (this.form.values?.slug !== slug) this.form.setFieldValue('slug', slug);
            };

            this.$nextTick(set);
            [400, 1200, 2500].forEach((wait) => setTimeout(set, wait));
        },

        apply({ values, meta, notes, gaps }) {
            // Field by field, so everything the draft does not cover keeps the
            // value and meta it already had. Meta goes first: fields such as
            // Bard and Replicator read it as soon as their value arrives.
            Object.entries(meta).forEach(([handle, value]) => this.form.setFieldMeta(handle, value));
            Object.entries(values).forEach(([handle, value]) => handle !== 'slug' && this.form.setFieldValue(handle, value));

            if (typeof values.slug === 'string' && values.slug !== '') this.setSlug(values.slug);

            const unpublished = this.startUnpublished();

            this.open = false;

            // What the draft left for a person becomes Finish this page's
            // steps, so those notes needn't be listed again.
            if (gaps?.gaps?.length) {
                Statamic.$events.$emit('ghostwriter.finish', { report: gaps });
                notes = notes.filter((note) => !/^(A striped placeholder marks|Still to set by hand|Still to choose by hand|Still to add by hand)/.test(note));
            }

            const done = this.entry ? this.__('Changes added to the form. Check them over, then save.') : this.__('Draft added to the form. Check it over, then save.');
            const hint = unpublished ? this.__('Ghostwriter drafts start unpublished. Switch on Published when you\'re ready.') : null;

            if (!notes.length) {
                this.notice = null;
                this.$toast.success(hint ? `${done} ${hint}` : done);

                return;
            }

            // The notes are a to-do list, so they come as one notice that
            // stays until it is closed. A toast has a fixed height, which a
            // list of notes soon outgrows, so the notice sits above the form.
            this.notice = { heading: done, hint, notes };

            this.$nextTick(() => this.$refs.notice?.scrollIntoView?.({ block: 'nearest', behavior: 'smooth' }));
        },
    },
};
</script>

<template>
    <div>
        <Teleport v-if="slot" :to="slot">
            <!-- On a phone or tablet the header has no room for the label beside Save, so only the ghost shows. -->
            <ButtonGroup>
                <Button :icon="ghost" :text="label" :title="label" :loading="starting" class="max-lg:gap-0! max-lg:px-3! max-lg:[&>div]:sr-only!" @click="launch" />
                <!-- With nothing in the menu (a new entry with nothing to count) the button stands alone. -->
                <Dropdown v-if="entry || rows" align="end">
                    <template #trigger>
                        <!-- One button whatever the count, so the menu stays anchored to it. -->
                        <Button icon-append="chevron-down" :aria-label="menuLabel" :title="total ? totalLabel : null" class="min-w-9 gap-1.5! px-2.5!" data-ghostwriter-menu>
                            <Badge v-if="total" :text="total" :color="counts.finish ? 'amber' : 'default'" size="sm" pill role="img" :aria-label="totalLabel" class="tabular-nums" data-ghostwriter-menu-count />
                        </Button>
                    </template>
                    <DropdownMenu>
                        <template v-if="rows">
                            <DropdownItem v-if="counts.finish" icon="clipboard-check" data-ghostwriter-menu-finish @click="show('finish')">
                                <span class="flex items-center justify-between gap-4">{{ __('Finish this page') }}<Badge :text="counts.finish" color="amber" size="sm" pill aria-hidden="true" class="tabular-nums" /></span>
                            </DropdownItem>
                            <DropdownItem v-if="counts.suggestions || counts.reviewing" icon="ai-chat-spark" data-ghostwriter-menu-review @click="show('suggest')">
                                <span class="flex items-center justify-between gap-4">{{ __('Review suggestions') }}<Badge v-if="counts.suggestions" :text="counts.suggestions" size="sm" pill aria-hidden="true" class="tabular-nums" /><span v-else class="text-xs text-gray-500">{{ __('Reviewing…') }}</span></span>
                            </DropdownItem>
                            <DropdownSeparator v-if="entry" />
                        </template>
                        <template v-if="entry">
                            <DropdownItem :text="__('Suggest edits')" icon="checkmark" data-ghostwriter-suggest @click="suggest" />
                            <DropdownLabel :text="__('Reads the page against your voice guide and checks each suggestion twice. Uses Ghostwriter.')" class="max-w-64 whitespace-normal!" />
                        </template>
                    </DropdownMenu>
                </Dropdown>
            </ButtonGroup>
        </Teleport>

        <div v-else class="fixed end-6 bottom-6 z-10">
            <Button :icon="ghost" :text="label" :loading="starting" @click="launch" />
        </div>

        <Teleport v-if="notice" :to="noticeSlot" :disabled="!noticeSlot">
            <div ref="notice" :class="noticeSlot ? 'mb-6' : 'fixed end-6 bottom-20 z-10 max-h-[50vh] w-[28rem] max-w-[calc(100vw-3rem)] overflow-y-auto'" data-ghostwriter-notes>
                <Alert variant="default" icon="info">
                    <div class="flex items-start gap-3">
                        <div class="min-w-0 flex-1">
                            <strong class="block">{{ notice.heading }}</strong>
                            <p v-if="notice.hint" class="mt-0.5">{{ notice.hint }}</p>
                            <ul class="mt-1.5 list-disc ps-5">
                                <li v-for="(note, i) in notice.notes" :key="i">{{ note }}</li>
                            </ul>
                        </div>
                        <Button variant="ghost" size="sm" icon="x" :aria-label="__('Close')" class="-me-1 -mt-1 shrink-0" @click="notice = null" />
                    </div>
                </Alert>
            </div>
        </Teleport>

        <Stack v-model:open="open" :title="__('Ghostwriter')" :icon="ghost" size="full">
            <Panel v-if="open" :collection="collection" :blueprint="blueprint" :base-url="baseUrl" :resume="resume" :idea="idea" :entry="entry" :form-values="formValues" @apply="apply" @session="remember" />
        </Stack>
    </div>
</template>
