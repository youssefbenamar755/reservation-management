import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { createRequire } from 'node:module'
import test from 'node:test'
import { runInNewContext } from 'node:vm'
import ts from 'typescript'
import { compileScript, parse } from '@vue/compiler-sfc'

const require = createRequire(import.meta.url)
const vue = require('vue')
const { renderToString } = require('vue/server-renderer')
const source = (path) => readFileSync(new URL(`../${path}`, import.meta.url), 'utf8')
const plain = (value) => JSON.parse(JSON.stringify(value))
const wrap = (tag) => vue.defineComponent({ setup: (_, { slots }) => () => vue.h(tag, slots.default?.()) })
const ui = {
  '@/layouts/AppLayout.vue': { default: wrap('div') },
  '@/components/ui/card': Object.fromEntries(['Card', 'CardContent', 'CardDescription', 'CardHeader', 'CardTitle'].map((name) => [name, wrap('section')])),
  '@/components/ui/button': { Button: wrap('button') },
  '@/components/ui/badge': { Badge: wrap('span') },
  '@/components/submissions/ItineraryDocumentCard.vue': { default: vue.defineComponent({
    props: ['entryId', 'itinerary', 'canGenerate'],
    setup: (props) => () => vue.h('section', {
      'data-itinerary-entry': props.entryId,
      'data-itinerary-can-generate': props.canGenerate,
      'data-itinerary-reference': props.itinerary?.reference,
    }, 'Itinerary document'),
  }) },
  '@/components/OrderEmailComposer.vue': { default: vue.defineComponent({
    props: ['orderId', 'orderNumber'],
    setup: (props) => () => vue.h('button', {
      'data-email-order': props.orderId,
      'data-email-order-number': props.orderNumber,
    }, 'Email documents'),
  }) },
  '@/components/OrderStatusControl.vue': { default: vue.defineComponent({
    props: ['orderId', 'orderNumber', 'status', 'websiteName', 'disabled'],
    emits: ['updated', 'settled'],
    setup: (props) => () => vue.h('button', {
      'data-order-control': props.orderId,
      'data-order-number': props.orderNumber,
      'data-website-name': props.websiteName,
      disabled: props.disabled,
    }, props.status),
  }) },
}
function loadComponent(path, mocks, globals, ssr = false) {
  const content = path.endsWith('.vue')
    ? compileScript(parse(source(path), { filename: path }).descriptor, { id: path, inlineTemplate: ssr, ...(ssr ? { templateOptions: { ssr: true } } : {}) }).content
    : source(path)
  const { outputText } = ts.transpileModule(content, {
    compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 },
  })
  const module = { exports: {} }
  runInNewContext(outputText, {
    module, exports: module.exports, console, URL, setTimeout: () => 1, clearTimeout() {},
    require: (name) => {
      if (Object.hasOwn(mocks, name)) return mocks[name]
      if (Object.hasOwn(ui, name)) return ui[name]
      if (name === '@/lib/whatsapp') return loadComponent('lib/whatsapp.ts', mocks, globals, ssr)
      const componentPath = name === './EntryFieldValue.vue' ? 'components/submissions/EntryFieldValue.vue'
        : name.startsWith('@/') && name.endsWith('.vue') ? name.slice(2) : null
      if (componentPath) return { default: loadComponent(componentPath, mocks, globals, ssr) }
      return require(name)
    },
    ...globals,
  })
  return path.endsWith('.vue') ? module.exports.default : module.exports
}
function fixture(response = {}, overrides = {}) {
  return { id: 42, entry_id: 9001, website_id: 13, form_id: 4, email: null, created_at_wp: '2026-09-06T10:00:00Z', website: { name: 'Fixture website' }, payload: { response }, ...overrides }
}
function harness(t, response = {}, overrides = {}) {
  const calls = { posts: [], deletes: [], reloads: [], visits: [], copies: [], errors: [], successes: [], opened: [] }
  let confirmation = false
  const window = { location: { href: '' }, open: (...args) => calls.opened.push(args) }
  const globals = { window, navigator: { clipboard: { writeText: async (text) => calls.copies.push(text) } }, confirm: () => confirmation }
  const page = vue.reactive({ props: { flash: {} } })
  const mocks = {
    '@inertiajs/vue3': {
      usePage: () => page, Head: wrap('header'), Link: wrap('a'),
      router: {
        post: (url, data, options) => calls.posts.push({ url, data, options }),
        delete: (url, options) => calls.deletes.push({ url, options }),
        reload: (options) => calls.reloads.push(options),
        visit: (url) => calls.visits.push(url),
      },
    },
    '@/composables/useToast': { useToast: () => ({ error: (message) => calls.errors.push(message), success: (message) => calls.successes.push(message) }) },
  }
  const props = vue.reactive({ entry: fixture(response, overrides), linkedOrder: null, formSchema: { fields: { names: { label: 'Passenger names', type: 'name' }, notes_2: { label: 'Agent notes', type: 'text' } } } })
  const component = loadComponent('pages/Submissions/EntryDetails.vue', mocks, globals)
  const scope = vue.effectScope()
  const state = scope.run(() => component.setup(props, { expose() {} }))
  t.after(() => scope.stop())
  return { state, props, calls, window, confirm: (value) => { confirmation = value }, render: () => renderToString(vue.createSSRApp(loadComponent('pages/Submissions/EntryDetails.vue', mocks, globals, true), props)) }
}
const flight = { itineraries: [{ segments: [{ carrierCode: 'AT', number: '750', departure: { iataCode: 'CMN', at: '2026-10-01T10:00:00' }, arrival: { iataCode: 'CDG', at: '2026-10-01T13:00:00' } }] }] }

