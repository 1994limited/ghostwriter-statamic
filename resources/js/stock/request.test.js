// The request helpers, in Node with no DOM: node --test resources/js/stock/*.test.js
import test from 'node:test';
import assert from 'node:assert/strict';
import { transient } from './request.js';

test('a request that never got an answer, or a busy server, is worth asking again', () => {
    assert.equal(transient({ isAxiosError: true, request: {} }), true, 'axios: no answer (net::ERR_NETWORK_CHANGED)');
    assert.equal(transient(new TypeError('Failed to fetch')), true, 'fetch: no answer');
    assert.equal(transient({ isAxiosError: true, response: { status: 502 } }), true);
    assert.equal(transient({ isAxiosError: true, response: { status: 503 } }), true);
    assert.equal(transient({ response: { status: 429 } }), true);
    assert.equal(transient({ response: { status: 408 } }), true);
});

test('a refusal is not asked again', () => {
    assert.equal(transient({ isAxiosError: true, response: { status: 404 } }), false);
    assert.equal(transient({ isAxiosError: true, response: { status: 403 } }), false);
    assert.equal(transient({ isAxiosError: true, response: { status: 409 } }), false);
    assert.equal(transient({ isAxiosError: true, response: { status: 422 } }), false);
    assert.equal(transient(new Error('Something went wrong.')), false);
    assert.equal(transient(null), false);
});
