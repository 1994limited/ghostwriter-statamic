// The Finish this page guide: the count by Save, the highlighted fields and
// their numbered tags, the floating guide that walks through each gap, the
// flying Ghostwriter mark, and the dock it minimises into. It has no
// framework and knows no CMS: everything CMS-specific goes through the
// adapter (locate, reveal, select, run a fix, check again). The design keeps
// this shell CMS-free so Craft and Filament can share it.
//
// Accessibility: the guide is a non-modal region (the form stays usable),
// steps and fixes are announced in a polite live region, Esc inside the
// guide minimises it, Alt+Shift+N / P / G step and open it from anywhere but
// a text field, and nothing moves under prefers-reduced-motion.

const MARK = '<svg viewBox="0 0 14 14" aria-hidden="true" focusable="false"><path class="gw-f-body" fill-rule="evenodd" d="M2.5 12.5V6a4.5 4.5 0 0 1 9 0V9.5H8.5V12.5Z M4.75 6.25a.65.65 0 1 0 1.3 0a.65.65 0 1 0-1.3 0Z M7.95 6.25a.65.65 0 1 0 1.3 0a.65.65 0 1 0-1.3 0Z"/><path class="gw-f-fold" d="M9 10H11.5L9 12.5Z"/></svg>';

const PHONE = '(max-width: 639px)';
const STILL = '(prefers-reduced-motion: reduce)';

function el(tag, attrs = {}, children = []) {
    const node = document.createElement(tag);

    Object.entries(attrs).forEach(([key, value]) => {
        if (value === null || value === undefined || value === false) return;
        if (key === 'text') node.textContent = value;
        else if (key === 'html') node.innerHTML = value;
        else if (key.startsWith('on')) node.addEventListener(key.slice(2), value);
        else node.setAttribute(key, value === true ? '' : value);
    });

    [].concat(children).filter(Boolean).forEach((child) => node.append(child));

    return node;
}

const isTyping = (target) => target instanceof Element && (target.isContentEditable || ['INPUT', 'TEXTAREA', 'SELECT'].includes(target.tagName));

export class FinishGuide {
    /**
     * @param {object} options
     * @param {object} options.adapter  { locate(gap), reveal(gap), select(gap), run(gap, fix, value), recheck() }
     * @param {function} options.t  Translates an English string with :params.
     * @param {string} options.state  'open' or 'minimised', as the person last left it.
     * @param {function} options.onState  Called with the new state on minimise and restore.
     * @param {string} options.key  What skipped gaps are remembered under, for this page view.
     */
    constructor({ adapter, t, state = 'minimised', onState = () => {}, key = 'page' }) {
        this.adapter = adapter;
        this.t = t;
        this.onState = onState;
        this.key = `ghostwriter.finish.${key}`;
        this.minimised = state !== 'open';
        this.steps = [];
        this.index = 0;
        this.report = { count: 0, suggestions: 0, gaps: [] };
        this.shown = false;
        this.busy = null;
        this.lastCount = null;
        this.timers = new Set();
        this.still = window.matchMedia(STILL).matches;
        this.phone = window.matchMedia(PHONE).matches;
        this.sheetOpen = true;
        this.skipped = new Set(this.load('skipped'));
        this.dismissed = new Set(this.load('dismissed'));
    }

