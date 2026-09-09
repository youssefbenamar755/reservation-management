import { compileScript, parse } from '@vue/compiler-sfc';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import test from 'node:test';
import { runInNewContext } from 'node:vm';
import ts from 'typescript';

const require = createRequire(import.meta.url);
const vue = require('vue');
const plain = (value) => JSON.parse(JSON.stringify(value));
const source = (path) =>
    readFileSync(new URL(`../${path}`, import.meta.url), 'utf8');
const flush = async () => {
    await vue.nextTick();
    await new Promise(setImmediate);
};
const deferred = () => {
    let resolve, reject;
    const promise = new Promise((done, fail) => {
        resolve = done;
        reject = fail;
    });
    return { promise, resolve, reject };
};
const ga4 = (overrides = {}) => ({
    totals: { users: 10, sessions: 20, views: 40, engagement_rate: 0.5 },
    previous: { users: 5, sessions: 10, views: 30, engagement_rate: 0.4 },
    daily: [{ date: '2026-09-08', users: 10, sessions: 20, views: 40 }],
    sources: [{ name: 'google / organic', sessions: 20 }],
    pages: [{ name: '/', views: 40 }],
    countries: [{ name: 'France', users: 10 }],
    devices: [{ name: 'mobile', users: 10 }],
    timezone: 'Europe/Paris',
    ...overrides,
});
const gsc = (overrides = {}) => ({
    totals: { clicks: 10, impressions: 100, ctr: 0.1, position: 4 },
    previous: { clicks: 5, impressions: 50, ctr: 0.1, position: 6 },
    daily: [{ date: '2026-09-08', clicks: 10, impressions: 100 }],
    queries: [
        {
            name: 'flight reservation',
            clicks: 10,
            impressions: 100,
            ctr: 0.1,
            position: 4,
        },
    ],
    pages: [
        {
            name: 'https://example.test/',
            clicks: 10,
            impressions: 100,
            ctr: 0.1,
            position: 4,
        },
    ],
    ...overrides,
});
const report = (overrides = {}) => ({
    website_id: 1,
    website_name: 'Website A',
    ga4_property_id: '101',
    gsc_site_url: 'sc-domain:example.test',
    status: 'ready',
    updated_at: '2026-09-09T11:59:00Z',
    error: null,
    ga4: ga4(),
    gsc: gsc(),
    notes: [],
    ...overrides,
});
const traffic = (overrides = {}) => ({
    websites: [
        { id: 1, name: 'Website A' },
        { id: 2, name: 'Website B' },
    ],
    filters: {
        website_id: null,
        start_date: '2026-08-12',
        end_date: '2026-09-08',
    },
    connection: { connected: true, email: 'reporting@example.test' },
    reports: [report()],
    orders: {
        total: 12,
        completed: 7,
        revenue: [
            { currency: 'EUR', total: 100 },
            { currency: 'USD', total: 80 },
        ],
    },
    ...overrides,
});
const settings = (overrides = {}) => ({
    app: {
        configured: true,
        client_id: 'reporting-client',
        has_secret: true,
        redirect_uri: 'https://example.test/settings/traffic/callback',
        can_manage: true,
    },
    connection: { connected: true, email: 'reporting@example.test' },
    websites: [
        {
            id: 1,
            name: 'Website A',
            base_url: 'https://example.test',
            ga4_property_id: '101',
            gsc_site_url: 'sc-domain:example.test',
        },
        {
            id: 2,
            name: 'Website B',
            base_url: 'https://second.test',
            ga4_property_id: '',
            gsc_site_url: '',
        },
    ],
    catalog: {
        ga4: [
            { id: '101', name: 'Property A' },
            { id: '102', name: 'Property B' },
        ],
        gsc: [{ url: 'sc-domain:example.test', permission: 'siteOwner' }],
        errors: [],
        loaded_at: '2026-09-09T11:59:00Z',
    },
    ...overrides,
});
function clock() {
    let current = Date.parse('2026-09-09T12:00:00Z'),
        id = 0;
    const timers = new Map();
    return {
        now: () => current,
        setTimer(callback, delay) {
            timers.set(++id, { callback, time: current + delay });
            return id;
        },
        clearTimer(id) {
            timers.delete(id);
        },
        advance(ms) {
            const end = current + ms;
            while (true) {
                const next = [...timers].sort(
                    (a, b) => a[1].time - b[1].time,
                )[0];
                if (!next || next[1].time > end) break;
                current = next[1].time;
                timers.delete(next[0]);
                next[1].callback();
            }
            current = end;
        },
        get size() {
            return timers.size;
        },
    };
}
function load(path, mocks = {}, globals = {}) {
    let content = source(path);
    if (path.endsWith('.vue'))
        content = compileScript(parse(content, { filename: path }).descriptor, {
            id: path,
        }).content;
    const { outputText } = ts.transpileModule(content, {
        compilerOptions: {
            module: ts.ModuleKind.CommonJS,
            target: ts.ScriptTarget.ES2022,
        },
    });
    const module = { exports: {} };
    runInNewContext(outputText, {
        module,
        exports: module.exports,
        console,
        AbortController,
        setTimeout,
        clearTimeout,
        URL,
        ...globals,
        require: (name) =>
            mocks[name] ??
            (name === '@/lib/traffic'
                ? load('lib/traffic.ts', mocks, globals)
                : name.startsWith('@/')
                  ? {}
                  : require(name)),
    });
    return module.exports;
}
const helpers = load('lib/traffic.ts');

