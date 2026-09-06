import { compileScript, parse } from '@vue/compiler-sfc';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import test from 'node:test';
import { runInNewContext } from 'node:vm';
import ts from 'typescript';

const require = createRequire(import.meta.url);
const vue = require('vue');
const { renderToString } = require('vue/server-renderer');
const source = readFileSync(
    new URL('../pages/Customers/Index.vue', import.meta.url),
    'utf8',
);
const plain = (value) => JSON.parse(JSON.stringify(value));
const wrap = (tag) =>
    vue.defineComponent({
        setup:
            (_, { slots }) =>
            () =>
                vue.h(tag, slots.default?.()),
    });

function loadComponent(router, ssr = false) {
    const { descriptor } = parse(source, { filename: 'Customers/Index.vue' });
    const compiled = compileScript(descriptor, {
        id: 'customers',
        inlineTemplate: ssr,
        ...(ssr ? { templateOptions: { ssr: true } } : {}),
    });
    const { outputText } = ts.transpileModule(compiled.content, {
        compilerOptions: {
            module: ts.ModuleKind.CommonJS,
            target: ts.ScriptTarget.ES2022,
        },
    });
    const mocks = {
        '@inertiajs/vue3': { router, Head: wrap('header'), Link: wrap('a') },
        '@/layouts/AppLayout.vue': { default: wrap('div') },
        '@/components/ui/badge': { Badge: wrap('span') },
        '@/components/ui/button': {
            Button: vue.defineComponent({
                props: ['asChild'],
                setup:
                    (props, { slots }) =>
                    () =>
                        props.asChild
                            ? slots.default?.()
                            : vue.h('button', slots.default?.()),
            }),
        },
    };
    const module = { exports: {} };
    runInNewContext(outputText, {
        module,
        exports: module.exports,
        console,
        URLSearchParams,
        require: (name) =>
            Object.hasOwn(mocks, name) ? mocks[name] : require(name),
    });
    return module.exports.default;
}

function harness(t, filters = {}, customerOverrides = {}) {
    const calls = { gets: [], visits: [] };
    const router = {
        get: (url, params, options) =>
            calls.gets.push({ url, params, options }),
        visit: (url, options) => calls.visits.push({ url, options }),
    };
    const props = vue.reactive({
        filters,
        websites: [
            { id: 3, name: 'Reservation Visa' },
            { id: 8, name: 'Pre Reservation' },
        ],
        countries: ['FR', 'MA'],
        customers: {
            data: [
                {
                    email: 'customer+test@example.test',
                    orders_count: 6,
                    total_spent: 120,
                    average_order_value: 20,
                    websites: ['Reservation Visa'],
                    country: 'MA',
                    first_order_at: '2026-01-02T10:00:00Z',
                    last_order_at: '2026-09-06T10:00:00Z',
                },
            ],
            links: [
                { url: null, label: '&laquo; Previous', active: false },
                { url: '/customers?page=1', label: '1', active: true },
                {
                    url: '/customers?page=2',
                    label: 'Next &raquo;',
                    active: false,
                },
            ],
            current_page: 1,
            last_page: 4,
            per_page: 15,
            from: 1,
            to: 15,
            total: 56,
            ...customerOverrides,
        },
    });
    const scope = vue.effectScope();
    const state = scope.run(() =>
        loadComponent(router).setup(props, { expose() {} }),
    );
    t.after(() => scope.stop());
    return {
        state,
        props,
        calls,
        render: () =>
            renderToString(
                vue.createSSRApp(loadComponent(router, true), props),
            ),
    };
}

test('customer export uses applied filters, encodes all selected websites, and omits pagination', (t) => {
    const { state } = harness(t, {
        start_date: '2026-01-01',
        end_date: '2026-09-06',
        website_ids: [8, 3],
        country: 'MA',
        min_spend: 0,
        payment_status: 'paid',
        sort_by: 'total_spent',
        sort_dir: 'asc',
        page: 4,
        per_page: 15,
    });
    const url = new URL(state.exportUrl.value, 'https://example.test');
    assert.equal(url.pathname, '/customers/export');
    assert.deepEqual(url.searchParams.getAll('website_ids[]'), ['3', '8']);
    assert.equal(url.searchParams.get('start_date'), '2026-01-01');
    assert.equal(url.searchParams.get('end_date'), '2026-09-06');
    assert.equal(url.searchParams.get('country'), 'MA');
    assert.equal(url.searchParams.get('min_spend'), '0');
    assert.equal(url.searchParams.get('payment_status'), 'paid');
    assert.equal(url.searchParams.get('sort_by'), 'total_spent');
    assert.equal(url.searchParams.get('sort_dir'), 'asc');
    assert.equal(url.searchParams.has('page'), false);
    assert.equal(url.searchParams.has('per_page'), false);
    assert.equal(state.websiteScope.value, 'Reservation Visa, Pre Reservation');
    assert.equal(state.totalCustomers.value, 56);
    assert.match(
        state.appliedFilterSummary.value,
        /2026-01-01 to 2026-09-06.*Country: MA.*Paid orders.*\$0\.00/,
    );
    assert.equal(state.hasUnappliedFilters.value, false);
});