    // Puts the pill into `pillHost` (the header beside Save; floating when
    // there is none) and the guide, dock, mark and live region into the page.
    mount(pillHost) {
        this.pill = el('button', { type: 'button', class: 'gw-f-pill', hidden: true, onclick: () => this.openAt(this.firstOpen()) }, [
            el('span', { class: 'gw-f-pill-mark', html: MARK }),
            (this.pillCount = el('span', { class: 'gw-f-pill-n', 'aria-hidden': 'true' })),
            (this.pillText = el('span', { class: 'gw-f-pill-text' })),
        ]);

        (pillHost ?? document.body).append(this.pill);
        if (!pillHost) this.pill.classList.add('is-floating');

        this.hintId = `gw-f-hint-${Math.random().toString(36).slice(2, 8)}`;
        this.root = el('div', { class: 'gw-f', 'data-gw-finish': true }, [
            (this.live = el('div', { class: 'gw-f-sr', 'aria-live': 'polite', 'aria-atomic': 'true' })),
            el('span', { id: this.hintId, class: 'gw-f-sr', text: this.t('Shortcuts: Alt+Shift+N for the next gap, Alt+Shift+P for the one before, Alt+Shift+G to open or minimise. Esc minimises.') }),
            (this.flyer = el('div', { class: 'gw-f-flyer', 'aria-hidden': 'true', hidden: true }, [
                el('div', { class: 'gw-f-trail' }),
                (this.tilt = el('div', { class: 'gw-f-tilt' }, [el('div', { class: 'gw-f-bob', html: MARK })])),
                (this.say = el('span', { class: 'gw-f-say' })),
            ])),
            (this.dock = el('button', { type: 'button', class: 'gw-f-dock', hidden: true, onclick: () => this.restore() }, [
                el('span', { class: 'gw-f-dock-mark', html: MARK }),
                (this.badge = el('span', { class: 'gw-f-badge', 'aria-hidden': 'true' })),
                (this.tip = el('span', { class: 'gw-f-tip', 'aria-hidden': 'true' })),
            ])),
            (this.panel = el('section', { class: 'gw-f-guide', role: 'region', 'aria-label': this.t('Finish this page'), 'aria-describedby': this.hintId, hidden: true, onkeydown: (event) => this.panelKey(event) }, [
                (this.handle = el('button', { type: 'button', class: 'gw-f-handle', 'aria-label': this.t('Show or hide the guide'), onclick: () => this.toggleSheet() })),
                el('header', { class: 'gw-f-head' }, [
                    el('span', { class: 'gw-f-head-mark', html: MARK }),
                    el('strong', { text: this.t('Finish this page') }),
                    (this.stepText = el('span', { class: 'gw-f-step' })),
                    el('button', { type: 'button', class: 'gw-f-min', 'aria-label': this.t('Minimise'), title: this.t('Minimise'), html: '&#8212;', onclick: () => this.minimise() }),
                ]),
                (this.bar = el('div', { class: 'gw-f-bar', 'aria-hidden': 'true' })),
                (this.summary = el('button', { type: 'button', class: 'gw-f-summary', onclick: () => this.toggleSheet(true) })),
                (this.body = el('div', { class: 'gw-f-body' })),
                (this.foot = el('footer', { class: 'gw-f-foot' }, [
                    el('button', { type: 'button', text: `← ${this.t('Back')}`, onclick: () => this.back() }),
                    el('button', { type: 'button', text: this.t('Skip for now'), onclick: () => this.skip() }),
                    el('button', { type: 'button', text: `${this.t('Next')} →`, onclick: () => this.next() }),
                ])),
            ])),
        ]);

        document.body.append(this.root);

        this.onKey = (event) => this.shortcut(event);
        this.onMove = () => this.follow();
        this.stillQuery = window.matchMedia(STILL);
        this.phoneQuery = window.matchMedia(PHONE);
        this.onStill = () => {
            this.still = this.stillQuery.matches;
            this.root.classList.toggle('is-still', this.still);
            if (this.still) this.clearTimers();
        };
        this.onPhone = () => {
            this.phone = this.phoneQuery.matches;
            this.paint();
        };

        document.addEventListener('keydown', this.onKey);
        window.addEventListener('scroll', this.onMove, true);
        window.addEventListener('resize', this.onMove);
        this.stillQuery.addEventListener('change', this.onStill);
        this.phoneQuery.addEventListener('change', this.onPhone);
        this.root.classList.toggle('is-still', this.still);

        // The CMS re-renders fields as people type and sets move: put the
        // highlights back and follow the field, once a frame at most.
        this.observer = new MutationObserver((records) => {
            if (records.some((record) => !this.root.contains(record.target) && !(record.target instanceof Element && record.target.closest('.gw-f-tag, .gw-f-pill')))) this.soon();
        });
        this.observer.observe(document.querySelector('[data-ghostwriter-form]') ?? document.body, { childList: true, subtree: true });
        this.resizes = new ResizeObserver(() => this.soon());

        return this;
    }

