// An SEO title or description as the publish form holds it, for the fixes
// that put one in (Finish this page's "Use this", Suggest edits): SEO Pro
// keeps each key of its field as { source, value }, so the text goes in as
// its custom value; a plain field takes the text as it is. The same rule
// as the server's StatamicSeoWriter, which "Use this draft" goes through.

/** Whether a value is SEO Pro's form shape: { source, value }. */
export const isSeoPro = (value) => !!value && typeof value === 'object' && !Array.isArray(value) && 'source' in value;

/**
 * The value to set at an SEO field's place: `{ source: 'custom', value }`
 * where the field is SEO Pro's (`pro`) or already holds that shape, else
 * the text.
 */
export function seoValue(current, text, pro = false) {
    return pro || isSeoPro(current) ? { source: 'custom', value: text } : text;
}

/** An SEO value's text, in either shape. */
export const seoText = (value) => (isSeoPro(value) ? value.value ?? '' : value ?? '');

/**
 * Whether a gap's field is inside SEO Pro's field: its place is two deep
 * (`seo.description`) and the form holds an object there.
 */
export const inSeoPro = (dotted, values) => {
    const [handle, key, ...rest] = String(dotted ?? '').split('.');
    const field = values?.[handle];

    return rest.length === 0 && key !== undefined && !!field && typeof field === 'object' && !Array.isArray(field);
};
