import { compileScript, parse } from '@vue/compiler-sfc';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import test from 'node:test';
import { runInNewContext } from 'node:vm';
import ts from 'typescript';

const require = createRequire(import.meta.url);
const vue = require('vue');
const url = 'https://www.trustpilot.com/review/site1.example';
const otherUrl = 'https://www.trustpilot.com/review/site2.example';
const flush = async () => {
    await vue.nextTick();
    await new Promise((resolve) => setImmediate(resolve));
};

function load(filename, mocks) {
    const source = readFileSync(new URL(filename, import.meta.url), 'utf8');
    const script = compileScript(parse(source).descriptor, {
        id: 'trustpilot-settings-test',
    }).content;
    const { outputText } = ts.transpileModule(script, {
        compilerOptions: {
            module: ts.ModuleKind.CommonJS,
            target: ts.ScriptTarget.ES2022,
        },
    });
    const module = { exports: {} };
    const wrapper = { template: '<div />' };
    runInNewContext(outputText, {
        module,
        exports: module.exports,
        console,
        URL,
        URLSearchParams,
        AbortController,
        window: { location: { search: '' }, confirm: () => true },
        require: (name) => {
            if (Object.hasOwn(mocks, name)) return mocks[name];
            if (name.endsWith('.vue')) return { default: wrapper };
            if (name.startsWith('@/components/ui/'))
                return new Proxy({}, { get: () => wrapper });
            return require(name);
        },
    });
    return module.exports.default;
}
function harness(t, initial = {}) {
    const calls = [],
        events = [],
        hooks = {};
    const props = vue.reactive({
        websiteId: 1,
        profileId: 10,
        trustpilotUrl: null,
        disabled: false,
        ...initial,
    });
    const component = load('../components/TrustpilotReviewSettings.vue', {
        vue: {
            ...vue,
            onUnmounted: (callback) => {
                hooks.unmount = callback;
            },
        },
        axios: {
            default: {
                patch: (endpoint, data, options) =>
                    new Promise((resolve, reject) =>
                        calls.push({
                            endpoint,
                            data,
                            options,
                            resolve,
                            reject,
                        }),
                    ),
                isAxiosError: (error) => error?.isAxiosError === true,
            },
        },
    });
    const scope = vue.effectScope();
    const state = scope.run(() =>
        component.setup(props, {
            expose() {},
            emit: (...event) => events.push(event),
        }),
    );
    t.after(() => {
        hooks.unmount();
        scope.stop();
    });
    return {
        state,
        props,
        calls,
        events,
        hooks,
        respond: (i, link, websiteId = 1) =>
            calls[i].resolve({
                data: { website_id: websiteId, trustpilot_url: link },
            }),
    };
}

test('review settings make no automatic requests and require a saved profile and idle parent', async (t) => {
    const { state, props, calls } = harness(t, { profileId: null });
    state.current.value.input = url;
    await state.save();
    assert.equal(calls.length, 0);
    props.profileId = 10;
    props.disabled = true;
    await flush();
    await state.save();
    assert.equal(calls.length, 0);
    props.disabled = false;
    assert.equal(state.canSave.value, true);
});

test('review save sends only link and expected saved link, accepts canonical response, and blocks duplicate clicks', async (t) => {
    const { state, calls, events, respond } = harness(t);
    state.current.value.input =
        '  https://fr.trustpilot.com/review/site1.example/  ';
    const pending = state.save();
    await state.save();
    assert.equal(calls.length, 1);
    assert.equal(calls[0].endpoint, '/settings/email-documents/1/review-link');
    assert.deepEqual(Object.keys(calls[0].data).sort(), [
        'expected_trustpilot_url',
        'trustpilot_url',
    ]);
    assert.equal(
        calls[0].data.trustpilot_url,
        'https://fr.trustpilot.com/review/site1.example/',
    );
    assert.equal(calls[0].data.expected_trustpilot_url, null);
    assert.equal(calls[0].options.timeout, 20000);
    respond(0, url);
    await pending;
    assert.equal(state.current.value.input, url);
    assert.equal(state.savedLink.value, url);
    assert.equal(state.dirty.value, false);
    assert.equal(events.filter(([name]) => name === 'saved').length, 1);
    assert.equal(
        events.find(([name]) => name === 'saved')[1].trustpilotUrl,
        url,
    );
    assert.deepEqual(
        events.filter(([name]) => name === 'saving').map(([, value]) => value),
        [true, false],
    );
});

