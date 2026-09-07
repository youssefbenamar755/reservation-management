import { compileScript, parse } from '@vue/compiler-sfc';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import test from 'node:test';
import { runInNewContext } from 'node:vm';
import ts from 'typescript';

const require = createRequire(import.meta.url);
const vue = require('vue');
const wrapper = { template: '<div><slot /></div>' };
const deferred = () => {
    let resolve;
    let reject;
    const promise = new Promise((a, b) => {
        resolve = a;
        reject = b;
    });
    return { promise, resolve, reject };
};
const flush = async () => {
    await vue.nextTick();
    await new Promise((resolve) => setImmediate(resolve));
};

function loadComponent(filename, mocks, globals = {}) {
    const source = readFileSync(new URL(filename, import.meta.url), 'utf8');
    const { descriptor } = parse(source, { filename });
    const compiled = compileScript(descriptor, { id: 'order-notes-test' });
    const { outputText } = ts.transpileModule(compiled.content, {
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
        ...globals,
        require: (name) =>
            Object.hasOwn(mocks, name) ? mocks[name] : require(name),
    });
    return module.exports.default;
}

function harness(t, available = true) {
    const hooks = {};
    const calls = [];
    const props = vue.reactive({
        orderId: 10,
        available,
        refreshKey: 'pending',
    });
    const component = loadComponent(
        '../components/OrderNotes.vue',
        {
            vue: {
                ...vue,
                onMounted: (fn) => {
                    hooks.mount = fn;
                },
                onUnmounted: (fn) => {
                    hooks.unmount = fn;
                },
            },
            '@/components/ui/card': {
                Card: wrapper,
                CardContent: wrapper,
                CardHeader: wrapper,
                CardTitle: wrapper,
            },
            '@/components/ui/badge': { Badge: wrapper },
            '@/components/ui/button': { Button: wrapper },
        },
        {
            fetch: (url, options) => {
                const result = deferred();
                calls.push({ url, options, ...result });
                return result.promise;
            },
        },
    );
    const scope = vue.effectScope();
    const state = scope.run(() => component.setup(props, { expose() {} }));
    t.after(() => {
        hooks.unmount();
        scope.stop();
    });
    const respond = (index, notes) =>
        calls[index].resolve({ ok: true, json: async () => ({ notes }) });
    return { state, calls, props, hooks, respond };
}

test('order notes wait until mounting and load independently of the order page', async (t) => {
    const { state, calls, hooks, respond } = harness(t);
    assert.equal(calls.length, 0);
    hooks.mount();
    assert.equal(state.loading.value, true);
    assert.equal(calls[0].url, '/orders/10/notes');
    assert.equal(calls[0].options.credentials, 'same-origin');
    respond(0, [{ id: 1, note: 'Received' }]);
    await flush();
    assert.equal(state.notes.value[0].note, 'Received');
    assert.equal(state.loading.value, false);
});

test('failed notes are visible and retry can recover', async (t) => {
    const { state, calls, hooks, respond } = harness(t);
    hooks.mount();
    calls[0].resolve({ ok: false });
    await flush();
    assert.match(state.error.value, /could not be loaded/);
    const retry = state.loadNotes();
    respond(1, []);
    await retry;
    assert.equal(state.error.value, '');
    assert.equal(state.notes.value.length, 0);
    assert.equal(state.loading.value, false);
});

test('navigating orders cancels the old request and ignores late data', async (t) => {
    const { state, calls, props, hooks, respond } = harness(t);
    hooks.mount();
    props.orderId = 20;
    await flush();
    assert.equal(calls[0].options.signal.aborted, true);
    assert.equal(calls[1].url, '/orders/20/notes');
    respond(1, [{ id: 2, note: 'Current order' }]);
    await flush();
    respond(0, [{ id: 1, note: 'Old order' }]);
    await flush();
    assert.equal(state.notes.value[0].note, 'Current order');
    props.refreshKey = 'completed';
    await flush();
    assert.equal(calls.length, 3);
    hooks.unmount();
    assert.equal(calls[2].options.signal.aborted, true);
    calls[2].reject(new Error('aborted'));
    await flush();
    assert.equal(state.error.value, '');
});

test('unavailable notes make no request and malformed replies display an error', async (t) => {
    const { state, calls, props, hooks, respond } = harness(t, false);
    hooks.mount();
    assert.equal(calls.length, 0);
    assert.equal(state.loading.value, false);
    props.available = true;
    await flush();
    respond(0, { error: 'not a list' });
    await flush();
    assert.match(state.error.value, /could not be loaded/);
});

test('status failure redirects restore the confirmed selection without a success toast', (t) => {
    const calls = [];
    const toast = {
        success: (message) => calls.push(['success', message]),
        error: (message) => calls.push(['error', message]),
    };
    const props = vue.reactive({ order: { id: 42, status: 'pending' } });
    let options;
    const form = vue.reactive({
        status: 'pending',
        put: (_url, value) => {
            options = value;
        },
    });
    const component = loadComponent('../pages/Orders/Show.vue', {
        '@inertiajs/vue3': {
            Head: wrapper,
            Link: wrapper,
            router: {},
            usePage: () => ({ props: {} }),
            useForm: () => form,
        },
        '@/layouts/AppLayout.vue': { default: wrapper },
        '@/components/FlightCard.vue': { default: wrapper },
        '@/components/OrderNotes.vue': { default: wrapper },
        '@/components/ui/card': {
            Card: wrapper,
            CardContent: wrapper,
            CardDescription: wrapper,
            CardHeader: wrapper,
            CardTitle: wrapper,
        },
        '@/components/ui/button': { Button: wrapper },
        '@/components/ui/badge': { Badge: wrapper },
        '@/components/ui/label': { Label: wrapper },
        '@/composables/useToast': { useToast: () => toast },
    });
    const scope = vue.effectScope();
    const state = scope.run(() => component.setup(props, { expose() {} }));
    t.after(() => scope.stop());
    form.status = 'completed';
    state.updateStatus();
    options.onSuccess({
        props: {
            order: { status: 'pending' },
            flash: { error: 'WooCommerce rejected the update' },
        },
    });
    assert.equal(form.status, 'pending');
    assert.equal(
        state.statusUpdateError.value,
        'WooCommerce rejected the update',
    );
    assert.equal(
        calls.some(([type]) => type === 'success'),
        false,
    );
});
