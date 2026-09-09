import { compileScript, parse, registerTS } from '@vue/compiler-sfc';
import assert from 'node:assert/strict';
import { existsSync, readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import test from 'node:test';
import { fileURLToPath } from 'node:url';
import { runInNewContext } from 'node:vm';
import ts from 'typescript';

const require = createRequire(import.meta.url),
    vue = require('vue');
registerTS(() => ts);
const plain = (value) => JSON.parse(JSON.stringify(value));
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
const row = (overrides = {}) => ({
    id: 10,
    wp_order_id: 1001,
    website_id: 1,
    website: { id: 1, name: 'Website A' },
    status: 'processing',
    can_update_status: true,
    customer_name: 'Customer A',
    customer_email: 'customer@example.test',
    total: '20.00',
    currency: 'EUR',
    created_at_wp: '2026-09-08T10:00:00Z',
    stage: 'prepare',
    email: null,
    submission_id: 99,
    ...overrides,
});
const queue = (overrides = {}) => ({
    data: [row()],
    current_page: 1,
    last_page: 1,
    per_page: 25,
    total: 1,
    from: 1,
    to: 1,
    summary: {
        all: 1,
        prepare: 1,
        ready: 0,
        sending: 0,
        sent: 0,
        attention: 0,
        waiting: 0,
    },
    generated_at: '2026-09-09T12:00:00Z',
    timezone: 'Africa/Casablanca',
    ...overrides,
});
const filters = (overrides = {}) => ({
    website_id: null,
    search: '',
    stage: 'all',
    sort: 'oldest',
    per_page: 25,
    ...overrides,
});
const email = (status = 'prepared') => ({
    status,
    created_at: '2026-09-09T11:00:00Z',
    sent_at: status === 'sent' ? '2026-09-09T11:01:00Z' : null,
    expires_at: '2026-09-10T11:00:00Z',
});
function clock() {
    let now = 0,
        id = 0;
    const timers = new Map();
    return {
        now: () => now,
        setTimeout(callback, delay) {
            timers.set(++id, { callback, at: now + delay });
            return id;
        },
        clearTimeout(id) {
            timers.delete(id);
        },
        advance(ms) {
            const end = now + ms;
            while (true) {
                const next = [...timers].sort((a, b) => a[1].at - b[1].at)[0];
                if (!next || next[1].at > end) break;
                now = next[1].at;
                timers.delete(next[0]);
                next[1].callback();
            }
            now = end;
        },
        get size() {
            return timers.size;
        },
    };
}
function events() {
    const handlers = new Map();
    return {
        add(name, fn) {
            if (!handlers.has(name)) handlers.set(name, new Set());
            handlers.get(name).add(fn);
        },
        remove(name, fn) {
            handlers.get(name)?.delete(fn);
        },
        fire(name, value) {
            for (const fn of handlers.get(name) ?? []) fn(value);
        },
        get size() {
            return [...handlers.values()].reduce(
                (sum, list) => sum + list.size,
                0,
            );
        },
    };
}
function load(path, mocks = {}, globals = {}) {
    let content = readFileSync(new URL(`../${path}`, import.meta.url), 'utf8');
    if (path.endsWith('.vue'))
        content = compileScript(
            parse(content, {
                filename: fileURLToPath(
                    new URL(`../${path}`, import.meta.url),
                ).replaceAll('\\', '/'),
            }).descriptor,
            {
                id: path,
                fs: {
                    fileExists: existsSync,
                    readFile: (file) => readFileSync(file, 'utf8'),
                },
            },
        ).content;
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
        URL,
        AbortController,
        setTimeout,
        clearTimeout,
        queueMicrotask,
        ...globals,
        require: (name) =>
            mocks[name] ??
            (['@/lib/orderQueue', '@/lib/liveOrders'].includes(name)
                ? load(name.replace('@/', '') + '.ts', mocks, globals)
                : name.startsWith('@/')
                  ? {}
                  : require(name)),
    });
    return module.exports;
}
const helpers = load('lib/orderQueue.ts');
function harness(t, options = {}) {
    const timer = clock(),
        mounted = [],
        unmounted = [],
        routerEvents = events(),
        browserEvents = events(),
        documentEvents = events();
    const props = vue.reactive({
        queue: queue(options.queue),
        websites: [
            { id: 1, name: 'Website A' },
            { id: 2, name: 'Website B' },
        ],
        filters: filters(options.filters),
    });
    const page = vue.reactive({
        url: '/order-work-queue',
        props: { auth: { user: { id: 7 } }, flash: { success: 'Keep me' } },
    });
    const location = new URL('https://example.test/order-work-queue');
    const navigator = { onLine: true },
        document = {
            hidden: false,
            addEventListener: documentEvents.add,
            removeEventListener: documentEvents.remove,
        };
    const calls = {
        fetches: [],
        visits: [],
        replacements: [],
        subscriptions: [],
        emailOpens: [],
        notifications: new Map(),
    };
    const composerState = { open: false, sending: false, hasDraft: false };
    const mocks = {
        vue: {
            ...vue,
            onMounted: (fn) => mounted.push(fn),
            onUnmounted: (fn) => unmounted.push(fn),
        },
        '@inertiajs/vue3': {
            usePage: () => page,
            router: {
                get(url, data, opts) {
                    const visit = {
                        url,
                        data,
                        options: opts,
                        cancelled: false,
                    };
                    calls.visits.push(visit);
                    opts.onCancelToken?.({
                        cancel() {
                            visit.cancelled = true;
                            opts.onFinish?.();
                        },
                    });
                },
                replaceProp(key, updater, opts) {
                    const replacement = { key, updater, options: opts };
                    calls.replacements.push(replacement);
                    if (!options.deferApply) {
                        props[key] = updater(props[key]);
                        opts.onFinish?.();
                    }
                },
                on(name, fn) {
                    routerEvents.add(name, fn);
                    return () => routerEvents.remove(name, fn);
                },
            },
        },
        '@/lib/ordersPush': {
            subscribeToOrders(opts) {
                const subscription = { ...opts, stopped: false };
                calls.subscriptions.push(subscription);
                return () => {
                    subscription.stopped = true;
                };
            },
        },
        '@/composables/useEchoNotifications': {
            useEchoNotifications: () => ({
                onNotification(key, fn, userId, reconnect) {
                    calls.notifications.set(key, { fn, userId, reconnect });
                },
                offNotification(key) {
                    calls.notifications.delete(key);
                },
            }),
        },
    };
    const globals = {
        document,
        navigator,
        window: {
            location,
            addEventListener: browserEvents.add,
            removeEventListener: browserEvents.remove,
        },
        setTimeout: timer.setTimeout,
        clearTimeout: timer.clearTimeout,
        fetch(url, opts) {
            const request = { url, options: opts, ...deferred() };
            calls.fetches.push(request);
            return request.promise;
        },
    };
    const scope = vue.effectScope(),
        state = scope.run(() =>
            load('pages/Orders/WorkQueue.vue', mocks, globals).default.setup(
                props,
                { expose() {} },
            ),
        );
    state.composer.value = {
        open(reviewLatest = false) {
            calls.emailOpens.push({
                id: state.activeEmailOrder.value?.id,
                reviewLatest,
            });
            composerState.open = true;
        },
        getState: () => composerState,
    };
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
        page,
        calls,
        timer,
        document,
        navigator,
        location,
        routerEvents,
        browserEvents,
        documentEvents,
        composerState,
        dispose,
        mount: () => mounted.forEach((fn) => fn()),
        async respond(index, next = queue()) {
            calls.fetches[index].resolve({
                ok: true,
                json: async () => ({
                    queue: next,
                    auth: { user: { id: 900 } },
                    flash: { error: 'Never merge' },
                }),
            });
            await flush();
        },
        async apply(index) {
            const update = calls.replacements[index];
            props[update.key] = update.updater(props[update.key]);
            update.options.onFinish?.();
            await flush();
        },
        async success(index, nextFilters, nextQueue) {
            if (nextFilters) props.filters = filters(nextFilters);
            if (nextQueue) props.queue = nextQueue;
            await flush();
            calls.visits[index].options.onSuccess?.();
            calls.visits[index].options.onFinish?.();
            await flush();
        },
    };
}

