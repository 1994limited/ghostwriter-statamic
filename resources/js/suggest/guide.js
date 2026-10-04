// Suggest edits in the Finish this page guide: the same shell (the pill by
// Save, the flying mark, the dock, the highlights and the keyboard), with
// a review's suggestions as its steps. Each step shows the category, the
// reason and where it comes from, a before/after, and the actions for its
// kind: Accept, Edit, Another version, Dismiss; a fact's answer box with
// It's still right and Remove the number; Link to it; Save to the image.
// Category filters, Accept all wording fixes and Undo all sit around them.
//
// Every change goes into the publish form (the adapter), never a save; the
// one exception is alt text, saved to the asset after its own confirm.
// Decisions are shared through the server (api), and kept as the page's
// history.
import { FinishGuide, MARK, el } from '../finish/shell.js';
import { counts as finishCounts } from '../finish/state.js';
import { counts, fieldStates, fillFact, filters, following, isOpen, nextOpen, previous, stepsFrom, tagText, versionsOf, wordingFixes } from './steps.js';
import { plain } from './markdown.js';

const SPEECH = ['Your turn', 'Over here', 'This one'];

export class SuggestGuide extends FinishGuide {
    /**
     * @param {object} options
     * @param {object} options.adapter  suggest/statamic.js: locate, reveal, replace, undo, link, unlink, choose, present, applied, highlight, tagHost.
     * @param {object} options.api  { decide(list), another(step), saveAlt(step, alt), undoAlt(step, before) }, all promises.
     * @param {function} options.t
     * @param {function} options.onStart  Asks for a review (the confirm, then start).
     * @param {string} options.key
     */
    constructor({ adapter, api, t, onStart = () => {}, key = 'page' }) {
        const sadapter = adapter;

        super({
            adapter: {
                locate: (step) => sadapter.locate(step),
                reveal: (step) => sadapter.reveal(step),
                tagHost: (field) => sadapter.tagHost(field),
                highlight: () => {},
            },
            t,
            state: 'minimised',
            key: `suggest.${key}`,
        });

        this.sadapter = sadapter;
        this.api = api;
        this.onStart = onStart;
        this.local = new Map();
        this.filter = 'all';
        this.data = { suggestions: [], review: null, running: false };
        this.versionAt = new Map();
        this.undoAll = null;
        this.last = null;
        this.status = 'idle';
    }

    mount(pillHost) {
        super.mount(pillHost);

        this.root.classList.add('is-suggest');
        this.root.setAttribute('data-gw-suggest-guide', '');
        this.pill.classList.add('gw-s-pill');
        this.pill.onclick = () => this.openAt(this.steps.length ? this.nextOpen(-1) : 0);
        this.dock.classList.add('gw-s-dock');
        this.panel.setAttribute('aria-label', this.t('Suggested edits'));
        this.panel.querySelector('.gw-f-head strong').textContent = this.t('Suggested edits');

        this.status_ = el('p', { class: 'gw-s-status', hidden: true });
        this.filtersEl = el('div', { class: 'gw-s-filters', role: 'group', 'aria-label': this.t('Show suggestions') });
        this.panel.querySelector('.gw-f-head').after(this.status_, this.filtersEl);

        this.acceptAllButton = el('button', { type: 'button', class: 'gw-s-all', onclick: () => (this.undoAll ? this.undoAllNow() : this.acceptAll()) });
        this.foot.replaceChildren(
            el('button', { type: 'button', text: `← ${this.t('Back')}`, onclick: () => this.back() }),
            this.acceptAllButton,
            el('button', { type: 'button', text: `${this.t('Next')} →`, onclick: () => this.next() }),
        );

        return this;
    }

    // -- The review, from the server ------------------------------------------

    /**
     * A new answer from the server: the free findings, or a review's
     * suggestions with their states. `open` brings the guide out (it was
     * just asked for); a stored review shows only its pill.
     */
    receive(data, { open = false, show = false } = {}) {
        const before = this.steps[this.index]?.id;
        const wasRunning = this.data?.running;

        this.data = data;
        this.status = data.running ? 'running' : data.review?.status === 'failed' ? 'failed' : data.review ? 'ready' : 'free';

        // What this page view knows and the server doesn't yet: a decision
        // made here is the server's once it says so.
        data.suggestions.forEach((s) => {
            const mine = this.local.get(s.id);

            if (mine && !mine.pending && s.state === mine.state) mine.synced = true;
        });

        this.steps = stepsFrom(data.suggestions, new Map([...this.local].map(([id, mine]) => [id, mine])));
        this.steps.forEach((step) => (step.gap = step));
        this.recheck(false);

        const same = this.steps.findIndex((step) => step.id === before);
        this.index = same >= 0 ? same : this.nextOpen(-1);

        if (show || open) this.shown = true;

        if (wasRunning && !data.running) {
            const left = counts(this.steps).open;

            this.announce(data.review?.status === 'failed'
                ? this.t('The review stopped before it finished.')
                : left ? this.t(':count suggestions ready.', { count: left }) : this.t('Nothing to suggest. The page reads well against your guide.'));
        }

        if (open) {
            this.index = this.nextOpen(-1);
            this.restore(true);

            return;
        }

        this.paint();
    }

