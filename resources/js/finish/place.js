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
export function saySide(x, label, width, { size = 44, prefer = 'left' } = {}) {
    const room = { right: width - (x + size + 2) - 8, left: x - 2 - 8 };
    const other = prefer === 'left' ? 'right' : 'left';

    if (label <= room[prefer]) return prefer;
    if (label <= room[other]) return other;

    return x + size / 2 > width / 2 ? 'below-left' : 'below-right';
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
