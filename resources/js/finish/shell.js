// The Finish this page guide: the count on the header menu, the highlighted fields and
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

export const MARK = '<svg viewBox="0 0 14 14" aria-hidden="true" focusable="false"><path class="gw-f-body" fill-rule="evenodd" d="M2.5 12.5V6a4.5 4.5 0 0 1 9 0V9.5H8.5V12.5Z M4.75 6.25a.65.65 0 1 0 1.3 0a.65.65 0 1 0-1.3 0Z M7.95 6.25a.65.65 0 1 0 1.3 0a.65.65 0 1 0-1.3 0Z"/><path class="gw-f-fold" d="M9 10H11.5L9 12.5Z"/></svg>';

const PHONE = '(max-width: 639px)';
const STILL = '(prefers-reduced-motion: reduce)';

export function el(tag, attrs = {}, children = []) {
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

import { bringsOut, counts, currentAfter, fieldStates, firstOpen, firstToDo, nextOpen, published, stepsFrom, tagText } from './state.js';
import { coversText, inlineSpot, labelParts, pinnedBottom, saySide, sayRect, textBoxes } from './place.js';

// What is pinned over the top of the form: Statamic's header and a Bard
// toolbar stuck under it. The mark and its words never go under them.
const CHROME = 'header.bg-global-header-bg, .bard-fixed-toolbar';

const isTyping = (target) => target instanceof Element && (target.isContentEditable || ['INPUT', 'TEXTAREA', 'SELECT'].includes(target.tagName));

export class FinishGuide {
    /** Every guide on the page (Finish this page, Suggest edits): one is open at a time. */
    static guides = new Set();

    /** The guide shortcuts act on: the one opened last. */
    static active = null;

    // The field's tag, and the current gap's words in its editor (bard.js's decoration).
    tagSelector = '.gw-f-tag';
    inlineSelector = '.gw-gap-mark.is-current';

    /**
     * @param {object} options
     * @param {object} options.adapter  { locate(gap), reveal(gap), select(gap), run(gap, fix, value), recheck() }
     * @param {function} options.t  Translates an English string with :params.
     * @param {string} options.state  'open' or 'minimised', as the person last left it.
     * @param {function} options.onState  Called with the new state on minimise and restore.
     * @param {string} options.key  What skipped gaps are remembered under, for this page view.
     * @param {function} options.onCount  Called with { count, label } on each paint: the header menu's count (0 while the guide isn't out).
     */
    constructor({ adapter, t, state = 'minimised', onState = () => {}, key = 'page', onCount = () => {} }) {
        this.adapter = adapter;
        this.t = t;
        this.onState = onState;
        this.onCount = onCount;
        // Whether the guide is out (its dock or panel): until then nothing is highlighted or counted.
        this.visible = false;
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
        // Every field that had a gap in this view, so it can turn green.
        this.touched = new Set();
        this.dismissed = new Set(this.load('dismissed'));
    }

    // Puts the guide, dock, mark and live region into the page. Its count
    // is on the header menu (onCount).
    mount() {
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
        FinishGuide.guides.add(this);

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
            if (records.some((record) => !this.root.contains(record.target) && !(record.target instanceof Element && record.target.closest('.gw-f-tag')))) this.soon();
        });
        this.observer.observe(document.querySelector('[data-ghostwriter-form]') ?? document.body, { childList: true, subtree: true });
        this.resizes = new ResizeObserver(() => this.soon());
        // Anything that moves the page (a banner, a set opening) moves the
        // fields: measure again.
        this.resizes.observe(document.documentElement);

        return this;
    }

    destroy() {
        FinishGuide.guides.delete(this);
        if (FinishGuide.active === this) FinishGuide.active = null;
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
    }

    // From the header menu ("Finish this page") or Suggest edits' "1 thing
    // still to finish": the guide, brought out if it wasn't, on the first
    // step still to do, so whatever counted a step opens onto it.
    openFromMenu() {
        if (!this.shown) {
            this.shown = true;
            this.steps.forEach((step) => this.touched.add(step.gap.dotted));
        }

        this.openAt(firstToDo(this.steps));
    }

    // What is left as the header menu and Suggest edits show it (state.js published()).
    left() {
        return published(this.steps, this.index, this.shown);
    }

    // A new check. The steps are the live gaps and nothing else (state.js):
    // a gap gone since it was shown leaves the list and its field turns green
    // once nothing else is left in it; a gap a fix made is a new open step.
    update(report, { open = false } = {}) {
        this.report = report;

        const previous = this.steps[this.index];
        const previousSpeech = previous?.gap.speech;

        this.steps = stepsFrom(report, { skipped: this.skipped, dismissed: this.dismissed });
        this.index = currentAfter(this.steps, previous?.gap.id, this.index);

        if (this.steps[this.index]?.status === 'skipped' && previous?.gap.id !== this.steps[this.index].gap.id) {
            this.index = nextOpen(this.steps, this.index);
        }

        // Only something that blocks publishing, or an image the page needs,
        // brings the guide out on its own (state.js bringsOut()): a new,
        // empty form isn't nagged.
        if (open || bringsOut(this.steps)) this.shown = true;

        if (this.shown) this.steps.forEach((step) => this.touched.add(step.gap.dotted));

        if (open && this.steps.length) {
            this.index = firstOpen(this.steps);
            this.restore(true);

            return;
        }

        // A different gap (or the same field's gap of another kind) is on
        // screen now: the mark flies to it and says what it is.
        const now = this.steps[this.index];
        const moved = previous !== undefined && (now?.gap.id !== previous.gap.id || now?.gap.speech !== previousSpeech);
        const fixed = this.afterFix && !this.steps.some((step) => step.gap.id === this.afterFix);

        // A fix from the guide: say so, and focus moves on to the next step.
        if (this.afterFix) {
            const left = counts(this.steps, this.index).count;

            if (fixed) this.announce(left === 1 ? this.t('Fixed. 1 left.') : this.t('Fixed. :count left.', { count: left }));
            this.afterFix = null;
        }

        this.paint({ fly: moved && !this.minimised, focus: fixed && !this.minimised });
    }

    // -- Moving through the steps ------------------------------------------

    firstOpen() {
        return firstOpen(this.steps);
    }

    // The next step still to do from here, coming round to the start for
    // any passed by; past the end only when none is left but skipped ones.
    nextOpen(from) {
        return nextOpen(this.steps, from);
    }

    done() {
        return this.index >= this.steps.length;
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
        FinishGuide.active = this;
        FinishGuide.guides.forEach((other) => other !== this && other.stepAside());

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

    // Another guide opened: this one goes to its dock, quietly (the
    // person's choice of open or minimised isn't changed by it).
    stepAside() {
        if (this.minimised) return;

        this.minimised = true;
        this.adapter.highlight?.(null);
        this.panel.hidden = true;
        this.flyer.hidden = true;
        this.paint();
    }

    toggleSheet(open = !this.sheetOpen) {
        this.sheetOpen = open;
        this.paint();
    }

    // -- Drawing -----------------------------------------------------------

    paint({ focus = false, fly = false } = {}) {
        // One live list for every number shown: the menu, the dock, "n of
        // total" and the bar all come from the same steps.
        const numbers = counts(this.steps, this.index);
        const count = numbers.count;
        const visible = this.shown && (this.steps.length > 0 || count > 0);
        const label = count ? (count === 1 ? this.t('1 thing to finish') : this.t(':count things to finish', { count })) : this.t('Ready to publish');

        // The header menu's count: nothing until there was something to finish.
        this.visible = visible;
        this.onCount({ count: this.left(), label });

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

        // Nothing on the fields until the guide has something to show: a
        // new entry's empty fields aren't highlighted on their own.
        if (visible) this.highlight();
        else this.unhighlight();

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

        this.drawStep(this.steps[this.index], numbers);
        this.summary.textContent = [this.stepText.textContent, this.steps[this.index].gap.speech, `${this.t('Next')} →`].join(' · ');

        if (focus) {
            this.message.focus({ preventScroll: true });
            this.announce(`${numbers.suggestion ? this.t('Suggestion :n of :total.', { n: numbers.number, total: numbers.suggestions }) : this.t('Step :n of :total.', { n: numbers.number, total: numbers.total })} ${this.steps[this.index].gap.message}`);
        }

        if (fly) this.reveal(this.steps[this.index]);
        else this.follow();
    }

    drawBar() {
        // A segment for each gap still to finish: the same list as the count.
        const counted = this.steps.filter((step) => step.gap.severity !== 'suggestion');

        this.bar.replaceChildren(...counted.map((step) => el('i', { class: step === this.steps[this.index] ? 'is-current' : step.status === 'skipped' ? 'is-skipped' : '' })));
    }

    drawEnd() {
        const { skipped, count: still } = counts(this.steps, this.index);

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

    drawStep(step, numbers) {
        const { gap } = step;
        const suggestions = numbers.suggestions;

        this.foot.hidden = false;
        this.stepText.textContent = numbers.suggestion
            ? this.t('Suggestion :n of :total', { n: numbers.number, total: numbers.suggestions })
            : `${numbers.number} ${this.t('of')} ${numbers.total}`;
        this.message = el('p', { class: 'gw-f-msg', tabindex: '-1', text: gap.message });

        const parts = [this.message];

        if (gap.reason) parts.push(el('p', { class: 'gw-f-reason', text: gap.reason }));
        if (gap.severity === 'suggestion') parts.push(el('p', { class: 'gw-f-reason', text: this.t('A suggestion: it won\'t stop the page going live.') }));

        if (gap.kind === 'stock-preview' && gap.meta?.expired) parts.push(el('p', { class: 'gw-f-reason', text: this.t('The preview has expired.') }));

        parts.push(this.fixes(step));

        if (this.index === 0 && suggestions && numbers.count) {
            parts.push(el('p', { class: 'gw-f-reason', text: `${numbers.count === 1 ? this.t('1 thing to finish') : this.t(':count things to finish', { count: numbers.count })}, ${suggestions === 1 ? this.t('and 1 suggestion') : this.t('and :count suggestions', { count: suggestions })}.` }));
        }

        this.body.replaceChildren(...parts);
        this.adapter.highlight?.(gap);
    }

    // The fixes, primary first. A fact gets an answer box: always editable,
    // Enter or leaving it puts the words in, Esc puts it back. A count to
    // check gets "Change it", which opens its value to edit.
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

            // "Change it" on a count to check: the value, editable, in place
            // of the button. Enter puts it in; Esc goes back to the button.
            if (fix.action === 'change') {
                const input = el('input', { type: 'text', class: 'gw-f-answer', 'aria-label': fix.label, autocomplete: 'off' });
                const wrap = el('label', { class: 'gw-f-answer-wrap', hidden: true }, [el('span', { class: 'gw-f-sr', text: fix.label }), input]);
                const open = this.fixButton(fix.label, { disabled: this.busy === gap.id, onclick: () => {
                    open.hidden = true;
                    wrap.hidden = false;
                    input.value = fix.value ?? gap.hint ?? '';
                    input.focus();
                    input.select();
                } });

                input.addEventListener('keydown', (event) => {
                    if (event.key === 'Enter') {
                        event.preventDefault();

                        if (input.value.trim() && !input.dataset.done) {
                            input.dataset.done = '1';
                            this.run(gap, fix, input.value.trim());
                        }
                    } else if (event.key === 'Escape') {
                        event.stopPropagation();
                        wrap.hidden = true;
                        open.hidden = false;
                        open.focus();
                    }
                });
                box.append(open, wrap);

                return;
            }

            box.append(this.fixButton(fix.label, { name: fix.name, primary: fix.primary, model: fix.cost === 'model', disabled: this.busy === gap.id, onclick: () => this.run(gap, fix) }));
        });

        return box;
    }

    // A fix's button. The label stays on one line inside the guide: the name
    // in it (an entry's title) is cut short with an ellipsis when there's no
    // room, so "Link to" always shows, and the whole label is its tooltip and
    // accessible name. "uses Ghostwriter" is kept apart from it.
    fixButton(label, { name = null, primary = false, model = false, disabled = false, onclick = null } = {}) {
        const parts = labelParts(label, name);
        const uses = this.t('uses Ghostwriter');

        return el('button', {
            type: 'button',
            class: `gw-f-btn${primary ? ' is-primary' : ''}`,
            title: label,
            'aria-label': model ? `${label} (${uses})` : label,
            disabled,
            onclick,
        }, [
            primary ? el('span', { class: 'gw-f-btn-mark', html: MARK }) : null,
            el('span', { class: 'gw-f-btn-text' }, [
                parts.lead ? el('span', { class: 'gw-f-btn-keep', text: parts.lead }) : null,
                el('span', { class: 'gw-f-btn-name', text: parts.name }),
                parts.tail ? el('span', { class: 'gw-f-btn-keep', text: parts.tail }) : null,
            ]),
            model ? el('small', { text: uses }) : null,
        ]);
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

            // The CMS's own editor or picker is open for the person: leave
            // focus with it.
            if (result?.stay) {
                this.busy = null;
                this.adapter.recheck?.();
                this.paint();

                return;
            }

            // Whether it is fixed, and what the fix left (a placeholder
            // swapped for a stock preview is a new gap), is the next check's
            // to say: the steps and the fields' states come only from it.
            if (result?.fixed) {
                this.busy = null;
                this.afterFix = gap.id;
                this.adapter.recheck?.(true);

                return;
            }
        } finally {
            this.busy = null;
        }

        this.adapter.recheck?.();
        this.paint({ focus: true, fly: true });
    }

    // -- Highlights and tags on the fields ----------------------------------

    highlight() {
        const current = !this.minimised && !this.done() ? this.index : -1;
        const fields = fieldStates(this.steps, current, this.touched);
        const seen = new Set();

        fields.forEach((state, dotted) => {
            const field = this.adapter.locate(state.step?.gap ?? { dotted });

            if (!field || seen.has(field)) return;

            seen.add(field);

            if (field.getAttribute('data-gw-gap') !== state.state) field.setAttribute('data-gw-gap', state.state);

            if (!field.dataset.gwNote) {
                field.dataset.gwNote = `gw-f-note-${Math.random().toString(36).slice(2, 8)}`;
            }

            // The tag sits beside the field's name, clear of its border and
            // of the CMS's own buttons.
            const host = this.adapter.tagHost?.(field) ?? field;
            let tag = field.querySelector('.gw-f-tag');

            if (tag && tag.parentElement !== host) {
                tag.remove();
                tag = null;
            }

            if (!tag) {
                tag = el('button', { type: 'button', class: 'gw-f-tag', onclick: (event) => {
                    event.preventDefault();
                    event.stopPropagation();
                    if (tag.dataset.step !== '') this.openAt(Number(tag.dataset.step));
                } });
                tag.classList.toggle('is-inline', host !== field);
                host.append(tag);
                this.resizes.observe(field);
            }

            const text = tagText(state, this.steps, this.t);
            const target = state.state === 'current' ? current : (state.steps[0] ?? '');

            if (tag.dataset.step !== String(target) || tag.textContent !== text) {
                tag.dataset.step = String(target);
                tag.textContent = text;
                tag.setAttribute('aria-label', state.state === 'fixed' ? this.t('Fixed') : this.t('Gap :n of :total: :label', { n: Number(target) + 1, total: this.steps.length, label: state.step?.gap.speech ?? '' }));
            }

            let note = document.getElementById(field.dataset.gwNote);

            if (!note) {
                note = el('span', { id: field.dataset.gwNote, class: 'gw-f-sr' });
                field.append(note);
            }

            const said = state.state === 'fixed' ? `Ghostwriter: ${this.t('Fixed')}` : `Ghostwriter: ${state.step.gap.speech}`;

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
        field.querySelector('.gw-f-tag')?.remove();
        if (field.dataset.gwNote) document.getElementById(field.dataset.gwNote)?.remove();
    }

    // -- The flying mark ----------------------------------------------------

    async reveal(step) {
        if (!step) return;

        this.wanted = step;

        const shown = await this.adapter.reveal(step.gap);

        // Words inline in an editor go to the middle of it, clear of the
        // header and its toolbar, and are tinted for a moment.
        const inline = shown ? this.inline(step) : null;

        if (inline) {
            const box = inline.getBoundingClientRect();
            const top = this.chromeBottom(inline) + 60;

            if (box.top < top || box.bottom > window.innerHeight - (this.phone ? 260 : 40)) {
                inline.scrollIntoView({ block: 'center', behavior: this.still ? 'auto' : 'smooth' });
                await new Promise((resolve) => setTimeout(resolve, this.still ? 0 : 350));
            }

            this.tint(step);
        }

        this.later(() => this.fly(step, shown), this.still ? 0 : 120);
    }

    // The step's field in the form.
    fieldFor(step) {
        return step ? this.adapter.locate(step.gap) : null;
    }

    // The step's words in its field's editor (a link, a marker, a quote):
    // where the mark points. Null when they aren't inline.
    inline(step) {
        const field = this.fieldFor(step);

        return field?.offsetParent ? field.querySelector(this.inlineSelector) : null;
    }

    // The bottom of what's pinned over the form, and of the editor's own
    // toolbar when pointing into it.
    chromeBottom(inline = null) {
        const own = inline?.closest('.bard-fieldtype, .bard-fieldtype-wrapper')?.querySelector('.bard-fixed-toolbar');

        return pinnedBottom([...document.querySelectorAll(CHROME)].map((element) => element.getBoundingClientRect()), own?.getBoundingClientRect() ?? null, window.innerHeight);
    }

    // Where the mark goes for a step: beside its words in the editor, else
    // just past the tag beside the field's name. Null when it can't be seen.
    target(step) {
        const field = this.fieldFor(step);

        if (!field || !field.offsetParent) return null;

        const inline = field.querySelector(this.inlineSelector);
        const top = this.chromeBottom(inline);

        if (inline) {
            const line = inline.getClientRects()[0];

            return line ? inlineSpot(line, { top, width: window.innerWidth, height: window.innerHeight, mirror: document.dir === 'rtl' }) : null;
        }

        const tag = field.querySelector(this.tagSelector);
        const r = field.getBoundingClientRect();
        const t = tag?.getBoundingClientRect();

        if (r.bottom < top || r.top > window.innerHeight) return null;

        // Just past the tag beside the field's name, above the field.
        return { x: t?.width ? Math.min(t.right + 8, window.innerWidth - 60) : r.right - 170, y: Math.max(top + 4, r.top - 46) };
    }

    // A tint over the step's words that fades, so the eye finds them; still
    // under reduced motion, then gone.
    tint(step) {
        this.flashing?.remove();

        const flashing = el('div', { class: 'gw-f-flash', 'aria-hidden': 'true' });

        this.flashing = flashing;
        this.flashingStep = step;
        this.root.append(flashing);
        this.placeTint();
        setTimeout(() => flashing.remove(), 1800);
    }

    // The tint over the words where they are now: moved, not drawn again,
    // as the page scrolls, so its fade carries on.
    placeTint() {
        if (!this.flashing?.isConnected) return;

        const marks = [...(this.fieldFor(this.flashingStep)?.querySelectorAll(this.inlineSelector) ?? [])];
        const rects = marks.flatMap((mark) => [...mark.getClientRects()]);
        const lines = [...this.flashing.children];

        lines.slice(rects.length).forEach((line) => line.remove());
        rects.forEach((rect, i) => {
            const line = lines[i] ?? this.flashing.appendChild(el('span', { class: 'gw-f-flash-line' }));

            Object.assign(line.style, { left: `${rect.left - 3}px`, top: `${rect.top - 2}px`, width: `${rect.width + 6}px`, height: `${rect.height + 4}px` });
        });
    }

    // The mark at x, y, its words on whichever side fits in the window and
    // covers none of the page's own (a field's words, a sidebar's dates, a
    // label); none hidden: the mark alone (data-say="none").
    place(x, y) {
        const label = this.say.offsetWidth;
        const height = this.say.offsetHeight || 20;
        let boxes = null;
        const covers = (side) => coversText(sayRect(side, x, y, label, height), { boxes: (boxes ??= textBoxes({ skip: '.gw-f-flyer, .gw-f-flash' })) });

        this.flyer.style.transform = `translate(${x}px, ${y}px)`;
        this.flyer.dataset.say = saySide(x, label, window.innerWidth, { prefer: 'left', covers: label ? covers : null, y, height });
    }

    // A CMS dialog or stack is on top: the mark keeps out of its way.
    covered() {
        return !!document.querySelector('[role="dialog"]:not([hidden]), .stack-container');
    }

    fly(step, shown = true) {
        if (this.phone || this.panel.hidden || this.covered()) {
            this.flyer.hidden = true;

            return;
        }

        const to = shown ? this.target(step) : null;

        this.flyer.hidden = false;
        this.flyer.classList.remove('is-arrived', 'is-home', 'is-away');

        if (!to) {
            this.flyHome();

            return;
        }

        const tilt = this.lastX === undefined ? 0 : to.x < this.lastX ? -12 : 12;

        this.tilt.style.transform = this.still ? '' : `rotate(${tilt}deg)`;
        // The tag beside the field already says what it needs; the mark just
        // says hello.
        this.say.textContent = this.greeting(step);
        this.place(to.x, to.y);
        this.lastX = to.x;
        this.flying = step;

        this.later(() => {
            this.flyer.classList.add('is-arrived');
            this.tilt.style.transform = '';
        }, this.still ? 0 : 900);
    }

    greeting(step) {
        const lines = [this.t('Your turn'), this.t('Over here'), this.t('This one')];

        return lines[Math.max(0, this.steps.indexOf(step)) % lines.length];
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
        this.say.textContent = toDock ? '' : this.done() ? this.t('All done!') : this.t('Over here');
        this.place(x, y);
        if (toDock && !this.still) this.flyer.style.transform += ' scale(.6) rotate(360deg)';
        this.flying = null;

        if (!toDock) this.later(() => this.flyer.classList.add('is-arrived'), this.still ? 0 : 900);
    }

    // Keeps the mark on its field while the page scrolls (any scrolling
    // pane: the listener is on capture), resizes or re-renders.
    follow() {
        if (this.panel.hidden || this.phone || this.minimised) return;

        this.flyer.classList.toggle('is-covered', this.covered());
        this.placeTint();

        // The field wasn't there when the mark set off (the form was still
        // drawing it): fly to it now it is.
        if (!this.flying && this.wanted && !this.done() && this.steps[this.index]?.gap.id === this.wanted.gap.id && this.target(this.wanted)) {
            this.fly(this.wanted);

            return;
        }

        if (this.flyer.hidden || !this.flying) return;

        const to = this.target(this.flying);

        // Its field (or its words) scrolled out of sight: the mark fades until it's back.
        this.flyer.classList.toggle('is-away', !to);

        if (!to) return;

        this.flyer.classList.add('is-following');
        this.place(to.x, to.y);
        this.lastX = to.x;
        cancelAnimationFrame(this.unfollow);
        this.unfollow = requestAnimationFrame(() => requestAnimationFrame(() => this.flyer.classList.remove('is-following')));
    }

    soon() {
        if (this.frame) return;

        this.frame = requestAnimationFrame(() => {
            this.frame = null;
            // A CMS stack or dialog (a selector, License & replace) is on top:
            // the guide steps out of its way until it closes.
            this.root.classList.toggle('is-covered', this.covered());
            if (this.visible) this.highlight();
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
        if (!event.altKey || !event.shiftKey || event.metaKey || event.ctrlKey || isTyping(event.target) || !this.visible) return;

        // Another guide on the page was opened last: the keys are its.
        const active = FinishGuide.active;

        if (active && active !== this && FinishGuide.guides.has(active) && active.visible) return;

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
