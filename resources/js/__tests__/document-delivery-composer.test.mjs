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
const itineraryModule = { exports: {} };
runInNewContext(
    ts.transpileModule(
        readFileSync(
            new URL('../lib/itineraryAttachments.ts', import.meta.url),
            'utf8',
        ),
        {
            compilerOptions: {
                module: ts.ModuleKind.CommonJS,
                target: ts.ScriptTarget.ES2022,
            },
        },
    ).outputText,
    { module: itineraryModule, exports: itineraryModule.exports },
);
const source = readFileSync(
    new URL('../components/DocumentDeliveryComposer.vue', import.meta.url),
    'utf8',
);
const script = compileScript(parse(source).descriptor, {
    id: 'document-workflow-test',
}).content;
const { outputText } = ts.transpileModule(script, {
    compilerOptions: {
        module: ts.ModuleKind.CommonJS,
        target: ts.ScriptTarget.ES2022,
    },
});
const id = '12345678-1234-1234-1234-123456789abc';
const fileId = '22345678-1234-1234-1234-123456789abc';
const document = (overrides = {}) => ({
    id: fileId,
    name: 'flight-reservation.pdf',
    size: 40,
    sha256: 'a'.repeat(64),
    version: 1,
    approved: true,
    ...overrides,
});
const itinerary = (version = 'b'.repeat(64)) => ({
    available: true,
    reason: null,
    source: {
        submission_id: 77,
        submission_number: 301,
        reference: 'ITN-301',
        reference_type: 'internal',
        external_pnr: null,
        generated_at: '2026-10-23T12:00:00Z',
        source_version: version,
        name: 'Travel-Itinerary-301.pdf',
        download_url: '/submissions/entries/77/itinerary',
    },
});
const generated = (overrides = {}) =>
    document({
        id: '33345678-1234-1234-1234-123456789abc',
        source: 'submission_itinerary',
        submission_id: 77,
        source_version: itinerary().source.source_version,
        download_url:
            '/orders/10/document-deliveries/documents/33345678-1234-1234-1234-123456789abc',
        ...overrides,
    });
const workflow = (overrides = {}) => ({
    enabled: true,
    language: 'en',
    readiness: [],
    profile: {
        brand_name: 'Example travel',
        sender_email: 'team@example.test',
        sender_name: 'Example team',
        reply_to: 'help@example.test',
        default_language: 'fr',
        config_version: 3,
    },
    documents: [document()],
    required_services: ['Flight reservation', 'Hotel reservation'],
    store: { supported: true, verified: true, message: 'Verified' },
    document_set_version: 1,
    deliveries: [],
    ...overrides,
});
const preview = (overrides = {}) => ({
    id,
    status: 'prepared',
    subject: 'Example — PDFs',
    html: '<p>Two PDFs attached</p>',
    body: 'Two PDFs attached',
    recipient: 'customer@example.test',
    sender: { email: 'team@example.test', name: 'Example team' },
    reply_to: 'help@example.test',
    document_set_version: 'saved-version',
    config_version: 3,
    attachments: [document()],
    mode: 'complete',
    will_complete: true,
    suppression: true,
    ...overrides,
});
const outcome = (overrides = {}) => ({
    id,
    status: 'prepared',
    sync_status: 'not_requested',
    message: '',
    sent_at: null,
    ...overrides,
});
const flush = async () => {
    await vue.nextTick();
    await new Promise((resolve) => setImmediate(resolve));
};