    destroy() {
        this.clearTimers();
        this.observer?.disconnect();
        this.resizes?.disconnect();
        document.removeEventListener('keydown', this.onKey);
        window.removeEventListener('scroll', this.onMove, true);
        window.removeEventListener('resize', this.onMove);
        this.stillQuery?.removeEventListener('change', this.onStill);
        this.phoneQuery?.removeEventListener('change', this.onPhone);
        this.unhighlight();
        this.root?.remove();
        this.pill?.remove();
    }

    // A new check: gaps still there keep their place and state, gaps gone
    // since they were shown turn green, new ones join in form order.
    update(report, { open = false } = {}) {
        this.report = report;
        const live = (report.gaps ?? []).filter((gap) => !this.dismissed.has(gap.id));
        const byId = new Map(live.map((gap) => [gap.id, gap]));
        const currentId = this.steps[this.index]?.gap.id;
        // Before anything was shown, there is nothing to have been fixed.
        const previous = this.shown ? this.steps : this.steps.filter((step) => byId.has(step.gap.id));
        const steps = previous.map((step) => (byId.has(step.gap.id) ? { gap: byId.get(step.gap.id), status: step.status === 'fixed' ? 'open' : step.status } : { gap: step.gap, status: 'fixed' }));
        const known = new Set(steps.map((step) => step.gap.id));

        live.forEach((gap, position) => {
            if (known.has(gap.id)) return;

            const later = live.slice(position + 1).map((g) => g.id);
            const at = steps.findIndex((step) => later.includes(step.gap.id));
            const step = { gap, status: this.skipped.has(gap.id) ? 'skipped' : 'open' };

            if (at < 0) steps.push(step);
            else steps.splice(at, 0, step);
        });

        // What counts first, suggestions after.
        const counts = (step) => (step.gap.severity === 'suggestion' ? 1 : 0);
        this.steps = steps.map((step, i) => [step, i]).sort((a, b) => counts(a[0]) - counts(b[0]) || a[1] - b[1]).map(([step]) => step);

        const keep = this.steps.findIndex((step) => step.gap.id === currentId && step.status !== 'fixed');

        if (keep >= 0) this.index = keep;
        else if (this.steps[this.index]?.status === 'fixed' || this.index >= this.steps.length) this.index = this.nextOpen(this.index);

        // Only something that blocks publishing brings the guide out on its
        // own: a new, empty form isn't nagged about its required title.
        if (open || live.some((gap) => gap.severity === 'blocks')) this.shown = true;

        if (open && live.length) {
            this.index = this.firstOpen();
            this.restore(true);

            return;
        }

        // The step on screen was fixed: the mark moves on to the next one.
        const moved = currentId !== undefined && this.steps[this.index]?.gap.id !== currentId;

        this.paint({ fly: moved && !this.minimised });
    }

    // -- Moving through the steps ------------------------------------------

    firstOpen() {
        const i = this.steps.findIndex((step) => step.status === 'open');

        return i < 0 ? Math.max(0, this.steps.findIndex((step) => step.status !== 'fixed')) : i;
    }

    // The next step still to do from here, coming round to the start for
    // any passed by; past the end only when none is left but skipped ones.
    nextOpen(from) {
        for (let i = from; i < this.steps.length; i++) {
            if (this.steps[i].status === 'open') return i;
        }

        for (let i = 0; i < Math.min(from, this.steps.length); i++) {
            if (this.steps[i].status === 'open') return i;
        }

        return this.steps.length;
    }

    done() {
        return this.index >= this.steps.length || this.steps.every((step) => step.status === 'fixed');
    }

