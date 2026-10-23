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
    new URL(
        '../components/submissions/ItineraryDocumentCard.vue',
        import.meta.url,
    ),
    'utf8',
);
const wrap = (tag) =>
    vue.defineComponent({
        setup:
            (_, { slots }) =>
            () =>
                vue.h(tag, slots.default?.()),
    });
const ui = {
    '@/components/ui/button': { Button: wrap('button') },
    '@/components/ui/card': Object.fromEntries(
        ['Card', 'CardHeader', 'CardContent', 'CardDescription'].map((name) => [
            name,
            wrap('section'),
        ]),
    ),
    '@/components/ui/input': { Input: wrap('input') },
    '@/components/ui/label': { Label: wrap('label') },
    '@/components/InputError.vue': {
        default: vue.defineComponent({
            props: ['message'],
            setup: (props) => () =>
                props.message ? vue.h('p', props.message) : null,
        }),
    },
};
const plain = (value) => JSON.parse(JSON.stringify(value));
const document = (overrides = {}) => ({
    reference: 'WPH-EXAMPLE-42',
    reference_type: 'internal',
    external_pnr: null,
    booking_source: null,
    generated_at: '2026-10-23T10:00:00Z',
    download_url: '/submissions/entries/42/itinerary/download',
    can_update: true,
    ...overrides,
});

function harness(t, overrides = {}) {
    const calls = [];
    const props = vue.reactive({
        entryId: 42,
        itinerary: null,
        canGenerate: true,
        ...overrides,
    });
    const useForm = (initial) => {
        let transform = (data) => data;
        const form = vue.reactive({
            ...initial,
            processing: false,
            errors: {},
            get hasErrors() {
                return Object.keys(this.errors).length > 0;
            },
            clearErrors() {
                this.errors = {};
            },
            setError(key, message) {
                this.errors[key] = message;
            },
            transform(callback) {
                transform = callback;
                return this;
            },
            post(url, options) {
                this.processing = true;
                calls.push({
                    url,
                    data: transform(
                        Object.fromEntries(
                            Object.keys(initial).map((key) => [key, this[key]]),
                        ),
                    ),
                    options,
                });
            },
        });
        return form;
    };
    function load(ssr = false) {
        const module = { exports: {} };
        const script = compileScript(parse(source).descriptor, {
            id: 'itinerary-test',
            inlineTemplate: ssr,
            ...(ssr ? { templateOptions: { ssr: true } } : {}),
        }).content;
        const output = ts.transpileModule(script, {
            compilerOptions: {
                module: ts.ModuleKind.CommonJS,
                target: ts.ScriptTarget.ES2022,
            },
        }).outputText;
        runInNewContext(output, {
            module,
            exports: module.exports,
            require: (name) => {
                if (name === '@inertiajs/vue3') return { useForm };
                return ui[name] ?? require(name);
            },
        });
        return module.exports.default;
    }
    const scope = vue.effectScope();
    const state = scope.run(() => load().setup(props, { expose() {} }));
    t.after(() => scope.stop());
    return {
        state,
        props,
        calls,
        render: () => renderToString(vue.createSSRApp(load(true), props)),
    };
}

test('internal generation posts only submission context and prevents duplicate in-flight generation', (t) => {
    const { state, calls } = harness(t);
    state.form.external_pnr = 'OLD-PNR';
    state.form.booking_source = 'Old source';
    state.generateItinerary();
    state.generateItinerary();
    assert.equal(calls.length, 1);
    assert.equal(calls[0].url, '/submissions/entries/42/itinerary');
    assert.deepEqual(plain(calls[0].data), {
        reference_type: 'internal',
        external_pnr: '',
        booking_source: '',
    });
    assert.equal(calls[0].options.preserveScroll, true);
    assert.equal(state.form.processing, true);
});