test('all-websites export omits website selection and supports canonical CSV input', (t) => {
    const all = harness(t, {
        website_ids: [],
        country: null,
        min_spend: null,
        payment_status: 'all',
    });
    const query = new URL(all.state.exportUrl.value, 'https://example.test')
        .searchParams;
    assert.equal(query.has('website_ids[]'), false);
    assert.equal(query.has('country'), false);
    assert.equal(query.has('min_spend'), false);
    assert.equal(query.has('payment_status'), false);
    assert.equal(all.state.websiteScope.value, 'All websites');
    assert.equal(
        all.state.appliedFilterSummary.value,
        'All dates · All countries · All order statuses',
    );
    const csv = harness(t, { website_ids: '8,3,8' });
    assert.deepEqual(plain(csv.state.selectedWebsiteIds.value), [3, 8]);
    assert.deepEqual(
        new URL(
            csv.state.exportUrl.value,
            'https://example.test',
        ).searchParams.getAll('website_ids[]'),
        ['3', '8'],
    );
});

test('draft edits stay local until one Apply request, preserve current rows, and block stale export actions', async (t) => {
    const { state, props, calls } = harness(t, { website_ids: [3] });
    const appliedUrl = state.exportUrl.value;
    const rows = props.customers.data;
    state.minSpend.value = '1';
    state.minSpend.value = '12';
    state.minSpend.value = '120';
    state.startDate.value = '2026-01-01';
    state.endDate.value = '2026-09-06';
    state.selectedCountry.value = 'FR';
    state.paymentStatus.value = 'paid';
    state.addWebsite({ target: { value: '8' } });
    state.addWebsite({ target: { value: '8' } });
    await vue.nextTick();
    assert.equal(calls.gets.length, 0);
    assert.equal(state.resultsActionsDisabled.value, true);
    assert.equal(state.exportUrl.value, appliedUrl);
    state.toggleSort('total_spent');
    state.goToPage('/customers?page=2');
    assert.equal(calls.gets.length, 0);
    assert.equal(calls.visits.length, 0);
    state.applyFilters();
    state.applyFilters();
    state.resetFilters();
    assert.equal(calls.gets.length, 1);
    assert.equal(calls.gets[0].url, '/customers');
    assert.deepEqual(plain(calls.gets[0].params), {
        start_date: '2026-01-01',
        end_date: '2026-09-06',
        website_ids: [3, 8],
        country: 'FR',
        min_spend: '120',
        payment_status: 'paid',
        sort_by: 'last_order_at',
        sort_dir: 'desc',
    });
    assert.equal(calls.gets[0].options.preserveState, true);
    assert.equal(state.isLoading.value, true);
    assert.equal(props.customers.data, rows);
    props.filters = plain(calls.gets[0].params);
    await vue.nextTick();
    calls.gets[0].options.onFinish();
    assert.equal(state.resultsActionsDisabled.value, false);
    assert.equal(
        new URL(state.exportUrl.value, 'https://example.test').searchParams.get(
            'min_spend',
        ),
        '120',
    );
});

test('sorting sends one atomic request with both sort keys and all applied filters', async (t) => {
    const { state, calls, props } = harness(t, {
        website_ids: [8],
        country: 'MA',
        sort_by: 'last_order_at',
        sort_dir: 'desc',
    });
    state.toggleSort('total_spent');
    state.toggleSort('orders_count');
    assert.equal(calls.gets.length, 1);
    assert.deepEqual(plain(calls.gets[0].params), {
        website_ids: [8],
        country: 'MA',
        sort_by: 'total_spent',
        sort_dir: 'desc',
    });
    assert.equal(
        state.sortBy.value,
        'last_order_at',
        'sort marker reflects the displayed results until the response arrives',
    );
    props.filters = plain(calls.gets[0].params);
    await vue.nextTick();
    calls.gets[0].options.onFinish();
    state.toggleSort('total_spent');
    assert.equal(calls.gets.length, 2);
    assert.equal(calls.gets[1].params.sort_by, 'total_spent');
    assert.equal(calls.gets[1].params.sort_dir, 'asc');
});