test('all displayed response fields belong to exactly one details section', (t) => {
  const { state } = harness(t, {
    email: 'customer@example.test', phone_departure: '+1234567',
    flight_from: 'CMN', flight_to: 'CDG', flight_arrival: '2026-10-01',
    flight_departure_json: JSON.stringify(flight), names: { first_name: 'Ada', last_name: 'Example' },
    notes_2: 'Please call', amount: 0, accepted: false, baggage: { pieces: 0, requested: false, options: ['Cabin'] },
    empty_answer: '',
  })
  const displayed = state.formFields.value.map((field) => field.key)
  const assigned = [...state.contactFields.value, ...state.travelFields.value, ...state.flightFields.value, ...state.additionalFields.value, state.namesFieldDisplay.value].filter(Boolean).map((field) => field.key)
  assert.equal(assigned.length, displayed.length)
  assert.equal(new Set(assigned).size, assigned.length)
  assert.deepEqual(Array.from(assigned).sort(), Array.from(displayed).sort())
  assert.ok(state.contactFields.value.some((field) => field.key === 'phone_departure'))
  assert.ok(!state.travelFields.value.some((field) => field.key === 'phone_departure'))
  assert.equal(state.flightFields.value.length, 1)
  assert.equal(state.flightFields.value[0].key, 'flight_departure_json')
  assert.equal(state.additionalFields.value.find((field) => field.key === 'notes_2').label, 'Agent notes')
  assert.equal(state.additionalFields.value.find((field) => field.key === 'amount').value, 0)
  assert.equal(state.additionalFields.value.find((field) => field.key === 'accepted').value, false)
  assert.deepEqual(plain(state.additionalFields.value.find((field) => field.key === 'baggage').value), { pieces: 0, requested: false, options: ['Cabin'] })
  assert.ok(!displayed.includes('empty_answer'))
  state.showEmptyFields.value = true
  assert.ok(state.additionalFields.value.some((field) => field.key === 'empty_answer'))
})

