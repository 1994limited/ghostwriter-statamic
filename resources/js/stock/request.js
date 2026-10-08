// A JSON request to Ghostwriter's Control Panel routes, from code outside
// a Vue component (which would use this.$axios). Throws with the server's
// message, as axios would, so callers can show it.
export async function request(url, { method = 'GET', body = null } = {}) {
    const response = await fetch(url, {
        method,
        credentials: 'same-origin',
        headers: {
            Accept: 'application/json',
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest',
            'X-CSRF-TOKEN': Statamic.$config.get('csrfToken'),
        },
        body: body ? JSON.stringify(body) : null,
    });

    const data = await response.json().catch(() => ({}));

    if (!response.ok) {
        const first = Object.values(data.errors ?? {})[0]?.[0];
        const error = new Error(first ?? data.message ?? __('Something went wrong.'));
        error.response = { status: response.status, data };
        throw error;
    }

    return data;
}

/**
 * Whether a request failed on the way rather than being refused: no answer
 * at all (the network changed or dropped), or the server busy or timing
 * out. One worth asking again: a poll that stops on one leaves the screen
 * waiting for ever. Works for axios's errors and request()'s.
 *
 * @param {unknown} error
 * @returns {boolean}
 */
export function transient(error) {
    const status = error?.response?.status;

    if (status !== undefined && status !== null && status !== 0) return status === 408 || status === 429 || status >= 500;

    return Boolean(error?.isAxiosError || error instanceof TypeError);
}