    go(i) {
        this.index = Math.max(0, Math.min(i, this.steps.length));
        const step = this.steps[this.index];

        if (step?.status === 'skipped') this.setSkipped(step, false);

        this.paint({ focus: true, fly: true });
    }

    next() {
        if (this.index < this.steps.length) this.go(this.index + 1);
    }

    back() {
        if (this.index > 0) this.go(this.index - 1);
    }

    skip() {
        const step = this.steps[this.index];

        if (step && step.status !== 'fixed') this.setSkipped(step, true);

        this.go(this.nextOpen(this.index + 1));
    }

    again() {
        this.steps.forEach((step) => step.status === 'skipped' && this.setSkipped(step, false));
        this.go(this.firstOpen());
    }

    openAt(i) {
        this.index = i;

        if (this.minimised) this.restore();
        else this.go(i);
    }

    setSkipped(step, on) {
        step.status = on ? 'skipped' : 'open';

        if (on) this.skipped.add(step.gap.id);
        else this.skipped.delete(step.gap.id);

        this.save('skipped', [...this.skipped]);
    }

    // -- Minimise and restore ----------------------------------------------

    minimise() {
        if (this.minimised) return;

        this.minimised = true;
        this.onState('minimised');
        this.announce(this.t('Guide minimised.'));
        this.adapter.highlight?.(null);

        if (this.still) {
            this.panel.hidden = true;
            this.paint();
            this.dock.focus();

            return;
        }

        this.panel.classList.remove('is-unfurling');
        this.panel.classList.add('is-sucking');
        this.flyHome(true);

        this.later(() => {
            this.panel.classList.remove('is-sucking');
            this.panel.hidden = true;
            this.paint();
            this.dock.classList.add('is-popping');
            this.puff();
            this.dock.focus();
            this.later(() => {
                this.dock.classList.remove('is-popping');
                this.dock.classList.add('is-waving');
            }, 450);
            this.later(() => this.dock.classList.remove('is-waving'), 1200);
        }, 480);
    }

    restore(fromDraft = false) {
        if (!this.minimised) {
            this.paint({ focus: true, fly: true });

            return;
        }

        this.minimised = false;
        if (!fromDraft) this.onState('open');
        this.announce(this.t('Guide open.'));

        if (this.still || this.dock.hidden) {
            this.paint({ focus: true, fly: true });

            return;
        }

        this.dock.classList.add('is-leaping');

        this.later(() => {
            this.dock.classList.remove('is-leaping');
            this.panel.classList.add('is-unfurling');
            this.paint({ focus: true, fly: true });
            this.later(() => this.panel.classList.remove('is-unfurling'), 600);
        }, 450);
    }

    toggleSheet(open = !this.sheetOpen) {
        this.sheetOpen = open;
        this.paint();
    }

    // -- Drawing -----------------------------------------------------------

