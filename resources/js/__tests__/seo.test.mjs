import { compileScript, parse } from '@vue/compiler-sfc';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import test from 'node:test';
import { runInNewContext } from 'node:vm';
import ts from 'typescript';

const require = createRequire(import.meta.url),
    vue = require('vue');
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
const metrics = (overrides = {}) => ({
    clicks: 10,
    impressions: 500,
    ctr: 0.02,
    position: 5,
    ...overrides,
});
const opportunity = (overrides = {}) => ({
    id: 'decline-page',
    type: 'declining',
    dimension: 'page',
    name: 'https://example.test/flights',
    current: metrics(),
    previous: metrics({ clicks: 20 }),
    click_change: -10,
    decline_percent: 50,
    reason: 'Clicks declined across comparable rows.',
    action: 'Review changes to this page and its search intent.',
    ...overrides,
});
const payload = (overrides = {}) => ({
    opportunities: [opportunity()],
    opportunity_count: 1,
    coverage: {
        queries: 20,
        pages: 10,
        previous_queries: 18,
        previous_pages: 9,
        row_limit: 1000,
    },
    previous_start_date: '2026-07-13',
    previous_end_date: '2026-08-09',
    notes: ['Top returned rows only; privacy limits can omit queries.'],
    queries: [],
    ...overrides,
});
const report = (overrides = {}) => ({
    website_id: 1,
    website_name: 'Website A',
    gsc_site_url: 'sc-domain:example.test',
    status: 'ready',
    updated_at: '2026-09-09T11:59:00Z',
    error: null,
    data: payload(),
    ...overrides,
});
const seo = (overrides = {}) => ({
    websites: [
        { id: 1, name: 'Website A' },
        { id: 2, name: 'Website B' },
    ],
    filters: {
        website_id: null,
        start_date: '2026-08-10',
        end_date: '2026-09-06',
    },
    max_end_date: '2026-09-06',
    connection: { connected: true, email: 'reports@example.test' },
    reports: [report()],
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
        TextEncoder,
        ...globals,
        require: (name) =>
            mocks[name] ??
            (name === '@/lib/seo'
                ? load('lib/seo.ts', mocks, globals)
                : name.startsWith('@/')
                  ? {}
                  : require(name)),
    });
    return module.exports;
}
const helpers = load('lib/seo.ts');
function harness(t, initial = {}) {
    const timer = clock(),
        mounted = [],
        unmounted = [],
        listeners = new Map(),
        routerListeners = new Map(),
        calls = { axios: [], visits: [] };
    const props = vue.reactive({ seo: seo(initial) });
    const document = {
        hidden: false,
        addEventListener: (name, fn) => listeners.set(name, fn),
        removeEventListener: (name) => listeners.delete(name),
    };
    const browser = {
        addEventListener: (name, fn) => listeners.set(name, fn),
        removeEventListener: (name) => listeners.delete(name),
    };
    const request = (method, url, data, options) => {
        const call = { method, url, data, options, ...deferred() };
        calls.axios.push(call);
        return call.promise;
    };
    const mocks = {
        vue: {
            ...vue,
            onMounted: (fn) => mounted.push(fn),
            onUnmounted: (fn) => unmounted.push(fn),
        },
        axios: {
            default: {
                get: (url, options) => request('GET', url, undefined, options),
                post: (url, data, options) =>
                    request('POST', url, data, options),
                isAxiosError: (error) => error?.isAxiosError === true,
            },
        },
        '@inertiajs/vue3': {
            router: {
                get: (url, data, options) => {
                    const visit = { url, data, options, cancelled: false };
                    calls.visits.push(visit);
                    options.onCancelToken?.({
                        cancel: () => {
                            visit.cancelled = true;
                            options.onFinish?.();
                        },
                    });
                },
                on: (name, fn) => {
                    routerListeners.set(name, fn);
                    return () => routerListeners.delete(name);
                },
            },
        },
    };
    const globals = {
        document,
        window: browser,
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
    const scope = vue.effectScope(),
        state = scope.run(() =>
            load('pages/Seo.vue', mocks, globals).default.setup(props, {
                expose() {},
            }),
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
        dispose,
        mount: () => mounted.forEach((fn) => fn()),
        async respond(index, data) {
            calls.axios[index].resolve({ data });
            await flush();
        },
        async reject(index, error) {
            calls.axios[index].reject(error);
            await flush();
        },
        async visitSuccess(index, next) {
            if (next) {
                props.seo = next;
                await flush();
            }
            calls.visits[index].options.onFinish?.();
            await flush();
        },
    };
}

test('SEO keeps stable website keys, prioritizes losses, and counts distinct entities separately from matching rules', () => {
    const rows = [
        opportunity({
            id: 'low',
            type: 'low_ctr',
            current: metrics({ impressions: 2000 }),
        }),
        opportunity({
            id: 'near',
            type: 'near_page_one',
            dimension: 'query',
            name: 'flight query',
            previous: null,
            click_change: null,
            decline_percent: null,
        }),
        opportunity(),
    ];
    const reports = [
        report({
            data: payload({ opportunities: rows, opportunity_count: 600 }),
        }),
        report({
            website_id: 2,
            website_name: 'Website B',
            data: payload({
                opportunities: [opportunity({ click_change: -15 })],
            }),
        }),
    ];
    const sorted = helpers.seoOpportunities(reports);
    assert.deepEqual(plain(sorted.map((row) => row.key)), [
        '2:decline-page',
        '1:decline-page',
        '1:low',
        '1:near',
    ]);
    assert.deepEqual(plain(helpers.seoCounts(reports)), {
        returned: 4,
        matched: 601,
        pages: 2,
        queries: 1,
    });
    assert.equal(helpers.seoFiltered(sorted, 'low_ctr', 'WEBSITE A').length, 1);
    assert.equal(helpers.seoFiltered(sorted, 'all', 'search intent').length, 4);
});
test('SEO guards malformed payloads while retaining empty reports and unknown previous comparisons', () => {
    const empty = report({
        data: payload({ opportunities: [], opportunity_count: 0, queries: [] }),
    });
    assert.equal(helpers.readSeoReports([empty])[0].data.opportunity_count, 0);
    const missing = report({ status: 'missing', data: null, updated_at: null });
    assert.equal(helpers.readSeoReport(missing).data, null);
    const unknown = opportunity({
        previous: null,
        click_change: null,
        decline_percent: null,
    });
    assert.equal(
        helpers.readSeoReport(
            report({ data: payload({ opportunities: [unknown] }) }),
        ).data.opportunities[0].previous,
        null,
    );
    for (const mutate of [
        (item) => {
            item.data.opportunities[0].previous = null;
        },
        (item) => {
            item.data.opportunities[0].current.ctr = 2;
        },
        (item) => {
            item.data.opportunities[0].current.clicks = -1;
        },
        (item) => {
            item.data.opportunities[0].name = 'javascript:alert(1)';
        },
        (item) => {
            item.data.opportunities.push(item.data.opportunities[0]);
            item.data.opportunity_count = 2;
        },
        (item) => {
            item.data.coverage.row_limit = 5000;
        },
        (item) => {
            item.data.previous_start_date = '2026-02-30';
        },
        (item) => {
            item.data.opportunity_count = 0;
        },
        (item) => {
            item.updated_at = 'invalid';
        },
    ]) {
        const value = report();
        mutate(value);
        assert.throws(() => helpers.readSeoReport(value));
    }
    assert.throws(() => helpers.readSeoReports([report(), report()]));
});
test('SEO safely displays long canonical URLs while restricting detail to 2048 UTF-8 bytes', () => {
    const long = `https://example.test/${'é'.repeat(1200)}`;
    assert.equal(helpers.seoPageUrl(long), false);
    assert.equal(helpers.seoPageUrl(long, 4096), true);
    assert.equal(
        helpers.readSeoReport(
            report({
                data: payload({ opportunities: [opportunity({ name: long })] }),
            }),
        ).data.opportunities[0].name,
        long,
    );
    for (const invalid of [
        ' https://example.test/',
        'https://example.test/a b',
        'https://a:secret@example.test/',
        'https://example.test/a\nb',
        'ftp://example.test/a',
    ])
        assert.equal(helpers.seoPageUrl(invalid), false);
    assert.equal(helpers.seoPageUrl('HTTPS://example.test/é'), true);
});
test('SEO date presets use the server final-data date across month, year and leap boundaries', () => {
    assert.deepEqual(plain(helpers.seoRange(7, '2026-01-03')), {
        start_date: '2025-12-28',
        end_date: '2026-01-03',
    });
    assert.deepEqual(plain(helpers.seoRange(28, '2024-03-01')), {
        start_date: '2024-02-03',
        end_date: '2024-03-01',
    });
    assert.equal(helpers.seoRange(1, '2024-02-29').start_date, '2024-02-29');
    assert.throws(() => helpers.seoRange(94, '2026-09-06'));
    assert.throws(() => helpers.seoRange(28, '2026-02-30'));
});
test('SEO leaves fresh and failed reports idle, and disconnected accounts never request data', async (t) => {
    for (const initial of [
        {},
        {
            reports: [
                report({
                    status: 'failed',
                    data: null,
                    error: 'Reconnect required.',
                }),
            ],
        },
        {
            connection: {
                connected: false,
                email: 'reports@example.test',
                reconnect_required: true,
            },
            reports: [report({ status: 'missing', data: null })],
        },
    ]) {
        const app = harness(t, initial);
        app.mount();
        app.timer.advance(600000);
        await flush();
        assert.equal(app.calls.axios.length, 0);
        app.dispose();
    }
});
test('SEO missing overview queues once then checks pending status until ready without idle requests', async (t) => {
    const app = harness(t, {
        reports: [report({ status: 'missing', data: null, updated_at: null })],
    });
    app.mount();
    assert.equal(app.calls.axios[0].url, '/seo/refresh');
    await app.respond(0, { message: 'Queued.' });
    assert.equal(app.calls.axios[1].url, '/seo/status');
    await app.respond(1, {
        reports: [report({ status: 'running', data: null })],
    });
    app.timer.advance(3999);
    assert.equal(app.calls.axios.length, 2);
    app.timer.advance(1);
    await app.respond(2, { reports: [report()] });
    assert.equal(app.state.counts.value.returned, 1);
    assert.equal(app.state.waiting.value, false);
    app.timer.advance(600000);
    assert.equal(app.calls.axios.length, 3);
});
test('SEO queued refresh clears prior opportunities and rejects an out-of-scope response', async (t) => {
    const app = harness(t);
    app.mount();
    void app.state.refreshReports();
    await app.respond(0, { message: 'Queued.' });
    assert.equal(app.state.allRows.value.length, 0);
    await app.respond(1, { reports: [report({ website_id: 900 })] });
    assert.equal(app.state.allRows.value.length, 0);
    assert.match(app.state.errorMessage.value, /could not be checked/);
    app.timer.advance(600000);
    assert.equal(app.calls.axios.length, 2);
});
test('SEO pending polling has a hard deadline, aborts a hung read, and does not reset on visibility', async (t) => {
    const app = harness(t, {
        reports: [report({ status: 'queued', data: null })],
    });
    app.mount();
    app.timer.advance(120000);
    assert.equal(app.calls.axios[0].options.signal.aborted, true);
    assert.equal(app.state.waiting.value, false);
    assert.match(app.state.errorMessage.value, /Refresh analysis/);
    app.document.hidden = true;
    app.listeners.get('visibilitychange')();
    app.document.hidden = false;
    app.listeners.get('visibilitychange')();
    app.timer.advance(600000);
    await flush();
    assert.equal(app.calls.axios.length, 1);
    await app.respond(0, { reports: [report()] });
    assert.equal(app.state.allRows.value.length, 0);
});
test('SEO hidden polling resumes its original deadline, then removes listeners and timers on disposal', async (t) => {
    const app = harness(t, {
        reports: [report({ status: 'queued', data: null })],
    });
    app.mount();
    await app.respond(0, {
        reports: [report({ status: 'running', data: null })],
    });
    app.timer.advance(1000);
    app.document.hidden = true;
    app.listeners.get('visibilitychange')();
    app.timer.advance(110000);
    assert.equal(app.calls.axios.length, 1);
    app.document.hidden = false;
    app.listeners.get('visibilitychange')();
    assert.equal(app.calls.axios.length, 2);
    app.timer.advance(9000);
    assert.equal(app.calls.axios[1].options.signal.aborted, true);
    assert.match(app.state.errorMessage.value, /Refresh analysis/);
    app.dispose();
    assert.equal(app.timer.size, 0);
    assert.equal(app.listeners.size, 0);
    assert.equal(app.routerListeners.size, 0);
});
test('SEO filters validate final dates, cancel stale reads and visits, and show applied period until navigation succeeds', async (t) => {
    const app = harness(t, {
        reports: [report({ status: 'queued', data: null })],
    });
    app.mount();
    app.state.draft.start_date = '2026-02-30';
    app.state.applyFilters();
    assert.equal(app.calls.visits.length, 0);
    app.state.draft.start_date = '2026-09-01';
    app.state.draft.end_date = '2026-09-07';
    app.state.applyFilters();
    assert.match(app.state.filterErrors.value.end_date, /processing buffer/);
    app.state.draft.start_date = '2026-01-01';
    app.state.draft.end_date = '2026-09-06';
    app.state.applyFilters();
    assert.match(app.state.filterErrors.value.start_date, /93 days/);
    app.state.draft.website_id = '2';
    app.state.applyPreset(7);
    assert.deepEqual(plain(app.calls.visits[0].data), {
        website_id: '2',
        start_date: '2026-08-31',
        end_date: '2026-09-06',
    });
    assert.equal(app.calls.axios[0].options.signal.aborted, true);
    assert.equal(app.state.rangeLabel.value, 'Aug 10, 2026 – Sep 6, 2026');
    app.state.applyPreset(28);
    assert.equal(app.calls.visits[0].cancelled, true);
    await app.respond(0, { reports: [report()] });
    assert.equal(app.state.allRows.value.length, 0);
    await app.visitSuccess(
        1,
        seo({
            filters: {
                website_id: 2,
                start_date: '2026-08-10',
                end_date: '2026-09-06',
            },
            reports: [report({ website_id: 2 })],
        }),
    );
    assert.equal(app.state.filterLoading.value, false);
    assert.equal(app.state.reports.value[0].website_id, 2);
    app.props.seo = seo({
        filters: {
            website_id: null,
            start_date: '2026-08-31',
            end_date: '2026-09-06',
        },
    });
    await flush();
    assert.equal(app.state.rangeLabel.value, 'Aug 31, 2026 – Sep 6, 2026');
    assert.equal(app.state.draft.start_date, '2026-08-31');
});
test('SEO search, category cards and pagination remain local and distinguish capped counts', async (t) => {
    const opportunities = Array.from({ length: 60 }, (_, i) =>
        opportunity({
            id: `row-${i}`,
            name: `https://example.test/${i}`,
            type: i < 30 ? 'declining' : 'low_ctr',
        }),
    );
    const app = harness(t, {
        reports: [
            report({
                data: payload({ opportunities, opportunity_count: 700 }),
            }),
        ],
    });
    app.mount();
    assert.equal(app.state.rows.value.length, 25);
    assert.equal(app.state.pageCount.value, 3);
    assert.equal(app.state.counts.value.matched, 700);
    app.state.pageNumber.value = 3;
    assert.equal(app.state.rows.value.length, 10);
    app.state.category.value = 'low_ctr';
    await flush();
    assert.equal(app.state.pageNumber.value, 1);
    assert.equal(app.state.filteredRows.value.length, 30);
    app.state.search.value = '/59';
    await flush();
    assert.equal(app.state.rows.value.length, 1);
    assert.equal(app.state.pageCount.value, 1);
    assert.equal(app.calls.axios.length, 0);
    assert.equal(app.calls.visits.length, 0);
});
test('SEO cached page queries use the clicked website in an all-websites overview and retain unknown previous rows', async (t) => {
    const app = harness(t, {
        reports: [
            report(),
            report({ website_id: 2, website_name: 'Website B' }),
        ],
    });
    app.mount();
    const row = app.state.allRows.value.find((row) => row.website_id === 2);
    app.state.openPage(row);
    assert.equal(app.calls.axios.length, 1);
    assert.equal(app.calls.axios[0].method, 'GET');
    assert.equal(app.calls.axios[0].url, '/seo/page');
    assert.deepEqual(plain(app.calls.axios[0].options.params), {
        website_id: 2,
        start_date: '2026-08-10',
        end_date: '2026-09-06',
        page_url: row.name,
    });
    const query = {
        name: 'reservation flight',
        current: metrics(),
        previous: null,
        click_change: null,
    };
    await app.respond(0, {
        report: report({ website_id: 2, data: payload({ queries: [query] }) }),
    });
    assert.equal(app.state.visibleQueries.value[0].previous, null);
    assert.equal(
        app.state.change(app.state.visibleQueries.value[0].click_change),
        '—',
    );
    app.timer.advance(600000);
    assert.equal(app.calls.axios.length, 1);
});
test('SEO missing page analysis performs a cached read then queues only on open and stops after ready', async (t) => {
    const app = harness(t);
    app.mount();
    assert.equal(app.calls.axios.length, 0);
    app.state.openPage(app.state.allRows.value[0]);
    await app.respond(0, {
        report: report({ status: 'missing', data: null, updated_at: null }),
    });
    assert.equal(app.calls.axios[1].method, 'POST');
    assert.equal(app.calls.axios[1].url, '/seo/page/refresh');
    await app.respond(1, { message: 'Queued.' });
    await app.respond(2, { report: report({ status: 'running', data: null }) });
    app.timer.advance(4000);
    await app.respond(3, {
        report: report({
            data: payload({
                queries: [
                    {
                        name: 'q',
                        current: metrics(),
                        previous: metrics({ clicks: 0 }),
                        click_change: 10,
                    },
                ],
            }),
        }),
    });
    assert.equal(app.state.visibleQueries.value[0].previous.clicks, 0);
    assert.equal(app.state.change(10), '+10');
    assert.equal(app.state.detailWaiting.value, false);
    app.timer.advance(600000);
    assert.equal(app.calls.axios.length, 4);
});
test('SEO stale detail refresh clears old queries, and closing or selecting another page ignores late responses', async (t) => {
    const app = harness(t, {
        reports: [
            report({
                data: payload({
                    opportunities: [
                        opportunity(),
                        opportunity({
                            id: 'other',
                            name: 'https://example.test/hotels',
                        }),
                    ],
                    opportunity_count: 2,
                }),
            }),
        ],
    });
    app.mount();
    app.state.openPage(app.state.allRows.value[0]);
    const first = app.calls.axios[0];
    app.state.openPage(app.state.allRows.value[1]);
    assert.equal(first.options.signal.aborted, true);
    await app.respond(0, {
        report: report({
            data: payload({
                queries: [
                    {
                        name: 'old',
                        current: metrics(),
                        previous: null,
                        click_change: null,
                    },
                ],
            }),
        }),
    });
    assert.equal(app.state.detailReport.value, null);
    await app.respond(1, {
        report: report({ updated_at: '2026-09-09T09:00:00Z' }),
    });
    assert.equal(app.calls.axios[2].method, 'POST');
    await app.respond(2, { message: 'Queued.' });
    assert.equal(app.state.detailReport.value.data, null);
    app.state.closeDetail();
    assert.equal(app.calls.axios[3].options.signal.aborted, true);
    await app.respond(3, { report: report() });
    assert.equal(app.state.detailReport.value, null);
    assert.equal(app.state.detailOpen.value, false);
});
test('SEO page errors preserve actionable 409 messages and never automatically retry', async (t) => {
    const app = harness(t);
    app.mount();
    app.state.openPage(app.state.allRows.value[0]);
    await app.reject(0, {
        isAxiosError: true,
        response: {
            status: 409,
            data: {
                message: 'Reconnect Google before requesting another analysis.',
            },
        },
    });
    assert.match(app.state.detailError.value, /Reconnect Google/);
    app.timer.advance(600000);
    assert.equal(app.calls.axios.length, 1);
    assert.match(
        app.state.requestError(
            { isAxiosError: true, response: { status: 419 } },
            'fallback',
        ),
        /session expired/,
    );
    app.state.closeDetail();
    app.state.openPage(app.state.allRows.value[0]);
    await app.respond(1, { report: report({ website_id: 2 }) });
    assert.equal(app.state.detailReport.value, null);
    assert.match(app.state.detailError.value, /could not be loaded/);
});
test('SEO detail checks are independently bounded and cancelled on navigation or unmount', async (t) => {
    const app = harness(t);
    app.mount();
    app.state.openPage(app.state.allRows.value[0]);
    await app.respond(0, { report: report({ status: 'running', data: null }) });
    assert.equal(app.calls.axios[1].url, '/seo/page');
    app.timer.advance(120000);
    assert.equal(app.calls.axios[1].options.signal.aborted, true);
    assert.match(app.state.detailError.value, /Refresh analysis/);
    app.document.hidden = true;
    app.listeners.get('visibilitychange')();
    app.document.hidden = false;
    app.listeners.get('visibilitychange')();
    assert.equal(app.calls.axios.length, 2);
    app.routerListeners.get('before')({ detail: { visit: { async: false } } });
    assert.equal(app.state.detailOpen.value, false);
    app.state.openPage(app.state.allRows.value[0]);
    app.dispose();
    assert.equal(app.calls.axios[2].options.signal.aborted, true);
    await app.respond(2, { report: report() });
    assert.equal(app.state.detailReport.value, null);
    assert.equal(app.timer.size, 0);
});
test('SEO does not offer long, query or fabricated rows for page analysis', async (t) => {
    const long = `https://example.test/${'é'.repeat(1200)}`;
    const app = harness(t, {
        reports: [
            report({
                data: payload({ opportunities: [opportunity({ name: long })] }),
            }),
        ],
    });
    app.mount();
    app.state.openPage(app.state.allRows.value[0]);
    app.state.openPage({
        ...app.state.allRows.value[0],
        name: 'https://other.test',
    });
    app.state.openPage({ ...app.state.allRows.value[0], dimension: 'query' });
    assert.equal(app.calls.axios.length, 0);
    assert.equal(app.state.detailOpen.value, false);
});
