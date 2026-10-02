// What Ghostwriter's stock image ledger says about the assets on the
// forms open in this Control Panel: `container::path` → its record's
// summary, or null when the ledger doesn't know it. Reactive, so the
// "Preview · not licensed" badge on an assets field appears and goes as
// records change (a preview inserted, licensed, or loaded).
import { reactive } from 'vue';
import { request } from './request.js';

export const stock = reactive({ assets: {} });

const asked = new Set();
let waiting = [];
let timer = null;

// Looks up assets the store hasn't seen, a few at a time.
export function ensure(keys) {
    const base = Statamic.$config.get('ghostwriter')?.url;

    keys.filter((key) => typeof key === 'string' && key.includes('::') && !asked.has(key)).forEach((key) => {
        asked.add(key);
        waiting.push(key);
    });

    if (!waiting.length || !base || timer) return;

    timer = setTimeout(async () => {
        const batch = waiting;
        waiting = [];
        timer = null;

        try {
            const { assets } = await request(`${base}/stock/assets`, { method: 'POST', body: { assets: batch } });

            batch.forEach((key) => (stock.assets[key] = assets[key] ?? null));
        } catch (error) {
            batch.forEach((key) => asked.delete(key));
        }
    }, 150);
}

// A record that changed: inserted, licensed, requested or refreshed.
export function put(summary) {
    if (!summary?.asset) return;

    asked.add(summary.asset);
    stock.assets[summary.asset] = summary;
}

// The unlicensed preview among a field's value, if any.
export function previewIn(value) {
    const keys = (Array.isArray(value) ? value : value ? [value] : []).filter((key) => typeof key === 'string');

    ensure(keys);

    return keys.map((key) => stock.assets[key]).find((summary) => summary?.unlicensed) ?? null;
}