    paint({ focus = false, fly = false } = {}) {
        const count = this.report.count ?? 0;
        const visible = this.shown && (this.steps.length > 0 || count > 0);
        const label = count ? (count === 1 ? this.t('1 thing to finish') : this.t(':count things to finish', { count })) : this.t('Ready to publish');

        // The pill by Save: nothing until there was something to finish.
        this.pill.hidden = !visible;
        this.pill.classList.toggle('is-ready', !count);
        this.pillText.textContent = label;
        this.pillCount.textContent = count ? String(count) : '✓';
        this.pill.setAttribute('aria-label', `${this.t('Finish this page')}: ${label}`);
        this.pill.title = this.t('Alt+Shift+G opens or minimises the guide');

        // The dock, while minimised.
        this.dock.hidden = !visible || !this.minimised;
        this.dock.setAttribute('aria-label', `${this.t('Finish this page')}: ${label}`);
        this.badge.textContent = count ? String(count) : '✓';
        this.badge.classList.toggle('is-ready', !count);
        this.tip.textContent = label;

        if (this.lastCount !== null && this.lastCount !== count) {
            this.badge.classList.remove('is-bump');
            void this.badge.offsetWidth;
            this.badge.classList.add('is-bump');
        }

        this.lastCount = count;

        this.panel.hidden = !visible || this.minimised;
        this.root.classList.toggle('is-phone', this.phone);
        this.panel.classList.toggle('is-collapsed', this.phone && !this.sheetOpen);

        this.highlight();

        if (this.panel.hidden) {
            this.flyer.hidden = true;

            return;
        }

        this.drawBar();

        if (this.done()) {
            this.drawEnd();
            this.flyHome();
            if (focus) this.message?.focus();

            return;
        }

        this.drawStep(this.steps[this.index]);
        this.summary.textContent = [`${this.index + 1} ${this.t('of')} ${this.steps.length}`, this.steps[this.index].gap.speech, `${this.t('Next')} →`].join(' · ');

        if (focus) {
            this.message.focus({ preventScroll: true });
            this.announce(`${this.t('Step :n of :total.', { n: this.index + 1, total: this.steps.length })} ${this.steps[this.index].gap.message}`);
        }

        if (fly) this.reveal(this.steps[this.index]);
        else this.follow();
    }

    drawBar() {
        this.bar.replaceChildren(...this.steps.map((step, i) => el('i', { class: step.status === 'fixed' ? 'is-fixed' : step.status === 'skipped' ? 'is-skipped' : i === this.index ? 'is-current' : '' })));
    }

    drawEnd() {
        const skipped = this.steps.filter((step) => step.status === 'skipped').length;
        const still = this.report.count ?? 0;

        this.stepText.textContent = '';
        this.message = el('p', { class: 'gw-f-msg', tabindex: '-1', text: skipped || still
            ? (Math.max(skipped, still) === 1
                ? this.t('That\'s everything I could help with. 1 still needs you; it stays highlighted until it\'s filled in.')
                : this.t('That\'s everything I could help with. :count still need you; they stay highlighted until they\'re filled in.', { count: Math.max(skipped, still) }))
            : this.t('All done. Nothing left to fill in, so this page is ready to publish.') });

        this.body.replaceChildren(this.message, skipped || still ? el('div', { class: 'gw-f-fixes' }, [el('button', { type: 'button', class: 'gw-f-btn', text: this.t('Go through again'), onclick: () => this.again() })]) : null);
        this.foot.hidden = true;
        this.adapter.highlight?.(null);
    }

    drawStep(step) {
        const { gap } = step;
        const suggestions = this.report.suggestions ?? 0;

        this.foot.hidden = false;
        this.stepText.textContent = `${this.index + 1} ${this.t('of')} ${this.steps.length}`;
        this.message = el('p', { class: 'gw-f-msg', tabindex: '-1', text: gap.message });

        const parts = [this.message];

        if (gap.reason) parts.push(el('p', { class: 'gw-f-reason', text: gap.reason }));
        if (gap.severity === 'suggestion') parts.push(el('p', { class: 'gw-f-reason', text: this.t('A suggestion: it won\'t stop the page going live.') }));

        if (gap.kind === 'stock-preview' && gap.meta?.expired) parts.push(el('p', { class: 'gw-f-reason', text: this.t('The preview has expired.') }));

        parts.push(this.fixes(step));

        if (this.index === 0 && suggestions && (this.report.count ?? 0)) {
            parts.push(el('p', { class: 'gw-f-reason', text: `${this.report.count === 1 ? this.t('1 thing to finish') : this.t(':count things to finish', { count: this.report.count })}, ${suggestions === 1 ? this.t('and 1 suggestion') : this.t('and :count suggestions', { count: suggestions })}.` }));
        }

        if (step.status === 'fixed') parts.unshift(el('p', { class: 'gw-f-fixed', text: this.t('Fixed ✓') }));

        this.body.replaceChildren(...parts);
        this.adapter.highlight?.(gap);
    }