test('history and filter responses synchronize drafts, export scope, and sorting', async (t) => {
    const { state, props } = harness(t, { website_ids: [3], min_spend: 10 });
    state.minSpend.value = 99;
    props.filters = {
        start_date: '2026-02-01',
        end_date: null,
        website_ids: [8],
        country: 'FR',
        min_spend: 0,
        payment_status: 'pending',
        sort_by: 'orders_count',
        sort_dir: 'asc',
    };
    await vue.nextTick();
    assert.equal(state.minSpend.value, 0);
    assert.equal(state.startDate.value, '2026-02-01');
    assert.equal(state.endDate.value, '');
    assert.deepEqual(plain(state.selectedWebsiteIds.value), [8]);
    assert.equal(state.selectedCountry.value, 'FR');
    assert.equal(state.paymentStatus.value, 'pending');
    assert.equal(state.sortBy.value, 'orders_count');
    assert.equal(state.sortDir.value, 'asc');
    assert.equal(state.hasUnappliedFilters.value, false);
    assert.equal(state.websiteScope.value, 'Pre Reservation');
    assert.match(
        state.appliedFilterSummary.value,
        /From 2026-02-01.*Pending orders/,
    );
});

test('All websites clears only the draft website filter; Reset clears every filter in one request', (t) => {
    const { state, calls } = harness(t, {
        website_ids: [3, 8],
        country: 'MA',
        min_spend: 25,
        sort_by: 'total_spent',
        sort_dir: 'asc',
    });
    const select = { value: 'all' };
    state.addWebsite({ target: select });
    assert.deepEqual(plain(state.selectedWebsiteIds.value), []);
    assert.equal(select.value, '');
    assert.equal(state.selectedCountry.value, 'MA');
    assert.equal(calls.gets.length, 0);
    state.resetFilters();
    assert.equal(calls.gets.length, 1);
    assert.deepEqual(plain(calls.gets[0].params), {
        sort_by: 'last_order_at',
        sort_dir: 'desc',
    });
    assert.equal(state.selectedCountry.value, '');
    assert.equal(state.minSpend.value, '');
    calls.gets[0].options.onError({});
    calls.gets[0].options.onFinish();
    assert.equal(state.isLoading.value, false);
    assert.match(state.filterError.value, /could not be applied/);
});

test('filter validation errors remain visible and preserve editable drafts and current rows', async (t) => {
    const { state, calls, props } = harness(t);
    const rows = props.customers.data;
    state.startDate.value = '2026-09-06';
    state.endDate.value = '2026-01-01';
    state.minSpend.value = -5;
    state.applyFilters();
    props.filters = plain(props.filters);
    await vue.nextTick();
    calls.gets[0].options.onError({
        end_date: 'The end date must be after or equal to start date.',
        min_spend: 'The minimum spend must be at least 0.',
    });
    calls.gets[0].options.onFinish();
    assert.match(state.filterError.value, /end date must be after/);
    assert.match(state.filterError.value, /minimum spend must be at least 0/);
    assert.equal(state.minSpend.value, -5);
    assert.equal(state.isLoading.value, false);
    assert.equal(state.hasUnappliedFilters.value, true);
    assert.equal(props.customers.data, rows);
});

test('pagination retains repeated website parameters without reconstruction and guards concurrent navigation', (t) => {
    const { state, calls } = harness(t, { website_ids: [3, 8] });
    const url =
        '/customers?website_ids%5B%5D=3&website_ids%5B%5D=8&page=2&country=MA';
    state.goToPage(null);
    state.goToPage(url);
    state.goToPage(url);
    assert.equal(calls.visits.length, 1);
    assert.equal(calls.visits[0].url, url);
    assert.equal(calls.visits[0].options.preserveState, true);
    assert.equal(calls.visits[0].options.preserveScroll, true);
    assert.equal(state.isLoading.value, true);
    calls.visits[0].options.onFinish();
    assert.equal(state.isLoading.value, false);
});

test('customer page SSR retains all row data, accessible filters, and native CSV download scope', async (t) => {
    const { render } = harness(t, { website_ids: [3] });
    const html = await render();
    assert.match(html, /56 matching customers/);
    assert.match(html, /Includes all matching customers, across every page/);
    assert.match(html, /href="\/customers\/export\?website_ids%5B%5D=3/);
    assert.match(html, /href="\/customers\/customer%2Btest%40example.test"/);
    for (const text of [
        'customer+test@example.test',
        '$120.00',
        '$20.00',
        'Reservation Visa',
        'MA',
        'Jan 2, 2026',
        'Sep 6, 2026',
    ])
        assert.ok(html.includes(text), text);
    for (const id of [
        'customer-start-date',
        'customer-end-date',
        'customer-websites',
        'customer-country',
        'customer-min-spend',
        'customer-payment-status',
    ]) {
        assert.ok(html.includes(`for="${id}"`), id);
        assert.ok(html.includes(`id="${id}"`), id);
    }
    assert.match(html, /Apply filters/);
    assert.match(html, /Reset filters/);
    assert.match(html, /aria-sort="descending"/);
});
