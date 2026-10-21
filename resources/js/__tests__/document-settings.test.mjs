import { compileScript, parse } from '@vue/compiler-sfc';
import assert from 'node:assert/strict';
import { randomUUID } from 'node:crypto';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import test from 'node:test';
import { runInNewContext } from 'node:vm';
import ts from 'typescript';
const require = createRequire(import.meta.url);
const vue = require('vue');
const source = readFileSync(
    new URL('../pages/settings/EmailDocuments.vue', import.meta.url),
    'utf8',
);
const script = compileScript(parse(source).descriptor, {
    id: 'document-settings-test',
}).content;
const { outputText } = ts.transpileModule(script, {
    compilerOptions: {
        module: ts.ModuleKind.CommonJS,
        target: ts.ScriptTarget.ES2022,
    },
});
const defaults = Object.fromEntries(
    ['acknowledgement', 'delivery', 'correction'].map((kind) => [
        kind,
        Object.fromEntries(
            ['fr', 'en'].map((language) => [
                language,
                {
                    subject: '{{brand_name}} {{order_number}}',
                    heading: 'Documents',
                    body: 'Hello {{customer_first_name}}',
                    preheader: 'Your PDFs',
                    optional_block: '',
                },
            ]),
        ),
    ]),
);
const site = (id = 1) => ({
    id,
    name: `Website ${id}`,
    url: `https://site${id}.example`,
    profile: {
        brand_name: `Brand ${id}`,
        default_language: 'en',
        templates: defaults,
        required_services: [],
        workflow_enabled: false,
        config_version: 0,
    },
    readiness: {
        ready: false,
        missing: ['logo_url'],
        gmail_connected: false,
        sender_verified: false,
        templates_ready: true,
        completion_supported: false,
        platform: 'unsupported',
    },
    connections: [],
});
const flush = async () => {
    await vue.nextTick();
    await new Promise((resolve) => setImmediate(resolve));
};
function harness(t) {
    const calls = [],
        hooks = {};
    const props = vue.reactive({
        websites: [site(1), site(2)],
        placeholders: ['{{brand_name}}'],
        defaultTemplates: defaults,
    });
    const axios = {
        post: (url, data, options) =>
            new Promise((resolve, reject) =>
                calls.push({
                    method: 'POST',
                    url,
                    data,
                    options,
                    resolve,
                    reject,
                }),
            ),
        put: (url, data, options) =>
            new Promise((resolve, reject) =>
                calls.push({
                    method: 'PUT',
                    url,
                    data,
                    options,
                    resolve,
                    reject,
                }),
            ),
        isAxiosError: (e) => e?.isAxiosError === true,
    };
    const wrapper = { template: '<div />' },
        module = { exports: {} };
    runInNewContext(outputText, {
        module,
        exports: module.exports,
        console,
        AbortController,
        URLSearchParams,
        crypto: { randomUUID },
        window: { location: { search: '' }, confirm: () => true },
        require: (name) => {
            if (name === 'axios') return { default: axios };
            if (name === 'vue')
                return {
                    ...vue,
                    onUnmounted: (fn) => {
                        hooks.unmount = fn;
                    },
                };
            if (name === '@inertiajs/vue3')
                return {
                    Head: wrapper,
                    Link: wrapper,
                    router: { reload: () => {} },
                };
            if (name.endsWith('.vue')) return { default: wrapper };
            if (name.startsWith('@/components/ui/'))
                return new Proxy({}, { get: () => wrapper });
            return require(name);
        },
    });
    const scope = vue.effectScope();
    const state = scope.run(() =>
        module.exports.default.setup(props, { expose() {} }),
    );
    t.after(() => {
        hooks.unmount();
        scope.stop();
    });
    return {
        state,
        props,
        calls,
        respond: (i, data) => calls[i].resolve({ data }),
    };
}
test('new registered websites receive separate editable drafts without invented sender or automatic requests', async (t) => {
    const { state, calls } = harness(t);
    assert.equal(calls.length, 0);
    assert.equal(state.draft.value.sender_email, '');
    assert.equal(state.draft.value.logo_url, '');
    assert.equal(state.dirty.value, false);
    state.switchWebsite(2);
    await flush();
    assert.equal(state.draft.value.brand_name, 'Brand 2');
    assert.equal(state.draft.value.workflow_enabled, false);
    assert.equal(calls.length, 0);
});
test('saving a draft does not activate; activation requires an explicit separate confirmation', async (t) => {
    const { state, calls, respond } = harness(t);
    state.draft.value.brand_name = 'Revised brand';
    const pending = state.save();
    assert.equal(calls[0].data.workflow_enabled, false);
    assert.equal(calls[0].url, '/settings/email-documents/1');
    respond(0, {
        website: {
            ...site(),
            profile: { ...calls[0].data, config_version: 1 },
        },
    });
    await pending;
    await flush();
    await state.save(true);
    assert.equal(calls.length, 1);
    state.activationConfirmed.value = true;
    const activate = state.save(true);
    assert.equal(calls[1].data.workflow_enabled, true);
    respond(1, {
        website: {
            ...site(),
            profile: { ...calls[1].data, config_version: 2 },
        },
    });
    await activate;
});
test('store branding requires explicit approval and carries the saved config version', async (t) => {
    const { state, calls, respond } = harness(t);
    await state.applyStoreBranding();
    assert.equal(calls.length, 0);
    state.storeBrandingConfirmed.value = true;
    const pending = state.applyStoreBranding();
    assert.equal(calls[0].url, '/settings/email-documents/1/sync-brand');
    assert.equal(calls[0].data.config_version, 0);
    assert.equal(calls[0].data.confirmed, true);
    respond(0, { website: site() });
    await pending;
});