test('Traffic sums period users directly and weights engagement, CTR and position by the correct denominators', () => {
    const second = report({
        website_id: 2,
        ga4: ga4({
            totals: {
                users: 30,
                sessions: 80,
                views: 160,
                engagement_rate: 0.75,
            },
            daily: [
                { date: '2026-09-08', users: 1000, sessions: 80, views: 160 },
            ],
        }),
        gsc: gsc({
            totals: { clicks: 90, impressions: 900, ctr: 0.1, position: 10 },
        }),
    });
    const total = helpers.trafficSummary([report(), second]);
    assert.deepEqual(plain(total.ga4), {
        users: 40,
        sessions: 100,
        views: 200,
        engagement_rate: 0.7,
    });
    assert.deepEqual(plain(total.gsc), {
        clicks: 100,
        impressions: 1000,
        ctr: 0.1,
        position: 9.4,
    });
    assert.equal(total.ga4Previous.sessions, 20);
    assert.equal(total.ga4Count, 2);
    assert.equal(total.gscCount, 2);
    assert.equal(
        helpers.trafficDaily([report(), second], 'sessions')[0].value,
        100,
    );
});

test('Traffic keeps successful partial sources and differentiates unavailable data from confirmed zero', () => {
    const partial = report({
        status: 'failed',
        error: 'Search unavailable.',
        gsc: null,
    });
    const total = helpers.trafficSummary([
        partial,
        report({ website_id: 2, ga4: null, gsc: null, status: 'missing' }),
    ]);
    assert.equal(total.ga4.users, 10);
    assert.equal(total.gsc, null);
    assert.equal(total.gscPrevious, null);
    assert.equal(total.ga4Count, 1);
    const zero = helpers.trafficSummary([
        report({
            ga4: ga4({
                totals: { users: 0, sessions: 0, views: 0, engagement_rate: 0 },
            }),
            gsc: gsc({
                totals: { clicks: 0, impressions: 0, ctr: 0, position: 0 },
            }),
        }),
    ]);
    assert.equal(zero.ga4.sessions, 0);
    assert.equal(zero.gsc.clicks, 0);
    assert.equal(zero.gsc.position, 0);
});

test('Traffic tables merge matching names and calculate rates from combined counts without mixing sessions and clicks', () => {
    const second = report({
        website_id: 2,
        ga4: ga4(),
        gsc: gsc({
            queries: [
                {
                    name: 'flight reservation',
                    clicks: 20,
                    impressions: 400,
                    ctr: 0.05,
                    position: 9,
                },
            ],
        }),
    });
    const query = helpers.trafficSearchRows([report(), second], 'queries')[0];
    assert.deepEqual(plain(query), {
        name: 'flight reservation',
        clicks: 30,
        impressions: 500,
        ctr: 0.06,
        position: 8,
    });
    assert.deepEqual(
        plain(helpers.trafficBreakdown([report(), second], 'sources')),
        [{ name: 'google / organic', value: 40 }],
    );
    assert.equal(
        helpers.trafficDaily([report(), second], 'clicks')[0].value,
        20,
    );
    assert.equal(helpers.trafficSummary([report(), second]).ga4.sessions, 40);
});

