<!--
    The Search section under the Text tab (SEO layer §9.5, decision 21):
    how the page may appear in search results, as "Use this draft" will
    put it in. Nothing here is saved until the draft is used.

    - SEO title: the page title while it uses it ("Give it its own" makes
      one, starting from the page title), or its own, click to edit, with
      "Use the page title".
    - Meta description: click to edit, with a live count that turns amber
      outside the range its tooltip gives. Where the entry's own stays, the
      draft's is offered with "Use this" ("Keep mine" takes it back); an
      inherited one that fits is shown as it is.
    - Address: the slug after the page's address, editable on a new page
      only.
    - Try again: one call for another title and description.

    Edits are saved on blur or Enter, as everywhere in the draft; Esc puts
    the text back. No edit calls a model. The rows' data and words come
    from the server (core's SearchSection, its `seo.search.*` strings).
-->
<script>
export default {
    props: {
        search: { type: Object, required: true },
        editable: { type: Boolean, default: false },
    },

    // `edit` {role, text, revert}; `use` {role, use}; `try-again`.
    emits: ['edit', 'use', 'try-again'],

    data() {
        return {
            // What is typed now, by role, for the live count.
            typed: {},
            // "Give it its own": the title, editable, before it is saved.
            givingOwn: false,
        };
    },

    computed: {
        strings() {
            return this.search.strings ?? {};
        },

        title() {
            return this.search.title;
        },

        description() {
            return this.search.description;
        },

        address() {
            return this.search.address;
        },

        titleOwn() {
            return !!this.title?.own || this.givingOwn;
        },

        titleText() {
            return this.title?.own ? this.title.text : this.title?.pageTitle ?? '';
        },

        // The description shown: Ghostwriter's (or the editor's) text, else the entry's own.
        descriptionText() {
            return this.description?.text || '';
        },

        descriptionEditable() {
            return this.editable && !!this.description?.editable;
        },

        // The entry's own description stays; the draft's is only offered.
        suggesting() {
            return this.description?.action === 'suggest' && this.descriptionText !== '';
        },

        // Inherited text that fits, nothing of Ghostwriter's: shown as it is.
        inherits() {
            return !this.descriptionText && !!this.description?.current;
        },
    },

    watch: {
        search() {
            this.typed = {};
            this.givingOwn = false;
        },
    },

    methods: {
        length(role, fallback) {
            return this.typed[role] ?? [...(fallback ?? '')].length;
        },

        count(row, role, fallback) {
            return `${this.length(role, fallback)} / ${row.limit}`;
        },

        // A title over its room; a description outside its range.
        out(row, role, fallback) {
            const n = this.length(role, fallback);

            if (this.typed[role] === undefined) return !!row.out;

            return role === 'title' ? n > row.max : n < row.min || n > row.max;
        },

        input(role, event) {
            this.typed = { ...this.typed, [role]: [...event.target.innerText.replace(/\n/g, ' ').trim()].length };
        },

        enter(event) {
            event.target.dataset.was = event.target.innerText;
        },

        leave(role, event) {
            const element = event.target;
            const was = element.dataset.was;
            const text = element.innerText.replace(/\s+/g, ' ').trim();

            delete element.dataset.was;

            // "Give it its own" left as the page title: nothing to save.
            if (role === 'title' && this.givingOwn && text === (this.title?.pageTitle ?? '').trim()) {
                this.givingOwn = false;
                this.typed = {};

                return;
            }

            if (was === undefined || (text === was.replace(/\s+/g, ' ').trim() && !(role === 'title' && this.givingOwn))) {
                const { [role]: _, ...rest } = this.typed;
                this.typed = rest;

                return;
            }

            this.$emit('edit', {
                role,
                text,
                revert: () => {
                    element.innerText = was;
                    const { [role]: _, ...rest } = this.typed;
                    this.typed = rest;
                },
            });
        },

        cancel(event) {
            if (event.target.dataset.was !== undefined) event.target.innerText = event.target.dataset.was;
            if (this.givingOwn && event.target.dataset.gwSearchText !== undefined) this.givingOwn = false;

            event.target.blur();
        },

        finish(event) {
            if (event.shiftKey || event.isComposing) return;

            event.preventDefault();
            event.target.blur();
        },

        // The title becomes editable, starting from the page title.
        giveOwn() {
            this.givingOwn = true;

            this.$nextTick(() => {
                const element = this.$refs.title;

                if (!element) return;

                element.focus();
                document.getSelection()?.selectAllChildren(element);
            });
        },
    },
};
</script>

<template>
    <section class="mt-8 max-w-3xl border-t border-gray-200 pt-4 dark:border-gray-700!" aria-labelledby="gw-search-heading" data-gw-search>
        <h3 id="gw-search-heading" class="text-sm font-semibold">{{ strings.heading }}</h3>
        <p class="mt-0.5 mb-3 text-sm text-gray-500">{{ strings.intro }}</p>

        <!-- SEO title -->
        <div v-if="title" class="grid grid-cols-1 gap-1 border-t border-dashed border-gray-200 py-2.5 first-of-type:border-t-0 sm:grid-cols-[8.5rem_minmax(0,1fr)] sm:gap-3 dark:border-gray-700!" data-gw-search-row="title">
            <div class="text-sm font-medium sm:pt-1">
                {{ title.heading }}
                <small v-if="search.via?.title" class="block text-xs font-normal text-gray-500">{{ search.via.title }}</small>
            </div>
            <div class="min-w-0">
                <div
                    v-if="titleOwn && title.editable"
                    ref="title"
                    class="min-h-7 text-sm wrap-anywhere"
                    :class="{ 'gw-editable': editable }"
                    :contenteditable="editable ? 'plaintext-only' : null"
                    :role="editable ? 'textbox' : null"
                    :aria-label="title.heading"
                    spellcheck="true"
                    data-gw-search-text
                    @focus="enter"
                    @input="input('title', $event)"
                    @blur="leave('title', $event)"
                    @keydown.esc.stop.prevent="cancel"
                    @keydown.enter="finish"
                    v-text="titleText"
                />
                <div v-else class="min-h-7 text-sm wrap-anywhere text-gray-500" data-gw-search-text>{{ title.own ? title.text : title.uses_text }}</div>
                <div class="mt-1 flex flex-wrap items-baseline gap-x-2.5 gap-y-0.5 text-xs text-gray-500">
                    <span
                        class="tabular-nums"
                        :class="{ 'text-amber-700 dark:text-amber-400!': out(title, 'title', titleText) }"
                        :title="title.range_text"
                        data-gw-search-count
                        :data-gw-search-out="out(title, 'title', titleText) ? 'true' : 'false'"
                    >{{ count(title, 'title', titleText) }}</span>
                    <span data-gw-search-note>{{ title.note_text }}</span>
                    <button v-if="!titleOwn && title.editable && editable" type="button" class="text-[var(--gw-accent)] underline underline-offset-2" data-gw-search-action="give-own" @click="giveOwn">{{ strings.give_own }}</button>
                    <button v-if="title.own && editable" type="button" class="text-[var(--gw-accent)] underline underline-offset-2" data-gw-search-action="use-page-title" @click="$emit('edit', { role: 'title', text: '' })">{{ strings.use_page_title }}</button>
                </div>
            </div>
        </div>

        <!-- Meta description -->
        <div v-if="description" class="grid grid-cols-1 gap-1 border-t border-dashed border-gray-200 py-2.5 first-of-type:border-t-0 sm:grid-cols-[8.5rem_minmax(0,1fr)] sm:gap-3 dark:border-gray-700!" data-gw-search-row="description">
            <div class="text-sm font-medium sm:pt-1">
                {{ description.heading }}
                <small v-if="search.via?.description" class="block text-xs font-normal text-gray-500">{{ search.via.description }}</small>
            </div>
            <div class="min-w-0">
                <p v-if="suggesting && !description.use" class="mb-1 text-sm text-gray-500" data-gw-search-note>{{ description.note_text }}</p>
                <div v-if="inherits" class="min-h-7 text-sm wrap-anywhere text-gray-500" data-gw-search-text>{{ description.current }}</div>
                <div
                    v-else
                    class="min-h-7 text-sm wrap-anywhere"
                    :class="{ 'gw-editable': descriptionEditable, 'text-gray-500': !descriptionText }"
                    :contenteditable="descriptionEditable ? 'plaintext-only' : null"
                    :role="descriptionEditable ? 'textbox' : null"
                    :aria-label="description.heading"
                    spellcheck="true"
                    data-gw-search-text
                    @focus="enter"
                    @input="input('description', $event)"
                    @blur="leave('description', $event)"
                    @keydown.esc.stop.prevent="cancel"
                    @keydown.enter="finish"
                    v-text="descriptionText"
                />
                <div class="mt-1 flex flex-wrap items-baseline gap-x-2.5 gap-y-0.5 text-xs text-gray-500">
                    <span
                        class="tabular-nums"
                        :class="{ 'text-amber-700 dark:text-amber-400!': out(description, 'description', inherits ? description.current : descriptionText) }"
                        :title="description.range_text"
                        data-gw-search-count
                        :data-gw-search-out="out(description, 'description', inherits ? description.current : descriptionText) ? 'true' : 'false'"
                    >{{ count(description, 'description', inherits ? description.current : descriptionText) }}</span>
                    <span v-if="!(suggesting && !description.use)" data-gw-search-note>{{ description.note_text }}</span>
                    <button v-if="suggesting && !description.use && editable" type="button" class="text-[var(--gw-accent)] underline underline-offset-2" data-gw-search-action="use-this" @click="$emit('use', { role: 'description', use: true })">{{ strings.use_this }}</button>
                    <button v-if="suggesting && description.use && editable" type="button" class="text-[var(--gw-accent)] underline underline-offset-2" data-gw-search-action="keep-mine" @click="$emit('use', { role: 'description', use: false })">{{ strings.keep_mine }}</button>
                </div>
            </div>
        </div>

        <!-- Address -->
        <div v-if="address" class="grid grid-cols-1 gap-1 border-t border-dashed border-gray-200 py-2.5 first-of-type:border-t-0 sm:grid-cols-[8.5rem_minmax(0,1fr)] sm:gap-3 dark:border-gray-700!" data-gw-search-row="address">
            <div class="text-sm font-medium sm:pt-1">
                {{ address.heading }}
                <small v-if="search.via?.address" class="block text-xs font-normal text-gray-500">{{ search.via.address }}</small>
            </div>
            <div class="min-w-0">
                <div class="font-mono text-[13px] wrap-anywhere" :class="{ 'text-gray-500': !address.editable }">
                    <span class="text-gray-500">{{ address.base }}</span><span
                        class="inline-block"
                        :class="{ 'gw-editable': editable && address.editable }"
                        :contenteditable="editable && address.editable ? 'plaintext-only' : null"
                        :role="editable && address.editable ? 'textbox' : null"
                        :aria-label="address.heading"
                        spellcheck="false"
                        data-gw-search-text
                        @focus="enter"
                        @blur="leave('slug', $event)"
                        @keydown.esc.stop.prevent="cancel"
                        @keydown.enter="finish"
                        v-text="address.slug"
                    />
                </div>
                <div class="mt-1 flex flex-wrap items-baseline gap-x-2.5 gap-y-0.5 text-xs text-gray-500"><span data-gw-search-note>{{ address.note_text }}</span></div>
            </div>
        </div>

        <!-- Try again -->
        <div v-if="search.fields" class="mt-3 flex flex-wrap items-center gap-x-3 gap-y-1">
            <button
                type="button"
                class="rounded-md border border-gray-300 px-2.5 py-1 text-sm hover:bg-gray-50 disabled:opacity-60 dark:border-gray-600! dark:hover:bg-gray-800!"
                :disabled="!editable || search.writing"
                :aria-busy="search.writing ? 'true' : 'false'"
                data-gw-search-action="try-again"
                @click="$emit('try-again')"
            >{{ search.writing ? strings.writing : strings.try_again }}</button>
            <span class="text-xs text-gray-500">{{ strings.try_again_note }}</span>
            <p v-if="search.failed" role="alert" class="w-full text-sm text-red-700 dark:text-red-400!" data-gw-search-failed>{{ search.failed }}</p>
        </div>
    </section>
</template>
