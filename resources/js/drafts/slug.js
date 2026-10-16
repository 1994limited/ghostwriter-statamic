// Ghostwriter's address for a new entry, into Statamic's own slug field.
//
// The field makes a slug from a new title a moment later (a request), which
// would replace Ghostwriter's, so the address is set again a few times
// while that settles. Each time only over a slug nobody typed: an empty
// field, one the field made from the draft's title, or the one Ghostwriter
// set before. Anything else is a person's (or anyone's) and is left alone.

/** The waits, in ms, after which the address is set again. */
export const RETRIES = [0, 400, 1200, 2500];

/**
 * Whether the slug field's value may be replaced with Ghostwriter's.
 *
 * @param {unknown} current The field's value now.
 * @param {{ fromTitle?: string[], ours?: string[] }} untouched What the field holds when nobody typed in it.
 */
export function replaceable(current, { fromTitle = [], ours = [] } = {}) {
    const slug = typeof current === 'string' ? current.trim() : '';

    return slug === '' || fromTitle.includes(slug) || ours.includes(slug);
}

/**
 * Sets the address now and again after each wait, only while the field
 * holds a slug nobody typed and isn't being typed in.
 *
 * @param {object} options
 * @param {string} options.slug Ghostwriter's address.
 * @param {string[]} [options.fromTitle] The slugs the field makes from the draft's title.
 * @param {string[]} [options.ours] Addresses Ghostwriter set before.
 * @param {() => unknown} options.get The field's value.
 * @param {(slug: string) => void} options.set Sets the field.
 * @param {() => boolean} [options.typing] Whether the field has focus.
 * @param {(fn: () => void, ms: number) => unknown} [options.later] setTimeout.
 * @returns {() => void} Stops the retries still to come.
 */
export function keepSlug({ slug, fromTitle = [], ours = [], get, set, typing = () => false, later = setTimeout }) {
    let stopped = false;
    const untouched = { fromTitle, ours: [...ours, slug] };

    const attempt = () => {
        if (stopped || typing()) return;

        const current = get();

        if (current === slug) return;

        // Someone else's: leave it, and stop setting it again.
        if (!replaceable(current, untouched)) {
            stopped = true;

            return;
        }

        set(slug);
    };

    RETRIES.forEach((wait) => later(attempt, wait));

    return () => {
        stopped = true;
    };
}