test('Traffic comparisons avoid fictitious growth from a zero baseline and use percentage points for rates', () => {
    assert.equal(helpers.trafficComparison(10, 0), 'No previous baseline');
    assert.equal(helpers.trafficComparison(null, 0), 'Comparison unavailable');
    assert.equal(
        helpers.trafficComparison(0, 0),
        'No change vs previous period',
    );
    assert.equal(
        helpers.trafficComparison(20, 10),
        '+100.0% vs previous period',
    );
    assert.equal(
        helpers.trafficComparison(0.6, 0.4, true),
        '+20.0 pp vs previous period',
    );
    assert.deepEqual(
        plain(helpers.trafficRange(7, new Date('2026-01-02T00:01:00Z'))),
        { start_date: '2025-12-26', end_date: '2026-01-01' },
    );
});

test('Traffic rejects invalid provider payloads and refreshes missing/stale reports without retrying failures on its own', () => {
    assert.equal(helpers.readTrafficReports([report()]).length, 1);
    for (const bad of [
        null,
        {},
        [report(), report()],
        [report({ ga4: ga4({ totals: { users: '10' } }) })],
        [report({ gsc: gsc({ totals: { clicks: NaN } }) })],
        [report({ notes: [123] })],
    ])
        assert.throws(() => helpers.readTrafficReports(bad));
    const now = Date.parse('2026-09-09T12:00:00Z');
    assert.equal(helpers.trafficNeedsRefresh([report()], now), false);
    assert.equal(
        helpers.trafficNeedsRefresh(
            [report({ updated_at: '2026-09-09T10:00:00Z' })],
            now,
        ),
        true,
    );
    assert.equal(
        helpers.trafficNeedsRefresh([report({ status: 'missing' })], now),
        true,
    );
    assert.equal(
        helpers.trafficNeedsRefresh(
            [report({ status: 'failed', updated_at: null })],
            now,
        ),
        false,
    );
    assert.equal(helpers.trafficPending([report({ status: 'queued' })]), true);
    assert.equal(helpers.trafficPending([report({ status: 'failed' })]), false);
});

function pollHarness() {
    const timer = clock(),
        calls = [],
        applied = [],
        errors = [],
        waiting = [];
    let available = true;
    const poller = helpers.createTrafficPoller({
        ...timer,
        read(signal) {
            const result = { signal, ...deferred() };
            calls.push(result);
            return result.promise;
        },
        apply: (reports) => applied.push(reports),
        error: (message) => errors.push(message),
        waiting: (value) => waiting.push(value),
        available: () => available,
    });
    return {
        timer,
        calls,
        applied,
        errors,
        waiting,
        poller,
        available(value) {
            available = value;
        },
    };
}
test('Traffic checks only pending reports, then stops completely without idle polling', async () => {
    const app = pollHarness();
    app.poller.start();
    app.calls[0].resolve([report({ status: 'queued' })]);
    await flush();
    app.timer.advance(4000);
    assert.equal(app.calls.length, 2);
    app.calls[1].resolve([report()]);
    await flush();
    assert.equal(app.waiting.at(-1), false);
    assert.equal(app.timer.size, 0);
    app.timer.advance(600000);
    assert.equal(app.calls.length, 2);
    app.poller.stop();
});
test('Traffic polling aborts a stalled request at two minutes and ignores its late response', async () => {
    const app = pollHarness();
    app.poller.start();
    app.timer.advance(120000);
    assert.equal(app.calls[0].signal.aborted, true);
    assert.match(app.errors[0], /still being prepared/);
    app.calls[0].resolve([report()]);
    await flush();
    assert.equal(app.applied.length, 0);
    assert.equal(app.timer.size, 0);
    app.timer.advance(600000);
    assert.equal(app.calls.length, 1);
});
test('Traffic pause invalidates pending reads and resume retains the original two-minute deadline', async () => {
    const app = pollHarness();
    app.poller.start();
    app.timer.advance(30000);
    app.available(false);
    app.poller.pause();
    assert.equal(app.calls[0].signal.aborted, true);
    app.calls[0].resolve([report()]);
    await flush();
    assert.equal(app.applied.length, 0);
    app.timer.advance(30000);
    app.available(true);
    app.poller.resume();
    assert.equal(app.calls.length, 2);
    app.timer.advance(60000);
    assert.equal(app.calls[1].signal.aborted, true);
    assert.equal(app.timer.size, 0);
    app.poller.stop();
    app.poller.resume();
    assert.equal(app.calls.length, 2);
});
test('Traffic status failures stop checking and keep the previous good report', async () => {
    const app = pollHarness();
    app.poller.start();
    app.calls[0].resolve([report({ status: 'running' })]);
    await flush();
    app.timer.advance(4000);
    app.calls[1].reject(new Error('offline'));
    await flush();
    assert.equal(app.applied.length, 1);
    assert.match(app.errors[0], /could not be checked/);
    app.timer.advance(600000);
    assert.equal(app.calls.length, 2);
    assert.equal(app.timer.size, 0);
});

