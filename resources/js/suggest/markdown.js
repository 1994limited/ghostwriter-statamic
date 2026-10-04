// A replacement's inline markdown (text, **bold**, *italic*, [links](to)),
// as the words alone or as TipTap content for Bard, where links to the
// site's entries are Statamic's `statamic://entry::id`.

/**
 * @returns {Array<{text: string, bold: boolean, italic: boolean, href: string|null}>}
 */
export function tokens(markdown) {
    const source = String(markdown ?? '');
    const out = [];
    const pattern = /\*\*([^*]+)\*\*|__([^_]+)__|\*([^*]+)\*|_([^_]+)_|\[([^\]]+)\]\(([^)\s]+)\)/g;
    let last = 0;
    let match;

    while ((match = pattern.exec(source)) !== null) {
        if (match.index > last) out.push({ text: source.slice(last, match.index), bold: false, italic: false, href: null });

        if (match[1] ?? match[2]) out.push({ text: match[1] ?? match[2], bold: true, italic: false, href: null });
        else if (match[3] ?? match[4]) out.push({ text: match[3] ?? match[4], bold: false, italic: true, href: null });
        else out.push({ text: match[5], bold: false, italic: false, href: href(match[6]) });

        last = match.index + match[0].length;
    }

    if (last < source.length) out.push({ text: source.slice(last), bold: false, italic: false, href: null });

    return out.filter((token) => token.text !== '');
}

/** A link target as Bard stores it: an entry or asset reference gets `statamic://`. */
export function href(target) {
    const value = String(target ?? '');

    return /^(entry|asset)::/.test(value) ? `statamic://${value}` : value;
}

/** The words alone, for a text field. */
export function plain(markdown) {
    return tokens(markdown).map((token) => token.text).join('');
}

/**
 * TipTap content for Bard: text nodes with their marks, each also given
 * `inherit` (the marks the replaced words all had: a quote wholly in bold
 * stays bold).
 */
export function bardContent(markdown, inherit = []) {
    return tokens(markdown).map((token) => {
        const marks = inherit.filter((mark) => mark.type !== 'link' || !token.href).map((mark) => ({ ...mark }));

        if (token.bold && !marks.some((mark) => mark.type === 'bold')) marks.push({ type: 'bold' });
        if (token.italic && !marks.some((mark) => mark.type === 'italic')) marks.push({ type: 'italic' });
        if (token.href) marks.push({ type: 'link', attrs: { href: token.href } });

        return marks.length ? { type: 'text', text: token.text, marks } : { type: 'text', text: token.text };
    });
}
