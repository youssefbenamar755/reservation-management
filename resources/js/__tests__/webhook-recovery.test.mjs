import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { runInNewContext } from 'node:vm';
import ts from 'typescript';

const code = ts.transpileModule(readFileSync(new URL('../lib/webhookRecovery.ts', import.meta.url), 'utf8'), {
    compilerOptions: { target: ts.ScriptTarget.ES2022, module: ts.ModuleKind.CommonJS },
}).outputText;
const flush = () => new Promise(setImmediate);
function deferred() {
    let resolve, reject;
    const promise = new Promise((yes, no) => { resolve = yes; reject = no; });
    return { promise, resolve, reject };
}
function detail(id = 7, canRetry = true, status = 'failed') {
    return { event: { id, can_retry: canRetry, retry_reason: null, status }, attempts: [] };
}
function response(body, status = 200) {
    return { ok: status >= 200 && status < 300, status, json: async () => body };
}
function harness() {
    const exports = {};
    const unexpectedTimer = () => { throw new Error('Recovery must not start an idle timer'); };
    runInNewContext(code, { exports, AbortController, Error, setTimeout: unexpectedTimer, setInterval: unexpectedTimer });
    const requests = [];
    let state, changed = 0, settled = 0;
    const recovery = exports.createWebhookRecovery({
        fetch(url, options) { const request = { url, options, ...deferred() }; requests.push(request); return request.promise; },
        csrf: () => 'test-csrf',
        onState: value => { state = value; changed++; },
        onRetrySettled: () => { settled++; },
    });
    return { recovery, requests, get state() { return state; }, get changed() { return changed; }, get settled() { return settled; } };
}
async function open(h, id = 7, canRetry = true) {
    const operation = h.recovery.open(id);
    h.requests.at(-1).resolve(response(detail(id, canRetry)));
    await operation;
}

test('recovery only reads when opened and creates no polling timers', async () => {
    const h = harness();
    await flush();
    assert.equal(h.requests.length, 0);
    await open(h);
    await flush();
    assert.equal(h.requests.length, 1);
    assert.equal(h.requests[0].options.cache, 'no-store');
    assert.equal(h.requests[0].options.credentials, 'same-origin');
});

test('a slow previous delivery cannot replace the newly selected delivery', async () => {
    const h = harness();
    const first = h.recovery.open(7);
    const second = h.recovery.open(8);
    assert.equal(h.requests[0].options.signal.aborted, true);
    h.requests[1].resolve(response(detail(8)));
    await second;
    h.requests[0].resolve(response(detail(7)));
    await first;
    assert.equal(h.state.eventId, 8);
    assert.equal(h.state.detail.event.id, 8);
    assert.equal(h.state.loading, false);
});

test('closing a delivery aborts its read and ignores delayed responses', async () => {
    const h = harness();
    const pending = h.recovery.open(7);
    h.recovery.close();
    h.requests[0].resolve(response(detail(7)));
    await pending;
    assert.equal(h.requests[0].options.signal.aborted, true);
    assert.equal(h.state.eventId, null);
    assert.equal(h.state.detail, null);
});

test('invalid or failed detail responses never display raw response or parser errors', async () => {
    const h = harness();
    const operation = h.recovery.open(7);
    h.requests[0].resolve({ ok: true, json: async () => { throw new Error('secret-token private@example.test'); } });
    await operation;
    assert.match(h.state.error, /could not be loaded/);
    assert.doesNotMatch(h.state.error, /secret|private@/);
    const wrong = h.recovery.open(7);
    h.requests[1].resolve(response(detail(99)));
    await wrong;
    assert.equal(h.state.detail, null);
});

test('ineligible deliveries and pending reads cannot submit a retry', async () => {
    const h = harness();
    const pending = h.recovery.open(7);
    await h.recovery.retry();
    assert.equal(h.requests.length, 1);
    h.requests[0].resolve(response(detail(7, false, 'queued')));
    await pending;
    await h.recovery.retry();
    assert.equal(h.requests.length, 1);
});

test('a double retry click makes one authenticated POST and refreshes the attempt history', async () => {
    const h = harness();
    await open(h);
    const retry = h.recovery.retry();
    await h.recovery.retry();
    assert.equal(h.requests.length, 2);
    assert.equal(h.state.retrying, true);
    const post = h.requests[1];
    assert.equal(post.url, '/website-health/events/7/retry');
    assert.equal(post.options.method, 'POST');
    assert.equal(post.options.headers['X-CSRF-TOKEN'], 'test-csrf');
    assert.equal(post.options.body, '{}');
    post.resolve(response({}, 202));
    await flush();
    assert.equal(h.requests[2].url, '/website-health/events/7');
    const updated = detail(7, false, 'processed');
    updated.attempts = [{ id: 4, status: 'succeeded' }];
    h.requests[2].resolve(response(updated));
    await retry;
    assert.equal(h.state.detail.attempts[0].status, 'succeeded');
    assert.equal(h.state.retrying, false);
    assert.equal(h.settled, 1);
    assert.match(h.state.notice, /Retry requested/);
});

test('retry completion after changing the selected delivery preserves the new detail', async () => {
    const h = harness();
    await open(h);
    const retry = h.recovery.retry();
    const newer = h.recovery.open(8);
    h.requests[2].resolve(response(detail(8)));
    await newer;
    h.requests[1].resolve(response({}, 202));
    await retry;
    assert.equal(h.state.detail.event.id, 8);
    assert.equal(h.state.notice, '');
    assert.equal(h.requests.length, 3);
    assert.equal(h.settled, 1);
});

for (const [status, message] of [[422, /no longer eligible/], [429, /Too many retry/], [419, /session or access/], [500, /could not be confirmed/]]) {
    test(`retry HTTP ${status} is explicit and requires a fresh eligibility check`, async () => {
        const h = harness();
        await open(h);
        const retry = h.recovery.retry();
        h.requests[1].resolve(response({ message: 'password=secret customer@example.test' }, status));
        await retry;
        assert.match(h.state.error, message);
        assert.doesNotMatch(h.state.error, /password|secret|customer@/);
        assert.equal(h.state.detail.event.can_retry, false);
        await h.recovery.retry();
        assert.equal(h.requests.length, 2);
        assert.equal(h.settled, 1);
        await open(h);
        assert.equal(h.state.detail.event.can_retry, true);
    });
}

test('an ambiguous network failure blocks repeat retry until details are refreshed', async () => {
    const h = harness();
    await open(h);
    const retry = h.recovery.retry();
    h.requests[1].reject(new Error('Connection lost'));
    await retry;
    assert.match(h.state.error, /could not be confirmed/);
    assert.equal(h.state.detail.event.can_retry, false);
    assert.equal(h.settled, 1);
});

test('disposing during a retry cannot update an unmounted page or schedule refresh', async () => {
    const h = harness();
    await open(h);
    const retry = h.recovery.retry();
    h.recovery.dispose();
    const changed = h.changed;
    h.requests[1].resolve(response({}, 202));
    await retry;
    assert.equal(h.changed, changed);
    assert.equal(h.settled, 0);
    assert.equal(h.requests.length, 2);
});
