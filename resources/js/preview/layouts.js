// The layout cards and the extras in the writing panel: what to show, with
// no DOM, so Node can test it (node --test resources/js/preview/*.test.js).
// The components draw it (LayoutCards.vue, ExtrasList.vue).

// The page is rendered this wide and scaled down into a card.
export const THUMB_RENDER_WIDTH = 1280;
export const THUMB_HEIGHT = 118;
export const MAX_CARDS = 3;

// Where Compare layouts (the cards with thumbnails, not the chips) is remembered.
export const COMPARE_KEY = 'ghostwriter.layouts.compare';

/**
 * Whether the cards show their thumbnails: off (a row of chips, so the page
 * preview has the room) unless Compare layouts was left on in this browser.
 * Storage that can't be read counts as off.
 */
export function compareRemembered(storage) {
    try {
        return storage?.getItem(COMPARE_KEY) === '1';
    } catch {
        return false;
    }
}

export function rememberCompare(storage, on) {
    try {
        storage?.setItem(COMPARE_KEY, on ? '1' : '0');
    } catch {
        // Not remembered: it's only a convenience.
    }
}

/**
 * The cards for a piece: the writer's draft and up to two others, and a
 * skeleton for each the planner may still add. Nothing to show (no row) for
 * a piece with one layout and no planner at work.
 *
 * @param {{planning?: boolean, chosen?: string, stale?: boolean, plans?: object[]}|null} layouts
 * @returns {{show: boolean, cards: object[], skeletons: number, stale: boolean, planning: boolean, chosen: ?string}}
 */
export function cardsFor(layouts) {
    const plans = layouts?.plans ?? [];
    const planning = Boolean(layouts?.planning);
    const skeletons = planning ? Math.max(0, MAX_CARDS - plans.length) : 0;
    const show = plans.length > 1 || (planning && plans.length > 0);

    return {
        show,
        cards: plans.slice(0, MAX_CARDS).map((plan) => ({ ...plan, chosen: plan.id === layouts?.chosen })),
        skeletons: show ? skeletons : 0,
        stale: Boolean(layouts?.stale) && !planning,
        planning: show && planning,
        chosen: layouts?.chosen ?? null,
    };
}

/**
 * "Use this draft (Numbers first)" once there is more than one layout;
 * otherwise the button's own words.
 */
export function useLabel(base, layouts) {
    const name = layouts?.chosen_name;

    return name ? `${base} (${name})` : base;
}

/**
 * How far a page rendered at THUMB_RENDER_WIDTH is scaled to fill a card.
 */
export function thumbScale(width) {
    return width > 0 ? width / THUMB_RENDER_WIDTH : 0.18;
}

/**
 * The cards as a radio group by keyboard: arrows move (and wrap), Home and
 * End go to the ends. A card that needs refreshing is passed over.
 *
 * @returns {number|null} the index to move to, or null for a key it doesn't handle
 */
export function moveTo(cards, index, key) {
    const step = { ArrowRight: 1, ArrowDown: 1, ArrowLeft: -1, ArrowUp: -1 }[key];
    const usable = cards.map((card, i) => (card.stale ? null : i)).filter((i) => i !== null);

    if (!usable.length) return null;
    if (key === 'Home') return usable[0];
    if (key === 'End') return usable[usable.length - 1];
    if (!step) return null;

    const at = usable.indexOf(index);
    const from = at === -1 ? (step > 0 ? -1 : 0) : at;

    return usable[(from + step + usable.length) % usable.length];
}

/**
 * The order thumbnails are rendered in, one at a time once the main
 * preview has loaded: the chosen layout's (the same render as the page),
 * then the others in order. Stale ones are skipped.
 */
export function thumbOrder(cards) {
    const usable = cards.filter((card) => !card.stale);

    return [...usable.filter((card) => card.chosen), ...usable.filter((card) => !card.chosen)].map((card) => card.id);
}

/**
 * What a card is called to a screen reader: "Numbers first, suggested, 4
 * blocks: Hero, Stats, Text, Call to action. The numbers up top."
 */
export function cardName(card, t = (text, params) => fill(text, params)) {
    const bits = [card.name];

    if (card.suggested) bits.push(t('suggested'));
    if (card.stale) bits.push(t('needs refreshing'));

    const blocks = card.blocks === 1 ? t('1 block') : t(':count blocks', { count: card.blocks });
    const outline = card.outline?.length ? `: ${card.outline.join(', ')}` : '';
    const changes = card.changes?.length ? ` ${t('Changes: :changes', { changes: card.changes.join(', ') })}.` : '';

    return `${bits.join(', ')}, ${blocks}${outline}.${changes}${card.description ? ` ${card.description}.` : ''}`.replace(/\.\./g, '.');
}

/**
 * What a layout changes against the writer's, on one line beside its chip
 * or under its card: "Quote moved up · Text blocks joined". Empty for the
 * writer's.
 */
export function changesText(card) {
    return (card?.changes ?? []).join(' · ');
}

