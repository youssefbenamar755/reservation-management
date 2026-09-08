import { compileScript, parse } from '@vue/compiler-sfc';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import test from 'node:test';
import { runInNewContext } from 'node:vm';
import ts from 'typescript';

const require = createRequire(import.meta.url);
const vue = require('vue');
const transpile = (source) =>
    ts.transpileModule(source, {
        compilerOptions: {
            module: ts.ModuleKind.CommonJS,
            target: ts.ScriptTarget.ES2022,
        },
    }).outputText;
const helpersModule = { exports: {} };
runInNewContext(
    transpile(
        readFileSync(new URL('../lib/orderStatus.ts', import.meta.url), 'utf8'),
    ),
    {
        module: helpersModule,
        exports: helpersModule.exports,
    },
);
const helpers = helpersModule.exports;
const source = readFileSync(
    new URL('../components/OrderStatusControl.vue', import.meta.url),
    'utf8',
);
const compiled = transpile(
    compileScript(parse(source).descriptor, { id: 'order-status-test' })
        .content,
);
const flush = async () => {
    await vue.nextTick();
    await new Promise((resolve) => setImmediate(resolve));
};

function harness(t, overrides = {}) {
    const props = vue.reactive({
        orderId: 10,
        orderNumber: 1001,
        status: 'processing',
        websiteName: 'Test store',
        ...overrides,
    });
    const calls = [],
        events = [],
        notices = [];
    const hooks = {};
    const axios = {
        put: (url, data, options) =>
            new Promise((resolve, reject) =>
                calls.push({ url, data, options, resolve, reject }),
            ),
        isAxiosError: (error) => error?.isAxiosError === true,
    };
    const wrapper = { template: '<div><slot /></div>' };
    const module = { exports: {} };
    runInNewContext(compiled, {
        module,
        exports: module.exports,
        AbortController,
        require: (name) => {
            if (name === 'axios') return { default: axios };
            if (name === 'vue')
                return {
                    ...vue,
                    useId: () => 'test-status-select',
                    onUnmounted: (fn) => {
                        hooks.unmount = fn;
                    },
                };
            if (name === '@/lib/orderStatus') return helpers;
            if (name === '@/composables/useToast')
                return {
                    useToast: () => ({
                        success: (message) => notices.push(message),
                    }),
                };
            if (name.startsWith('@/components/ui/'))
                return new Proxy({}, { get: () => wrapper });
            return require(name);
        },
    });
    const scope = vue.effectScope();
    const state = scope.run(() =>
        module.exports.default.setup(props, {
            expose() {},
            emit: (name, data) => events.push({ name, data }),
        }),
    );
    t.after(() => {
        hooks.unmount();
        scope.stop();
    });
    const respond = (index, status = 'completed', id = 10) =>
        calls[index].resolve({
            data: { message: 'Order status updated.', order: { id, status } },
        });
    const reject = (index, status, data = {}) =>
        calls[index].reject({ isAxiosError: true, response: { status, data } });
    const select = (status = 'completed') => {
        state.setOpen(true);
        state.selectedStatus.value = status;
    };
    return {
        state,
        props,
        calls,
        events,
        notices,
        hooks,
        respond,
        reject,
        select,
    };
}

test('status helpers offer exactly the seven supported writes and preserve unknown current statuses', () => {
    assert.equal(helpers.editableOrderStatuses.length, 7);
    assert.equal(helpers.orderStatusName('pending'), 'Pending payment');
    assert.equal(helpers.orderStatusName('wc-processing'), 'wc-processing');
    assert.equal(helpers.orderStatusName('Custom STATUS'), 'Custom STATUS');
    assert.equal(helpers.isEditableOrderStatus('wc-processing'), false);
    assert.equal(helpers.isEditableOrderStatus('checkout-draft'), false);
    assert.equal(helpers.isEditableOrderStatus('refunded'), true);
    assert.equal(
        helpers.readConfirmedOrderStatus({ id: 20, status: 'completed' }, 10),
        null,
    );
    assert.equal(
        helpers.readConfirmedOrderStatus({ id: 10, status: '' }, 10),
        null,
    );
});

test('opening, selecting and cancelling never write, and reopening starts from the confirmed status', async (t) => {
    const { state, calls, select } = harness(t);
    assert.equal(state.canSave.value, false);
    select();
    assert.equal(state.confirmedStatus.value, 'processing');
    assert.equal(state.canSave.value, true);
    state.setOpen(false);
    await state.saveStatus();
    assert.equal(calls.length, 0);
    state.setOpen(true);
    assert.equal(state.selectedStatus.value, 'processing');
    assert.equal(state.canSave.value, false);
});

test('disabled controls and unknown destinations cannot send updates', async (t) => {
    const { state, props, calls } = harness(t, {
        disabled: true,
        status: 'custom-status',
    });
    state.setOpen(true);
    assert.equal(state.open.value, false);
    props.disabled = false;
    state.setOpen(true);
    assert.equal(state.confirmedStatus.value, 'custom-status');
    assert.equal(state.selectedStatus.value, 'custom-status');
    assert.equal(state.canSave.value, false);
    state.selectedStatus.value = 'wc-completed';
    await state.saveStatus();
    assert.equal(calls.length, 0);
    state.selectedStatus.value = 'completed';
    props.disabled = true;
    await state.saveStatus();
    assert.equal(calls.length, 0);
});

