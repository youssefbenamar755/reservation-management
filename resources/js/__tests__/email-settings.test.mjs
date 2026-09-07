import { compileScript, parse } from '@vue/compiler-sfc';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import test from 'node:test';
import { runInNewContext } from 'node:vm';
import ts from 'typescript';

const require = createRequire(import.meta.url);
const vue = require('vue');
const source = readFileSync(
    new URL('../pages/settings/Email.vue', import.meta.url),
    'utf8',
);
const script = compileScript(parse(source).descriptor, {
    id: 'email-settings-test',
}).content;
const { outputText } = ts.transpileModule(script, {
    compilerOptions: {
        module: ts.ModuleKind.CommonJS,
        target: ts.ScriptTarget.ES2022,
    },
});

function fixture() {
    return {
        app: {
            configured: true,
            client_id: 'saved-client',
            has_secret: true,
            redirect_uri: 'https://app.example.test/settings/email/callback',
            can_manage: true,
        },
        connection: {
            connected: true,
            email: 'verified@example.test',
            aliases: [
                { email: 'verified@example.test', name: 'Verified Sender' },
            ],
        },
        websites: [1, 2].map((id) => ({
            id,
            name: `Website ${id}`,
            base_url: `https://site${id}.example.test`,
            sender_email: 'verified@example.test',
            subject_template: `Subject ${id}`,
            body_template: `Message ${id}`,
            signature: `Signature ${id}`,
        })),
        placeholders: [
            '{{customer_name}}',
            '{{order_number}}',
            '{{website_name}}',
        ],
    };
}

function harness(t, settings = fixture(), flash = {}) {
    const requests = [],
        connections = [],
        destinations = [],
        copies = [];
    const props = vue.reactive({ emailSettings: settings });
    let unmount;
    const inertia = {
        Head: {},
        usePage: () => ({ props: { flash } }),
        router: {
            put: (url, data, options) =>
                requests.push({ method: 'put', url, data, options }),
            post: (url, data, options) =>
                requests.push({ method: 'post', url, data, options }),
            delete: (url, options) =>
                requests.push({ method: 'delete', url, options }),
        },
    };
    const module = { exports: {} };
    runInNewContext(outputText, {
        module,
        exports: module.exports,
        URL,
        AbortController,
        window: { location: { assign: (url) => destinations.push(url) } },
        navigator: {
            clipboard: { writeText: async (text) => copies.push(text) },
        },
        require: (name) => {
            if (name === 'vue')
                return {
                    ...vue,
                    onUnmounted: (callback) => {
                        unmount = callback;
                    },
                };
            if (name === '@inertiajs/vue3') return inertia;
            if (name === 'axios')
                return {
                    default: {
                        post: (url, data, options) =>
                            new Promise((resolve, reject) =>
                                connections.push({
                                    url,
                                    data,
                                    options,
                                    resolve,
                                    reject,
                                }),
                            ),
                    },
                };
            if (name.startsWith('@/') || name === 'lucide-vue-next')
                return new Proxy({}, { get: () => ({}) });
            return require(name);
        },
    });
    const scope = vue.effectScope();
    const state = scope.run(() =>
        module.exports.default.setup(props, { expose() {} }),
    );
    t.after(() => {
        unmount();
        scope.stop();
    });
    function complete(request, responseFlash = {}) {
        request.options.onSuccess({ props: { flash: responseFlash } });
        request.options.onFinish();
    }
    return {
        state,
        props,
        requests,
        connections,
        destinations,
        copies,
        complete,
        unmount: () => unmount(),
    };
}

test('email settings make no automatic requests and enforce application setup and administrator guards', (t) => {
    const settings = fixture();
    settings.app.configured = false;
    settings.app.can_manage = false;
    const h = harness(t, settings);
    h.state.saveApplication();
    h.state.connectMailbox();
    assert.equal(h.requests.length, 0);
    assert.equal(h.connections.length, 0);
    assert.equal(h.state.busy.value, null);
});

