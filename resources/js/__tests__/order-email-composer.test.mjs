import { compileScript, parse } from '@vue/compiler-sfc';
import assert from 'node:assert/strict';
import { File } from 'node:buffer';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import test from 'node:test';
import { runInNewContext } from 'node:vm';
import ts from 'typescript';

const require = createRequire(import.meta.url);
const vue = require('vue');
const source = readFileSync(
    new URL('../components/OrderEmailComposer.vue', import.meta.url),
    'utf8',
);
const script = compileScript(parse(source).descriptor, {
    id: 'email-composer-test',
}).content;
const { outputText } = ts.transpileModule(script, {
    compilerOptions: {
        module: ts.ModuleKind.CommonJS,
        target: ts.ScriptTarget.ES2022,
    },
});
const uuid = '12345678-1234-1234-1234-123456789abc';
const flush = async () => {
    await vue.nextTick();
    await new Promise((resolve) => setImmediate(resolve));
};
const pdf = (name = 'reservation.pdf', bytes = 20) =>
    new File([new Uint8Array(bytes)], name, { type: 'application/pdf' });
const context = (overrides = {}) => ({
    connection: { connected: true, email: 'mailbox@example.test' },
    settings_url: '/settings/email',
    sender: { email: 'team@example.test', name: 'Website team' },
    recipient: 'customer@example.test',
    subject: 'Your reservation',
    body: 'Hello Customer,\nYour documents are attached.\n\nWebsite team',
    limits: {
        max_files: 5,
        max_file_bytes: 5242880,
        max_total_bytes: 10485760,
    },
    history: [],
    ...overrides,
});
const saved = (overrides = {}) => ({
    id: uuid,
    recipient: 'confirmed@example.test',
    sender: { email: 'verified@example.test', name: 'Verified sender' },
    subject: 'Saved subject',
    body: 'Exact saved message\nSignature',
    attachments: [{ name: 'saved-reservation.pdf', size: 20 }],
    expires_at: '2099-01-01T00:00:00Z',
    status: 'prepared',
    ...overrides,
});
const result = (status = 'sent', overrides = {}) => ({
    id: uuid,
    status,
    message: 'Safe result',
    sent_at: status === 'sent' ? '2026-09-07T12:00:00Z' : null,
    ...overrides,
});
const tracked = (first = null) => ({
    enabled: true,
    first_open_detected_at: first,
});
const history = (status = 'sent', overrides = {}) => {
    const preview = saved({ status });
    delete preview.body;
    return {
        ...preview,
        ...result(status),
        created_at: '2026-09-07T11:59:00Z',
        ...overrides,
    };
};
const validationFailure = (errors) => ({
    isAxiosError: true,
    response: { status: 422, data: { errors } },
});

function harness(t) {
    const calls = [];
    const hooks = {};
    const events = [];
    const props = vue.reactive({ orderId: 10, orderNumber: 1001 });
    const request = (method, url, data, options) =>
        new Promise((resolve, reject) =>
            calls.push({ method, url, data, options, resolve, reject }),
        );
    const axios = {
        get: (url, options) => request('GET', url, undefined, options),
        post: (url, data, options) => request('POST', url, data, options),
        isAxiosError: (error) => error?.isAxiosError === true,
    };
    const wrapper = { template: '<div><slot /></div>' };
    const module = { exports: {} };
    runInNewContext(outputText, {
        module,
        exports: module.exports,
        console,
        AbortController,
        FormData,
        require: (name) => {
            if (name === 'axios') return { default: axios };
            if (name === 'vue')
                return {
                    ...vue,
                    onUnmounted: (fn) => {
                        hooks.unmount = fn;
                    },
                };
            if (name.startsWith('@/components/ui/'))
                return new Proxy({}, { get: () => wrapper });
            return require(name);
        },
    });
    const scope = vue.effectScope();
    const state = scope.run(() =>
        module.exports.default.setup(props, {
            expose(value) {
                hooks.exposed = value;
            },
            emit(name, value) {
                events.push({ name, value });
            },
        }),
    );
    t.after(() => {
        hooks.unmount();
        scope.stop();
    });
    const respond = (index, data) => calls[index].resolve({ data });
    const open = async (data = context()) => {
        state.setOpen(true);
        respond(calls.length - 1, data);
        await flush();
    };
    const prepare = async (data = saved()) => {
        if (!state.files.value.length) state.addFiles([pdf()]);
        const pending = state.preparePreview();
        respond(calls.length - 1, { preview: data });
        await pending;
    };
    return { state, props, calls, hooks, events, respond, open, prepare };
}