function componentHarness(t, kind = 'report', initial = {}) {
    const timer = clock(),
        mounted = [],
        unmounted = [],
        listeners = new Map(),
        routerListeners = new Map();
    const calls = { axios: [], visits: [], destinations: [] };
    const props = vue.reactive(
        kind === 'report'
            ? { traffic: traffic(initial) }
            : { trafficSettings: settings(initial) },
    );
    const document = {
        hidden: false,
        addEventListener: (name, fn) => listeners.set(name, fn),
        removeEventListener: (name) => listeners.delete(name),
    };
    const browser = {
        location: { assign: (url) => calls.destinations.push(url) },
        addEventListener: (name, fn) => listeners.set(name, fn),
        removeEventListener: (name) => listeners.delete(name),
    };
    const axiosCall = (method, url, data, options) => {
        const request = { method, url, data, options, ...deferred() };
        calls.axios.push(request);
        return request.promise;
    };
    const routerCall = (method, url, data, options) => {
        const visit = { method, url, data, options, cancelled: false };
        calls.visits.push(visit);
        options.onCancelToken?.({
            cancel: () => {
                visit.cancelled = true;
                options.onFinish?.();
            },
        });
    };
    const mocks = {
        vue: {
            ...vue,
            onMounted: (fn) => mounted.push(fn),
            onUnmounted: (fn) => unmounted.push(fn),
        },
        axios: {
            default: {
                get: (url, options) =>
                    axiosCall('GET', url, undefined, options),
                post: (url, data, options) =>
                    axiosCall('POST', url, data, options),
                isAxiosError: (error) => error?.isAxiosError === true,
            },
        },
        '@inertiajs/vue3': {
            usePage: () => ({ props: {} }),
            router: {
                get: (url, data, options) =>
                    routerCall('GET', url, data, options),
                put: (url, data, options) =>
                    routerCall('PUT', url, data, options),
                post: (url, data, options) =>
                    routerCall('POST', url, data, options),
                delete: (url, options) =>
                    routerCall('DELETE', url, undefined, options),
                on: (name, fn) => {
                    routerListeners.set(name, fn);
                    return () => routerListeners.delete(name);
                },
            },
        },
        'chart.js': { Chart: { register() {} } },
        'vue-chartjs': {},
    };
    const globals = {
        document,
        window: browser,
        navigator: { clipboard: { writeText: async () => {} } },
        setTimeout: timer.setTimer,
        clearTimeout: timer.clearTimer,
        Date: class extends Date {
            constructor(...args) {
                if (args.length) super(...args);
                else super(timer.now());
            }
            static now() {
                return timer.now();
            }
        },
    };
    const scope = vue.effectScope();
    const state = scope.run(() =>
        load(
            kind === 'report'
                ? 'pages/Traffic.vue'
                : 'pages/settings/Traffic.vue',
            mocks,
            globals,
        ).default.setup(props, { expose() {} }),
    );
    let disposed = false;
    const dispose = () => {
        if (disposed) return;
        disposed = true;
        unmounted.forEach((fn) => fn());
        scope.stop();
    };
    t.after(dispose);
    return {
        state,
        props,
        calls,
        timer,
        document,
        listeners,
        routerListeners,
        mount: () => mounted.forEach((fn) => fn()),
        dispose,
        async respond(index, data) {
            calls.axios[index].resolve({ data });
            await flush();
        },
        async visitSuccess(index, next) {
            if (next) {
                if (kind === 'report') props.traffic = next;
                else props.trafficSettings = next;
                await flush();
            }
            calls.visits[index].options.onSuccess?.({ props: { flash: {} } });
            calls.visits[index].options.onFinish?.();
            await flush();
        },
    };
}