test('Google connection prevents duplicate actions and only redirects to the returned Google authorization URL', async (t) => {
    const h = harness(t);
    const pending = h.state.connectMailbox();
    await h.state.connectMailbox();
    h.state.saveWebsite();
    assert.equal(h.connections.length, 1);
    assert.equal(h.requests.length, 0);
    assert.equal(h.connections[0].url, '/settings/email/connect');
    assert.equal(h.connections[0].options.headers.Accept, 'application/json');
    h.connections[0].resolve({
        data: {
            url: 'https://accounts.google.com/o/oauth2/v2/auth?state=fixture',
        },
    });
    await pending;
    assert.equal(h.destinations.length, 1);
    assert.match(h.destinations[0], /^https:\/\/accounts\.google\.com\//);
});

test('connection failure remains local and navigation cancels a pending authorization request', async (t) => {
    const h = harness(t);
    const first = h.state.connectMailbox();
    h.connections[0].resolve({
        data: { url: 'https://wrong.example.test/private' },
    });
    await first;
    assert.equal(h.destinations.length, 0);
    assert.match(h.state.errorMessage.value, /could not be started/);
    assert.equal(h.state.busy.value, null);
    const second = h.state.connectMailbox();
    h.unmount();
    assert.equal(h.connections[1].options.signal.aborted, true);
    h.connections[1].resolve({
        data: { url: 'https://accounts.google.com/o/oauth2/v2/auth' },
    });
    await second;
    assert.equal(h.destinations.length, 0);
});

test('application flash failures keep the secret draft and never show a successful save', (t) => {
    const h = harness(t);
    assert.equal(h.state.secretRequired.value, false);
    h.state.appForm.client_id = 'replacement-client';
    h.state.appForm.client_secret = 'draft-secret';
    assert.equal(h.state.secretRequired.value, true);
    h.state.saveApplication();
    h.state.saveApplication();
    assert.equal(h.requests.length, 1);
    assert.equal(h.requests[0].data.client_secret, 'draft-secret');
    h.complete(h.requests[0], {
        error: 'Application could not be saved.',
        success: 'Do not show this',
    });
    assert.equal(h.state.errorMessage.value, 'Application could not be saved.');
    assert.equal(h.state.successMessage.value, '');
    assert.equal(h.state.appForm.client_secret, 'draft-secret');
    h.state.saveApplication();
    h.complete(h.requests[1], { success: 'Application saved.' });
    assert.equal(h.state.appForm.client_secret, '');
    assert.equal(h.state.successMessage.value, 'Application saved.');
});

test('website selection and refreshed props preserve each unsaved message draft', async (t) => {
    const h = harness(t);
    h.state.websiteForm.value.body_template = 'Unsaved first website';
    h.state.selectedWebsiteId.value = 2;
    h.state.websiteForm.value.signature = 'Unsaved second signature';
    h.props.emailSettings = fixture();
    await vue.nextTick();
    assert.equal(
        h.state.websiteForm.value.signature,
        'Unsaved second signature',
    );
    h.state.selectedWebsiteId.value = 1;
    assert.equal(
        h.state.websiteForm.value.body_template,
        'Unsaved first website',
    );
    assert.equal(h.requests.length, 0);
});

test('website save uses its captured draft and keeps validation errors with the right website', (t) => {
    const h = harness(t);
    h.state.websiteForm.value.subject_template = 'Draft subject';
    h.state.saveWebsite();
    assert.equal(h.requests[0].url, '/settings/email/websites/1');
    assert.equal(h.requests[0].data.subject_template, 'Draft subject');
    h.state.selectedWebsiteId.value = 2;
    h.requests[0].options.onError({ subject_template: 'Subject is too long.' });
    h.requests[0].options.onFinish();
    assert.equal(h.state.selectedErrors.value.subject_template, undefined);
    h.state.selectedWebsiteId.value = 1;
    assert.equal(
        h.state.selectedErrors.value.subject_template,
        'Subject is too long.',
    );
    assert.equal(h.state.websiteForm.value.subject_template, 'Draft subject');
    assert.equal(h.state.successMessage.value, '');
});

test('saving rejects unverified senders and disconnected mailbox aliases', (t) => {
    const h = harness(t);
    h.state.websiteForm.value.sender_email = 'unverified@example.test';
    h.state.saveWebsite();
    assert.equal(h.requests.length, 0);
    h.state.websiteForm.value.sender_email = 'verified@example.test';
    h.props.emailSettings.connection.connected = false;
    h.state.saveWebsite();
    assert.equal(h.requests.length, 0);
    assert.equal(h.state.aliases.value.length, 0);
});

test('alias refresh and disconnect use redirect endpoints with accurate errors and preserved drafts', (t) => {
    const h = harness(t);
    h.state.websiteForm.value.body_template = 'Keep my draft';
    h.state.changeConnection('aliases');
    h.state.changeConnection('disconnect');
    assert.equal(h.requests.length, 1);
    assert.equal(h.requests[0].method, 'post');
    assert.equal(h.requests[0].url, '/settings/email/aliases');
    h.complete(h.requests[0], { error: 'Gmail needs to be reconnected.' });
    assert.equal(h.state.successMessage.value, '');
    assert.equal(h.state.errorMessage.value, 'Gmail needs to be reconnected.');
    assert.equal(h.state.websiteForm.value.body_template, 'Keep my draft');
    h.state.changeConnection('disconnect');
    assert.equal(h.requests[1].method, 'delete');
    assert.equal(h.requests[1].url, '/settings/email/connection');
    h.complete(h.requests[1]);
    assert.equal(h.state.successMessage.value, 'Gmail disconnected.');
});

test('OAuth return errors take precedence over success flash and callback copy uses the server value', async (t) => {
    const h = harness(t, fixture(), {
        error: 'Google connection was cancelled.',
        success: 'Must not show',
    });
    assert.equal(
        h.state.errorMessage.value,
        'Google connection was cancelled.',
    );
    assert.equal(h.state.successMessage.value, '');
    await h.state.copyCallback();
    assert.equal(h.copies[0], h.props.emailSettings.app.redirect_uri);
    assert.equal(h.state.copyMessage.value, 'Callback URL copied.');
    assert.equal(h.connections.length, 0);
});
