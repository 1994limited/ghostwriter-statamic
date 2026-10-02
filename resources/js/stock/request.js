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