test('Traffic page leaves fresh reports idle, and realtime is an explicit selected-site action', async (t) => {
    const app = componentHarness(t);
    app.mount();
    app.timer.advance(600000);
    await flush();
    assert.equal(app.calls.axios.length, 0);
    await app.state.loadRealtime();
    assert.equal(app.calls.axios.length, 0);
    app.props.traffic = traffic({
        filters: {
            website_id: 1,
            start_date: '2026-08-12',
            end_date: '2026-09-08',
        },
    });
    await flush();
    const action = app.state.loadRealtime();
    assert.equal(app.calls.axios[0].url, '/traffic/realtime');
    assert.equal(app.calls.axios[0].options.params.website_id, 1);
    await app.respond(0, {
        active_users: 4,
        updated_at: '2026-09-09T12:00:00Z',
    });
    await action;
    assert.equal(app.state.realtime.value.active_users, 4);
    app.timer.advance(600000);
    assert.equal(app.calls.axios.length, 1);
});
test('Traffic page queues missing reports once then polls only while pending', async (t) => {
    const app = componentHarness(t, 'report', {
        reports: [
            report({
                status: 'missing',
                ga4: null,
                gsc: null,
                updated_at: null,
            }),
        ],
    });
    app.mount();
    assert.equal(app.calls.axios[0].url, '/traffic/refresh');
    await app.respond(0, { message: 'Queued reports.' });
    assert.equal(app.calls.axios[1].url, '/traffic/status');
    await app.respond(1, { reports: [report({ status: 'queued' })] });
    app.timer.advance(4000);
    await app.respond(2, { reports: [report()] });
    assert.equal(app.state.summary.value.ga4.users, 10);
    assert.equal(app.state.waiting.value, false);
    app.timer.advance(600000);
    assert.equal(app.calls.axios.length, 3);
});
test('Traffic filters cancel queued reads, retain applied data while loading and reject invalid or overlong ranges', async (t) => {
    const app = componentHarness(t, 'report', {
        reports: [report({ status: 'queued' })],
    });
    app.mount();
    assert.equal(app.calls.axios[0].url, '/traffic/status');
    app.state.draft.start_date = '2026-02-30';
    app.state.applyFilters();
    assert.equal(app.calls.visits.length, 0);
    app.state.draft.start_date = '2026-09-08';
    app.state.draft.end_date = '2026-09-10';
    app.state.applyFilters();
    assert.equal(app.calls.visits.length, 0);
    assert.match(app.state.filterErrors.value.end_date, /earlier date/);
    app.state.draft.end_date = '2026-09-08';
    app.state.draft.start_date = '2026-01-01';
    app.state.applyFilters();
    assert.equal(app.calls.visits.length, 0);
    assert.match(app.state.filterErrors.value.end_date, /93 days/);
    app.state.applyQuick(7, new Date('2026-09-09T12:00:00Z'));
    assert.equal(app.calls.visits.length, 1);
    assert.equal(app.calls.axios[0].options.signal.aborted, true);
    assert.deepEqual(plain(app.calls.visits[0].data), {
        website_id: '',
        start_date: '2026-09-02',
        end_date: '2026-09-08',
    });
    assert.equal(app.state.rangeLabel.value, 'Aug 12, 2026 – Sep 8, 2026');
    await app.respond(0, {
        reports: [
            report({
                ga4: ga4({
                    totals: {
                        users: 999,
                        sessions: 999,
                        views: 999,
                        engagement_rate: 0,
                    },
                }),
            }),
        ],
    });
    assert.equal(app.state.summary.value.ga4.users, 10);
    await app.visitSuccess(
        0,
        traffic({
            filters: {
                website_id: null,
                start_date: '2026-09-02',
                end_date: '2026-09-08',
            },
        }),
    );
    assert.equal(app.state.rangeLabel.value, 'Sep 2, 2026 – Sep 8, 2026');
    assert.equal(app.state.filterLoading.value, false);
});
test('Traffic ignores realtime responses for a previous website and stops work on hide or unmount', async (t) => {
    const app = componentHarness(t, 'report', {
        filters: {
            website_id: 1,
            start_date: '2026-08-12',
            end_date: '2026-09-08',
        },
    });
    app.mount();
    const action = app.state.loadRealtime();
    app.props.traffic = traffic({
        filters: {
            website_id: 2,
            start_date: '2026-08-12',
            end_date: '2026-09-08',
        },
        reports: [report({ website_id: 2 })],
    });
    await flush();
    assert.equal(app.calls.axios[0].options.signal.aborted, true);
    await app.respond(0, {
        active_users: 900,
        updated_at: '2026-09-09T12:00:00Z',
    });
    await action;
    assert.equal(app.state.realtime.value, null);
    const request = app.state.refreshReports();
    app.document.hidden = true;
    app.listeners.get('visibilitychange')();
    assert.equal(app.calls.axios[1].options.signal.aborted, true);
    await app.respond(1, { message: 'Queued.' });
    await request;
    assert.equal(app.calls.axios.length, 2);
    app.dispose();
    app.timer.advance(600000);
    assert.equal(app.calls.axios.length, 2);
    assert.equal(app.timer.size, 0);
});

