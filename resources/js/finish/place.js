// Where the guide's pieces go, worked out from numbers alone so it can be
// tested without a page: a fix's label in parts, the mark beside the words
// it points at, and which side of the mark its words go.

// A fix's label in parts, so only the name in it is cut short: "Link to "
// stays, "Winter structure: plants that…" gives way. Names past `cap`
// characters are shortened whatever the room.
export function labelParts(label, name, cap = 40) {
    const text = String(label ?? '');
    const at = name ? text.indexOf(String(name)) : -1;

    if (at < 0) return { lead: '', name: text, tail: '' };

    const whole = String(name);
    const shown = whole.length > cap ? `${whole.slice(0, cap - 1).trimEnd()}…` : whole;

    return { lead: text.slice(0, at), name: shown, tail: text.slice(at + whole.length) };
}

// Where the mark sits for words inside an editor (a link, a marker): just
// above their first line, its middle over where they start, so it never
// covers them; below the line when the CP header or the editor's toolbar
// (everything down to `top`) leaves no room above. Null while the line is
// under that chrome or off screen.
export function inlineSpot(line, { top = 0, width, height, size = 44, mirror = false }) {
    if (line.bottom <= top + 2 || line.top >= height - 2) return null;

    const start = mirror ? line.right : line.left;
    const x = Math.min(Math.max(4, start - size / 2), width - size - 4);
    const above = line.top - size - 2;

    return { x, y: above >= top + 4 ? above : line.bottom + 4 };
}

// Which side of the mark its words go: `prefer` when they fit in the window,
// the other side when not, else under it, leaning away from the nearer edge.
// With `covers` (side → whether the words there would sit on the page's
// text), a side that fits and covers nothing wins: either side, then above,
// then below; when every side covers something, the first that fits.
export function saySide(x, label, width, { size = 44, prefer = 'left', covers = null, y = null, height = 20 } = {}) {
    const room = { right: width - (x + size + 2) - 8, left: x - 2 - 8 };
    const other = prefer === 'left' ? 'right' : 'left';
    const lean = x + size / 2 > width / 2 ? ['left', 'right'] : ['right', 'left'];
    const fits = [prefer, other].filter((side) => label <= room[side]);

    if (covers) {
        const vertical = (where) => lean.map((side) => `${where}-${side}`).filter((side) => (side.endsWith('left') ? x + size : width - x) >= label + 4);
        const above = y === null || y - height - 4 >= 4 ? vertical('above') : [];
        const clear = [...fits, ...above, ...vertical('below')].find((side) => !covers(side));

        if (clear) return clear;
    }

    return fits[0] ?? `below-${lean[0]}`;
}

// Where the mark's words would sit on screen for a side (saySide), the mark
// at (x, y): beside it at its top, or above or below it.
export function sayRect(side, x, y, label, height, size = 44) {
    const left = side === 'right' ? x + size + 2 : side === 'left' ? x - 2 - label : side.endsWith('-left') ? x + size - label : x;
    const top = side.startsWith('above') ? y - height - 4 : side.startsWith('below') ? y + size + 2 : y + 2;

    return { left, top, right: left + label, bottom: top + height };
}

// Whether a box on screen sits on the page's text: the topmost element that
// isn't `skip` (the mark's own) at each of nine points in it, and whether any
// of their words (or a text box) meet the box.
export function coversText(rect, { skip = '', doc = document, win = window } = {}) {
    const pad = 2;
    const found = new Set();

    [rect.left + 1, (rect.left + rect.right) / 2, rect.right - 1].forEach((px) => [rect.top + 1, (rect.top + rect.bottom) / 2, rect.bottom - 1].forEach((py) => {
        if (px < 0 || py < 0 || px > win.innerWidth || py > win.innerHeight) return;

        const node = doc.elementsFromPoint(px, py).find((hit) => !skip || !hit.closest(skip));

        if (node && node !== doc.body && node !== doc.documentElement) found.add(node);
    }));

    const meets = (box) => box.right > rect.left - pad && box.left < rect.right + pad && box.bottom > rect.top - pad && box.top < rect.bottom + pad;

    return [...found].some((node) => {
        if (node.matches('input:not([type="checkbox"]):not([type="radio"]), textarea, select, [contenteditable="true"]')) return true;

        return [...node.childNodes].some((child) => {
            if (child.nodeType !== 3 || !child.textContent.trim()) return false;

            const range = doc.createRange();
            range.selectNodeContents(child);

            return [...range.getClientRects()].some(meets);
        });
    });
}

// The bottom of what is pinned over the top of the page: boxes stacked from
// the window's top edge (the CP header, a toolbar stuck under it), plus the
// given editor toolbar. Tall boxes (a sidebar, the main pane) don't count.
export function pinnedBottom(boxes, own = null, height = Infinity) {
    let bottom = 0;

    [...boxes].filter((box) => box.height > 0 && box.height < height / 3).sort((a, b) => a.top - b.top).forEach((box) => {
        if (box.top <= bottom + 2) bottom = Math.max(bottom, box.bottom);
    });

    return own && own.height > 0 ? Math.max(bottom, own.bottom) : bottom;
}