test('name grouping preserves plain strings, unfamiliar records, and every mixed array item', (t) => {
  const { state } = harness(t, {
    names: 'Plain Name',
    names_5: { full_name: 'Unfamiliar Record' },
    names_12: [{ first_name: 'Known', last_name: 'Passenger' }, 'Another Name', 0, false, { custom: ['kept', { nested: true }] }, ['Nested Name']],
  })
  const rows = state.passengerRows.value
  assert.equal(rows.length, 8)
  assert.equal(rows[0], 'Plain Name')
  assert.deepEqual(plain(rows[1]), { full_name: 'Unfamiliar Record' })
  assert.equal(rows[2].first_name, 'Known')
  assert.equal(rows[2]._originalKey, 'names_12')
  assert.equal(rows[3], 'Another Name')
  assert.equal(rows[4], 0)
  assert.equal(rows[5], false)
  assert.deepEqual(plain(rows[6]), { custom: ['kept', { nested: true }] })
  assert.deepEqual(plain(rows[7]), ['Nested Name'])
  assert.deepEqual(Array.from(state.formFields.value, (field) => field.key), ['names'])
})

test('airport schema labels do not consume departure and return date fields', (t) => {
  const { state, props } = harness(t, { flight_from: 'CMN', flight_to: 'CDG', departure_date: '2026-10-01', return_date: '2026-10-12' })
  props.formSchema.fields.flight_from = { label: 'Departure airport', type: 'text' }
  props.formSchema.fields.flight_to = { label: 'Arrival airport', type: 'text' }
  assert.equal(state.flightDepartureField.value.key, 'departure_date')
  assert.equal(state.flightArrivalField.value.key, 'return_date')
  assert.deepEqual(Array.from(state.travelFields.value, (field) => field.key), ['flight_from', 'flight_to', 'departure_date', 'return_date'])
  assert.equal(state.additionalFields.value.length, 0)
})

test('sparse titles stay positional when names suffixes do not match passenger numbers', (t) => {
  const { state } = harness(t, {
    names: { first_name: 'First', last_name: 'Passenger', salutation: 'Dr' },
    names_5: { first_name: 'Second', last_name: 'Passenger' },
    names_12: { firstname: 'Third', lastname: 'Passenger' },
    title: '', title_2: 'Mrs', title_3: null,
  })
  assert.deepEqual(Array.from(state.passengerRows.value, (row) => row._title), ['Dr', 'Mrs', null])
  assert.deepEqual(Array.from(state.passengerRows.value, (row) => row._originalKey), ['names', 'names_5', 'names_12'])
})

test('hidden unanswered names and legacy blank dropdown titles do not shift later titles', (t) => {
  const blankNames = harness(t, { names: '', names_5: { first_name: 'Second' }, title: 'Mr', title_2: 'Ms' })
  assert.equal(blankNames.state.passengerRows.value.length, 1)
  assert.equal(blankNames.state.passengerRows.value[0]._title, 'Ms')
  const legacy = harness(t, { names: { first_name: 'First' }, names_5: { first_name: 'Second' }, dropdown: '', dropdown_2: 'Dr' })
  assert.deepEqual(Array.from(legacy.state.passengerRows.value, (row) => row._title), [null, 'Dr'])
})

test('JSON responses and entry email fallback retain their data', (t) => {
  const json = harness(t, JSON.stringify({ names: 'JSON Name', custom: { saved: true } }), { email: 'fallback@example.test' })
  assert.equal(json.state.passengerRows.value[0], 'JSON Name')
  assert.equal(json.state.contactFields.value[0].value, 'fallback@example.test')
  assert.deepEqual(plain(json.state.additionalFields.value[0].value), { saved: true })
  const text = harness(t, 'Unstructured original response')
  assert.equal(text.state.additionalFields.value[0].value, 'Unstructured original response')
})

test('itinerary replaces legacy airline generation and passes only this submission context', async (t) => {
  const page = harness(t)
  assert.equal(page.state.generatePnr, undefined)
  assert.equal(page.state.generateAmadeusCode, undefined)
  const initialHtml = await page.render()
  assert.match(initialHtml, /Itinerary not generated/)
  assert.match(initialHtml, /data-itinerary-entry="42" data-itinerary-can-generate="false"/)
  page.props.itinerary = { reference: 'WPH-EXAMPLE', reference_type: 'internal', can_update: true }
  page.props.itineraryCanGenerate = true
  const html = await page.render()
  assert.match(html, /Itinerary ready/)
  assert.match(html, /data-itinerary-entry="42" data-itinerary-can-generate="true" data-itinerary-reference="WPH-EXAMPLE"/)
  assert.ok(!html.includes('Generate PNR'))
  assert.ok(!html.includes('Dummy ticket code'))
  assert.ok(!html.includes('/storage/'))
  assert.equal(page.calls.posts.length, 0)
})