test('Traffic settings keep saved secrets blank, require a secret for a changed client, and clear a submitted secret after success', async (t) => {
    const app = componentHarness(t, 'settings');
    assert.equal(app.state.appForm.client_secret, '');
    assert.equal(app.state.secretRequired.value, false);
    app.state.appForm.client_id = 'separate-reporting-client';
    assert.equal(app.state.secretRequired.value, true);
    app.state.appForm.client_secret = 'typed-secret';
    app.state.saveApplication();
    assert.equal(app.calls.visits[0].url, '/settings/traffic/application');
    assert.equal(app.calls.visits[0].data.client_secret, 'typed-secret');
    app.state.saveApplication();
    assert.equal(app.calls.visits.length, 1);
    await app.visitSuccess(0);
    assert.equal(app.state.appForm.client_secret, '');
});
test('Traffic settings save either source alone or unlink both and block unavailable catalog choices', async (t) => {
    const app = componentHarness(t, 'settings');
    app.state.websiteForm.value.ga4_property_id = 'not-listed';
    app.state.saveWebsite();
    assert.equal(app.calls.visits.length, 0);
    app.state.websiteForm.value.ga4_property_id = '101';
    app.state.websiteForm.value.gsc_site_url = '';
    app.state.saveWebsite();
    assert.deepEqual(plain(app.calls.visits[0].data), {
        ga4_property_id: '101',
        gsc_site_url: null,
    });
    await app.visitSuccess(0);
    app.state.websiteForm.value.ga4_property_id = '';
    app.state.websiteForm.value.gsc_site_url = 'sc-domain:example.test';
    app.state.saveWebsite();
    assert.deepEqual(plain(app.calls.visits[1].data), {
        ga4_property_id: null,
        gsc_site_url: 'sc-domain:example.test',
    });
    await app.visitSuccess(1);
    app.state.websiteForm.value.gsc_site_url = '';
    app.state.saveWebsite();
    assert.deepEqual(plain(app.calls.visits[2].data), {
        ga4_property_id: null,
        gsc_site_url: null,
    });
});
test('Traffic account changes clear stale mapping drafts while same-account catalog refresh preserves edits', async (t) => {
    const app = componentHarness(t, 'settings');
    app.state.websiteForm.value.ga4_property_id = '102';
    app.props.trafficSettings = settings();
    await flush();
    assert.equal(app.state.websiteForm.value.ga4_property_id, '102');
    app.props.trafficSettings = settings({
        connection: { connected: true, email: 'another@example.test' },
        websites: settings().websites.map((site) => ({
            ...site,
            ga4_property_id: '',
            gsc_site_url: '',
        })),
    });
    await flush();
    assert.equal(app.state.websiteForm.value.ga4_property_id, '');
    assert.equal(app.state.websiteForm.value.gsc_site_url, '');
    app.state.changeConnection('disconnect');
    assert.equal(app.calls.visits[0].url, '/settings/traffic/connection');
    assert.ok(
        app.calls.visits.every((call) => !call.url.includes('/settings/email')),
    );
});
test('Traffic OAuth navigates only to Google and never accepts a late redirect after unmount', async (t) => {
    const app = componentHarness(t, 'settings');
    const first = app.state.connectAccount();
    await app.respond(0, { url: 'https://attacker.test/auth' });
    await first;
    assert.equal(app.calls.destinations.length, 0);
    assert.match(app.state.errorMessage.value, /could not be started/);
    const second = app.state.connectAccount();
    app.dispose();
    assert.equal(app.calls.axios[1].options.signal.aborted, true);
    await app.respond(1, {
        url: 'https://accounts.google.com/o/oauth2/v2/auth?client_id=reporting',
    });
    await second;
    assert.equal(app.calls.destinations.length, 0);
});
test('Traffic settings do not claim success on redirect errors and administrator actions stay restricted', async (t) => {
    const app = componentHarness(t, 'settings');
    app.state.appForm.client_secret = 'typed-secret';
    app.state.saveApplication();
    app.calls.visits[0].options.onSuccess({
        props: { flash: { error: 'Use a separate Google client ID.' } },
    });
    app.calls.visits[0].options.onFinish();
    assert.equal(
        app.state.errorMessage.value,
        'Use a separate Google client ID.',
    );
    assert.equal(app.state.successMessage.value, '');
    assert.equal(app.state.appForm.client_secret, 'typed-secret');
    app.props.trafficSettings = settings({
        app: { ...settings().app, can_manage: false },
    });
    await flush();
    app.state.saveApplication();
    assert.equal(app.calls.visits.length, 1);
});