    /**
     * The form changed: each open suggestion's words are looked for again.
     * Found, it stays; the new words there instead, it's accepted; neither,
     * it's stale ("This text has changed since the review").
     */
    recheck(repaint = true) {
        this.steps.forEach((step) => {
            if (step.scope !== 'range' || !step.quote || ['dismissed', 'confirmed', 'done'].includes(step.state)) return;

            const mine = this.local.get(step.id);

            if (mine?.state === 'accepted' && mine.change) return;

            const here = this.sadapter.present(step);

            // Accepted in an earlier visit, but never saved: the words
            // aren't in the page, so it's open again.
            if (step.state === 'accepted' && !mine && here && !versionsOf(step).some((words) => this.sadapter.applied(step, words))) {
                step.state = 'open';
                step.unsaved = true;

                return;
            }

            if (here) {
                step.stale = step.state === 'stale';
            } else if (versionsOf(step).some((words) => this.sadapter.applied(step, words))) {
                step.state = 'accepted';
                step.stale = false;
            } else {
                step.stale = true;
            }
        });

        if (repaint) this.paint();
    }

    // -- Moving -------------------------------------------------------------

    nextOpen(from) {
        return nextOpen(this.steps, from, this.filter);
    }

    firstOpen() {
        return this.nextOpen(-1);
    }

    next() {
        this.clearLast();
        this.go(following(this.steps, this.index, this.filter));
    }

    back() {
        this.clearLast();
        this.go(previous(this.steps, this.index, this.filter));
    }

    again() {
        this.local.forEach((mine, id) => mine.state === 'dismissed' && !mine.synced && this.local.delete(id));
        this.go(this.nextOpen(-1) < this.steps.length ? this.nextOpen(-1) : 0);
    }

    advance() {
        this.go(this.nextOpen(this.index));
    }

    setFilter(key) {
        this.filter = key;
        this.clearLast();

        const shown = this.steps.map((step, i) => i).filter((i) => key === 'all' || this.steps[i].category === key);
        const open = shown.find((i) => isOpen(this.steps[i]));

        this.index = open ?? shown[0] ?? this.steps.length;
        this.announce(key === 'all'
            ? this.t('Showing all: :count open.', { count: counts(this.steps).open })
            : this.t('Showing :label: :n of :total.', { label: this.steps[shown[0]]?.label ?? key, n: shown.filter((i) => isOpen(this.steps[i])).length, total: this.steps.length }));
        this.paint({ focus: false, fly: true });
    }

    clearLast() {
        this.last = null;
        this.undoAll = null;
    }

    // -- Drawing ------------------------------------------------------------

    pillLabel(numbers) {
        if (this.status === 'running') return this.t('Reviewing…');
        if (this.status === 'failed' && !numbers.open) return this.t('Review failed');
        if (this.status === 'ready' && !this.steps.length) return this.t('Nothing to suggest');
        if (!numbers.open) return this.t('All reviewed');

        const label = numbers.open === 1 ? this.t('1 suggestion') : this.t(':count suggestions', { count: numbers.open });
        const review = this.data.review;

        if (review && !review.mine && review.by && review.ago) return `${label} · ${this.t('by :name, :ago', { name: review.by, ago: review.ago })}`;

        return label;
    }

    paint({ focus = false, fly = false } = {}) {
        const numbers = counts(this.steps);
        const visible = this.shown && (this.steps.length > 0 || this.status !== 'idle');
        const label = this.pillLabel(numbers);
        const done = !numbers.open && this.status !== 'running';

        this.pill.hidden = !visible;
        this.pill.classList.toggle('is-ready', done && this.steps.length > 0);
        this.pill.classList.toggle('is-running', this.status === 'running');
        this.pillText.textContent = label;
        this.pillCount.textContent = numbers.open ? String(numbers.open) : '✓';
        this.pill.setAttribute('aria-label', `${this.t('Suggested edits')}: ${label}`);
        this.pill.title = this.t('Alt+Shift+G opens or minimises the guide');

        this.dock.hidden = !visible || !this.minimised;
        this.dock.setAttribute('aria-label', `${this.t('Suggested edits')}: ${label}`);
        this.badge.textContent = numbers.open ? String(numbers.open) : '✓';
        this.badge.classList.toggle('is-ready', !numbers.open);
        this.tip.textContent = label;

        this.panel.hidden = !visible || this.minimised;
        this.root.classList.toggle('is-phone', this.phone);
        this.panel.classList.toggle('is-collapsed', this.phone && !this.sheetOpen);

        if (visible) this.highlight();
        else this.unhighlight();

        const current = !this.minimised && this.index < this.steps.length ? this.steps[this.index] : null;
        this.sadapter.highlight(visible ? this.steps : [], current);

        if (this.panel.hidden) {
            this.flyer.hidden = true;

            return;
        }

        this.drawStatus();
        this.drawFilters();
        this.drawBar();
        this.drawFoot();

        if (this.index >= this.steps.length) {
            this.drawEnd(numbers);
            this.flyHome();
            if (focus) this.message?.focus();

            return;
        }

        const step = this.steps[this.index];
        const shown = this.steps.filter((s, i) => this.filter === 'all' || s.category === this.filter);

        this.stepText.textContent = this.t(':n of :total', { n: shown.indexOf(step) + 1, total: shown.length });
        this.drawStep(step);
        this.summary.textContent = [this.stepText.textContent, step.label, `${this.t('Next')} →`].join(' · ');

        if (focus) {
            this.message.focus({ preventScroll: true });
            this.announce(`${this.t('Suggestion :n of :total.', { n: shown.indexOf(step) + 1, total: shown.length })} ${step.label}. ${step.reason}`);
        }

        if (fly) this.reveal(step);
        else this.follow();
    }