test('queue can open the latest saved email explicitly without sending and receives scoped settlement and close events', async (t) => {
    const { hooks, state, calls, respond, events } = harness(t);
    assert.equal(hooks.exposed.getState().hasDraft, false);
    hooks.exposed.open(true);
    respond(0, context({ history: [history('uncertain')] }));
    await flush();
    assert.equal(calls[1].url, `/orders/10/email/${uuid}`);
    respond(1, {
        preview: saved({ status: 'uncertain' }),
        delivery: result('uncertain'),
    });
    await flush();
    assert.equal(calls.filter((call) => call.method === 'POST').length, 0);
    assert.equal(state.currentStatus.value, 'uncertain');
    assert.equal(events[0].name, 'settled');
    assert.equal(events[0].value.orderId, 10);
    assert.equal(events[0].value.action, 'status');
    state.setOpen(false);
    assert.equal(events.at(-1).name, 'closed');
    assert.equal(hooks.exposed.getState().hasDraft, true);
});

test('queue draft protection includes a revised draft after a completed send and settlement fires only for current requests', async (t) => {
    const { state, hooks, events, props, calls, open, prepare, respond } =
        harness(t);
    await open();
    assert.equal(hooks.exposed.getState().hasDraft, false);
    await prepare();
    assert.equal(events.at(-1).value.action, 'preview');
    const sending = state.sendEmail();
    assert.equal(hooks.exposed.getState().sending, true);
    state.setOpen(false);
    assert.equal(state.open.value, true);
    respond(calls.length - 1, { delivery: result('sent') });
    await sending;
    assert.equal(events.at(-1).value.action, 'send');
    assert.equal(hooks.exposed.getState().hasDraft, false);
    state.editDraft();
    state.draft.body = 'A corrected document is attached.';
    assert.equal(hooks.exposed.getState().hasDraft, true);
    const before = events.length;
    const action = state.preparePreview(),
        index = calls.length - 1;
    props.orderId = 20;
    await flush();
    respond(index, { preview: saved() });
    await action;
    assert.equal(events.length, before);
});

test('composer loads only when opened and refreshes settings without replacing retained draft/files', async (t) => {
    const { state, calls, open, respond } = harness(t);
    assert.equal(calls.length, 0);
    await open();
    assert.equal(calls[0].url, '/orders/10/email');
    assert.equal(calls[0].options.headers.Accept, 'application/json');
    assert.equal(calls[0].options.timeout, 25000);
    assert.equal(state.draft.trackOpens, false);
    state.draft.body = 'Edited message';
    state.draft.trackOpens = true;
    const original = pdf();
    state.addFiles([original]);
    state.setOpen(false);
    state.setOpen(true);
    respond(1, context({ body: 'New saved template' }));
    await flush();
    assert.equal(state.draft.body, 'Edited message');
    assert.equal(state.draft.trackOpens, true);
    assert.equal(state.files.value[0], original);
    assert.equal(calls.length, 2);
});

test('disconnected Gmail or missing sender cannot prepare a preview', async (t) => {
    const { state, calls, open } = harness(t);
    await open(context({ sender: null }));
    state.addFiles([pdf()]);
    await state.preparePreview();
    assert.equal(calls.length, 1);
    state.context.value = context({
        connection: { connected: false, email: null },
    });
    await state.preparePreview();
    assert.equal(calls.length, 1);
});