test('emergency disable ignores invalid unsaved fields and preserves the draft without publishing it', async (t) => {
    const { state, props, calls, respond } = harness(t);
    props.websites[0].profile.workflow_enabled = true;
    await flush();
    state.draft.value.brand_name = 'Unsaved brand edit';
    state.draft.value.templates = {};
    assert.equal(state.dirty.value, true);
    const pending = state.disableWorkflow();
    assert.equal(calls[0].url, '/settings/email-documents/1/disable');
    assert.deepEqual(JSON.parse(JSON.stringify(calls[0].data)), {
        confirmed: true,
    });
    respond(0, {
        website_id: 1,
        workflow_enabled: false,
        previous_config_version: 0,
        config_version: 1,
        profile: {
            ...site().profile,
            workflow_enabled: false,
            config_version: 1,
        },
    });
    await pending;
    assert.equal(state.selected.value.profile.workflow_enabled, false);
    assert.equal(state.selected.value.profile.brand_name, 'Brand 1');
    assert.equal(state.draft.value.brand_name, 'Unsaved brand edit');
    assert.equal(state.draft.value.workflow_enabled, false);
    assert.equal(state.dirty.value, true);
    assert.equal(calls.length, 1);
    assert.match(
        state.notice.value,
        /Publish the disabled state to the store separately/,
    );
});

test('disable cannot give a stale draft a fresh version that would overwrite concurrent saved edits', async (t) => {
    const { state, props, respond } = harness(t);
    props.websites[0].profile.workflow_enabled = true;
    await flush();
    state.draft.value.brand_name = 'Older unsaved edit';
    const pending = state.disableWorkflow();
    respond(0, {
        website_id: 1,
        workflow_enabled: false,
        previous_config_version: 4,
        config_version: 5,
        profile: {
            ...site().profile,
            brand_name: 'Another operator saved this',
            workflow_enabled: false,
            config_version: 5,
        },
    });
    await pending;
    assert.equal(
        state.selected.value.profile.brand_name,
        'Another operator saved this',
    );
    assert.equal(state.draft.value.config_version, 0);
    assert.match(state.notice.value, /reload before applying your draft/);
});
test('test email requires an entered recipient and approval; a network-unknown result retains its idempotency key and cannot silently retry', async (t) => {
    const { state, calls } = harness(t);
    await state.sendTest();
    assert.equal(calls.length, 0);
    state.testRecipient.value = 'operator@example.test';
    state.testConfirmed.value = true;
    const pending = state.sendTest();
    assert.equal(calls[0].data.recipient, 'operator@example.test');
    assert.match(calls[0].data.idempotency_key, /^[0-9a-f-]{36}$/);
    const key = state.testAttemptKey.value;
    calls[0].reject(new Error('Network timeout'));
    await pending;
    assert.equal(state.testUnconfirmed.value, true);
    assert.equal(state.testAttemptKey.value, key);
    await state.sendTest();
    assert.equal(calls.length, 1);
});
test('server validation failure is actionable and never leaves the test wrongly marked as potentially sent', async (t) => {
    const { state, calls } = harness(t);
    state.testRecipient.value = 'operator@example.test';
    state.testConfirmed.value = true;
    const pending = state.sendTest();
    calls[0].reject({
        isAxiosError: true,
        response: {
            status: 422,
            data: { errors: { profile: ['Configure sender first.'] } },
        },
    });
    await pending;
    assert.equal(state.testUnconfirmed.value, false);
    assert.equal(state.testAttemptKey.value, null);
    assert.deepEqual([...state.errors.value], ['Configure sender first.']);
});