    // The fixes, primary first. A fact gets an answer box: always editable,
    // Enter or leaving it puts the words in, Esc puts it back.
    fixes(step) {
        const { gap } = step;
        const box = el('div', { class: 'gw-f-fixes' });

        gap.fixes.forEach((fix) => {
            if (fix.action === 'answer') {
                const input = el('input', { type: 'text', class: 'gw-f-answer', 'aria-label': fix.label, placeholder: this.t('Type it here'), autocomplete: 'off' });
                const put = async () => {
                    const value = input.value.trim();

                    if (!value || input.dataset.done) return;

                    input.dataset.done = '1';
                    await this.run(gap, fix, value);
                };

                input.addEventListener('keydown', (event) => {
                    if (event.key === 'Enter') {
                        event.preventDefault();
                        put();
                    } else if (event.key === 'Escape') {
                        event.stopPropagation();
                        input.value = '';
                    }
                });
                input.addEventListener('blur', put);
                box.append(el('label', { class: 'gw-f-answer-wrap' }, [el('span', { class: 'gw-f-sr', text: fix.label }), input]));

                return;
            }

            const model = fix.cost === 'model';
            box.append(el('button', {
                type: 'button',
                class: `gw-f-btn${fix.primary ? ' is-primary' : ''}`,
                disabled: this.busy === gap.id,
                onclick: () => this.run(gap, fix),
            }, [
                fix.primary ? el('span', { class: 'gw-f-btn-mark', html: MARK }) : null,
                el('span', { text: fix.label }),
                model ? el('small', { text: this.t('uses Ghostwriter') }) : null,
            ]));
        });

        return box;
    }

    async run(gap, fix, value = null) {
        if (fix.action === 'dismiss') {
            this.dismissed.add(gap.id);
            this.save('dismissed', [...this.dismissed]);
            this.steps = this.steps.filter((step) => step.gap.id !== gap.id);
            this.go(this.nextOpen(this.index));

            return;
        }

        this.busy = gap.id;
        this.paint();

        try {
            const result = await this.adapter.run(gap, fix, value);

            if (result?.message) this.announce(result.message);
            if (result?.fixed) {
                const step = this.steps.find((s) => s.gap.id === gap.id);

                if (step) step.status = 'fixed';

                const left = this.steps.filter((s) => s.status !== 'fixed').length;
                this.announce(left === 1 ? this.t('Fixed. 1 left.') : this.t('Fixed. :count left.', { count: left }));
                this.index = this.nextOpen(this.index);
            }
        } finally {
            this.busy = null;
            this.adapter.recheck?.();
            this.paint({ focus: true, fly: true });
        }
    }

    // -- Highlights and tags on the fields ----------------------------------

    highlight() {
        const seen = new Set();
        const counted = this.steps;

        counted.forEach((step, i) => {
            const field = this.adapter.locate(step.gap);

            if (!field) return;

            const state = step.status === 'fixed' ? 'fixed' : !this.minimised && i === this.index && !this.done() ? 'current' : 'open';
            const prior = field.getAttribute('data-gw-gap');

            // Several gaps in one field: the current one wins, then open.
            if (seen.has(field) && (prior === 'current' || state !== 'current') && !(prior === 'fixed' && state !== 'fixed')) return;

            seen.add(field);
            field.setAttribute('data-gw-gap', state);

            if (!field.dataset.gwNote) {
                field.dataset.gwNote = `gw-f-note-${Math.random().toString(36).slice(2, 8)}`;
            }

            let tag = field.querySelector(':scope > .gw-f-tag');

            if (!tag) {
                tag = el('button', { type: 'button', class: 'gw-f-tag', onclick: (event) => {
                    event.preventDefault();
                    this.openAt(Number(tag.dataset.step));
                } });
                field.append(tag);
                this.resizes.observe(field);
            }

            const text = state === 'fixed' ? this.t('Fixed ✓') : `${i + 1} · ${step.gap.speech}`;

            if (tag.dataset.step !== String(i) || tag.textContent !== text) {
                tag.dataset.step = String(i);
                tag.textContent = text;
                tag.setAttribute('aria-label', this.t('Gap :n of :total: :label', { n: i + 1, total: counted.length, label: step.gap.speech }));
            }

            let note = document.getElementById(field.dataset.gwNote);

            if (!note) {
                note = el('span', { id: field.dataset.gwNote, class: 'gw-f-sr' });
                field.append(note);
            }

            const said = `Ghostwriter: ${step.gap.speech}`;

            if (note.textContent !== said) note.textContent = said;

            const control = field.querySelector('input, textarea, [contenteditable="true"]');

            if (control && !(control.getAttribute('aria-describedby') ?? '').includes(field.dataset.gwNote)) {
                control.setAttribute('aria-describedby', `${control.getAttribute('aria-describedby') ?? ''} ${field.dataset.gwNote}`.trim());
            }
        });

        // Fields no longer named by any step.
        document.querySelectorAll('[data-gw-gap]').forEach((field) => {
            if (!seen.has(field)) this.clearField(field);
        });
    }