test('save is explicit, blocks duplicate actions and close, and uses the server-confirmed status', async (t) => {
    const { state, calls, events, notices, respond, select } = harness(t);
    select('completed');
    const pending = state.saveStatus();
    assert.equal(state.confirmedStatus.value, 'processing');
    assert.equal(state.saving.value, true);
    assert.equal(state.canSave.value, false);
    assert.equal(calls[0].url, '/orders/10');
    assert.equal(calls[0].data.status, 'completed');
    assert.equal(calls[0].options.headers.Accept, 'application/json');
    assert.equal(calls[0].options.timeout, 35000);
    await state.saveStatus();
    state.setOpen(false);
    assert.equal(state.open.value, true);
    assert.equal(calls.length, 1);
    assert.equal(events.length, 0);
    respond(0, 'on-hold');
    await pending;
    assert.equal(state.confirmedStatus.value, 'on-hold');
    assert.equal(state.selectedStatus.value, 'on-hold');
    assert.equal(state.open.value, false);
    assert.equal(state.saving.value, false);
    assert.equal(events[0].name, 'updated');
    assert.equal(events[0].data.id, 10);
    assert.equal(events[0].data.status, 'on-hold');
    assert.equal(events[1].name, 'settled');
    assert.equal(notices[0], 'Order #1001 status is On hold.');
});

test('failed requests surface validation, resync fresh server status and refresh the parent without success', async (t) => {
    const { state, calls, events, notices, select, reject } = harness(t);
    select('refunded');
    const pending = state.saveStatus();
    reject(0, 422, {
        message: 'Update failed.',
        errors: { status: ['The store refused this status.'] },
        order: { id: 10, status: 'completed' },
    });
    await pending;
    assert.equal(state.confirmedStatus.value, 'completed');
    assert.equal(state.selectedStatus.value, 'completed');
    assert.equal(state.error.value, 'The store refused this status.');
    assert.equal(state.open.value, true);
    assert.equal(state.canSave.value, false);
    assert.equal(events.length, 1);
    assert.equal(events[0].name, 'settled');
    assert.equal(notices.length, 0);
    await flush();
    assert.equal(calls.length, 1);
});

test('session, access and network errors stay inline and never automatically retry', async (t) => {
    for (const [status, expected] of [
        [401, /session expired/],
        [419, /session expired/],
        [403, /access/],
        [404, /no longer available/],
        [429, /Too many requests/],
        [null, /could not be confirmed/],
    ]) {
        await t.test(String(status), async (t) => {
            const { state, calls, events, notices, select, reject } =
                harness(t);
            select();
            const pending = state.saveStatus();
            if (status === null) calls[0].reject(new Error('connection lost'));
            else reject(0, status);
            await pending;
            assert.match(state.error.value, expected);
            assert.equal(state.confirmedStatus.value, 'processing');
            assert.equal(state.selectedStatus.value, 'completed');
            assert.equal(state.saving.value, false);
            assert.equal(events.length, 1);
            assert.equal(events[0].name, 'settled');
            assert.equal(notices.length, 0);
            await flush();
            assert.equal(calls.length, 1);
        });
    }
});

test('malformed or cross-order success responses remain unconfirmed and never emit updated', async (t) => {
    const { state, events, notices, select, respond } = harness(t);
    select();
    const pending = state.saveStatus();
    respond(0, 'completed', 20);
    await pending;
    assert.equal(state.confirmedStatus.value, 'processing');
    assert.match(state.error.value, /could not be confirmed/);
    assert.equal(events.length, 1);
    assert.equal(events[0].name, 'settled');
    assert.equal(notices.length, 0);
});

test('unrelated page updates retain the selection while a changed status resyncs and notifies the editor', async (t) => {
    const { state, props, select } = harness(t);
    select('completed');
    props.websiteName = 'Updated store name';
    props.status = 'processing';
    await flush();
    assert.equal(state.selectedStatus.value, 'completed');
    assert.equal(state.notice.value, '');
    props.status = 'cancelled';
    await flush();
    assert.equal(state.confirmedStatus.value, 'cancelled');
    assert.equal(state.selectedStatus.value, 'cancelled');
    assert.equal(state.canSave.value, false);
    assert.match(
        state.notice.value,
        /changed to Cancelled while you were editing/,
    );
});

test('a live change during a request cannot overwrite the submitted choice or cause an automatic second write', async (t) => {
    const { state, props, calls, respond, select } = harness(t);
    select();
    const pending = state.saveStatus();
    props.status = 'on-hold';
    await flush();
    assert.equal(state.confirmedStatus.value, 'on-hold');
    assert.equal(state.selectedStatus.value, 'completed');
    assert.equal(calls[0].data.status, 'completed');
    respond(0, 'custom-warehouse');
    await pending;
    assert.equal(state.confirmedStatus.value, 'custom-warehouse');
    assert.equal(calls.length, 1);
});

test('changing order identity aborts pending work and ignores late success even after returning to its ID', async (t) => {
    const { state, props, calls, events, notices, respond, select } =
        harness(t);
    select();
    const pending = state.saveStatus();
    props.orderId = 20;
    props.status = 'pending';
    await flush();
    assert.equal(calls[0].options.signal.aborted, true);
    assert.equal(state.open.value, false);
    assert.equal(state.saving.value, false);
    props.orderId = 10;
    props.status = 'on-hold';
    await flush();
    respond(0);
    await pending;
    assert.equal(state.confirmedStatus.value, 'on-hold');
    assert.equal(events.length, 0);
    assert.equal(notices.length, 0);
});

test('unmount aborts the request and prevents stale notifications or parent refreshes', async (t) => {
    const { state, calls, events, notices, hooks, respond, select } =
        harness(t);
    select();
    const pending = state.saveStatus();
    hooks.unmount();
    assert.equal(calls[0].options.signal.aborted, true);
    respond(0);
    await pending;
    assert.equal(events.length, 0);
    assert.equal(notices.length, 0);
    await state.saveStatus();
    assert.equal(calls.length, 1);
});