test('queue uses server stages and honest next steps without inferring absent PDFs, unpaid balances, or delivery certainty', () => {
    assert.equal(
        helpers.orderQueueEmailLabel(row()),
        'No WP Hub email recorded',
    );
    assert.match(
        helpers.orderQueueNextAction(row({ stage: 'waiting' })),
        /Review the WooCommerce/,
    );
    assert.match(
        helpers.orderQueueNextAction(
            row({ stage: 'attention', email: email('uncertain') }),
        ),
        /Check Gmail Sent/,
    );
    assert.match(
        helpers.orderQueueNextAction(
            row({ stage: 'sent', email: email('sent') }),
        ),
        /mark it completed/,
    );
    assert.match(
        helpers.orderQueueNextAction(row({ email: email('expired') })),
        /fresh preview/,
    );
    assert.equal(helpers.orderQueueStage('ready').label, 'Ready to send');
    assert.equal(
        helpers.orderQueueAge(null, queue().generated_at),
        'Date unavailable',
    );
    assert.equal(
        helpers.orderQueueAge(row().created_at_wp, queue().generated_at),
        '1d 2h',
    );
});
test('queue snapshots reject malformed identities, counts, dates and email states without accepting shared props', () => {
    const safe = helpers.readOrderQueue({ ...queue(), auth: { id: 123 } });
    assert.equal(safe.auth, undefined);
    for (const mutate of [
        (value) => {
            value.summary.sent = -1;
        },
        (value) => {
            value.data[0].stage = 'finished';
        },
        (value) => {
            value.data[0].email = email('opened');
        },
        (value) => {
            value.data[0].website.id = 2;
        },
        (value) => {
            value.data.push(row());
        },
        (value) => {
            value.generated_at = 'invalid';
        },
        (value) => {
            value.data[0].submission_id = -1;
        },
        (value) => {
            value.data[0].total = 'invalid';
        },
    ]) {
        const value = queue();
        mutate(value);
        assert.throws(() => helpers.readOrderQueue(value));
    }
});
test('queue refreshes on subscription and coalesces pushes without idle polling', async (t) => {
    const app = harness(t);
    app.mount();
    app.timer.advance(250);
    await app.respond(0);
    app.timer.advance(600000);
    assert.equal(app.calls.fetches.length, 1);
    app.calls.subscriptions[0].onOrder({ website_id: 1 });
    app.calls.subscriptions[0].onOrder({ website_id: 1 });
    app.timer.advance(249);
    assert.equal(app.calls.fetches.length, 1);
    app.timer.advance(1);
    await app.respond(1);
    app.timer.advance(600000);
    assert.equal(app.calls.fetches.length, 2);
    assert.equal(app.page.props.auth.user.id, 7);
    assert.equal(app.page.props.flash.success, 'Keep me');
});
test('queue snapshots update rows and counters together while preserving draft filters and a page-owned email workspace', async (t) => {
    const app = harness(t);
    app.mount();
    app.state.openEmail(app.props.queue.data[0]);
    await flush();
    app.composerState.hasDraft = true;
    app.state.filterInputs.value.search = 'pending typing';
    const selected = app.state.activeEmailOrder.value;
    app.timer.advance(250);
    await app.respond(
        0,
        queue({
            data: [],
            total: 0,
            from: null,
            to: null,
            summary: {
                all: 0,
                prepare: 0,
                ready: 0,
                sending: 0,
                sent: 0,
                attention: 0,
                waiting: 0,
            },
        }),
    );
    assert.equal(app.props.queue.data.length, 0);
    assert.equal(app.props.queue.summary.all, 0);
    assert.equal(app.state.filterInputs.value.search, 'pending typing');
    assert.equal(app.state.activeEmailOrder.value, selected);
    assert.equal(app.composerState.hasDraft, true);
    assert.equal(app.calls.emailOpens.length, 1);
});
test('queue mutation settlement invalidates pre-write deferred snapshots even when a fresh request fails', async (t) => {
    const app = harness(t, { deferApply: true });
    app.mount();
    app.timer.advance(250);
    await app.respond(0, queue({ data: [row({ status: 'pending' })] }));
    app.state.refreshAfterMutation();
    assert.equal(app.calls.fetches.length, 2);
    await app.apply(0);
    assert.equal(app.props.queue.data[0].status, 'processing');
    app.calls.fetches[1].reject(new Error('offline'));
    await flush();
    assert.equal(app.state.refreshState.value.hasError, true);
    assert.equal(app.props.queue.data[0].status, 'processing');
});
test('queue search debounces once and stage/website changes submit the newest complete draft', async (t) => {
    const app = harness(t);
    app.state.updateSearch('A');
    app.timer.advance(100);
    app.state.updateSearch('Ada');
    app.timer.advance(299);
    assert.equal(app.calls.visits.length, 0);
    app.timer.advance(1);
    assert.equal(app.calls.visits.length, 1);
    app.state.updateFilter('stage', 'attention');
    assert.equal(app.calls.visits[0].cancelled, true);
    app.state.updateFilter('website_id', '2');
    assert.equal(app.calls.visits[1].cancelled, true);
    assert.deepEqual(plain(app.calls.visits[2].data), {
        website_id: '2',
        search: 'Ada',
        stage: 'attention',
        sort: 'oldest',
        per_page: '25',
    });
    app.state.submitFilters();
    assert.equal(app.calls.visits.length, 3);
    await app.success(0, null, null);
    assert.equal(app.state.filterLoading.value, true);
});
test('queue cancelling a pending filter back to the applied scope resumes live updates', async (t) => {
    const app = harness(t);
    app.mount();
    app.timer.advance(250);
    await app.respond(0);
    app.state.updateFilter('stage', 'ready');
    assert.equal(app.state.filterLoading.value, true);
    app.state.updateFilter('stage', 'all');
    await flush();
    assert.equal(app.calls.visits[0].cancelled, true);
    assert.equal(app.calls.visits.length, 1);
    app.calls.subscriptions[0].onOrder({ website_id: 1 });
    app.timer.advance(250);
    assert.equal(app.calls.fetches.length, 2);
    await app.respond(1);
    app.state.updateSearch('a');
    app.timer.advance(300);
    app.state.updateSearch('');
    await flush();
    app.calls.subscriptions[0].onOrder({ website_id: 1 });
    app.timer.advance(250);
    assert.equal(app.calls.fetches.length, 3);
});
test('queue filter navigation cancels old snapshots and pagination uses applied filters instead of unsent search', async (t) => {
    const app = harness(t, {
        queue: { total: 60, last_page: 3 },
        filters: { website_id: 1, stage: 'prepare' },
    });
    app.mount();
    app.timer.advance(250);
    app.state.updateSearch('unsent draft');
    app.state.goToPage(2);
    assert.equal(app.calls.fetches[0].options.signal.aborted, true);
    assert.deepEqual(plain(app.calls.visits[0].data), {
        website_id: '1',
        search: '',
        stage: 'prepare',
        sort: 'oldest',
        per_page: '25',
        page: 2,
    });
    await app.respond(
        0,
        queue({
            summary: {
                all: 999,
                prepare: 999,
                ready: 0,
                sending: 0,
                sent: 0,
                attention: 0,
                waiting: 0,
            },
        }),
    );
    assert.equal(app.props.queue.summary.all, 1);
    await app.success(
        0,
        { website_id: 1, stage: 'prepare' },
        queue({ current_page: 2, total: 60, last_page: 3 }),
    );
    assert.equal(app.state.filterLoading.value, false);
    app.timer.advance(300);
    assert.equal(app.calls.visits.length, 1);
});
test('queue filters and browser history retain the email workspace and discard stale response callbacks', async (t) => {
    const app = harness(t);
    app.mount();
    app.state.openEmail(app.props.queue.data[0]);
    await flush();
    app.composerState.hasDraft = true;
    app.state.updateFilter('website_id', '2');
    await app.success(
        0,
        { website_id: 2 },
        queue({
            data: [
                row({
                    id: 20,
                    website_id: 2,
                    website: { id: 2, name: 'Website B' },
                }),
            ],
        }),
    );
    assert.equal(app.state.activeEmailOrder.value.id, 10);
    app.browserEvents.fire('popstate');
    app.props.filters = filters({ stage: 'sent' });
    await flush();
    assert.equal(app.state.filterInputs.value.stage, 'sent');
    assert.equal(app.state.activeEmailOrder.value.id, 10);
});
test('queue protects an unfinished email when switching orders and preserves it on cancel', async (t) => {
    const second = row({
        id: 20,
        wp_order_id: 2002,
        stage: 'ready',
        email: email(),
    });
    const app = harness(t, {
        queue: { data: [row(), second], total: 2, to: 2 },
    });
    app.mount();
    app.state.openEmail(app.props.queue.data[0]);
    await flush();
    assert.deepEqual(app.calls.emailOpens[0], { id: 10, reviewLatest: false });
    app.composerState.hasDraft = true;
    app.state.openEmail(second);
    assert.equal(app.state.switchDialog.value, true);
    assert.equal(app.state.activeEmailOrder.value.id, 10);
    app.state.setSwitchDialog(false);
    assert.equal(app.state.activeEmailOrder.value.id, 10);
    app.state.openEmail(second);
    app.state.confirmEmailSwitch();
    await flush();
    assert.equal(app.state.activeEmailOrder.value.id, 20);
    assert.deepEqual(app.calls.emailOpens[1], { id: 20, reviewLatest: true });
});
test('queue cannot switch an active send, and only current composer settlement invalidates the snapshot', async (t) => {
    const second = row({ id: 20, wp_order_id: 2002 });
    const app = harness(t, {
        queue: { data: [row(), second], total: 2, to: 2 },
    });
    app.mount();
    app.state.openEmail(app.props.queue.data[0]);
    await flush();
    app.composerState.sending = true;
    app.state.openEmail(second);
    assert.equal(app.state.activeEmailOrder.value.id, 10);
    assert.equal(app.state.switchDialog.value, false);
    assert.match(app.state.composerNotice.value, /Wait for its result/);
    app.state.emailSettled({ orderId: 20 });
    assert.equal(app.calls.fetches.length, 0);
    app.state.emailSettled({ orderId: 10 });
    assert.equal(app.calls.fetches.length, 1);
    assert.equal(app.calls.emailOpens.length, 1);
});
test('queue rejects unlisted rows for email, clears private work on identity change, and ignores old subscription callbacks', async (t) => {
    const app = harness(t);
    app.mount();
    app.state.openEmail(row({ id: 900 }));
    await flush();
    assert.equal(app.state.activeEmailOrder.value, null);
    app.state.openEmail(row({ can_update_status: false }));
    await flush();
    assert.equal(app.state.activeEmailOrder.value, null);
    app.state.openEmail(app.props.queue.data[0]);
    await flush();
    app.composerState.hasDraft = true;
    const old = app.calls.subscriptions[0];
    app.page.props.auth.user.id = 8;
    await flush();
    assert.equal(app.state.activeEmailOrder.value, null);
    assert.equal(old.stopped, true);
    assert.equal(app.calls.subscriptions[1].userId, 8);
    app.timer.advance(250);
    await app.respond(0);
    old.onOrder({ website_id: 1 });
    app.timer.advance(250);
    assert.equal(app.calls.fetches.length, 1);
});
test('queue ignores unrelated website events, pauses offline/hidden work and bounds failed refresh retries', async (t) => {
    const app = harness(t, { filters: { website_id: 1 } });
    app.mount();
    app.timer.advance(250);
    await app.respond(0);
    app.calls.subscriptions[0].onOrder({ website_id: 2 });
    app.timer.advance(250);
    assert.equal(app.calls.fetches.length, 1);
    app.state.liveRefresh.request(0);
    app.timer.advance(0);
    app.document.hidden = true;
    app.documentEvents.fire('visibilitychange');
    assert.equal(app.calls.fetches[1].options.signal.aborted, true);
    await app.respond(1, queue({ data: [] }));
    app.timer.advance(600000);
    assert.equal(app.calls.fetches.length, 2);
    app.document.hidden = false;
    app.documentEvents.fire('visibilitychange');
    app.timer.advance(250);
    for (const [index, delay] of [
        [2, 2000],
        [3, 4000],
        [4, 8000],
        [5, 600000],
    ]) {
        app.calls.fetches[index].reject(new Error('failed'));
        await flush();
        app.timer.advance(delay);
    }
    assert.equal(app.calls.fetches.length, 6);
    assert.equal(app.state.refreshState.value.hasError, true);
    app.dispose();
    assert.equal(app.timer.size, 0);
    assert.equal(app.documentEvents.size, 0);
    assert.equal(app.browserEvents.size, 0);
    assert.equal(app.routerEvents.size, 0);
});
test('queue snapshot deadline covers a stalled body or deferred updater and never applies after navigation', async () => {
    const timer = clock(),
        response = deferred(),
        controller = new AbortController();
    let url = '/order-work-queue',
        applied = false;
    const operation = helpers.refreshOrderQueueSnapshot({
        getUrl: () => url,
        isCurrent: () => true,
        signal: controller.signal,
        setTimer: timer.setTimeout,
        clearTimer: timer.clearTimeout,
        fetch: async () => ({ ok: true, json: () => response.promise }),
        apply: async () => {
            applied = true;
            return true;
        },
    });
    await flush();
    timer.advance(20000);
    assert.equal(await operation, 'error');
    response.resolve({ queue: queue() });
    await flush();
    assert.equal(applied, false);
    const pendingApply = deferred();
    let updater;
    const next = helpers.refreshOrderQueueSnapshot({
        getUrl: () => url,
        isCurrent: () => true,
        signal: new AbortController().signal,
        setTimer: timer.setTimeout,
        clearTimer: timer.clearTimeout,
        fetch: async () => ({
            ok: true,
            json: async () => ({ queue: queue() }),
        }),
        apply: async (_queue, current) => {
            updater = current;
            return pendingApply.promise;
        },
    });
    await flush();
    url = '/orders';
    assert.equal(updater(), false);
    pendingApply.resolve(false);
    assert.equal(await next, 'cancelled');
    assert.equal(timer.size, 0);
});