test('external PNR requires valid manually supplied values before generation', (t) => {
    const { state, calls } = harness(t);
    state.form.reference_type = 'external';
    state.generateItinerary();
    assert.equal(calls.length, 0);
    assert.ok(state.form.errors.external_pnr);
    assert.ok(state.form.errors.booking_source);
    state.form.external_pnr = 'invalid/pnr';
    state.form.booking_source = 'Airline example';
    state.generateItinerary();
    assert.equal(calls.length, 0);
    state.form.external_pnr = 'A'.repeat(21);
    state.generateItinerary();
    assert.equal(calls.length, 0);
    state.form.external_pnr = ' AB12-CD ';
    state.form.booking_source = 'X'.repeat(121);
    state.generateItinerary();
    assert.equal(calls.length, 0);
    state.form.booking_source = ' Airline example ';
    state.generateItinerary();
    assert.equal(calls.length, 1);
    assert.deepEqual(plain(calls[0].data), {
        reference_type: 'external',
        external_pnr: 'AB12-CD',
        booking_source: 'Airline example',
    });
});

test('failed generation is actionable and cannot display a false success', (t) => {
    const { state, calls } = harness(t);
    state.generateItinerary();
    calls[0].options.onError({
        itinerary: 'No usable flight segments were found.',
    });
    assert.equal(
        state.errorMessage.value,
        'No usable flight segments were found.',
    );
    assert.equal(state.successMessage.value, '');
    calls[0].options.onSuccess({
        props: { flash: { error: 'Generation unavailable.' } },
    });
    assert.equal(state.errorMessage.value, 'Generation unavailable.');
    assert.equal(state.successMessage.value, '');
});

test('read-only permissions block submits while retaining authenticated download and honest reference labels', async (t) => {
    const page = harness(t, { itinerary: document(), canGenerate: false });
    page.state.generateItinerary();
    assert.equal(page.calls.length, 0);
    const html = await page.render();
    assert.match(
        html,
        /href="\/submissions\/entries\/42\/itinerary\/download"/,
    );
    assert.match(html, /Itinerary reference/);
    assert.match(html, /not an airline PNR/);
    assert.match(html, /does not make a reservation or issue a ticket/);
    assert.ok(!html.includes('Update itinerary'));
    page.props.canGenerate = true;
    page.props.itinerary.can_update = false;
    page.state.generateItinerary();
    assert.equal(page.calls.length, 0);
});

test('external document shows entered PNR and source without claiming verification', async (t) => {
    const page = harness(t, {
        itinerary: document({
            reference_type: 'external',
            external_pnr: 'AB12CD',
            booking_source: 'Example airline',
        }),
    });
    const html = await page.render();
    assert.match(html, /Booking PNR \(entered by you\)/);
    assert.match(html, /Example airline/);
    assert.match(html, /does not verify the booking or its status/);
    assert.match(html, /Update itinerary/);
    assert.match(html, /WPH-EXAMPLE-42/);
    assert.ok(!html.includes('Confirmed booking'));
    page.state.generateItinerary();
    assert.equal(page.calls[0].data.external_pnr, 'AB12CD');
    assert.equal(Object.hasOwn(page.calls[0].data, 'reference'), false);
});

test('changing submission clears previous manual PNR and errors instead of reusing another customer context', async (t) => {
    const page = harness(t, {
        itinerary: document({
            reference_type: 'external',
            external_pnr: 'AB12CD',
            booking_source: 'Example airline',
        }),
    });
    page.state.form.setError('external_pnr', 'Previous error');
    page.props.entryId = 99;
    page.props.itinerary = null;
    await vue.nextTick();
    assert.equal(page.state.form.reference_type, 'internal');
    assert.equal(page.state.form.external_pnr, '');
    assert.equal(page.state.form.booking_source, '');
    assert.equal(page.state.form.hasErrors, false);
    page.state.generateItinerary();
    assert.equal(page.calls[0].url, '/submissions/entries/99/itinerary');
});