test('delete still requires confirmation and returns to the correct form', (t) => {
  const page = harness(t)
  page.state.deleteEntry()
  assert.equal(page.calls.deletes.length, 0)
  page.confirm(true)
  page.state.deleteEntry()
  assert.equal(page.calls.deletes[0].url, '/submissions/entries/42')
  assert.equal(page.state.isDeleting.value, true)
  page.calls.deletes[0].options.onError()
  assert.equal(page.state.isDeleting.value, false)
  page.calls.deletes[0].options.onSuccess()
  assert.deepEqual(page.calls.visits, ['/submissions/forms/13/4'])
})

test('previous PNR and PDF remain read-only and use the authenticated submission download', async (t) => {
  const page = harness(t, {}, { pnr: 'OLD123', pnr_pdf_path: 'dummy-tickets/old-customer.pdf' })
  const html = await page.render()
  assert.match(html, /Previous booking record/)
  assert.match(html, /Previous PNR \(historical\)/)
  assert.match(html, /OLD123/)
  assert.match(html, /href="\/submissions\/entries\/42\/download-pdf"/)
  assert.match(html, /Itinerary not generated/)
  assert.ok(!html.includes('/storage/'))
  assert.ok(!html.includes('old-customer.pdf'))
  assert.ok(!html.includes('Generate PNR'))
  assert.equal(page.calls.posts.length, 0)
  page.props.entry.pnr_pdf_path = null
  const pnrOnlyHtml = await page.render()
  assert.match(pnrOnlyHtml, /Previous booking record/)
  assert.ok(!pnrOnlyHtml.includes('Download previous PDF'))
  page.props.entry.pnr = null
  assert.ok(!(await page.render()).includes('Previous booking record'))
})

test('copy preserves complete structured values and reports the copied field', async (t) => {
  const page = harness(t)
  const value = { names: ['One', 'Two'], amount: 0, confirmed: false }
  page.state.copyToClipboard(value, 'custom')
  await Promise.resolve()
  assert.equal(page.calls.copies[0], JSON.stringify(value, null, 2))
  assert.equal(page.state.copiedField.value, 'custom')
  assert.deepEqual(page.calls.successes, ['Copied to clipboard'])
})

test('structured values render zero, false, and nested answers beside one itinerary tool', async (t) => {
  const page = harness(t, { names: 'Plain Name', amount: 0, confirmed: false, custom: { nested: ['Saved value'] }, flight_from: 'CMN', flight_to: 'CDG' }, { amadeus_command_block: 'NM1EXAMPLE/ADA' })
  const html = await page.render()
  assert.equal((html.match(/data-itinerary-entry="42"/g) || []).length, 1)
  assert.ok(html.includes('Plain Name'))
  assert.ok(html.includes('Saved value'))
  assert.match(html, />0<\/span>/)
  assert.match(html, />No<\/span>/)
  assert.ok(!html.includes('Copy all code'))
  assert.ok(html.includes('Raw submission data'))
})