test('file limits reject an entire invalid addition while preserving original File objects', async (t) => {
    const { state, open } = harness(t);
    await open();
    const original = pdf('original.pdf');
    state.addFiles([original]);
    state.addFiles([pdf('not-a-pdf.txt')]);
    assert.match(state.errors.value.files, /non-empty PDF/);
    state.addFiles([pdf('empty.pdf', 0)]);
    assert.match(state.errors.value.files, /non-empty PDF/);
    state.addFiles([pdf('large.pdf', 5242881)]);
    assert.match(state.errors.value.files, /Each PDF/);
    state.addFiles([pdf('a.pdf', 5242880), pdf('b.pdf', 5242880)]);
    assert.match(state.errors.value.files, /together/);
    state.addFiles(Array.from({ length: 5 }, () => pdf()));
    assert.match(state.errors.value.files, /up to 5/);
    assert.equal(state.files.value.length, 1);
    assert.equal(state.files.value[0], original);
    const input = { files: [pdf('second.PDF')], value: 'selection' };
    state.chooseFiles({ target: input });
    assert.equal(input.value, '');
    assert.equal(state.files.value.length, 2);
});

test('preparation requires a single recipient and PDFs, and failed preparation keeps draft and files', async (t) => {
    const { state, calls, open } = harness(t);
    await open();
    state.draft.recipient = 'first@example.test,second@example.test';
    await state.preparePreview();
    assert.ok(state.errors.value.recipient);
    assert.ok(state.errors.value.files);
    assert.equal(calls.length, 1);
    state.draft.recipient = 'customer@example.test';
    state.draft.trackOpens = true;
    const original = pdf();
    state.addFiles([original]);
    const pending = state.preparePreview();
    await state.preparePreview();
    assert.equal(calls.length, 2);
    calls[1].reject(
        validationFailure({ 'files.0': ['The file is not a valid PDF.'] }),
    );
    await pending;
    assert.equal(state.step.value, 'compose');
    assert.equal(state.files.value[0], original);
    assert.equal(state.draft.recipient, 'customer@example.test');
    assert.equal(state.draft.trackOpens, true);
    assert.equal(state.errors.value['files.0'], 'The file is not a valid PDF.');
    const senderFailure = state.preparePreview();
    calls[2].reject(
        validationFailure({ sender: ['Reconnect Gmail in Email settings.'] }),
    );
    await senderFailure;
    assert.equal(
        state.otherErrors.value[0][1],
        'Reconnect Gmail in Email settings.',
    );
    assert.equal(state.files.value[0], original);
});

test('preview and final send use the immutable server message, attachment list and UUID', async (t) => {
    const { state, calls, open, respond } = harness(t);
    await open();
    const original = pdf('downloaded.pdf');
    state.addFiles([original]);
    const pending = state.preparePreview();
    assert.equal(calls[1].data.get('recipient'), 'customer@example.test');
    assert.equal(calls[1].data.get('body'), context().body);
    assert.equal(calls[1].data.get('track_opens'), '0');
    assert.equal(calls[1].data.getAll('files[]').length, 1);
    assert.equal(calls[1].data.get('files[]').name, original.name);
    assert.deepEqual(
        await calls[1].data.get('files[]').arrayBuffer(),
        await original.arrayBuffer(),
    );
    respond(1, { preview: saved() });
    await pending;
    assert.equal(state.preview.value.sender.email, 'verified@example.test');
    assert.equal(
        state.preview.value.attachments[0].name,
        'saved-reservation.pdf',
    );
    state.draft.subject = 'Changed local draft';
    assert.equal(state.preview.value.subject, 'Saved subject');
    const send = state.sendEmail();
    await state.sendEmail();
    state.setOpen(false);
    assert.equal(state.open.value, true);
    assert.equal(calls.length, 3);
    assert.equal(calls[2].url, `/orders/10/email/${uuid}/send`);
    assert.deepEqual(Object.keys(calls[2].data), ['confirmed']);
    assert.equal(calls[2].data.confirmed, true);
    respond(2, { delivery: result() });
    await send;
    assert.equal(state.currentStatus.value, 'sent');
    assert.equal(state.canSend.value, false);
    await state.sendEmail();
    assert.equal(calls.length, 3);
    state.editDraft();
    assert.equal(state.files.value[0], original);
    assert.equal(state.draft.subject, 'Changed local draft');
});

