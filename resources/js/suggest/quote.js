// Core's QuoteFinder, ported: finds a suggestion's quote in a field's
// current text the way the server found it, so a range still finds its
// words after the text around it moves. Tested against core's own cases
// (quote-cases.json, kept identical to core's by a PHP test).
//
// Offsets and lengths are in characters (code points), as core counts
// them; utf16() turns one into a JavaScript string index.

const QUOTES = { '‘': "'", '’': "'", '‚': "'", '‛': "'", '′': "'", '“': '"', '”': '"', '„': '"', '‟': '"', '″': '"' };
const DASHES = new Set(['‐', '‑', '‒', '–', '—', '―', '−']);
const SPACE = new RegExp('^[\\s' + String.fromCodePoint(0xa0, 0x1680) + String.fromCodePoint(0x2000) + '-' + String.fromCodePoint(0x200a, 0x2028, 0x2029, 0x202f, 0x205f, 0x3000) + ']$', 'u');
const INVISIBLE = /^[\u{E0000}-\u{E007F}\u{00AD}\u{200B}-\u{200D}\u{2060}\u{FEFF}]$/u;
export const FUZZY = 0.9;
export const FUZZY_MIN = 16;

function skipLineStart(chars, start, skip) {
    const count = chars.length;
    let i = start;

    for (;;) {
        let j = i;

        while (j < count && (chars[j] === ' ' || chars[j] === '\t')) j++;

        let k = j;

        if (k < count && chars[k] === '#') {
            while (k < count && chars[k] === '#') k++;
        } else if (k < count && chars[k] === '>') {
            k++;
        } else if (k < count && ['-', '*', '+'].includes(chars[k]) && (chars[k + 1] ?? '') === ' ') {
            k++;
        } else {
            while (k < count && /^[0-9]$/.test(chars[k])) k++;

            if (!(k > j && k < count && (chars[k] === '.' || chars[k] === ')') && (chars[k + 1] ?? '') === ' ')) return i;

            k++;
        }

        if (k < count && chars[k] !== ' ' && chars[k] !== '\t' && chars[k] !== '\n' && chars[k - 1] !== '>') return i;

        for (let m = i; m < k; m++) skip.add(m);

        while (k < count && (chars[k] === ' ' || chars[k] === '\t')) {
            skip.add(k);
            k++;
        }

        i = k;
    }
}