    drawStatus() {
        const review = this.data.review;
        let text = '';

        if (this.status === 'running') text = review?.status === 'queued' ? this.t('Waiting to start…') : this.t('Reviewing… then double-checking. Ghostwriter reads each thing it found in its paragraph, then checks every suggestion again before you see it.');
        else if (this.status === 'failed') text = this.t('I couldn\'t finish reading the page: :reason', { reason: review?.error ?? this.t('the AI provider didn\'t answer') });
        else if (this.status === 'ready' && this.data.checked) text = this.data.checked === 1 ? this.t('1 thing it found was fine in context, so it isn\'t shown.') : this.t(':count things it found were fine in context, so they aren\'t shown.', { count: this.data.checked });
        else if (review?.truncated) text = this.t('I ran out of room; these are the first :count.', { count: this.steps.length });
        else if (review && !review.fresh && review.ago) text = this.t('From a review :ago. The page has changed since; suggestions that no longer fit are marked.', { ago: review.ago });

        this.status_.hidden = !text;
        this.status_.classList.toggle('is-running', this.status === 'running');
        this.status_.replaceChildren(...[
            this.status === 'running' ? el('span', { class: 'gw-s-spinner', 'aria-hidden': 'true' }) : null,
            el('span', { text }),
            this.status === 'running' ? el('ol', { class: 'gw-s-phases', 'aria-label': this.t('Progress') }, [
                el('li', { class: review?.status === 'queued' ? 'is-now' : 'is-done', text: this.t('Started') }),
                el('li', { class: review?.status === 'running' ? 'is-now' : '', text: this.t('Reviewing, then double-checking') }),
                el('li', { text: this.t('Ready') }),
            ]) : null,
            this.status === 'failed' ? el('button', { type: 'button', class: 'gw-f-btn', text: this.t('Try again'), onclick: () => this.onStart() }) : null,
        ].filter(Boolean));
    }

    drawFilters() {
        const list = filters(this.steps, this.filter);

        this.filtersEl.hidden = this.steps.length < 2;
        this.filtersEl.replaceChildren(...list.map((filter) => {
            const name = filter.key === 'all' ? this.t('All') : filter.label;
            const spoken = filter.key === 'all'
                ? this.t('All, :count open', { count: filter.count })
                : filter.count === 1 ? this.t(':label, 1 suggestion', { label: name }) : this.t(':label, :count suggestions', { label: name, count: filter.count });

            return el('button', {
                type: 'button',
                class: 'gw-s-filter',
                'aria-pressed': this.filter === filter.key ? 'true' : 'false',
                'aria-label': spoken,
                onclick: () => this.setFilter(filter.key),
            }, [el('span', { text: name }), el('span', { class: 'gw-s-filter-n', text: String(filter.count) })]);
        }));
    }

    drawBar() {
        this.bar.replaceChildren(...this.steps.map((step, i) => el('i', {
            class: i === this.index && isOpen(step) ? 'is-current' : step.state === 'accepted' || step.state === 'done' ? 'is-fixed' : step.state === 'dismissed' || step.state === 'confirmed' || step.stale ? 'is-skipped' : '',
        })));
    }

    drawFoot() {
        const fixes = wordingFixes(this.steps, this.filter);

        this.foot.hidden = !this.steps.length;
        this.acceptAllButton.hidden = !this.undoAll && !fixes.length;
        this.acceptAllButton.textContent = this.undoAll
            ? this.t('Undo all (:count)', { count: this.undoAll.length })
            : this.t('Accept all wording fixes (:count)', { count: fixes.length });
    }

    drawEnd(numbers) {
        this.stepText.textContent = '';

        const finish = [...FinishGuide.guides].find((guide) => !(guide instanceof SuggestGuide));
        const left = finish ? finishCounts(finish.steps ?? [], 0).count : 0;
        let text;

        if (this.status === 'running') text = this.t('Suggestions show here once Ghostwriter has checked each one in its paragraph.');
        else if (this.status === 'failed') text = this.t('Nothing to show: the review didn\'t finish.');
        else if (this.status === 'ready' && !this.steps.length) text = this.t('Nothing to suggest. The page reads well against your guide.');
        else if (!numbers.open) text = this.t('Done. :accepted accepted, :dismissed dismissed. The changes are in the form: check them and save when you\'re happy.', { accepted: numbers.accepted, dismissed: numbers.dismissed + numbers.confirmed });
        else text = numbers.open === 1 ? this.t('1 suggestion is still open in another filter.') : this.t(':count suggestions are still open in other filters.', { count: numbers.open });

        this.message = el('p', { class: 'gw-f-msg', tabindex: '-1', text });

        const buttons = el('div', { class: 'gw-f-fixes' }, [
            this.steps.length ? el('button', { type: 'button', class: 'gw-f-btn', text: this.t('Go through again'), onclick: () => this.again() }) : null,
            left ? el('button', { type: 'button', class: 'gw-f-btn', text: left === 1 ? this.t('1 thing still to finish') : this.t(':count things still to finish', { count: left }), onclick: () => finish.openAt(finish.firstOpen()) }) : null,
            this.status !== 'running' && this.data.configured ? el('button', { type: 'button', class: 'gw-f-btn', onclick: () => this.onStart() }, [el('span', { text: this.t('Review again') }), el('small', { text: this.t('uses Ghostwriter') })]) : null,
        ]);

        const last = this.last ? el('p', { class: 'gw-s-last' }, [
            el('span', { text: this.last.text }),
            el('button', { type: 'button', class: 'gw-s-link', text: this.t('Undo'), onclick: () => this.undo(this.last.step) }),
        ]) : null;

        this.body.replaceChildren(...[last, this.message, buttons].filter(Boolean));
    }