test('blank removes only the review link with its current compare-and-swap value', async (t) => {
    const { state, calls, respond } = harness(t, { trustpilotUrl: url });
    state.current.value.input = '  ';
    const pending = state.save();
    assert.equal(calls[0].data.trustpilot_url, null);
    assert.equal(calls[0].data.expected_trustpilot_url, url);
    respond(0, null);
    await pending;
    assert.equal(state.savedLink.value, null);
    assert.match(state.current.value.notice, /removed/);
    assert.equal(state.dirty.value, false);
});

test('switching websites retains separate drafts and aborts/ignores an old website response', async (t) => {
    const { state, props, calls, events, respond } = harness(t);
    state.current.value.input = url;
    const pending = state.save();
    props.websiteId = 2;
    props.profileId = 20;
    props.trustpilotUrl = otherUrl;
    await flush();
    assert.equal(calls[0].options.signal.aborted, true);
    assert.equal(state.saving.value, false);
    assert.equal(state.current.value.input, otherUrl);
    state.current.value.input = '';
    props.websiteId = 1;
    props.profileId = 10;
    props.trustpilotUrl = null;
    await flush();
    assert.equal(state.current.value.input, url);
    respond(0, url);
    await pending;
    assert.equal(state.current.value.baseline, null);
    assert.equal(
        events.some(([name]) => name === 'saved'),
        false,
    );
    props.websiteId = 2;
    props.profileId = 20;
    props.trustpilotUrl = otherUrl;
    await flush();
    assert.equal(state.current.value.input, '');
    assert.equal(state.current.value.baseline, otherUrl);
});

test('conflicts retain the draft and require refreshed saved data before another save', async (t) => {
    const { state, props, calls } = harness(t);
    state.current.value.input = url;
    const pending = state.save();
    calls[0].reject({ isAxiosError: true, response: { status: 409 } });
    await pending;
    assert.equal(state.current.value.input, url);
    assert.equal(state.current.value.baseline, null);
    assert.match(state.current.value.error, /reload the page/);
    assert.equal(state.canSave.value, false);
    await state.save();
    assert.equal(calls.length, 1);
    props.trustpilotUrl = url;
    await flush();
    assert.equal(state.current.value.conflicted, false);
    assert.equal(state.dirty.value, false);
});

test('validation and uncertain network failures keep drafts without reporting success or retrying', async (t) => {
    const { state, calls, events } = harness(t);
    state.current.value.input = otherUrl;
    let pending = state.save();
    calls[0].reject({
        isAxiosError: true,
        response: {
            status: 422,
            data: {
                errors: {
                    trustpilot_url: ['Use this website’s Trustpilot profile.'],
                },
            },
        },
    });
    await pending;
    assert.match(state.current.value.error, /this website/);
    assert.equal(state.current.value.input, otherUrl);
    pending = state.save();
    calls[1].reject(new Error('connection lost'));
    await pending;
    await flush();
    assert.match(state.current.value.error, /could not be confirmed/);
    assert.equal(
        events.some(([name]) => name === 'saved'),
        false,
    );
    assert.equal(calls.length, 2);
    assert.equal(state.saving.value, false);
});

test('fresh server props preserve a typed draft while updating the saved link baseline', async (t) => {
    const { state, props } = harness(t);
    state.current.value.input =
        'https://fr.trustpilot.com/review/site1.example';
    props.trustpilotUrl = url;
    await flush();
    assert.equal(
        state.current.value.input,
        'https://fr.trustpilot.com/review/site1.example',
    );
    assert.equal(state.current.value.baseline, url);
    assert.equal(state.savedLink.value, url);
    assert.equal(state.dirty.value, true);
});

test('unmount aborts saves and malformed or unsafe response links cannot become saved anchors', async (t) => {
    const { state, calls, events, hooks, respond } = harness(t);
    for (const candidate of [
        'javascript:alert(1)',
        'https://evil.example/review/site1.example',
        `${url}?redirect=evil`,
        'https://www.trustpilot.com@evil.example/review/site1.example',
    ]) {
        assert.equal(state.canonicalProfileUrl(candidate), null);
    }
    state.current.value.input = url;
    let pending = state.save();
    respond(0, 'https://evil.example/review/site1.example');
    await pending;
    assert.equal(state.savedLink.value, null);
    assert.equal(state.current.value.baseline, null);
    pending = state.save();
    hooks.unmount();
    assert.equal(calls[1].options.signal.aborted, true);
    respond(1, url);
    await pending;
    assert.equal(
        events.some(([name]) => name === 'saved'),
        false,
    );
});