function markdownSyntax(chars) {
    const skip = new Set();
    const count = chars.length;
    let lineStart = true;

    for (let i = 0; i < count; i++) {
        let char = chars[i];

        if (lineStart) {
            i = skipLineStart(chars, i, skip);
            lineStart = false;

            if (i >= count) break;

            char = chars[i];
        }

        if (char === '\n') {
            lineStart = true;
            continue;
        }

        if (char === '\\' && i + 1 < count && /^[\\`*_{}[\]()#+\-.!>]$/.test(chars[i + 1])) {
            skip.add(i);
            i++;
            continue;
        }

        if (char === '*' || char === '_' || char === '`' || char === '[') {
            skip.add(i);
            continue;
        }

        if (char === ']' && (chars[i + 1] ?? '') === '(') {
            let close = null;

            for (let j = i + 2; j < count && chars[j] !== '\n'; j++) {
                if (chars[j] === ')') {
                    close = j;
                    break;
                }
            }

            if (close !== null) {
                for (let j = i; j <= close; j++) skip.add(j);
                i = close;
                continue;
            }
        }

        if (char === ']') skip.add(i);
    }

    return skip;
}

function fold(char) {
    if (QUOTES[char]) return [QUOTES[char]];
    if (DASHES.has(char)) return ['-'];

    return char === '…' ? ['.', '.', '.'] : [char];
}

/** Text as QuoteFinder compares it: { chars, map, length }; map[i] is where chars[i] came from. */
export function normalise(text, markdown = false) {
    const chars = Array.from(String(text ?? ''));
    const skip = markdown ? markdownSyntax(chars) : new Set();
    const out = [];
    const map = [];
    let space = false;

    chars.forEach((char, i) => {
        if (skip.has(i) || INVISIBLE.test(char)) return;

        if (SPACE.test(char)) {
            space = out.length > 0;

            return;
        }

        if (space) {
            out.push(' ');
            map.push(i - 1);
            space = false;
        }

        fold(char).forEach((folded) => {
            out.push(folded);
            map.push(i);
        });
    });

    return { chars: out, text: out.join(''), map, length: chars.length };
}

function original(norm, offset, length) {
    if (norm.map.length === 0 || length <= 0) return [norm.map[offset] ?? norm.length, 0];

    const start = norm.map[offset] ?? norm.length;
    const end = (norm.map[Math.min(offset + length, norm.map.length) - 1] ?? norm.length - 1) + 1;

    return [start, Math.max(0, end - start)];
}

// Every start of the needle in the haystack, in characters.
function exact(hay, needle) {
    const found = [];

    outer: for (let i = 0; i + needle.length <= hay.length; i++) {
        for (let j = 0; j < needle.length; j++) {
            if (hay[i + j] !== needle[j]) continue outer;
        }

        found.push(i);
    }

    return found;
}

const commonSuffix = (a, b) => {
    const x = Array.from(a);
    const y = Array.from(b);
    let n = 0;

    while (n < x.length && n < y.length && x[x.length - 1 - n] === y[y.length - 1 - n]) n++;

    return n;
};

const commonPrefix = (a, b) => {
    const x = Array.from(a);
    const y = Array.from(b);
    let n = 0;

    while (n < x.length && n < y.length && x[n] === y[n]) n++;

    return n;
};

function pick(found, hay, length, quote, markdown, occurrence) {
    if (found.length === 1) return 0;

    const prefix = normalise(quote.prefix ?? '', markdown).chars;
    const suffix = normalise(quote.suffix ?? '', markdown).chars;

    if (prefix.length || suffix.length) {
        const scores = found.map((at) => {
            const before = hay.slice(Math.max(0, at - prefix.length - 1), at).join('');
            const after = hay.slice(at + length, at + length + suffix.length + 1).join('');

            return commonSuffix(before.trim(), prefix.join('').trim()) + commonPrefix(after.trim(), suffix.join('').trim());
        });
        const order = scores.map((score, i) => [score, i]).sort((a, b) => b[0] - a[0] || a[1] - b[1]);

        if (order[0][0] > 0 && (order[1]?.[0] ?? -1) < order[0][0]) return order[0][1];
    }

    return occurrence !== null && occurrence !== undefined && found[occurrence] !== undefined ? occurrence : null;
}

function trigrams(text) {
    const chars = Array.from(` ${text.toLowerCase()} `);
    const grams = new Map();

    for (let i = 0; i + 2 < chars.length; i++) {
        const gram = chars[i] + chars[i + 1] + chars[i + 2];
        grams.set(gram, (grams.get(gram) ?? 0) + 1);
    }

    return grams;
}

function dice(a, b) {
    let shared = 0;
    let total = 0;

    a.forEach((count, gram) => {
        shared += Math.min(count, b.get(gram) ?? 0);
        total += count;
    });
    b.forEach((count) => (total += count));

    return total === 0 ? 0 : (2 * shared) / total;
}

function fuzzy(norm, needle) {
    const length = needle.length;

    if (length < FUZZY_MIN) return null;

    const target = trigrams(needle.join(''));
    const text = norm.chars;
    const spans = [];
    let i = 0;

    while (i < text.length) {
        if (text[i] === ' ') {
            i++;
            continue;
        }

        let j = i;
        while (j < text.length && text[j] !== ' ') j++;

        const word = text.slice(i, j).join('');
        const bare = Array.from(word.replace(/[\p{P}\p{S}]+$/u, '')).length;
        spans.push([i, [...new Set([j, i + Math.max(1, bare)])]]);
        i = j;
    }

    const candidates = [];

    for (let s = 0; s < spans.length; s++) {
        const start = spans[s][0];

        for (let e = s; e < spans.length; e++) {
            spans[e][1].forEach((end) => {
                const size = end - start;

                if (size < length * 0.75 || size > length * 1.25) return;

                const score = dice(target, trigrams(text.slice(start, start + size).join('')));

                if (score >= FUZZY) candidates.push([start, size, score]);
            });

            if (spans[e][1][0] - start > length * 1.25) break;
        }
    }

    if (!candidates.length) return null;

    candidates.sort((a, b) => a[0] - b[0]);
    const places = [];

    candidates.forEach((candidate) => {
        const last = places[places.length - 1];

        if (last && candidate[0] < last[0] + last[1]) {
            if (candidate[2] > last[2]) places[places.length - 1] = candidate;

            return;
        }

        places.push(candidate);
    });

    if (places.length !== 1) return null;

    const [offset, size] = original(norm, places[0][0], places[0][1]);

    return { offset, length: size, occurrence: 0, fuzzy: true };
}

/**
 * Where a quote ({exact, prefix, suffix}) is in some text: { offset,
 * length, occurrence, fuzzy } in characters, or null when it isn't there
 * or can't be told apart.
 */
export function findQuote(quote, text, occurrence = null, markdown = false) {
    const hay = normalise(text, markdown);
    const needle = normalise(quote?.exact ?? '', markdown).chars;

    if (!needle.length || !hay.chars.length) return null;

    const found = exact(hay.chars, needle);

    if (found.length) {
        const index = pick(found, hay.chars, needle.length, quote, markdown, occurrence);

        if (index === null) return null;

        const [offset, length] = original(hay, found[index], needle.length);

        return { offset, length, occurrence: index, fuzzy: false };
    }

    return fuzzy(hay, needle);
}

/** A character offset (code points) as a JavaScript string index. */
export function utf16(text, offset) {
    return Array.from(String(text)).slice(0, offset).join('').length;
}

/** The same match in UTF-16 indices: [start, end). */
export function rangeIn(text, match) {
    if (!match) return null;

    const start = utf16(text, match.offset);

    return [start, start + Array.from(String(text)).slice(match.offset, match.offset + match.length).join('').length];
}