    drawStep(step) {
        const parts = [];

        if (this.last && this.last.step !== step) {
            parts.push(el('p', { class: 'gw-s-last' }, [
                el('span', { text: this.last.text }),
                el('button', { type: 'button', class: 'gw-s-link', text: this.t('Undo'), onclick: () => this.undo(this.last.step) }),
            ]));
        }

        parts.push(el('div', { class: 'gw-s-chips' }, [
            el('span', { class: `gw-s-cat is-${step.category}`, text: step.label }),
            step.free ? el('span', { class: 'gw-s-free', text: this.t('Found without AI') }) : null,
            el('span', { class: 'gw-s-place', text: step.place }),
        ]));

        this.message = el('p', { class: 'gw-f-msg', tabindex: '-1', text: step.reason });
        parts.push(this.message);

        if (step.unsaved) parts.push(el('p', { class: 'gw-f-reason', text: this.t('Accepted earlier, but the page wasn\'t saved, so it isn\'t in the page.') }));

        if (step.stale) {
            parts.push(el('p', { class: 'gw-f-reason', text: this.t('This text has changed since the review.') }));
            parts.push(el('div', { class: 'gw-f-fixes' }, [this.button(this.t('Dismiss'), () => this.dismiss(step))]));
        } else if (!isOpen(step)) {
            parts.push(...this.decided(step));
        } else if (step.fact) {
            parts.push(...this.factStep(step));
        } else if (step.scope === 'asset') {
            parts.push(...this.altStep(step));
        } else if (step.link && step.category === 'link') {
            parts.push(...this.linkStep(step));
        } else {
            parts.push(...this.wordingStep(step));
        }

        parts.push(el('p', { class: 'gw-s-src', text: step.source }));

        this.body.replaceChildren(...parts);
    }

    decided(step) {
        const by = step.decided?.by;
        const said = {
            accepted: by ? this.t('Accepted by :name.', { name: by }) : this.t('Accepted. It\'s in the form until you save.'),
            done: this.t('Done: it\'s in the saved page.'),
            dismissed: by ? this.t('Dismissed by :name.', { name: by }) : this.t('Dismissed.'),
            confirmed: by ? this.t(':name said it\'s still right.', { name: by }) : this.t('Kept: it\'s still right.'),
        }[step.state] ?? '';

        const text = this.local.get(step.id)?.text ?? null;

        return [
            el('p', { class: 'gw-f-fixed', text: said }),
            text ? this.diff(step, null, text) : null,
            step.state === 'done' && this.local.get(step.id)?.alt === undefined ? null : el('div', { class: 'gw-f-fixes' }, [this.button(this.t('Undo'), () => this.undo(step))]),
        ].filter(Boolean);
    }

    // The before/after, each as its whole sentence with the change marked,
    // so the editor can judge the fit: <del> and <ins>, each with words a
    // screen reader says. A dated phrase is marked inside the old words.
    diff(step, before, after) {
        const ctx = step.scope === 'range' ? step.context : null;
        const lead = ctx?.before ?? '';
        const tail = ctx?.after ?? '';
        const marked = (text) => {
            const at = step.phrase ? text.indexOf(step.phrase) : -1;

            return at < 0 ? [document.createTextNode(text)] : [document.createTextNode(text.slice(0, at)), el('mark', { class: 'gw-s-phrase', text: step.phrase }), document.createTextNode(text.slice(at + step.phrase.length))];
        };
        const line = (kind, words) => el('p', { class: `gw-s-line is-${kind}` }, [
            lead ? el('span', { class: 'gw-s-ctx', text: lead }) : null,
            el(kind === 'old' ? 'del' : 'ins', {}, [el('span', { class: 'gw-f-sr', text: `${kind === 'old' ? this.t('Remove:') : this.t('Add:')} ` }), ...(kind === 'old' ? marked(words) : [document.createTextNode(words)])]),
            tail ? el('span', { class: 'gw-s-ctx', text: tail }) : null,
        ]);

        return el('div', { class: 'gw-s-diff', role: 'group', 'aria-label': this.t('Suggested change, in its sentence') }, [
            before !== null ? line('old', before) : null,
            after !== null ? line('new', after) : null,
        ]);
    }

    before(step) {
        if (step.scope === 'range') return step.quote?.exact ?? '';
        if (step.seo) return step.seo.text ?? '';

        return step.quote?.exact ?? '';
    }