function parentHarness(t) {
    const hooks = {},
        calls = [];
    const templates = Object.fromEntries(
        ['acknowledgement', 'delivery', 'correction'].map((type) => [
            type,
            Object.fromEntries(
                ['fr', 'en'].map((language) => [
                    language,
                    {
                        subject: 'Saved subject',
                        heading: 'Heading',
                        body: 'Saved body',
                        preheader: '',
                        optional_block: '',
                    },
                ]),
            ),
        ]),
    );
    const props = vue.reactive({
        websites: [1, 2].map((id) => ({
            id,
            name: `Site ${id}`,
            profile: {
                id: id * 10,
                brand_name: `Brand ${id}`,
                default_language: 'fr',
                templates: JSON.parse(JSON.stringify(templates)),
                workflow_enabled: true,
                config_version: 9,
                trustpilot_url: null,
            },
            connections: [],
            readiness: { ready: true },
        })),
        defaultTemplates: templates,
        placeholders: [],
    });
    const component = load('../pages/settings/EmailDocuments.vue', {
        vue: {
            ...vue,
            onUnmounted: (callback) => {
                hooks.unmount = callback;
            },
        },
        axios: {
            default: {
                put: (endpoint, data, options) =>
                    new Promise((resolve, reject) =>
                        calls.push({
                            endpoint,
                            data,
                            options,
                            resolve,
                            reject,
                        }),
                    ),
                isAxiosError: () => false,
            },
        },
        '@inertiajs/vue3': {
            Head: {},
            Link: {},
            router: {
                reload: () => {
                    throw new Error('Review save must not reload all profiles');
                },
            },
        },
    });
    const scope = vue.effectScope();
    const state = scope.run(() => component.setup(props, { expose() {} }));
    t.after(() => {
        hooks.unmount();
        scope.stop();
    });
    return { state, props, calls };
}

test('parent review save keeps unrelated unsaved templates, workflow/version, selected object and preview invalidation', async (t) => {
    const { state, calls } = parentHarness(t);
    const selected = state.selected.value;
    state.draft.value.footer = 'Unsaved support signature';
    state.draft.value.templates.delivery.fr.body = 'Unsaved French delivery';
    state.preview.value = { subject: 'Old', html: 'Old', text: 'Old' };
    state.reviewLinkSaved({ websiteId: 1, trustpilotUrl: url });
    await flush();
    assert.equal(state.selected.value, selected);
    assert.equal(state.draft.value.footer, 'Unsaved support signature');
    assert.equal(
        state.draft.value.templates.delivery.fr.body,
        'Unsaved French delivery',
    );
    assert.equal(state.draft.value.trustpilot_url, url);
    assert.equal(JSON.parse(state.baseline.value).trustpilot_url, url);
    assert.equal(
        JSON.parse(state.baseline.value).templates.delivery.fr.body,
        'Saved body',
    );
    assert.equal(state.draft.value.workflow_enabled, true);
    assert.equal(state.draft.value.config_version, 9);
    assert.equal(state.dirty.value, true);
    assert.equal(state.previewStale.value, true);
    assert.equal(calls.length, 0);
});

test('parent link-only change stays clean and full-profile save never submits the independent link', async (t) => {
    const { state, props, calls } = parentHarness(t);
    state.reviewLinkSaved({ websiteId: 1, trustpilotUrl: url });
    await flush();
    assert.equal(state.dirty.value, false);
    state.draft.value.brand_name = 'Revised brand';
    state.reviewSaving.value = true;
    await state.save();
    assert.equal(calls.length, 0);
    state.reviewSaving.value = false;
    const pending = state.save();
    assert.equal(Object.hasOwn(calls[0].data, 'trustpilot_url'), false);
    calls[0].resolve({
        data: {
            website: {
                ...props.websites[0],
                profile: { ...calls[0].data, trustpilot_url: url },
            },
        },
    });
    await pending;
    state.switchWebsite(2);
    await flush();
    assert.equal(state.draft.value.trustpilot_url, null);
    assert.equal(state.draft.value.config_version, 9);
});