test('Traffic presents reconnect recovery and retains only the same-account mappings returned by the server', async (t) => {
    const expired = {
        connected: false,
        email: 'reporting@example.test',
        reconnect_required: true,
    };
    const app = componentHarness(t, 'settings', {
        connection: expired,
        catalog: { ga4: [], gsc: [], errors: [], loaded_at: null },
    });
    assert.equal(app.state.reconnectRequired.value, true);
    assert.equal(app.state.connectLabel.value, 'Reconnect Google');
    assert.equal(app.state.websiteForm.value.ga4_property_id, '101');
    app.state.saveWebsite();
    app.state.changeConnection('catalog');
    assert.equal(app.calls.visits.length, 0);
    app.props.trafficSettings = settings({
        connection: {
            connected: true,
            email: 'reporting@example.test',
            reconnect_required: false,
        },
        websites: settings().websites.map((site) => ({
            ...site,
            gsc_site_url: '',
        })),
    });
    await flush();
    assert.equal(app.state.connectLabel.value, 'Change Google account');
    assert.equal(app.state.websiteForm.value.ga4_property_id, '101');
    assert.equal(app.state.websiteForm.value.gsc_site_url, '');
    const page = componentHarness(t, 'report', {
        connection: expired,
        reports: [report({ status: 'missing', ga4: null, gsc: null })],
    });
    page.mount();
    assert.equal(page.state.reconnectRequired.value, true);
    assert.equal(page.calls.axios.length, 0);
});

test('Traffic permits clearing an expired reporting connection without touching Gmail', (t) => {
    const app = componentHarness(t, 'settings', {
        connection: {
            connected: false,
            email: 'reporting@example.test',
            reconnect_required: true,
        },
    });
    app.state.changeConnection('disconnect');
    assert.equal(app.calls.visits[0].method, 'DELETE');
    assert.equal(app.calls.visits[0].url, '/settings/traffic/connection');
});