    version(step) {
        const versions = versionsOf(step);

        return versions[Math.min(this.versionAt.get(step.id) ?? 0, versions.length - 1)] ?? null;
    }

    button(label, onclick, { primary = false, model = false, disabled = false } = {}) {
        return el('button', { type: 'button', class: `gw-f-btn${primary ? ' is-primary' : ''}`, disabled: disabled || this.busy === true, onclick }, [
            primary ? el('span', { class: 'gw-f-btn-mark', html: MARK }) : null,
            el('span', { text: label }),
            model ? el('small', { text: this.t('uses Ghostwriter') }) : null,
        ]);
    }

    wordingStep(step) {
        const words = this.version(step);
        const parts = [];

        if (step.seo?.inheritsFrom) parts.push(el('p', { class: 'gw-f-reason', text: this.t('This comes from :field. Accepting gives the page its own :label.', { field: step.seo.inheritsFrom, label: step.place }) }));

        if (words === null) {
            // A free finding the review wrote no words for.
            parts.push(this.diff(step, this.before(step), null));
            parts.push(el('p', { class: 'gw-f-reason', text: step.free_label ?? this.t('Rewrite it yourself') }));
            parts.push(this.editBox(step, plain(this.before(step)), true));
            parts.push(el('div', { class: 'gw-f-fixes' }, [
                step.category === 'out-of-date' ? this.button(this.t('It\'s still right'), () => this.confirm(step)) : null,
                this.button(this.t('Dismiss'), () => this.dismiss(step)),
            ]));

            return parts;
        }

        const box = el('div', { class: 'gw-s-edit', hidden: true });
        const versions = versionsOf(step);
        const at = this.versionAt.get(step.id) ?? 0;
        const more = at + 1 < versions.length;

        parts.push(this.diff(step, this.before(step), plain(words)));
        parts.push(box);
        parts.push(el('div', { class: 'gw-f-fixes' }, [
            this.button(this.t('Accept'), () => this.accept(step, words), { primary: true }),
            this.button(this.t('Edit'), () => this.openEdit(step, box, plain(words))),
            step.category === 'out-of-date' ? this.button(this.t('It\'s still right'), () => this.confirm(step)) : null,
            more
                ? this.button(this.t('Another version'), () => this.another(step))
                : this.data.review?.status === 'ready' && ['out-of-date', 'voice', 'clarity', 'seo', 'duplicate'].includes(step.category) ? this.button(this.t('Write another'), () => this.writeAnother(step), { model: true }) : null,
            this.button(this.t('Dismiss'), () => this.dismiss(step)),
        ]));

        if (step.seo && step.seo.limit) parts.push(el('p', { class: 'gw-s-count', text: this.t(':length / :limit characters', { length: Array.from(plain(words)).length, limit: step.seo.limit }) }));

        return parts;
    }

    // "Edit": the new words in a box in the guide. Enter or leaving it puts
    // them in (accepted, with the editor's words); Esc puts it back.
    editBox(step, value, open = false) {
        const box = el('div', { class: 'gw-s-edit', hidden: !open });

        if (open) this.fillEdit(step, box, value);

        return box;
    }

    openEdit(step, box, value) {
        this.fillEdit(step, box, value);
        box.hidden = false;
        box.querySelector('textarea')?.focus();
    }

    fillEdit(step, box, value) {
        const input = el('textarea', { class: 'gw-f-answer gw-s-textarea', rows: '3', 'aria-label': this.t('Your words for :place', { place: step.place }) });
        let done = false;

        input.value = value;
        input.addEventListener('keydown', (event) => {
            if (event.key === 'Enter' && !event.shiftKey) {
                event.preventDefault();
                put();
            } else if (event.key === 'Escape') {
                event.preventDefault();
                event.stopPropagation();
                input.value = value;
                done = true;
                box.hidden = true;
                this.paint();
            }
        });
        input.addEventListener('blur', () => put());

        const put = () => {
            const words = input.value.trim();

            if (done || !words || words === value.trim()) return;

            done = true;
            this.accept(step, words, { edited: true });
        };

        box.replaceChildren(el('label', { class: 'gw-f-answer-wrap' }, [el('span', { class: 'gw-f-sr', text: this.t('Your words') }), input]), el('p', { class: 'gw-s-hint', text: this.t('Enter puts it in. Esc puts it back.') }));
    }

    factStep(step) {
        const input = el('input', { type: 'text', class: 'gw-f-answer', 'aria-label': step.fact.ask, placeholder: step.fact.ask, autocomplete: 'off', inputmode: step.fact.answer === 'number' ? 'decimal' : null });
        const error = el('p', { class: 'gw-s-error', role: 'alert', hidden: true });
        const use = () => {
            try {
                const filled = fillFact(step.fact, input.value);

                this.accept(step, filled, { answer: input.value.trim() });
            } catch (failure) {
                error.hidden = false;
                error.textContent = failure.message === 'date-only' ? this.t('Type a date.') : this.t('Type the number only.');
                input.setAttribute('aria-invalid', 'true');
                input.focus();
            }
        };

        input.setAttribute('aria-describedby', `${this.hintId}`);
        input.addEventListener('keydown', (event) => {
            if (event.key === 'Enter') {
                event.preventDefault();
                use();
            } else if (event.key === 'Escape') {
                event.stopPropagation();
                input.value = '';
            }
        });

        return [
            this.diff(step, this.before(step), null),
            el('div', { class: 'gw-s-ask' }, [el('label', { class: 'gw-f-answer-wrap' }, [el('span', { class: 'gw-f-sr', text: step.fact.ask }), input]), this.button(this.t('Use it'), use, { primary: true })]),
            error,
            el('div', { class: 'gw-f-fixes' }, [
                this.button(this.t('It\'s still right'), () => this.confirm(step)),
                step.fact.without !== null && step.fact.without !== undefined ? this.button(this.t('Remove the number'), () => this.accept(step, step.fact.without)) : null,
                this.button(this.t('Dismiss'), () => this.dismiss(step)),
            ]),
        ];
    }