test('active submission fields link valid phone values through the shared renderer and preserve other values', async (t) => {
  const originalPhones = ['+1 (202) 555-0100', '0044 7700 900123', '07700900123']
  const page = harness(t, { phone: originalPhones, cancellation: '1234567890', amount: 1234567, email: 'customer@example.test' })
  const html = await page.render()
  assert.equal((html.match(/href="https:\/\/wa.me\//g) || []).length, 2)
  assert.match(html, /href="https:\/\/wa.me\/12025550100"[^>]*target="_blank"[^>]*rel="noopener noreferrer"/)
  assert.match(html, /href="https:\/\/wa.me\/447700900123"/)
  assert.ok(html.includes('+1 (202) 555-0100'))
  assert.ok(html.includes('07700900123'))
  assert.ok(html.includes('1234567890'))
  assert.ok(html.includes('href="mailto:customer@example.test"'))
  assert.ok(!html.includes('wa.me/1234567890'))
  page.state.copyToClipboard(originalPhones, 'phone'); await Promise.resolve()
  assert.equal(page.calls.copies[0], JSON.stringify(originalPhones, null, 2))
})

const linkedOrder = (overrides = {}) => ({
  id: 73, wp_order_id: 5401, status: 'processing', website_name: 'Fixture website', can_update_status: true,
  ...overrides,
})

test('linked order uses the local order ID for its link and control while submission status stays separate', async (t) => {
  const page = harness(t, {}, { payment_status: 'paid', payload: { status: 'read', response: {} } })
  page.props.linkedOrder = linkedOrder()
  const html = await page.render()
  assert.match(html, /href="\/orders\/73"/)
  assert.match(html, /Order #5401/)
  assert.match(html, /data-order-control="73" data-order-number="5401" data-website-name="Fixture website"/)
  assert.match(html, /data-email-order="73" data-email-order-number="5401"/)
  assert.match(html, />processing<\/button>/)
  assert.match(html, /Submission: read/)
  assert.ok(!html.includes('No linked order'))
  assert.equal(page.props.entry.payment_status, 'paid')
  assert.equal(page.props.entry.payload.status, 'read')
  assert.equal(page.calls.posts.length, 0)
})

test('unlinked and read-only submissions cannot expose an enabled order status control', async (t) => {
  const page = harness(t)
  const unlinkedHtml = await page.render()
  assert.ok(unlinkedHtml.includes('No linked order'))
  assert.match(unlinkedHtml, /href="\/orders\?website_id=13"/)
  assert.ok(!unlinkedHtml.includes('data-order-control'))
  assert.ok(!unlinkedHtml.includes('data-email-order'))
  page.state.refreshLinkedOrder({ id: 73 })
  assert.equal(page.calls.reloads.length, 0)
  page.props.linkedOrder = linkedOrder({ can_update_status: false })
  const readOnlyHtml = await page.render()
  assert.match(readOnlyHtml, /data-order-control="73"[^>]* disabled/)
  assert.ok(!readOnlyHtml.includes('data-email-order'))
  assert.ok(readOnlyHtml.includes('cannot change its status'))
})

test('settled order updates refresh only the linked order and entry without changing payment or retrying a write', async (t) => {
  const page = harness(t, {}, { payment_status: 'paid' })
  page.props.linkedOrder = linkedOrder()
  await vue.nextTick()
  page.state.refreshLinkedOrder({ id: 99 })
  assert.equal(page.calls.reloads.length, 0)
  page.state.refreshLinkedOrder({ id: 73 })
  assert.equal(page.state.isRefreshingLinkedOrder.value, true)
  const reload = page.calls.reloads[0]
  assert.deepEqual(Array.from(reload.only), ['entry', 'linkedOrder'])
  assert.equal(Object.hasOwn(reload, 'preserveScroll'), false)
  assert.equal(Object.hasOwn(reload, 'preserveState'), false)
  page.state.refreshLinkedOrder({ id: 73 })
  assert.equal(page.calls.reloads.length, 1)
  reload.onError()
  assert.match(page.calls.errors[0], /Order details could not be refreshed/)
  reload.onFinish()
  assert.equal(page.state.isRefreshingLinkedOrder.value, false)
  page.state.refreshLinkedOrder({ id: 73 })
  const staleReload = page.calls.reloads[1]
  page.props.entry.id = 99
  page.props.linkedOrder = null
  await vue.nextTick()
  staleReload.onError()
  staleReload.onFinish()
  assert.equal(page.calls.errors.length, 1)
  assert.equal(page.state.isRefreshingLinkedOrder.value, false)
  assert.equal(page.props.entry.payment_status, 'paid')
  assert.equal(page.calls.posts.length, 0)
})
