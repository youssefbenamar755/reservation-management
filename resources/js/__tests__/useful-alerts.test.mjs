import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { runInNewContext } from 'node:vm';
import ts from 'typescript';

const source = readFileSync(new URL('../lib/usefulAlerts.ts', import.meta.url), 'utf8');
function load(get) {
    const module = { exports: {} };
    const axios = { get, isAxiosError: () => false };
    const output = ts.transpileModule(source, { compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022, esModuleInterop: true } }).outputText;
    runInNewContext(output, { module, exports: module.exports, require: () => axios, URL, AbortController, setTimeout, clearTimeout });
    return module.exports;
}
const center = () => ({ data: [], summary: { active: 0, snoozed: 0, resolved: 0 }, checked_at: null,
    thresholds: { webhook_minutes: 5, processing_hours: 24 }, current_page: 1, last_page: 1, per_page: 25, total: 0, from: null, to: null });
const deferred = () => { let resolve; const promise = new Promise(done => { resolve = done; }); return { promise, resolve }; };
const tick = () => new Promise(done => setImmediate(done));

test('alerts reject external action destinations and malformed snapshots before replacing the view', () => {
    const api = load();
    for (const url of ['https://evil.test', '//evil.test', '/\\evil.test', '/\nevil.test', 'javascript:alert(1)']) {
        assert.equal(api.usefulAlertActionUrl(url), false);
    }
    assert.equal(api.usefulAlertActionUrl('/orders/123?website_id=9'), true);
    assert.equal(api.readUsefulAlertCenter(center()).checked_at, null);
    assert.throws(() => api.readUsefulAlertCenter({ ...center(), summary: { active: -1, snoozed: 0, resolved: 0 } }));
    assert.throws(() => api.readUsefulAlertCenter({ ...center(), checked_at: 'invalid' }));
});

test('a late alerts GET cannot overwrite a newer website filter', async () => {
    const response = deferred();
    const api = load(() => response.promise);
    let url = 'https://wphub.test/alerts', applied = false;
    const task = api.refreshUsefulAlertsSnapshot({ getUrl: () => url, isCurrent: () => true,
        signal: new AbortController().signal, apply: async () => { applied = true; return true; } });
    url = 'https://wphub.test/alerts?website_id=9';
    response.resolve({ data: { alertCenter: center() } });
    assert.equal(await task, 'cancelled');
    assert.equal(applied, false);
});

test('a delayed Inertia updater becomes invalid when the refresh deadline expires', async () => {
    const update = deferred();
    let applyGuard, expire;
    const api = load(async () => ({ data: { alertCenter: center() } }));
    const task = api.refreshUsefulAlertsSnapshot({ getUrl: () => '/alerts', isCurrent: () => true,
        signal: new AbortController().signal, apply: async (_, guard) => { applyGuard = guard; return update.promise; },
        setTimer: callback => { expire = callback; return 1; }, clearTimer: () => {} });
    await tick();
    assert.equal(applyGuard(), true);
    expire();
    assert.equal(await task, 'error');
    assert.equal(applyGuard(), false);
    update.resolve(false);
});

test('leaving alerts cancels a pending read and prevents a late update', async () => {
    const response = deferred(), abort = new AbortController();
    let applied = false;
    const api = load(() => response.promise);
    const task = api.refreshUsefulAlertsSnapshot({ getUrl: () => '/alerts', isCurrent: () => true, signal: abort.signal,
        apply: async () => { applied = true; return true; } });
    abort.abort();
    assert.equal(await task, 'cancelled');
    response.resolve({ data: { alertCenter: center() } });
    await tick();
    assert.equal(applied, false);
});

test('failed or malformed reads preserve the previous view and a valid read applies once', async () => {
    let applied = 0;
    const options = { getUrl: () => '/alerts', isCurrent: () => true, signal: new AbortController().signal,
        apply: async () => { applied++; return true; } };
    assert.equal(await load(async () => { throw Error('offline'); }).refreshUsefulAlertsSnapshot(options), 'error');
    assert.equal(await load(async () => ({ data: { alertCenter: {} } })).refreshUsefulAlertsSnapshot(options), 'error');
    assert.equal(applied, 0);
    assert.equal(await load(async () => ({ data: { alertCenter: center() } })).refreshUsefulAlertsSnapshot(options), 'success');
    assert.equal(applied, 1);
});