    linkStep(step) {
        const words = typeof step.replacement === 'string' ? step.replacement : null;

        return [
            el('div', { class: 'gw-s-diff', role: 'group', 'aria-label': this.t('Suggested change') }, [
                el('span', { text: words ? plain(words) : this.before(step) }),
                el('span', { class: 'gw-s-arrow', 'aria-hidden': 'true', text: '→' }),
                el('span', { class: 'gw-s-target', text: step.link.title }),
            ]),
            el('div', { class: 'gw-f-fixes' }, [
                step.link.value ? this.button(this.t('Link to it'), () => this.linkTo(step, words), { primary: true }) : null,
                this.button(this.t('Choose an entry'), () => this.chooseEntry(step)),
                this.button(this.t('Remove the link'), () => this.removeLink(step)),
                this.button(this.t('Dismiss'), () => this.dismiss(step)),
            ]),
        ];
    }

    altStep(step) {
        const asset = step.asset ?? {};
        const proposed = this.version(step);
        const box = el('div', { class: 'gw-s-edit' });
        const input = el('textarea', { class: 'gw-f-answer gw-s-textarea', rows: '2', maxlength: '300', 'aria-label': this.t('Alt text for :file', { file: asset.filename }) });

        input.value = proposed ? plain(proposed) : '';
        box.append(el('label', { class: 'gw-f-answer-wrap' }, [el('span', { class: 'gw-f-sr', text: this.t('Alt text') }), input]));

        const parts = [
            el('div', { class: 'gw-s-asset' }, [
                asset.url ? el('img', { src: asset.url, alt: '', class: 'gw-s-thumb' }) : null,
                el('div', {}, [el('strong', { text: asset.filename }), el('p', { class: 'gw-f-reason', text: `${this.t('Alt text:')} ${asset.alt ? asset.alt : this.t('none')}` })]),
            ]),
        ];

        if (!proposed) parts.push(el('p', { class: 'gw-f-reason', text: step.free_label ?? this.t('Describe the image yourself') }));

        parts.push(box);

        if (!asset.canEdit) {
            parts.push(el('p', { class: 'gw-f-reason', text: this.t('Ask someone who can edit assets to add it.') }));
            parts.push(el('div', { class: 'gw-f-fixes' }, [
                this.button(this.t('Copy'), () => navigator.clipboard?.writeText(input.value).then(() => this.announce(this.t('Copied.')))),
                this.button(this.t('Dismiss'), () => this.dismiss(step)),
            ]));

            return parts;
        }

        const save = this.button(this.t('Save to the image'), () => this.confirmAlt(step, input.value, save), { primary: true });
        parts.push(el('div', { class: 'gw-f-fixes' }, [save, this.button(this.t('Dismiss'), () => this.dismiss(step))]));

        return parts;
    }

    // The confirm for alt text: a non-modal popover on the button, focus
    // moved in, back to the button on Cancel.
    confirmAlt(step, alt, button) {
        const text = alt.trim();

        if (!text) {
            this.announce(this.t('Write the alt text first.'));

            return;
        }

        this.body.querySelector('.gw-s-confirm')?.remove();

        const uses = step.asset?.uses ?? 0;
        const where = uses > 1 ? this.t('It shows wherever the image is used (:count pages).', { count: uses }) : uses === 1 ? this.t('It shows wherever the image is used (1 page).') : this.t('It shows wherever the image is used.');
        const cancel = this.button(this.t('Cancel'), () => {
            popover.remove();
            button.focus();
        });
        const popover = el('div', { class: 'gw-s-confirm', role: 'dialog', 'aria-label': this.t('Save to the image'), onkeydown: (event) => {
            if (event.key === 'Escape') {
                event.preventDefault();
                event.stopPropagation();
                popover.remove();
                button.focus();
            }
        } }, [
            el('p', { text: `${this.t('This saves the alt text on :file now, not when you save the page.', { file: step.asset?.filename })} ${where}` }),
            el('div', { class: 'gw-f-fixes' }, [this.button(this.t('Save to the image'), () => this.saveAlt(step, text), { primary: true }), cancel]),
        ]);

        button.closest('.gw-f-fixes').after(popover);
        popover.querySelector('button')?.focus();
    }

    // -- Actions ------------------------------------------------------------