    unhighlight() {
        document.querySelectorAll('[data-gw-gap]').forEach((field) => this.clearField(field));
    }

    clearField(field) {
        field.removeAttribute('data-gw-gap');
        field.querySelector(':scope > .gw-f-tag')?.remove();
        if (field.dataset.gwNote) document.getElementById(field.dataset.gwNote)?.remove();
    }

    // -- The flying mark ----------------------------------------------------

    async reveal(step) {
        if (!step) return;

        this.wanted = step;

        const shown = await this.adapter.reveal(step.gap);

        this.later(() => this.fly(step, shown), this.still ? 0 : 120);
    }

    target(step) {
        const field = step ? this.adapter.locate(step.gap) : null;

        if (!field || !field.offsetParent) return null;

        const tag = field.querySelector(':scope > .gw-f-tag');
        const r = field.getBoundingClientRect();
        const t = tag?.getBoundingClientRect();

        if (r.bottom < 0 || r.top > window.innerHeight) return null;

        return { x: (t?.width ? t.left : r.right - 120) - 50, y: Math.max(8, r.top - 46) };
    }

    // A CMS dialog or stack is on top: the mark keeps out of its way.
    covered() {
        return !!document.querySelector('[role="dialog"]:not([hidden]), [data-ui-stack]:not([hidden])');
    }

    fly(step, shown = true) {
        if (this.phone || this.panel.hidden || this.covered()) {
            this.flyer.hidden = true;

            return;
        }

        const to = shown ? this.target(step) : null;

        this.flyer.hidden = false;
        this.flyer.classList.remove('is-arrived', 'is-home');

        if (!to) {
            this.flyHome();

            return;
        }

        const tilt = this.lastX === undefined ? 0 : to.x < this.lastX ? -12 : 12;

        this.tilt.style.transform = this.still ? '' : `rotate(${tilt}deg)`;
        this.flyer.style.transform = `translate(${to.x}px, ${to.y}px)`;
        this.say.textContent = step.gap.speech;
        this.lastX = to.x;
        this.flying = step;

        this.later(() => {
            this.flyer.classList.add('is-arrived');
            this.tilt.style.transform = '';
        }, this.still ? 0 : 900);
    }

    // Beside the guide: when done, when the field can't be shown, or on the
    // way into the dock.
    flyHome(toDock = false) {
        if (this.phone) {
            this.flyer.hidden = true;

            return;
        }

        const box = (toDock ? this.dock : this.panel).getBoundingClientRect();
        const dock = toDock || !box.width;
        const x = dock ? window.innerWidth - 74 : box.left + box.width - 60;
        const y = dock ? window.innerHeight - 74 : box.top - 50;

        this.flyer.hidden = false;
        this.flyer.classList.remove('is-arrived');
        this.flyer.classList.toggle('is-home', toDock);
        this.flyer.style.transform = `translate(${x}px, ${y}px)${toDock && !this.still ? ' scale(.6) rotate(360deg)' : ''}`;
        this.say.textContent = toDock ? '' : this.done() ? this.t('All done!') : this.t('Over here');
        this.flying = null;

        if (!toDock) this.later(() => this.flyer.classList.add('is-arrived'), this.still ? 0 : 900);
    }

