// Small DOM helpers for the Statamic adapter, kept free of Vue and the CMS
// so they can be tested on their own.

const visible = (element) => !element.hidden && element.offsetParent !== null && element.getAttribute?.('aria-hidden') !== 'true';

/**
 * The assets field's own "Browse" button. A one-image field that is full
 * (it holds the placeholder) keeps it, and choosing there replaces the
 * image. The visible one is preferred: Statamic draws a short "Browse" for
 * narrow layouts and hides whichever doesn't fit.
 */
export function browseButton(field) {
    const buttons = [...(field?.querySelectorAll('button') ?? [])].filter((button) => /\bbrowse\b/i.test(button.textContent ?? '') && !button.disabled);

    return buttons.find(visible) ?? buttons[0] ?? null;
}
