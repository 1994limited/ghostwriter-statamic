// Statamic's Link fieldtype, driven through the publish form's own state.
//
// The fieldtype keeps its mode (URL, Entry…) and its URL text locally, set
// from its meta: writing only the form's value leaves it showing the old
// URL. It does watch its meta, though, and takes its mode, URL and
// selected entry from it whenever the meta changes. So a link is set by
// writing both: the meta (mode Entry, the entry selected, the entry type's
// own meta loaded so its title shows) and the value (`entry::<id>`).

/**
 * Where a field's meta sits in the form's meta: a set's fields are under
 * `existing.<the set's _id>`, so `page_builder.0.button_link` is
 * `page_builder.existing.ab12.button_link`.
 */
export function metaPath(dotted, values) {
    const parts = String(dotted).split('.');
    const out = [];
    let value = values;

    parts.forEach((part) => {
        const item = Array.isArray(value) && /^\d+$/.test(part) ? value[Number(part)] : null;

        if (item && typeof item === 'object' && item._id) {
            out.push('existing', item._id);
        } else {
            out.push(part);
        }

        value = value == null ? undefined : value[part];
    });

    return out.join('.');
}

export const get = (object, path) => String(path).split('.').reduce((value, key) => (value == null ? undefined : value[key]), object);

/** Whether a field's meta is a Link fieldtype's that can link to entries. */
export function isLinkMeta(meta) {
    return !!meta && typeof meta === 'object' && 'initialOption' in meta && !!meta.types?.entry;
}

/** The meta that makes a Link field show Entry, with these entries' details loaded. */
export function entryMeta(meta, ids, typeMeta) {
    return {
        ...meta,
        initialOption: 'entry',
        types: {
            ...meta.types,
            entry: { ...meta.types.entry, ...(typeMeta ? { meta: typeMeta, metaLoaded: true } : {}), selected: ids },
        },
    };
}

/** The entry ID in `entry::abc` (or `statamic://entry::abc`). */
export const entryId = (value) => (typeof value === 'string' ? (value.match(/entry::([^\s"']+)/)?.[1] ?? null) : null);

/**
 * A "Link to …" fix's value as Bard's link takes it: a title match's
 * `entry::abc` gets `statamic://`; the page the SEO pass suggested comes
 * as the pass writes it already (`statamic://entry::abc`), and an address
 * stays as it is.
 */
export const bardHref = (value) => {
    const target = String(value ?? '').trim();

    return /^(entry|asset)::/.test(target) ? `statamic://${target}` : target;
};