    async accept(step, words, { edited = false, answer = null } = {}) {
        const change = this.sadapter.replace(step, words);

        if (!change) {
            step.stale = true;
            this.announce(this.t('Those words aren\'t in the field any more.'));
            this.paint({ focus: true });

            return;
        }

        const text = plain(words);

        this.local.set(step.id, { state: 'accepted', text, change, pending: true });
        step.state = 'accepted';
        this.last = { step, text: this.t('Accepted.') };
        this.undoAll = null;
        this.announce(edited ? this.t('Your words are in the form.') : this.t('Accepted. It\'s in the form until you save.'));
        this.decide([{ suggestion: step.id, state: 'accepted', text, answer }]);
        this.advance();
    }

    async dismiss(step) {
        this.local.set(step.id, { state: 'dismissed', pending: true });
        step.state = 'dismissed';
        step.stale = false;
        this.last = { step, text: this.t('Dismissed.') };
        this.undoAll = null;
        this.announce(this.t('Dismissed. It won\'t be suggested again.'));
        this.decide([{ suggestion: step.id, state: 'dismissed' }]);
        this.advance();
    }

    async confirm(step) {
        this.local.set(step.id, { state: 'confirmed', pending: true });
        step.state = 'confirmed';
        this.last = { step, text: this.t('Kept: it\'s still right.') };
        this.announce(this.t('Kept. It won\'t be asked about for 12 months, or until it\'s edited.'));
        this.decide([{ suggestion: step.id, state: 'confirmed' }]);
        this.advance();
    }

    async undo(step) {
        const mine = this.local.get(step.id);

        if (mine?.alt !== undefined) {
            try {
                const result = await this.api.undoAlt(step, mine.alt);

                this.announce(result?.message ?? this.t('Undone.'));
            } catch (error) {
                this.announce(error.message);

                return;
            }
        } else if (mine?.change) {
            this.sadapter.undo(step, mine.change);
        }

        this.local.delete(step.id);
        step.state = 'open';
        step.stale = false;
        this.last = null;
        this.undoAll = null;

        if (mine?.alt === undefined) this.decide([{ suggestion: step.id, state: 'open' }]);

        this.index = this.steps.indexOf(step);
        this.announce(this.t('Undone.'));
        this.paint({ focus: true, fly: true });
    }

    another(step) {
        const versions = versionsOf(step);
        const at = Math.min((this.versionAt.get(step.id) ?? 0) + 1, versions.length - 1);

        this.versionAt.set(step.id, at);
        this.announce(`${this.t('Another version:')} ${plain(versions[at])}`);
        this.paint({ focus: true });
    }

    async writeAnother(step) {
        this.busy = true;
        this.paint();

        try {
            const found = await this.api.another(step);

            step.versions = [...(step.versions ?? []), ...found];
            this.versionAt.set(step.id, versionsOf(step).indexOf(found[0]));
            this.announce(`${this.t('Another version:')} ${plain(found[0])}`);
        } catch (error) {
            this.announce(error.message);
            this.flash(error.message);
        } finally {
            this.busy = false;
        }

        this.paint({ focus: true });
    }

    async linkTo(step, words) {
        const change = await this.sadapter.link(step, words);

        if (!change) {
            this.flash(this.t('That didn\'t change the field. Try again, or change it by hand.'));

            return;
        }

        this.local.set(step.id, { state: 'accepted', text: words ? plain(words) : step.link.title, change, pending: true });
        step.state = 'accepted';
        this.last = { step, text: this.t('Linked.') };
        this.announce(this.t('Linked to :title.', { title: step.link.title }));
        this.decide([{ suggestion: step.id, state: 'accepted', text: words ? plain(words) : null }]);
        this.advance();
    }

    async chooseEntry(step) {
        const result = await this.sadapter.choose(step);

        if (result?.fixed) {
            this.local.set(step.id, { state: 'accepted', pending: true });
            step.state = 'accepted';
            this.decide([{ suggestion: step.id, state: 'accepted' }]);
            this.advance();
        } else if (result?.message) {
            this.announce(result.message);
        }
    }

    removeLink(step) {
        const change = this.sadapter.unlink(step);

        if (!change) return;

        this.local.set(step.id, { state: 'accepted', change, pending: true });
        step.state = 'accepted';
        this.last = { step, text: this.t('Link removed; the words stay.') };
        this.decide([{ suggestion: step.id, state: 'accepted', text: this.before(step) }]);
        this.advance();
    }

    async saveAlt(step, alt) {
        try {
            const result = await this.api.saveAlt(step, alt);

            this.local.set(step.id, { state: 'accepted', text: alt, alt: result?.before ?? '', synced: true });
            step.state = 'accepted';
            if (step.asset) step.asset.alt = alt;
            this.last = { step, text: result?.message ?? this.t('Saved to the image.') };
            this.announce(result?.message ?? this.t('Saved to the image.'));
            this.advance();
        } catch (error) {
            this.flash(error.message);
        }
    }

    acceptAll() {
        const targets = wordingFixes(this.steps, this.filter);
        const done = [];

        // From the end of the form backwards, each found again just before
        // it goes in; one that isn't there stays open.
        [...targets].reverse().forEach((i) => {
            const step = this.steps[i];
            const words = this.version(step);
            const change = this.sadapter.replace(step, words);

            if (!change) return;

            this.local.set(step.id, { state: 'accepted', text: plain(words), change, pending: true });
            step.state = 'accepted';
            done.push(step);
        });

        if (!done.length) return;

        this.decide(done.map((step) => ({ suggestion: step.id, state: 'accepted', text: plain(this.version(step)) })));
        this.undoAll = done;
        this.last = null;
        this.announce(this.t(':count accepted. Undo all is available.', { count: done.length }));
        this.index = this.nextOpen(this.index);
        this.paint();
        this.acceptAllButton.focus();
    }