test('known failure permits only a fresh explicit send action and expired previews never send', async (t) => {
    const { state, calls, open, prepare, respond } = harness(t);
    await open();
    await prepare();
    const first = state.sendEmail();
    respond(2, { delivery: result('failed') });
    await first;
    await flush();
    assert.equal(calls.length, 3);
    assert.equal(state.canSend.value, true);
    const retry = state.sendEmail();
    respond(3, { delivery: result('sent') });
    await retry;
    assert.equal(calls.length, 4);
    state.preview.value = saved({ expires_at: '2000-01-01T00:00:00Z' });
    state.delivery.value = null;
    await state.sendEmail();
    assert.equal(state.currentStatus.value, 'expired');
    assert.equal(calls.length, 4);
});

test('an interrupted send requires explicit status recovery and never retries automatically', async (t) => {
    const { state, calls, open, prepare, respond } = harness(t);
    await open();
    await prepare();
    const send = state.sendEmail();
    calls[2].reject(new Error('connection interrupted'));
    await send;
    assert.equal(state.sendUnconfirmed.value, true);
    assert.equal(state.canSend.value, false);
    state.editDraft();
    await state.preparePreview();
    await state.sendEmail();
    assert.equal(calls.length, 3);
    assert.match(state.error.value, /previous delivery status/);
    const check = state.viewDelivery(uuid);
    respond(3, {
        preview: saved({ status: 'uncertain' }),
        delivery: result('uncertain'),
    });
    await check;
    assert.equal(calls[3].method, 'GET');
    assert.equal(state.sendUnconfirmed.value, false);
    assert.equal(state.canSend.value, false);
    assert.match(state.statusMessage.value, /Check Gmail Sent/);
    await state.sendEmail();
    assert.equal(calls.length, 4);
});

test('saved delivery recovery uses server attachments and only failed/prepared states can send', async (t) => {
    const { state, calls, open, respond } = harness(t);
    await open();
    for (const status of [
        'sending',
        'sent',
        'uncertain',
        'expired',
        'failed',
        'prepared',
    ]) {
        const pending = state.viewDelivery(uuid);
        respond(calls.length - 1, {
            preview: saved({ status }),
            delivery: result(status),
        });
        await pending;
        assert.equal(
            state.canSend.value,
            ['failed', 'prepared'].includes(status),
        );
        assert.equal(
            state.preview.value.attachments[0].name,
            'saved-reservation.pdf',
        );
        assert.equal(state.files.value.length, 0);
    }
    assert.ok(calls.every((call) => call.method === 'GET'));
});

test('closing and changing orders abort pending reads and preparation and ignore late results', async (t) => {
    const { state, props, calls, respond, open } = harness(t);
    state.setOpen(true);
    state.setOpen(false);
    assert.equal(calls[0].options.signal.aborted, true);
    respond(0, context());
    await flush();
    assert.equal(state.context.value, null);
    await open();
    state.draft.trackOpens = true;
    state.addFiles([pdf()]);
    const preparation = state.preparePreview();
    props.orderId = 20;
    await flush();
    assert.equal(calls[2].options.signal.aborted, true);
    assert.equal(calls[3].url, '/orders/20/email');
    respond(3, context({ subject: 'Second order' }));
    respond(2, { preview: saved() });
    await preparation;
    await flush();
    assert.equal(state.preview.value, null);
    assert.equal(state.files.value.length, 0);
    assert.equal(state.draft.subject, 'Second order');
    assert.equal(state.draft.trackOpens, false);
});

test('a late send cannot overwrite a newly opened order even after returning to its previous ID', async (t) => {
    const { state, props, calls, respond, open, prepare } = harness(t);
    await open();
    await prepare();
    const send = state.sendEmail();
    props.orderId = 20;
    await flush();
    assert.equal(calls[2].options.signal.aborted, true);
    props.orderId = 10;
    await flush();
    respond(4, context({ subject: 'Fresh context' }));
    respond(2, { delivery: result('sent') });
    await send;
    await flush();
    assert.equal(state.delivery.value, null);
    assert.equal(state.draft.subject, 'Fresh context');
});

test('unmount aborts an in-flight send without accepting its late result or scheduling work', async (t) => {
    const { state, calls, hooks, respond, open, prepare } = harness(t);
    await open();
    await prepare();
    const pending = state.sendEmail();
    hooks.unmount();
    assert.equal(calls[2].options.signal.aborted, true);
    respond(2, { delivery: result('sent') });
    await pending;
    assert.equal(state.delivery.value, null);
    await state.sendEmail();
    await state.viewDelivery(uuid);
    await state.preparePreview();
    assert.equal(calls.length, 3);
});

