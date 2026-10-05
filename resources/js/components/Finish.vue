<!--
    Finish this page on an entry's publish form: owns the guide shell
    (finish/shell.js) and its Statamic adapter, checks the form's values as
    they change (800ms after typing stops; no model, no save), and opens the
    guide when a draft is put into the form. Renders nothing itself.

    Plain text inputs (a Grid's or a Table's cells, text fields) can't show
    a highlight inside them, so one whose value holds a gap marker gets a
    row of chips under it (finish/inputs.js); the field-level highlight
    stays.
-->
<script>
import { unref } from 'vue';
import { FinishGuide } from '../finish/shell.js';
import { watchInputs } from '../finish/inputs.js';
import { gapLabels } from '../preview/overlay.js';
import { statamicAdapter } from '../finish/statamic.js';
import { stock } from '../stock/store.js';
import { request } from '../stock/request.js';

export default {
    props: {
        collection: { type: String, required: true },
        entry: { type: String, default: null },
        blueprint: { type: String, default: null },
        form: { type: Object, required: true },
        baseUrl: { type: String, required: true },
    },

    data() {
        // links: what Suggest links found in this page view, by its token: sent with each check.
        return { timer: null, asked: 0, links: null };
    },

    mounted() {
        const config = Statamic.$config.get('ghostwriter')?.finish ?? {};
        const t = (text, params = {}) => __(text, params);

        this.guide = new FinishGuide({
            adapter: statamicAdapter({ form: this.form, baseUrl: this.baseUrl, payload: () => this.payload(), recheck: () => this.check(300), t, onLinks: (token) => { this.links = token; } }),
            t,
            state: config.guide,
            key: `${this.collection}.${this.entry ?? 'new'}`,
            onState: (state) => request(`${this.baseUrl}/finish/guide`, { method: 'POST', body: { state } }).catch(() => {}),
            // The count on the header menu (Launcher.vue).
            onCount: ({ count }) => Statamic.$events.$emit('ghostwriter.counts', { finish: count }),
        }).mount();

        // "Finish this page" in the header menu.
        this.show = () => this.guide.openFromMenu();
        Statamic.$events.$on('ghostwriter.finish.show', this.show);

        this.openAfterDraft = config.open_after_draft !== false;
        this.applied = ({ report }) => this.guide.update(report, { open: this.openAfterDraft });
        Statamic.$events.$on('ghostwriter.finish', this.applied);

        // Chips under plain text inputs whose value has a gap marker.
        this.inputs = watchInputs(document.querySelector('main') ?? document.body, { labels: gapLabels(t) });

        this.$watch(() => unref(this.form.values), () => {
            this.inputs.refresh();
            this.check();
        }, { deep: true });
        // A stock preview licensed, requested or refreshed: check again.
        this.$watch(() => stock.assets, () => this.check(300), { deep: true });

        this.check(50);
    },

    beforeUnmount() {
        clearTimeout(this.timer);
        Statamic.$events.$off('ghostwriter.finish', this.applied);
        Statamic.$events.$off('ghostwriter.finish.show', this.show);
        Statamic.$events.$emit('ghostwriter.counts', { finish: 0 });
        this.guide?.destroy();
        this.inputs?.stop();
    },

    methods: {
        payload() {
            const session = new URLSearchParams(window.location.search).get('ghostwriter');

            return {
                collection: this.collection,
                entry: this.entry,
                blueprint: this.blueprint,
                session: session && session !== 'new' ? session : null,
                site: unref(this.form.site) ?? null,
                values: JSON.parse(JSON.stringify(unref(this.form.values) ?? {})),
                links: this.links,
            };
        },

        // Debounced: the last of a burst of changes is the one checked, and
        // an answer that comes back after a newer question is dropped.
        check(wait = 800) {
            clearTimeout(this.timer);
            this.timer = setTimeout(async () => {
                const asked = ++this.asked;

                try {
                    const report = await request(`${this.baseUrl}/finish/check`, { method: 'POST', body: this.payload() });

                    if (asked === this.asked) this.guide.update(report);
                } catch (error) {
                    // The guide keeps what it had; the next change checks again.
                }
            }, wait);
        },
    },

    render() {
        return null;
    },
};
</script>