function harness(t, context = workflow(), initialProps = {}) {
    const calls = [];
    const hooks = {};
    const events = [];
    const props = vue.reactive({
        orderId: 10,
        recipient: 'customer@example.test',
        workflow: context,
        limits: {
            max_files: 5,
            max_file_bytes: 5242880,
            max_total_bytes: 10485760,
        },
        ...initialProps,
    });
    const request = (method, url, data, options) =>
        new Promise((resolve, reject) =>
            calls.push({ method, url, data, options, resolve, reject }),
        );
    const axios = {
        get: (url, options) => request('GET', url, undefined, options),
        post: (url, data, options) => request('POST', url, data, options),
        isAxiosError: (error) => error?.isAxiosError === true,
    };
    const module = { exports: {} };
    const wrapper = { template: '<div />' };
    runInNewContext(outputText, {
        module,
        exports: module.exports,
        console,
        AbortController,
        FormData,
        URL,
        require: (name) => {
            if (name === '@/lib/itineraryAttachments')
                return itineraryModule.exports;
            if (name === 'axios') return { default: axios };
            if (name === 'vue')
                return {
                    ...vue,
                    onUnmounted: (fn) => {
                        hooks.unmount = fn;
                    },
                };
            if (
                name === '@/components/DocumentEmailPreview.vue' ||
                name === '@/components/GeneratedItineraryAttachment.vue'
            )
                return { default: wrapper };
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
    const fullyConfirm = () => {
        state.selected.value = [fileId];
        state.covered.value = [...context.required_services];
        state.fulfillmentConfirmed.value = true;
    };
    const prepare = async (saved = preview()) => {
        fullyConfirm();
        const pending = state.prepare();
        respond(calls.length - 1, {
            preview: saved,
            delivery: outcome({ status: saved.status }),
        });
        await pending;
    };
    return {
        state,
        props,
        calls,
        hooks,
        events,
        respond,
        fullyConfirm,
        prepare,
    };
}

test('branded add explicitly approves the source, retains pending uploads and selects one exact stored snapshot', async (t) => {
    const { state, calls, respond } = harness(t, workflow(), {
        submissionId: 77,
        itineraryAttachment: itinerary(),
        active: true,
    });
    assert.equal(calls.length, 0);
    assert.equal(state.selected.value.length, 0);
    state.recipient.value = 'corrected@example.test';
    const manual = new File(['%PDF'], 'manual.pdf', {
        type: 'application/pdf',
    });
    state.pendingFiles.value = [manual];
    const adding = state.addItinerary();
    await state.addItinerary();
    assert.equal(calls.length, 1);
    assert.deepEqual(JSON.parse(JSON.stringify(calls[0].data)), {
        submission_id: 77,
        source_version: itinerary().source.source_version,
        approved: true,
        intended_customer_email: 'customer@example.test',
    });
    respond(0, { document: generated(), itinerary_attachment: itinerary() });
    await adding;
    assert.deepEqual([...state.selected.value], [generated().id]);
    assert.equal(state.pendingFiles.value[0], manual);
    assert.equal(state.blocked.value, true);
    assert.match(state.attachmentNotice.value, /No email has been sent/);
    state.pendingFiles.value = [];
    state.recipient.value = 'customer@example.test';
    state.covered.value = [...state.context.value.required_services];
    state.fulfillmentConfirmed.value = true;
    const preparing = state.prepare();
    assert.deepEqual([...calls[1].data.document_ids], [generated().id]);
    respond(1, {
        preview: preview({ attachments: [generated()] }),
        delivery: outcome(),
    });
    await preparing;
    assert.equal(state.preview.value.attachments[0].id, generated().id);
    assert.equal(
        calls.some((call) => call.url.endsWith('/send')),
        false,
    );
});

test('branded context refresh fails without losing selections, then removes only a replaced itinerary', async (t) => {
    const { state, calls, respond } = harness(
        t,
        workflow({ documents: [document(), generated()] }),
        { submissionId: 77, itineraryAttachment: itinerary() },
    );
    state.selected.value = [fileId, generated().id];
    state.preview.value = preview({ attachments: [document(), generated()] });
    state.showPreview.value = true;
    const failed = state.reloadItinerary();
    calls[0].reject(new Error('Offline'));
    await failed;
    assert.deepEqual([...state.selected.value], [fileId, generated().id]);
    const reload = state.reloadItinerary();
    assert.equal(calls[1].options.params.submission_id, 77);
    respond(1, {
        workflow: workflow({ documents: [document(), generated()] }),
        itinerary_attachment: itinerary('d'.repeat(64)),
    });
    await reload;
    assert.deepEqual([...state.selected.value], [fileId]);
    assert.equal(state.preview.value, null);
    assert.equal(state.showPreview.value, false);
});

test('branded closing or switching origin invalidates add completions and resets operator-review state', async (t) => {
    const { state, props, calls, respond } = harness(t, workflow(), {
        active: true,
        submissionId: 77,
        itineraryAttachment: itinerary(),
    });
    const adding = state.addItinerary();
    props.active = false;
    assert.equal(calls[0].options.signal.aborted, true);
    respond(0, { document: generated(), itinerary_attachment: itinerary() });
    await adding;
    assert.equal(state.selected.value.length, 0);
    props.active = true;
    const delayed = state.addItinerary();
    state.reviewReason.value = 'Old customer review';
    state.reviewConfirmed.value = true;
    props.submissionId = 88;
    respond(1, { document: generated(), itinerary_attachment: itinerary() });
    await delayed;
    assert.equal(state.selected.value.length, 0);
    assert.equal(state.reviewReason.value, '');
    assert.equal(state.reviewConfirmed.value, false);
    assert.equal(state.attachmentNotice.value, '');
});

test('branded stale-after-save metadata and combined-size overflow cannot select or claim success', async (t) => {
    const { state, respond } = harness(t, workflow(), {
        itineraryAttachment: itinerary(),
        limits: { max_files: 2, max_file_bytes: 60, max_total_bytes: 70 },
    });
    state.selected.value = [fileId];
    const adding = state.addItinerary();
    respond(0, { document: generated(), itinerary_attachment: itinerary() });
    await adding;
    assert.deepEqual([...state.selected.value], [fileId]);
    assert.match(
        state.attachmentNotice.value,
        /saved but could not be selected/,
    );
    state.selected.value = [];
    const retry = state.addItinerary();
    respond(1, {
        document: generated(),
        itinerary_attachment: itinerary('d'.repeat(64)),
    });
    await retry;
    assert.equal(state.selected.value.length, 0);
    assert.match(state.error.value, /changed while/);
    assert.equal(state.attachmentNotice.value, '');
    assert.equal(
        state.context.value.documents.filter(
            (item) => item.id === generated().id,
        ).length,
        1,
    );
});

test('branded stale-itinerary preview rejection keeps manual selections and no automatic send or retry', async (t) => {
    const { state, calls, fullyConfirm } = harness(
        t,
        workflow({ documents: [document(), generated()] }),
        { itineraryAttachment: itinerary() },
    );
    fullyConfirm();
    state.selected.value.push(generated().id);
    const preparing = state.prepare();
    calls[0].reject({
        isAxiosError: true,
        response: {
            status: 422,
            data: { errors: { itinerary: ['The itinerary changed.'] } },
        },
    });
    await preparing;
    assert.deepEqual([...state.selected.value], [fileId]);
    assert.equal(state.itineraryAttachment.value.available, false);
    assert.equal(state.preview.value, null);
    assert.equal(calls.length, 1);
});

test('historical PDFs require explicit selection, recorded language wins, and full completion requires every explicit confirmation', async (t) => {
    const context = workflow({
        documents: [document(), document({ id: 'draft', approved: false })],
    });
    const { state, calls, fullyConfirm, prepare } = harness(t, context);
    assert.deepEqual([...state.selected.value], []);
    assert.equal(state.language.value, 'en');
    assert.equal(state.blocked.value, true);
    state.covered.value = ['Flight reservation'];
    state.fulfillmentConfirmed.value = true;
    assert.equal(state.blocked.value, true);
    await state.prepare();
    assert.equal(calls.length, 0);
    fullyConfirm();
    assert.equal(state.blocked.value, false);
    await prepare();
    assert.deepEqual([...calls[0].data.document_ids], [fileId]);
    assert.equal(calls[0].data.fulfillment_confirmed, true);
    assert.equal(calls[0].url, '/orders/10/document-deliveries/prepare');
});

test('local captures are never labelled as sent in the result or history timestamps', async (t) => {
    const { state } = harness(t);
    const captured = outcome({
        status: 'sent',
        captured: true,
        sync_status: 'pending',
        sent_at: '2026-10-21T12:00:00Z',
    });
    state.outcome.value = captured;
    assert.match(state.stateMessage.value, /Captured locally/);
    assert.match(state.stateMessage.value, /no email was sent/);
    assert.match(
        state.deliveryTimestamp(captured),
        /Captured locally.*no email sent/,
    );
    assert.doesNotMatch(state.deliveryTimestamp(captured), /^Sent /);
    assert.match(
        state.deliveryTimestamp(
            outcome({ status: 'sent', sent_at: '2026-10-21T12:00:00Z' }),
        ),
        /^Sent /,
    );
});

test('missing store capability preserves clearly separate send-only mode and never implies completion', async (t) => {
    const { state, calls, respond } = harness(
        t,
        workflow({
            store: {
                supported: false,
                verified: false,
                message: 'Unsupported platform',
            },
        }),
    );
    assert.equal(state.mode.value, 'partial');
    state.selected.value = [fileId];
    assert.equal(state.blocked.value, false);
    const pending = state.prepare();
    assert.equal(calls[0].data.mode, 'partial');
    assert.equal(calls[0].data.fulfillment_confirmed, false);
    respond(0, {
        preview: preview({ mode: 'partial', will_complete: false }),
        delivery: outcome(),
    });
    await pending;
    state.outcome.value = outcome({
        status: 'sent',
        sync_status: 'not_requested',
    });
    assert.match(state.stateMessage.value, /does not complete/);
    await state.retrySync();
    assert.equal(calls.length, 1);
});

test('missing required services, identity, selected files, and recipient override reason stop preparation', async (t) => {
    const { state, calls, fullyConfirm } = harness(t);
    fullyConfirm();
    state.context.value = { ...state.context.value, required_services: [] };
    assert.equal(state.blocked.value, true);
    state.mode.value = 'partial';
    state.context.value = {
        ...state.context.value,
        readiness: ['verified_sender'],
    };
    await state.prepare();
    assert.equal(calls.length, 0);
    state.context.value = { ...state.context.value, readiness: [] };
    state.recipient.value = 'correction@example.test';
    assert.equal(state.blocked.value, true);
    state.overrideReason.value = 'Customer confirmed the corrected address';
    assert.equal(state.blocked.value, false);
    state.selected.value = [];
    assert.equal(state.blocked.value, true);
});

test('uploads require explicit approval, retain exact-order customer binding, and select only newly approved files', async (t) => {
    const { state, calls, respond } = harness(t);
    state.pendingFiles.value = [
        new File(['%PDF-sample'], 'hotel.pdf', { type: 'application/pdf' }),
    ];
    await state.upload();
    assert.equal(calls.length, 0);
    state.approvedUpload.value = true;
    const pending = state.upload();
    assert.equal(calls[0].url, '/orders/10/document-deliveries/documents');
    assert.equal(
        calls[0].data.get('intended_customer_email'),
        'customer@example.test',
    );
    assert.equal(calls[0].data.get('approved'), '1');
    respond(0, { documents: [] });
    await flush();
    respond(1, {
        workflow: workflow({
            documents: [
                document(),
                document({ id: 'new-approved' }),
                document({ id: 'draft', approved: false }),
            ],
        }),
    });
    await pending;
    assert.deepEqual([...state.selected.value], ['new-approved']);
    assert.equal(state.pendingFiles.value.length, 0);
});

test('send is bound to the reviewed immutable attempt; double click and uncertain results cannot send again', async (t) => {
    const { state, calls, prepare, respond } = harness(t);
    await prepare();
    state.recipient.value = 'different@example.test';
    const sending = state.send();
    await state.send();
    assert.equal(calls.length, 2);
    assert.equal(calls[1].url, `/orders/10/document-deliveries/${id}/send`);
    assert.deepEqual(Object.keys(calls[1].data), ['confirmed']);
    calls[1].reject(new Error('Network timeout'));
    await sending;
    assert.equal(state.resultUnconfirmed.value, true);
    await state.send();
    await state.prepare();
    assert.equal(calls.length, 2);
    const refresh = state.view(id);
    respond(2, {
        preview: preview({ status: 'uncertain' }),
        delivery: outcome({ status: 'uncertain' }),
    });
    await refresh;
    assert.equal(state.canSend.value, false);
    assert.match(state.stateMessage.value, /review required/);
});

test('upload context failure preserves original local files and an already selected generated PDF', async (t) => {
    const { state, calls, respond } = harness(
        t,
        workflow({ documents: [generated()] }),
        { itineraryAttachment: itinerary() },
    );
    state.selected.value = [generated().id];
    const manual = new File(['%PDF-sample'], 'hotel.pdf', {
        type: 'application/pdf',
    });
    state.pendingFiles.value = [manual];
    state.approvedUpload.value = true;
    const uploading = state.upload();
    respond(0, { documents: [document()] });
    await flush();
    calls[1].reject(new Error('Refresh failed'));
    await uploading;
    assert.equal(state.pendingFiles.value[0], manual);
    assert.deepEqual([...state.selected.value], [generated().id]);
    assert.equal(calls.length, 2);
});

test('sent email with pending order update retries only sync and exposes saved status and Gmail link', async (t) => {
    const { state, calls, prepare, respond } = harness(t);
    await prepare();
    state.outcome.value = outcome({
        status: 'sent',
        sync_status: 'pending',
        gmail_url: 'https://mail.google.com/mail/u/0/#sent/message',
    });
    assert.match(state.stateMessage.value, /order update pending/);
    const pending = state.retrySync();
    assert.equal(calls[1].url, `/orders/10/document-deliveries/${id}/sync`);
    respond(1, {
        delivery: outcome({ status: 'sent', sync_status: 'completed' }),
    });
    await flush();
    respond(2, { workflow: workflow() });
    await pending;
    assert.equal(calls.filter((call) => call.url.endsWith('/send')).length, 0);
    state.outcome.value.gmail_url = 'https://evil.example/mail.google.com';
    assert.equal(state.gmailUrl.value, null);
});

test('resend and correction reset selected files and require deliberate confirmation without another completion', async (t) => {
    const { state } = harness(t);
    state.newAttempt(
        { ...preview(), ...outcome({ status: 'sent' }) },
        'correction',
    );
    assert.equal(state.mode.value, 'correction');
    assert.equal(state.previous.value, id);
    assert.equal(state.selected.value.length, 0);
    assert.equal(state.fulfillmentConfirmed.value, false);
    assert.equal(state.resendConfirmed.value, false);
    assert.equal(state.blocked.value, true);
    state.selected.value = [fileId];
    state.resendConfirmed.value = true;
    assert.equal(state.blocked.value, false);
});

test('late responses after unmount cannot expose a preview or confirm a send for another order', async (t) => {
    const { state, calls, respond, hooks, fullyConfirm } = harness(t);
    fullyConfirm();
    const pending = state.prepare();
    hooks.unmount();
    assert.equal(calls[0].options.signal.aborted, true);
    respond(0, { preview: preview(), delivery: outcome() });
    await pending;
    assert.equal(state.preview.value, null);
});

test('malformed empty-attachment previews never enable sending', async (t) => {
    const { state, calls, respond, fullyConfirm } = harness(t);
    fullyConfirm();
    const pending = state.prepare();
    respond(0, { preview: preview({ attachments: [] }), delivery: outcome() });
    await pending;
    assert.equal(state.canSend.value, false);
    assert.equal(state.preview.value, null);
    assert.equal(calls.length, 1);
});

test('reviewing another history item cannot bypass an unresolved send for this order', async (t) => {
    const uncertain = { ...preview(), ...outcome({ status: 'uncertain' }) };
    const { state, calls, fullyConfirm } = harness(
        t,
        workflow({ deliveries: [uncertain] }),
    );
    fullyConfirm();
    state.preview.value = preview({ id: 'other', status: 'sent' });
    state.outcome.value = outcome({ id: 'other', status: 'sent' });
    assert.equal(state.pendingOutcome.value, true);
    await state.prepare();
    state.newAttempt(
        {
            ...preview({ id: 'other' }),
            ...outcome({ id: 'other', status: 'sent' }),
        },
        'resend',
    );
    assert.equal(calls.length, 0);
    assert.equal(state.previous.value, null);
});

test('manual review requires evidence and confirmation and does not send or complete an order', async (t) => {
    const { state, calls, prepare, respond } = harness(t);
    await prepare();
    state.outcome.value = outcome({ status: 'uncertain' });
    await state.recordReview();
    assert.equal(calls.length, 1);
    state.reviewDecision.value = 'sent';
    state.reviewReason.value =
        'I checked the connected Gmail Sent folder and confirmed both PDF versions.';
    state.reviewConfirmed.value = true;
    const pending = state.recordReview();
    assert.equal(calls[1].url, `/orders/10/document-deliveries/${id}/review`);
    assert.equal(calls[1].data.confirmed, true);
    respond(1, { delivery: outcome({ status: 'reviewed_sent' }) });
    await flush();
    respond(2, { workflow: workflow() });
    await pending;
    assert.match(state.stateMessage.value, /manual finding/);
    assert.equal(state.canSend.value, false);
    assert.equal(
        calls.filter((call) => /\/(send|sync)$/.test(call.url)).length,
        0,
    );
});

test('a definite failed send can be explicitly retried while an ambiguous send cannot', async (t) => {
    const { state, prepare } = harness(t);
    await prepare();
    state.outcome.value = outcome({ status: 'failed' });
    assert.equal(state.canSend.value, true);
    state.outcome.value = outcome({ status: 'uncertain' });
    assert.equal(state.canSend.value, false);
});

test('queue draft protection includes selected existing documents and clears after a confirmed send', async (t) => {
    const { state, hooks } = harness(t);
    assert.equal(hooks.exposed.getState().hasDraft, false);
    state.selected.value = [fileId];
    assert.equal(hooks.exposed.getState().hasDraft, true);
    state.preview.value = preview({ status: 'sent' });
    state.outcome.value = outcome({ status: 'sent' });
    state.showPreview.value = true;
    assert.equal(hooks.exposed.getState().hasDraft, false);
});