test('malformed previews cannot expose a final send action', async (t) => {
    const { state, open, prepare } = harness(t);
    await open();
    await prepare(saved({ id: '../other-order' }));
    assert.equal(state.preview.value, null);
    assert.equal(state.canSend.value, false);
    assert.match(state.error.value, /could not be prepared/);
    await prepare(saved({ attachments: [] }));
    assert.equal(state.preview.value, null);
    assert.equal(state.canSend.value, false);
});

test('tracking choice is submitted explicitly and final send keeps the saved choice after draft edits', async (t) => {
    const { state, calls, open, prepare, respond } = harness(t);
    await open();
    state.draft.trackOpens = true;
    await prepare(saved({ tracking: tracked() }));
    assert.equal(calls[1].data.get('track_opens'), '1');
    assert.equal(state.currentTracking.value.enabled, true);
    state.draft.trackOpens = false;
    assert.equal(state.preview.value.tracking.enabled, true);
    const send = state.sendEmail();
    assert.deepEqual(Object.keys(calls[2].data), ['confirmed']);
    respond(2, { delivery: result('sent', { tracking: tracked() }) });
    await send;
    assert.equal(state.currentTracking.value.enabled, true);
    assert.equal(
        state.trackingLabel(
            state.currentTracking.value,
            state.currentStatus.value,
        ),
        'No open detected yet',
    );
    state.editDraft();
    assert.equal(state.canSend.value, false);
    await state.sendEmail();
    assert.equal(calls.length, 3);
    const nextUuid = 'aaaaaaaa-1234-1234-1234-123456789abc';
    await prepare(saved({ id: nextUuid }));
    assert.equal(calls[3].data.get('track_opens'), '0');
    assert.equal(state.preview.value.id, nextUuid);
    assert.equal(state.preview.value.tracking.enabled, false);
    assert.equal(state.canSend.value, true);
});

test('a failed fresh preview never sends the stale preview with the previous tracking setting', async (t) => {
    const { state, calls, open, prepare } = harness(t);
    await open();
    await prepare(saved({ tracking: tracked() }));
    state.editDraft();
    state.draft.trackOpens = false;
    const pending = state.preparePreview();
    assert.equal(calls[2].data.get('track_opens'), '0');
    calls[2].reject(
        validationFailure({ track_opens: ['Invalid tracking option.'] }),
    );
    await pending;
    assert.equal(state.step.value, 'compose');
    assert.equal(state.preview.value.tracking.enabled, true);
    assert.equal(state.draft.trackOpens, false);
    assert.equal(state.canSend.value, false);
    assert.equal(state.otherErrors.value[0][1], 'Invalid tracking option.');
    await state.sendEmail();
    assert.equal(calls.length, 3);
});

test('legacy preview, outcome and history tracking are normalized to off', async (t) => {
    const { state, open, prepare, respond } = harness(t);
    await open(context({ history: [history()] }));
    assert.equal(state.context.value.history[0].tracking.enabled, false);
    assert.equal(
        state.trackingLabel(state.context.value.history[0].tracking, 'sent'),
        'Tracking off',
    );
    await prepare();
    assert.equal(state.preview.value.tracking.enabled, false);
    assert.equal(state.preview.value.tracking.first_open_detected_at, null);
    const pending = state.sendEmail();
    respond(2, { delivery: result() });
    await pending;
    assert.equal(state.delivery.value.tracking.enabled, false);
    assert.equal(state.currentStatus.value, 'sent');
});

