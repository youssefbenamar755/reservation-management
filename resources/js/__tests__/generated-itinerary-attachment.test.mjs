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
    new URL('../components/GeneratedItineraryAttachment.vue', import.meta.url),
    'utf8',
);
const script = compileScript(parse(source).descriptor, {
    id: 'generated-attachment',
    inlineTemplate: true,
    templateOptions: { ssr: true },
}).content;
const module = { exports: {} };
runInNewContext(
    ts.transpileModule(script, {
        compilerOptions: {
            module: ts.ModuleKind.CommonJS,
            target: ts.ScriptTarget.ES2022,
        },
    }).outputText,
    {
        module,
        exports: module.exports,
        require: (name) =>
            name === '@/components/ui/button'
                ? {
                      Button: vue.defineComponent({
                          setup:
                              (_, { attrs, slots }) =>
                              () =>
                                  vue.h('button', attrs, slots.default?.()),
                      }),
                  }
                : require(name),
    },
);
const render = (props) =>
    renderToString(
        vue.createSSRApp(module.exports.default, {
            orderNumber: 1001,
            recipient: 'fictional@example.test',
            submissionId: 77,
            ...props,
        }),
    );
const attachment = {
    available: true,
    reason: null,
    source: {
        submission_id: 77,
        submission_number: 301,
        reference: 'ITN-301',
        reference_type: 'internal',
        external_pnr: null,
        generated_at: '2026-10-23',
        source_version: 'a'.repeat(64),
        name: 'Travel-Itinerary-301.pdf',
        download_url: '/submissions/entries/77/itinerary',
    },
};

test('generated PDF card exposes the exact download and explicit customer-scoped add approval', async () => {
    const html = await render({ attachment });
    assert.match(html, /href="\/submissions\/entries\/77\/itinerary"/);
    assert.match(html, /Travel-Itinerary-301\.pdf/);
    assert.match(html, /order #1001 and fictional@example\.test/);
    assert.match(html, /nothing is sent yet/);
    assert.match(html, /Add itinerary/);
});

test('missing generated PDF directs the operator to its originating submission without an add action', async () => {
    const html = await render({
        attachment: {
            available: false,
            source: null,
            reason: 'Generate a PDF first.',
        },
    });
    assert.match(html, /Generate a PDF first\./);
    assert.match(html, /href="\/submissions\/entries\/77"/);
    assert.doesNotMatch(html, /Add itinerary/);
});

test('selected generated PDF remains visible for download and cannot be added again', async () => {
    const html = await render({ attachment, selected: true });
    assert.match(html, /Selected for review/);
    assert.match(html, /<button[^>]*disabled[^>]*>[\s\S]*Selected for review/);
    assert.match(html, /Download PDF/);
});