/**
 * The preview's blocks to point at after switching to a layout: its
 * `places` ({field, block, section}, as core's LayoutDiff counts them)
 * found in the render's block map. A page builder's nth top-level block of
 * that field, or the top-level field itself, and in it the nth section of
 * rich text when the map has sections there (else the whole block). In
 * page order, each once.
 *
 * @param {object[]} map The render's block map.
 * @param {{field: string, block: ?number, section: ?number}[]} places
 * @returns {string[]}
 */
export function keysForPlaces(map, places) {
    if (!Array.isArray(map) || !Array.isArray(places)) return [];

    const keys = [];

    for (const place of places) {
        const owner = place.block === null || place.block === undefined
            ? map.find((entry) => entry.kind === 'field' && entry.type === place.field)
            : map.filter((entry) => entry.kind === 'block' && !entry.parent && String(entry.path ?? '').split('/')[0] === place.field)[place.block];

        if (!owner) continue;

        const sections = map.filter((entry) => entry.kind === 'section' && entry.parent === owner.key);
        const key = place.section !== null && place.section !== undefined && sections.length ? sections[place.section]?.key ?? owner.key : owner.key;

        if (!keys.includes(key)) keys.push(key);
    }

    const order = map.map((entry) => entry.key);

    return keys.sort((a, b) => order.indexOf(a) - order.indexOf(b));
}

/**
 * An extra item as the editor changed it, for PATCH extras/{item}: its
 * text and, for kinds that have them, its named parts. What the editor
 * didn't touch goes back as it was stored (`raw`), so a count still to
 * check keeps its marker. A stat shown as its number and label keeps its
 * text in step with them: "4 visits a winter" with the label changed is
 * "4 visits each winter"; a new number is the editor's own.
 */
export function extraPayload(item, field, value) {
    const raw = item.raw ?? { text: item.text, parts: item.parts ?? {} };
    const parts = { ...(raw.parts ?? {}) };
    let text = raw.text;

    if (field === 'text') {
        text = value;
    } else {
        parts[field] = value;

        if (isStat(item) && field === 'label') {
            const at = raw.text.lastIndexOf(item.parts.label);
            text = at === -1 ? `${item.parts.value} ${value}` : raw.text.slice(0, at) + value + raw.text.slice(at + item.parts.label.length);
        } else if (isStat(item) && field === 'value') {
            text = `${value} ${item.parts.label}`;
        }
    }

    return Object.keys(parts).length ? { text, parts } : { text };
}

/**
 * A stat with its number and label apart: shown as those two, its text
 * kept from them.
 */
export function isStat(item) {
    return Boolean(item?.parts?.value) && Boolean(item?.parts?.label);
}

/**
 * Whether an edit changed anything worth saving.
 */
export function changed(item, field, value) {
    const was = field === 'text' ? item.text : item.parts?.[field] ?? '';

    return String(value).trim() !== String(was).trim();
}

/**
 * The words for a part of an extra item, beside its field.
 */
export function partLabel(part, t = (text) => text) {
    return {
        value: t('Number'),
        label: t('Label'),
        question: t('Question'),
        attribution: t('Who said it'),
        button: t('Button'),
        for: t('Image'),
    }[part] ?? part;
}

function fill(text, params = {}) {
    return Object.entries(params).reduce((out, [key, value]) => out.replaceAll(`:${key}`, String(value)), text);
}

/**
 * Where the layouts start to differ: the number of blocks every card
 * begins with alike ("Hero" in all three is 1). A thumbnail starts a
 * little above that block, so the cards show what sets them apart rather
 * than the same hero three times. 0 when they differ from the top, or
 * when there is only one.
 */
export function sharedStart(cards) {
    const outlines = cards.filter((card) => !card.stale).map((card) => card.outline ?? []);

    if (outlines.length < 2) return 0;

    let n = 0;

    while (outlines.every((outline) => n < outline.length - 1 && outline[n] === outlines[0][n])) n += 1;

    return n;
}

/**
 * The block a thumbnail starts at, from the render's block map: the nth
 * block at the top of the page (a page builder's set), or the nth section
 * of rich text when there is no page builder. Null for the top.
 */
export function startBlock(map, n) {
    if (!n || !Array.isArray(map)) return null;

    const top = map.filter((block) => block.kind === 'block' && !block.parent);
    const blocks = top.length ? top : map.filter((block) => block.kind === 'section');

    return blocks[n]?.key ?? null;
}

/**
 * The same places in the draft's Blocks and Text views (core's
 * DraftPreview nodes): a page builder's nth block, or a whole top-level
 * field (rich text isn't split into its sections there).
 *
 * @returns {{fields: string[], blocks: Object<string, number[]>}}
 */
export function marksFor(places) {
    const marks = { fields: [], blocks: {} };

    for (const place of places ?? []) {
        if (place.block === null || place.block === undefined) {
            if (!marks.fields.includes(place.field)) marks.fields.push(place.field);
        } else {
            marks.blocks[place.field] ??= [];
            if (!marks.blocks[place.field].includes(place.block)) marks.blocks[place.field].push(place.block);
        }
    }

    return marks;
}

/** How long a switched-to layout's changes stay outlined, in ms. */
export const SWITCH_HIGHLIGHT_MS = 2200;
