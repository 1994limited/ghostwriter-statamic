// Links to the site's other pages, as core compares them: the links
// Ghostwriter's SEO pass added, which Finish this page asks the editor to
// check (core's AddedLinks). No CMS here, so it is tested on its own.

// One form for the ways a link to a page is written (core's
// LinkCandidates::linkKey()): `entry::abc` for `statamic://entry::abc`,
// `entry:12` for Craft's `{entry:12@1:url||…}` and CKEditor's
// `…#entry:12@1:url`, `path:/contact` for an address. Null for anything else.
export function linkKey(href) {
    if (typeof href !== 'string' || href.trim() === '') return null;

    let value = href.trim();

    try {
        value = decodeURI(value);
    } catch {
        // A stray `%`: keep it as written.
    }

    if (value.toLowerCase().startsWith('statamic://')) value = value.slice('statamic://'.length);

    let m = value.match(/^(\w+)::(.+)$/);
    if (m) return `${m[1].toLowerCase()}::${m[2]}`;

    m = value.match(/^\{(\w+):(\d+)(?:@\d+)?(?::[\w.]*)?(?:\|\|.*)?\}$/s);
    if (m) return `${m[1].toLowerCase()}:${m[2]}`;

    m = value.match(/#(entry|category|asset):(\d+)(?:@\d+)?(?::[\w.]*)?$/);
    if (m) return `${m[1]}:${m[2]}`;

    if (/^(#|mailto:|tel:|javascript:)/i.test(value)) return null;

    let path = value;

    try {
        path = new URL(value, 'http://x').pathname;
    } catch {
        return null;
    }

    return `path:${path.replace(/\/+$/, '').toLowerCase()}`;
}

// Markdown links to a page (`[words](href)`), as core's AddedLinks finds them.
export function linksTo(text, href) {
    const key = linkKey(href);

    if (!key) return [];

    return [...String(text).matchAll(/(?<!!)\[([^\[\]\n]*)\]\(\s*<?([^()\s>]*)>?(?:\s+"[^"\n]*")?\s*\)/gu)]
        .filter((m) => linkKey(m[2]) === key)
        .map((m) => ({ index: m.index, length: m[0].length, match: m[0], words: m[1], href: m[2] }));
}

