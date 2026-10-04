// Core's marker patterns (Gaps\Markers::patterns(), resources/gaps/patterns.json),
// so the form highlights exactly what the server finds. Nothing is retyped here.
import patterns from '../../../vendor/1994/ghostwriter-core/resources/gaps/patterns.json';

const make = ({ source, flags }) => new RegExp(source, flags.includes('g') ? flags : `${flags}g`);

export const LINK_PREFIX = patterns.linkPrefix;

export function asks(text) {
    return [...text.matchAll(make(patterns.ask))].map((m) => ({ index: m.index, length: m[0].length, match: m[0], hint: m[1].trim() }));
}

// Counts to check: `[[check: 3 areas | from: …]]`. `hint` is the value, as core's gap has it.
export function checks(text) {
    return [...text.matchAll(make(patterns.check))].map((m) => ({ index: m.index, length: m[0].length, match: m[0], hint: m[1].trim(), list: m[2].trim() }));
}

export function leftovers(text) {
    return [...text.matchAll(make(patterns.leftover))].map((m) => ({ index: m.index, length: m[0].length, match: m[0], hint: m[1] }));
}

export function isLinkSentinel(href) {
    return typeof href === 'string' && href.includes(LINK_PREFIX);
}

// A `#gw-link:` hint as core reads it (Markers::linkHintFrom()): decoded,
// spaces and all ("Winter%20structure" and "Winter structure" alike).
export function hintFrom(written) {
    let hint = String(written ?? '');

    try {
        hint = decodeURIComponent(hint);
    } catch {
        // A stray `%`: keep it as written.
    }

    return hint.replace(/\s+/g, ' ').trim();
}

export function linkHint(href) {
    if (!isLinkSentinel(href)) return null;

    return hintFrom(href.slice(href.indexOf(LINK_PREFIX) + LINK_PREFIX.length));
}

// Markdown links still to choose, as core finds them: `[words](#gw-link:hint)`.
export function links(text) {
    return [...text.matchAll(make(patterns.link))].map((m) => ({ index: m.index, length: m[0].length, match: m[0], words: m[1], hint: hintFrom(m[2]) }));
}

// A hint as core compares them: lower case, words only.
export function normaliseHint(hint) {
    return String(hint ?? '').toLowerCase().replace(/[^\p{L}\p{N}]+/gu, ' ').trim();
}

// Where a sentence holding [from, to) starts and ends in a line of text.
export function sentenceAround(text, from, to) {
    let start = 0;
    const before = text.slice(0, from);
    const stops = [...before.matchAll(/[.!?](?=\s)/g)];

    if (stops.length) start = stops[stops.length - 1].index + 1;

    while (start < from && /\s/.test(text[start])) start++;

    const after = text.slice(to);
    const stop = after.match(/[.!?](?=\s|$)/);
    const end = stop ? to + stop.index + 1 : text.length;

    return { start, end };
}