    undoAllNow() {
        const list = this.undoAll ?? [];

        list.forEach((step) => {
            const mine = this.local.get(step.id);

            if (mine?.change) this.sadapter.undo(step, mine.change);

            this.local.delete(step.id);
            step.state = 'open';
        });

        this.decide(list.map((step) => ({ suggestion: step.id, state: 'open' })));
        this.undoAll = null;
        this.announce(this.t('Undone: :count.', { count: list.length }));
        this.index = this.nextOpen(-1);
        this.paint({ focus: true });
    }

    decide(list) {
        this.api.decide(list).then(() => list.forEach((d) => {
            const mine = this.local.get(d.suggestion);

            if (mine) mine.pending = false;
        })).catch((error) => this.flash(error.message));
    }

    flash(text) {
        if (!text) return;

        this.announce(text);
        Statamic?.$toast?.error?.(text);
    }

    // -- Highlights: indigo, so a suggestion never looks like a Finish gap --

    highlight() {
        const current = !this.minimised && this.index < this.steps.length ? this.index : -1;
        const fields = fieldStates(this.steps, current);
        const seen = new Set();

        fields.forEach((state) => {
            const field = this.sadapter.locate(state.step);

            if (!field || seen.has(field)) return;

            seen.add(field);

            if (field.getAttribute('data-gw-suggest') !== state.state) field.setAttribute('data-gw-suggest', state.state);

            if (!field.dataset.gwSuggestNote) field.dataset.gwSuggestNote = `gw-s-note-${Math.random().toString(36).slice(2, 8)}`;

            const host = this.sadapter.tagHost(field) ?? field;
            let tag = field.querySelector('.gw-s-tag');

            if (tag && tag.parentElement !== host) {
                tag.remove();
                tag = null;
            }

            if (!tag) {
                tag = el('button', { type: 'button', class: 'gw-f-tag gw-s-tag', onclick: (event) => {
                    event.preventDefault();
                    event.stopPropagation();
                    if (tag.dataset.step !== '') this.openAt(Number(tag.dataset.step));
                } });
                tag.classList.toggle('is-inline', host !== field);
                host.append(tag);
                this.resizes.observe(field);
            }

            const text = tagText(state, current, this.steps);
            const target = state.state === 'current' ? current : state.steps[0] ?? state.first;

            if (tag.dataset.step !== String(target) || tag.textContent !== text) {
                tag.dataset.step = String(target);
                tag.textContent = text;
                tag.setAttribute('aria-label', state.state === 'done' ? this.t('Suggestions here: all reviewed') : this.t('Suggestion :n: :label', { n: Number(target) + 1, label: this.steps[target]?.label ?? '' }));
            }

            let note = document.getElementById(field.dataset.gwSuggestNote);

            if (!note) {
                note = el('span', { id: field.dataset.gwSuggestNote, class: 'gw-f-sr' });
                field.append(note);
            }

            const said = state.state === 'done' ? '' : this.t('Ghostwriter suggests a change here (:label).', { label: state.step?.label ?? '' });

            if (note.textContent !== said) note.textContent = said;

            const control = field.querySelector('input, textarea, [contenteditable="true"]');

            if (control && said && !(control.getAttribute('aria-describedby') ?? '').includes(field.dataset.gwSuggestNote)) {
                control.setAttribute('aria-describedby', `${control.getAttribute('aria-describedby') ?? ''} ${field.dataset.gwSuggestNote}`.trim());
            }
        });

        document.querySelectorAll('[data-gw-suggest]').forEach((field) => {
            if (!seen.has(field)) this.clearField(field);
        });
    }

    unhighlight() {
        document.querySelectorAll('[data-gw-suggest]').forEach((field) => this.clearField(field));
        this.sadapter.highlight([], null);
    }

    clearField(field) {
        field.removeAttribute('data-gw-suggest');
        field.querySelector('.gw-s-tag')?.remove();
        if (field.dataset.gwSuggestNote) document.getElementById(field.dataset.gwSuggestNote)?.remove();
    }

    target(step) {
        const field = step ? this.sadapter.locate(step) : null;

        if (!field || !field.offsetParent) return null;

        const tag = field.querySelector('.gw-s-tag');
        const r = field.getBoundingClientRect();
        const t = tag?.getBoundingClientRect();

        if (r.bottom < 0 || r.top > window.innerHeight) return null;

        return { x: t?.width ? Math.min(t.right + 8, window.innerWidth - 60) : r.right - 170, y: Math.max(8, r.top - 46) };
    }

    greeting(step) {
        return step?.speech ?? this.t(SPEECH[Math.max(0, this.steps.indexOf(step)) % SPEECH.length]);
    }

    // While the review runs, the mark waits rather than calling it done.
    flyHome(toDock = false) {
        super.flyHome(toDock);

        if (!toDock && this.status === 'running') this.say.textContent = this.t('Reading…');
    }

    // Nothing to minimise into while there's nothing to show.
    done() {
        return this.index >= this.steps.length;
    }
}