test('malformed tracking values reject preview, history and status responses without confirming a send', async (t) => {
    const { state, calls, open, prepare, respond } = harness(t);
    await open();
    for (const tracking of [
        null,
        {},
        { enabled: 'true', first_open_detected_at: null },
        { enabled: true },
        tracked('not-a-date'),
        tracked('2026-09-08'),
        tracked('2026-99-08T12:00:00Z'),
        { enabled: false, first_open_detected_at: '2026-09-08T12:00:00Z' },
    ]) {
        await prepare(saved({ tracking }));
        assert.equal(state.preview.value, null);
        assert.equal(state.canSend.value, false);
        assert.match(state.error.value, /could not be prepared/);
    }
    await prepare(saved({ tracking: tracked() }));
    const send = state.sendEmail();
    respond(calls.length - 1, {
        delivery: result('sent', { tracking: { enabled: 'true' } }),
    });
    await send;
    assert.equal(state.delivery.value, null);
    assert.equal(state.sendUnconfirmed.value, true);
    assert.equal(state.canSend.value, false);
    state.setOpen(false);
    await open(context({ history: [history('sent', { tracking: null })] }));
    assert.match(state.error.value, /could not be loaded/);
    assert.equal(state.sendUnconfirmed.value, true);
    assert.equal(state.context.value.history.length, 0);
    assert.equal(calls.at(-1).url, '/orders/10/email');
});

test('manual status refresh exposes first open detection without treating an uncertain delivery as sent', async (t) => {
    const { state, calls, open, respond } = harness(t);
    const first = '2026-09-08T12:34:56+01:00';
    await open(
        context({
            history: [history('uncertain', { tracking: tracked(first) })],
        }),
    );
    const item = state.context.value.history[0];
    assert.equal(item.status, 'uncertain');
    assert.equal(
        state.trackingLabel(item.tracking, item.status),
        'Open detected',
    );
    assert.equal(item.tracking.first_open_detected_at, first);
    const pending = state.viewDelivery(uuid);
    respond(1, {
        preview: saved({ status: 'uncertain', tracking: tracked(first) }),
        delivery: result('uncertain', { tracking: tracked(first) }),
    });
    await pending;
    assert.equal(state.currentStatus.value, 'uncertain');
    assert.equal(state.currentTracking.value.first_open_detected_at, first);
    assert.match(state.statusMessage.value, /Delivery is uncertain/);
    assert.equal(state.canSend.value, false);
    await state.sendEmail();
    await flush();
    assert.equal(calls.length, 2);
    assert.ok(calls.every((call) => call.method === 'GET'));
});

test('reopening a selected email refreshes history and its detection exactly once without replacing the draft', async (t) => {
    const { state, calls, open, prepare, respond } = harness(t);
    await open();
    state.draft.trackOpens = true;
    await prepare(saved({ tracking: tracked() }));
    const send = state.sendEmail();
    respond(2, { delivery: result('sent', { tracking: tracked() }) });
    await send;
    state.setOpen(false);
    state.setOpen(true);
    const first = '2026-09-08T12:34:56.123456Z';
    respond(
        3,
        context({ history: [history('sent', { tracking: tracked(first) })] }),
    );
    await flush();
    assert.equal(calls[4].url, `/orders/10/email/${uuid}`);
    respond(4, {
        preview: saved({ status: 'sent', tracking: tracked(first) }),
        delivery: result('sent', { tracking: tracked(first) }),
    });
    await flush();
    assert.equal(state.draft.trackOpens, true);
    assert.equal(state.currentStatus.value, 'sent');
    assert.equal(state.currentTracking.value.first_open_detected_at, first);
    assert.equal(
        state.context.value.history[0].tracking.first_open_detected_at,
        first,
    );
    assert.equal(state.canSend.value, false);
    assert.equal(calls.length, 5);
});

test('tracking response metadata is restricted to the flag and detection time and never exposes a tracker URL', async (t) => {
    const { state, open, prepare } = harness(t);
    await open();
    await prepare(
        saved({
            tracking: {
                ...tracked(),
                pixel_url: 'https://example.test/pixel/private-token.gif',
                html: '<img src="https://example.test/pixel/private-token.gif">',
            },
        }),
    );
    assert.deepEqual(Object.keys(state.preview.value.tracking), [
        'enabled',
        'first_open_detected_at',
    ]);
    assert.equal(state.preview.value.tracking.enabled, true);
    assert.equal(state.trackingLabel(tracked(), 'prepared'), 'Tracking on');
    assert.equal(
        state.trackingLabel(tracked(), 'uncertain'),
        'No open detected yet',
    );
});
