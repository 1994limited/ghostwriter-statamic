// Plain words in Markdown text, as core's ProposedLinks finds them.

// Every place some words are in Markdown text outside a link or image, in
// order: where a link Suggest links found can still go (core's
// ProposedLinks counts the repeats the same way).
export function unlinkedWords(text, words) {
    const found = [];
    const value = String(text ?? '');
    const needle = String(words ?? '');

    if (!needle) return found;

    const taken = [...value.matchAll(/!?\[[^\[\]\n]*\]\(\s*<?[^()\s>]*>?(?:\s+"[^"\n]*")?\s*\)/gu)].map((m) => [m.index, m.index + m[0].length]);
    let at = value.indexOf(needle);

    while (at !== -1) {
        if (!taken.some(([start, end]) => at < end && at + needle.length > start)) found.push({ index: at, length: needle.length, words: needle });
        at = value.indexOf(needle, at + 1);
    }

    return found;
}