    // Keeps the mark on its field while the page scrolls (any scrolling
    // pane: the listener is on capture), resizes or re-renders.
    follow() {
        if (this.panel.hidden || this.phone || this.minimised) return;

        this.flyer.classList.toggle('is-covered', this.covered());

        // The field wasn't there when the mark set off (the form was still
        // drawing it): fly to it now it is.
        if (!this.flying && this.wanted && !this.done() && this.steps[this.index]?.gap.id === this.wanted.gap.id && this.target(this.wanted)) {
            this.fly(this.wanted);

            return;
        }

        if (this.flyer.hidden || !this.flying) return;

        const to = this.target(this.flying);

        if (!to) return;

        this.flyer.classList.add('is-following');
        this.flyer.style.transform = `translate(${to.x}px, ${to.y}px)`;
        this.lastX = to.x;
        cancelAnimationFrame(this.unfollow);
        this.unfollow = requestAnimationFrame(() => requestAnimationFrame(() => this.flyer.classList.remove('is-following')));
    }

    soon() {
        if (this.frame) return;

        this.frame = requestAnimationFrame(() => {
            this.frame = null;
            this.highlight();
            this.follow();
        });
    }

    puff() {
        if (this.still) return;

        const r = this.dock.getBoundingClientRect();
        const cx = r.left + r.width / 2;
        const cy = r.top + r.height / 2;

        [[-38, -26], [-44, 8], [-20, -42], [6, -46], [-34, 30]].forEach(([dx, dy], i) => {
            const wisp = el('span', { class: 'gw-f-wisp', 'aria-hidden': 'true' });

            wisp.style.left = `${cx - 4}px`;
            wisp.style.top = `${cy - 4}px`;
            wisp.style.setProperty('--dx', `${document.dir === 'rtl' ? -dx : dx}px`);
            wisp.style.setProperty('--dy', `${dy}px`);
            wisp.style.animationDelay = `${i * 40}ms`;
            this.root.append(wisp);
            this.later(() => wisp.remove(), 900);
        });
    }

    // -- Keyboard -----------------------------------------------------------

    panelKey(event) {
        if (event.key === 'Escape' && !event.defaultPrevented) {
            event.preventDefault();
            event.stopPropagation();
            this.minimise();
        }
    }

    shortcut(event) {
        if (!event.altKey || !event.shiftKey || event.metaKey || event.ctrlKey || isTyping(event.target) || this.pill.hidden) return;

        const act = { KeyN: () => (this.minimised ? this.restore() : this.next()), KeyP: () => (this.minimised ? this.restore() : this.back()), KeyG: () => (this.minimised ? this.restore() : this.minimise()) }[event.code];

        if (!act) return;

        event.preventDefault();
        act();
    }

    // -- Helpers ------------------------------------------------------------

    announce(text) {
        this.live.textContent = '';
        requestAnimationFrame(() => (this.live.textContent = text));
    }

    later(callback, ms) {
        if (this.still || ms === 0) {
            callback();

            return;
        }

        const timer = setTimeout(() => {
            this.timers.delete(timer);
            callback();
        }, ms);

        this.timers.add(timer);
    }

    clearTimers() {
        this.timers.forEach((timer) => clearTimeout(timer));
        this.timers.clear();
    }

    load(what) {
        try {
            return JSON.parse(sessionStorage.getItem(`${this.key}.${what}`) ?? '[]');
        } catch (error) {
            return [];
        }
    }

    save(what, value) {
        try {
            sessionStorage.setItem(`${this.key}.${what}`, JSON.stringify(value));
        } catch (error) {
            // Skipping just isn't remembered across reloads.
        }
    }
}
