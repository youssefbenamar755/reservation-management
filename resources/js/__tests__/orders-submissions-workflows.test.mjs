import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { createRequire } from 'node:module'
import test from 'node:test'
import { runInNewContext } from 'node:vm'
import { compileScript, parse } from '@vue/compiler-sfc'
import ts from 'typescript'

const require = createRequire(import.meta.url)
const vue = require('vue')
const plain = (value) => JSON.parse(JSON.stringify(value))
const source = (path) => readFileSync(new URL(`../${path}`, import.meta.url), 'utf8')
const flush = async () => { await vue.nextTick(); await new Promise(setImmediate) }
function deferred() {
  let resolve, reject
  const promise = new Promise((done, fail) => { resolve = done; reject = fail })
  return { promise, resolve, reject }
}
function clock() {
  let now = 0, nextId = 0
  const tasks = new Map()
  return {
    setTimeout(callback, delay) { const id = ++nextId; tasks.set(id, { at: now + delay, callback }); return id },
    clearTimeout(id) { tasks.delete(id) },
    advance(milliseconds) {
      const end = now + milliseconds
      while (true) {
        const first = [...tasks].sort((a, b) => a[1].at - b[1].at)[0]
        if (!first || first[1].at > end) break
        now = first[1].at; tasks.delete(first[0]); first[1].callback()
      }
      now = end
    },
    get pending() { return tasks.size },
  }
}
function events() {
  const listeners = new Map()
  return {
    addEventListener(name, callback) { if (!listeners.has(name)) listeners.set(name, new Set()); listeners.get(name).add(callback) },
    removeEventListener(name, callback) { listeners.get(name)?.delete(callback) },
    emit(name, event) { for (const callback of listeners.get(name) || []) callback(event) },
    get size() { return [...listeners.values()].reduce((sum, callbacks) => sum + callbacks.size, 0) },
  }
}

function harness(t, path, initialFilters = {}, options = {}) {
  const timers = clock()
  const mounted = [], unmounted = []
  const calls = { gets: [], posts: [], deletes: [], reloads: [], forms: [], successes: [], errors: [], confirms: [], snapshots: [], replacements: [], subscriptions: [] }
  const routerEvents = events(), windowEvents = events(), documentEvents = events()
  let confirmation = false
  const page = vue.reactive({ url: path.includes('Orders') ? '/orders' : '/submissions', props: { auth: { user: { id: 7 } }, flash: {} } })
  const router = {
    on(name, callback) { routerEvents.addEventListener(name, callback); return () => routerEvents.removeEventListener(name, callback) },
    get(url, params, options) {
      const visit = { async: false, url: new URL(url, 'https://example.test') }
      routerEvents.emit('before', { detail: { visit } })
      const call = { url, params, options, visit, cancelled: false }
      calls.gets.push(call)
      options.onCancelToken?.({ cancel() { call.cancelled = true; options.onFinish?.(); routerEvents.emit('finish', { detail: { visit } }) } })
      routerEvents.emit('start', { detail: { visit } })
    },
    reload(options) { calls.reloads.push(options) },
    delete(url, options) { calls.deletes.push({ url, options }) },
    visit() {},
    replaceProp(key, updater, options) {
      calls.replacements.push(key)
      props[key] = updater(props[key])
      options.onFinish?.()
    },
  }
  const axios = {
    get(url, options) { const request = { url, options, ...deferred() }; calls.forms.push(request); return request.promise },
    post(url, data, options) { const request = { url, data, options, ...deferred() }; calls.posts.push(request); return request.promise },
  }
  const props = vue.reactive({
    orders: { data: [{ id: 1 }], links: [] }, filters: initialFilters,
    forms: { data: [], links: [] }, websites: [{ id: 1, name: 'Site A' }, { id: 2, name: 'Site B' }],
    entries: { data: [], links: [] }, website: { id: 2, name: 'Site B' }, formId: 88, formName: 'Booking form',
  })
  const mocks = {
    vue: { ...vue, onMounted: (callback) => mounted.push(callback), onUnmounted: (callback) => unmounted.push(callback) },
    axios: { default: axios },
    '@inertiajs/vue3': { router, usePage: () => page },
    '@/composables/useToast': { useToast: () => ({ success: (text) => calls.successes.push(text), error: (text) => calls.errors.push(text) }) },
    '@/composables/useEchoNotifications': { useEchoNotifications: () => ({ onNotification() {}, offNotification() {} }) },
    ...(!options.realLive ? { '@/lib/liveOrders': { createAutoRefresh: () => ({ start() {}, stop() {}, suspend() {}, resume() {}, request() {}, availabilityChanged() {} }) } } : {}),
    '@/lib/ordersPush': { subscribeToOrders: (subscription) => { calls.subscriptions.push(subscription); return () => {} } },
  }
  const globals = {
    console, AbortController, URL, URLSearchParams, setTimeout: timers.setTimeout, clearTimeout: timers.clearTimeout, queueMicrotask,
    window: { ...windowEvents, location: { href: 'https://example.test/orders', origin: 'https://example.test' } },
    document: { ...documentEvents, hidden: false }, navigator: { onLine: true },
    fetch(url, options) { const request = { url, options, ...deferred() }; calls.snapshots.push(request); return request.promise },
    confirm(text) { calls.confirms.push(text); return confirmation },
  }
  function load(path) {
    let content = source(path)
    if (path.endsWith('.vue')) content = compileScript(parse(content, { filename: path }).descriptor, { id: path }).content
    const { outputText } = ts.transpileModule(content, { compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 } })
    const module = { exports: {} }
    runInNewContext(outputText, {
      ...globals, module, exports: module.exports,
      require: (name) => Object.hasOwn(mocks, name) ? mocks[name]
        : ['@/lib/fluentFormsSync', '@/lib/liveOrders'].includes(name) ? load(`${name.slice(2)}.ts`)
          : name.startsWith('@/') ? {} : require(name),
    })
    return module.exports
  }
  const scope = vue.effectScope()
  const state = scope.run(() => load(path).default.setup(props, { expose() {} }))
  let disposed = false
  const dispose = () => { if (disposed) return; disposed = true; unmounted.forEach((callback) => callback()); scope.stop() }
  t.after(dispose)
  return {
    state, props, page, calls, timers, windowEvents, routerEvents, dispose, browser: globals.window,
    mount: () => mounted.forEach((callback) => callback()),
    confirm: (value) => { confirmation = value },
    helpers: () => load('lib/fluentFormsSync.ts'),
    async complete(call, filters) {
      props.filters = filters; page.url = `/orders?${new URLSearchParams(filters)}`; globals.window.location.href = `https://example.test${page.url}`; await vue.nextTick()
      call.options.onSuccess?.(); call.options.onFinish?.(); routerEvents.emit('finish', { detail: { visit: call.visit } })
    },
  }
}
const orders = (t, filters, options) => harness(t, 'pages/Orders/Index.vue', filters, options)
const submissions = (t) => harness(t, 'pages/Submissions/Index.vue')
const reply = (status, synced = 0, updated = 0, extra = {}) => ({ status: 200, data: { status, synced, updated, ...extra } })

test('Orders typing makes one debounced request and retains the displayed rows and filters', (t) => {
  const { state, calls, timers, props } = orders(t, { website_id: 2, status: 'completed', start_date: '2026-09-01', end_date: '2026-09-07', sort: 'oldest', per_page: 30 })
  const originalRows = props.orders.data
  state.updateSearch('a'); timers.advance(100); state.updateSearch('ad'); timers.advance(100); state.updateSearch('ada@example.test')
  timers.advance(299); assert.equal(calls.gets.length, 0)
  timers.advance(1); assert.equal(calls.gets.length, 1)
  assert.deepEqual(plain(calls.gets[0].params), { website_id: '2', status: 'completed', search: 'ada@example.test', start_date: '2026-09-01', end_date: '2026-09-07', sort: 'oldest', per_page: '30' })
  assert.equal(calls.gets[0].options.preserveState, true)
  assert.equal(calls.gets[0].options.preserveScroll, true)
  assert.equal(props.orders.data, originalRows)
})

test('Orders website/status changes include the latest draft search and supersede one pending request', (t) => {
  const { state, calls, timers } = orders(t)
  state.updateSearch('Ada')
  state.updateFilter('website_id', '2')
  assert.equal(calls.gets.length, 1)
  assert.deepEqual(plain(calls.gets[0].params), { website_id: '2', status: '', search: 'Ada', start_date: '', end_date: '', sort: 'newest', per_page: '15' })
  state.updateFilter('status', 'completed')
  assert.equal(calls.gets.length, 2)
  assert.equal(calls.gets[0].cancelled, true)
  assert.deepEqual(plain(calls.gets[1].params), { website_id: '2', status: 'completed', search: 'Ada', start_date: '', end_date: '', sort: 'newest', per_page: '15' })
  timers.advance(1000)
  assert.equal(calls.gets.length, 2)
})

test('Orders Enter flushes the debounce without sending a duplicate while its result is pending', async (t) => {
  const { state, calls, timers, complete } = orders(t)
  state.updateSearch('Ada'); state.submitFilters(); state.submitFilters(); timers.advance(1000)
  assert.equal(calls.gets.length, 1)
  await complete(calls.gets[0], { website_id: '', status: '', search: 'Ada' })
  state.submitFilters()
  assert.equal(calls.gets.length, 1)
  state.updateSearch(''); timers.advance(300)
  assert.equal(calls.gets.length, 2)
  assert.equal(calls.gets[1].params.search, '')
})

test('Orders ignores late own callbacks, follows history, and cancels pending work when leaving', async (t) => {
  const app = orders(t, { website_id: '1', search: 'old' }); app.mount()
  app.state.updateSearch('first'); app.timers.advance(300)
  const first = app.calls.gets[0]
  app.state.updateSearch('latest')
  assert.equal(first.cancelled, true)
  first.options.onSuccess(); first.options.onFinish()
  assert.equal(app.state.filterInputs.value.search, 'latest')
  app.windowEvents.emit('popstate')
  app.props.filters = { website_id: 2, status: 'pending', search: 'history', start_date: '2026-08-01', end_date: '2026-08-31', sort: 'lowest', per_page: 50 }
  app.page.url = '/orders?search=history'
  await flush(); app.timers.advance(1000)
  assert.deepEqual(plain(app.state.filterInputs.value), { website_id: '2', status: 'pending', search: 'history', start_date: '2026-08-01', end_date: '2026-08-31', sort: 'lowest', per_page: '50' })
  assert.equal(app.calls.gets.length, 1)
  app.state.updateSearch('do not navigate back')
  app.routerEvents.emit('before', { detail: { visit: { async: false, url: new URL('https://example.test/customers') } } })
  app.timers.advance(1000)
  assert.equal(app.calls.gets.length, 1)
  app.state.updateSearch('unmount'); app.dispose(); app.timers.advance(1000)
  assert.equal(app.calls.gets.length, 1)
  assert.equal(app.timers.pending, 0)
  assert.equal(app.routerEvents.size, 0)
})

test('Orders sort and page size each submit once with every applied filter and the pending search', async (t) => {
  const app = orders(t, { website_id: 2, status: 'on-hold', start_date: '2026-09-01', end_date: '2026-09-07', sort: 'newest', per_page: 15 })
  app.state.updateSearch('Ada')
  app.state.updateFilter('sort', 'highest')
  assert.equal(app.calls.gets.length, 1)
  assert.deepEqual(plain(app.calls.gets[0].params), { website_id: '2', status: 'on-hold', search: 'Ada', start_date: '2026-09-01', end_date: '2026-09-07', sort: 'highest', per_page: '15' })
  app.state.updateFilter('per_page', '100')
  assert.equal(app.calls.gets.length, 2)
  assert.equal(app.calls.gets[0].cancelled, true)
  assert.deepEqual(plain(app.calls.gets[1].params), { website_id: '2', status: 'on-hold', search: 'Ada', start_date: '2026-09-01', end_date: '2026-09-07', sort: 'highest', per_page: '100' })
  await app.complete(app.calls.gets[1], { website_id: 2, status: 'on-hold', search: 'Ada', start_date: '2026-09-01', end_date: '2026-09-07', sort: 'highest', per_page: 100 })
  app.state.submitFilters(); app.timers.advance(1000)
  assert.equal(app.calls.gets.length, 2)
  assert.equal(app.state.filterLoading.value, false)
})

test('Orders date validation retains invalid drafts, then recovers in one corrected request', async (t) => {
  const app = orders(t, { website_id: 2, end_date: '2026-09-07', sort: 'oldest', per_page: 30 })
  app.state.updateFilter('start_date', '2026-09-08')
  assert.equal(app.calls.gets.length, 0)
  assert.match(app.state.filterErrors.value.end_date, /on or after/)
  assert.equal(app.state.filterInputs.value.start_date, '2026-09-08')
  app.props.filters = { ...app.props.filters }; await flush()
  assert.equal(app.state.filterInputs.value.start_date, '2026-09-08')
  app.state.updateFilter('end_date', '2026-09-30')
  assert.equal(app.calls.gets.length, 1)
  assert.deepEqual(plain(app.calls.gets[0].params), { website_id: '2', status: '', search: '', start_date: '2026-09-08', end_date: '2026-09-30', sort: 'oldest', per_page: '30' })
  assert.deepEqual(plain(app.state.filterErrors.value), {})
  const current = app.calls.gets[0]
  current.options.onError({ start_date: 'Server rejected the date.' }); current.options.onFinish()
  app.props.filters = { ...app.props.filters }; await flush()
  assert.equal(app.state.filterInputs.value.start_date, '2026-09-08')
  assert.deepEqual(plain(app.state.filterErrors.value), { start_date: 'Server rejected the date.' })
  assert.equal(app.state.filterLoading.value, false)
})

test('Orders pagination keeps the applied date/sort/page-size scope and cancels an unsent search', (t) => {
  const app = orders(t, { website_id: 2, status: 'refunded', search: 'Ada', start_date: '2026-09-01', end_date: '2026-09-07', sort: 'lowest', per_page: 50 })
  const originalRows = app.props.orders.data
  app.state.updateSearch('unapplied draft')
  app.state.goToPage('https://example.test/orders?website_id=2&status=refunded&search=Ada&start_date=2026-09-01&end_date=2026-09-07&sort=lowest&per_page=50&page=3')
  assert.equal(app.calls.gets.length, 1)
  assert.equal(app.calls.gets[0].url, '/orders')
  assert.deepEqual(plain(app.calls.gets[0].params), { website_id: '2', status: 'refunded', search: 'Ada', start_date: '2026-09-01', end_date: '2026-09-07', sort: 'lowest', per_page: '50', page: '3' })
  assert.equal(app.calls.gets[0].options.replace, false)
  assert.equal(app.state.filterInputs.value.search, 'Ada')
  app.timers.advance(1000)
  assert.equal(app.calls.gets.length, 1)
  assert.equal(app.props.orders.data, originalRows)
})

test('Orders Reset clears the whole scope once and removing one chip preserves the other filters', async (t) => {
  const initial = { website_id: 2, status: 'failed', search: 'Ada', start_date: '2026-09-01', end_date: '2026-09-07', sort: 'oldest', per_page: 100 }
  const app = orders(t, initial)
  assert.equal(app.state.hasFilters.value, true)
  app.state.removeFilter('status')
  assert.deepEqual(plain(app.calls.gets[0].params), { website_id: '2', status: '', search: 'Ada', start_date: '2026-09-01', end_date: '2026-09-07', sort: 'oldest', per_page: '100' })
  app.state.resetFilters()
  assert.equal(app.calls.gets.length, 2)
  assert.equal(app.calls.gets[0].cancelled, true)
  assert.deepEqual(plain(app.calls.gets[1].params), { website_id: '', status: '', search: '', start_date: '', end_date: '', sort: 'newest', per_page: '15' })
  await app.complete(app.calls.gets[1], { website_id: null, status: null, search: null, start_date: null, end_date: null, sort: 'newest', per_page: 15 })
  assert.equal(app.state.hasFilters.value, false)
  assert.deepEqual(plain(app.state.filterChips.value), [])
  app.state.resetFilters(); app.timers.advance(1000)
  assert.equal(app.calls.gets.length, 2)
})

test('Orders cannot sync the old website while a new selection is pending or unapplied', async (t) => {
  const app = orders(t, { website_id: 1 })
  assert.equal(app.state.syncScopePending.value, false)
  app.state.updateFilter('website_id', '2')
  assert.equal(app.state.filterLoading.value, true)
  assert.equal(app.state.syncScopePending.value, true)
  await app.state.syncOrdersFromWooCommerce()
  assert.equal(app.state.isSyncing.value, false)
  assert.equal(app.calls.snapshots.length, 0)
  assert.deepEqual(app.calls.errors, [])
  app.state.updateSearch('Ada') // Cancels the filter request but leaves the selected website draft.
  assert.equal(app.state.filterLoading.value, false)
  assert.equal(app.state.syncScopePending.value, true)
  await app.state.syncOrdersFromWooCommerce()
  assert.equal(app.state.isSyncing.value, false)
  assert.equal(app.calls.snapshots.length, 0)
  assert.deepEqual(app.calls.errors, [])
  app.timers.advance(300)
  await app.complete(app.calls.gets.at(-1), { website_id: 2, search: 'Ada' })
  assert.equal(app.state.syncScopePending.value, false)
})

test('Orders supports every standard Woo status plus checkout draft and formats imported currencies safely', (t) => {
  const { state, props } = orders(t)
  assert.deepEqual(plain(state.statuses.map((status) => status.value)), ['pending', 'on-hold', 'processing', 'completed', 'cancelled', 'refunded', 'failed', 'checkout-draft'])
  assert.equal(state.statusName('on-hold'), 'On hold')
  assert.match(state.getStatusBadgeClass('on-hold'), /orange/)
  assert.equal(state.statusName('checkout-draft'), 'Checkout draft')
  assert.equal(state.formatCurrency(0, 'eur'), '€0.00')
  assert.equal(state.formatCurrency('12.50', null), '12.5 UNKNOWN')
  assert.equal(state.formatCurrency(12, 'UNKNOWN'), '12 UNKNOWN')
  assert.equal(state.formatCurrency(12, 'EURO'), '12 EURO')
  assert.equal(state.formatCurrency('not a number', 'USD'), '—')
  assert.equal(state.formatCurrency(4500, 'JPY'), '¥4,500')
  assert.equal(state.formatDate('invalid'), '—')
  props.orders.timezone = 'UTC'
  assert.equal(state.formatDate('2026-09-01T00:30:00+01:00'), 'Aug 31, 2026')
  props.orders.timezone = 'Africa/Casablanca'
  assert.equal(state.formatDate('2026-09-01T00:30:00+01:00'), 'Sep 1, 2026')
})

test('Orders live updates replace the rows and nested summary together without idle polling', async (t) => {
  const app = orders(t, { website_id: 2, status: 'completed', search: 'Ada', start_date: '2026-09-01', end_date: '2026-09-07', sort: 'oldest', per_page: 30 }, { realLive: true })
  app.browser.location.href = 'https://example.test/orders?website_id=2&status=completed&search=Ada&start_date=2026-09-01&end_date=2026-09-07&sort=oldest&per_page=30&page=3'
  app.props.orders = { data: [{ id: 1 }], current_page: 3, total: 52, summary: { completed: 3, pending: 40, on_hold: 5, processing: 4, failed: 0, completed_revenue: [{ currency: 'USD', total: 40 }] }, timezone: 'UTC' }
  const initialFilters = app.props.filters
  const observations = []
  const stopWatching = vue.watch(() => app.props.orders, (value) => observations.push(plain(value)), { flush: 'sync' })
  t.after(stopWatching)
  app.mount()
  app.timers.advance(300_000)
  assert.equal(app.calls.snapshots.length, 0)
  app.calls.subscriptions[0].onSubscribed()
  app.calls.subscriptions[0].onOrder({ website_id: 2 })
  app.timers.advance(250)
  assert.equal(app.calls.snapshots.length, 1)
  const nextOrders = { data: [{ id: 2 }], current_page: 3, total: 53, summary: { completed: 4, pending: 40, on_hold: 5, processing: 4, failed: 0, completed_revenue: [{ currency: 'EUR', total: 25 }, { currency: 'USD', total: 40 }] }, timezone: 'UTC' }
  app.calls.snapshots[0].resolve({ ok: true, json: async () => ({ orders: nextOrders }) })
  await flush()
  assert.deepEqual(observations, [nextOrders])
  assert.deepEqual(app.calls.replacements, ['orders'])
  assert.equal(app.props.filters, initialFilters)
  assert.equal(app.props.orders.current_page, 3)
  assert.equal(app.calls.gets.length, 0)
  assert.equal(app.calls.snapshots[0].url, 'https://example.test/orders?website_id=2&status=completed&search=Ada&start_date=2026-09-01&end_date=2026-09-07&sort=oldest&per_page=30&page=3')
  assert.equal(app.calls.snapshots[0].options.headers['X-Inertia'], undefined)
  app.timers.advance(600_000)
  assert.equal(app.calls.snapshots.length, 1)
  assert.equal(app.timers.pending, 0)
})

test('Orders cancels a stale live rows/summary response before filter navigation can apply it', async (t) => {
  const app = orders(t, { website_id: '1' }, { realLive: true })
  const originalOrders = app.props.orders
  app.mount()
  app.calls.subscriptions[0].onSubscribed()
  app.timers.advance(250)
  const stale = app.calls.snapshots[0]
  app.state.updateFilter('website_id', '2')
  assert.equal(stale.options.signal.aborted, true)
  stale.resolve({ ok: true, json: async () => ({ orders: { data: [{ id: 999 }], current_page: 1, total: 999, summary: { total: 999 } } }) })
  await flush()
  assert.equal(app.props.orders, originalOrders)
  assert.equal(app.calls.replacements.length, 0)
  assert.equal(app.calls.gets.length, 1)
  app.dispose()
  app.timers.advance(600_000)
  assert.equal(app.calls.snapshots.length, 1)
  assert.equal(app.timers.pending, 0)
})

test('Submissions website changes clear stale form selection and ignore old success/failure responses', async (t) => {
  const { state, calls } = submissions(t)
  state.openModal(); state.selectedWebsiteId.value = 1
  const first = calls.forms[0]
  state.selectedFormId.value = 17
  state.selectedWebsiteId.value = 2
  assert.equal(first.options.signal.aborted, true)
  assert.equal(state.selectedFormId.value, '')
  assert.equal(state.canSync.value, false)
  await state.handleSync(); assert.equal(calls.posts.length, 0)
  first.reject(new Error('late failure')); await flush()
  assert.equal(state.isLoadingForms.value, true)
  assert.equal(calls.errors.length, 0)
  calls.forms[1].resolve({ data: { forms: [{ id: 88, title: 'Site B form' }] } }); await flush()
  state.selectedFormId.value = 17; await state.handleSync()
  assert.equal(calls.posts.length, 0)
  state.selectedFormId.value = 88
  assert.equal(state.canSync.value, true)
  state.selectedWebsiteId.value = 1
  const older = calls.forms[2]
  state.selectedWebsiteId.value = 2
  calls.forms[3].resolve({ data: { forms: [{ id: 99, title: 'Newest list' }] } }); await flush()
  older.resolve({ data: { forms: [{ id: 17, title: 'Old list' }] } }); await flush()
  assert.deepEqual(plain(state.availableForms.value), [{ id: 99, title: 'Newest list' }])
})

test('Submissions closing/unmounting aborts form loads and failed loads cannot enable Sync', async (t) => {
  const app = submissions(t)
  app.state.openModal(); app.state.selectedWebsiteId.value = 1
  app.calls.forms[0].reject({ response: { data: { error: 'Connection unavailable' } } }); await flush()
  assert.equal(app.state.isLoadingForms.value, false)
  assert.equal(app.state.canSync.value, false)
  assert.equal(app.state.syncError.value, 'Connection unavailable')
  app.state.selectedWebsiteId.value = 2
  const pending = app.calls.forms[1]
  app.state.isModalOpen.value = false
  assert.equal(pending.options.signal.aborted, true)
  pending.resolve({ data: { forms: [{ id: 88, title: 'Late form' }] } }); await flush()
  assert.deepEqual(plain(app.state.availableForms.value), [])
  app.state.openModal(); app.state.selectedWebsiteId.value = 1
  app.dispose(); assert.equal(app.calls.forms[2].options.signal.aborted, true)
})

async function readyForm(app) {
  app.state.openModal(); app.state.selectedWebsiteId.value = 2
  app.calls.forms.at(-1).resolve({ data: { forms: [{ id: 88, title: 'Booking form' }] } }); await flush()
  app.state.selectedFormId.value = 88
}

test('Submissions sync shows cumulative progress and closes only after the final confirmed page', async (t) => {
  const app = submissions(t); await readyForm(app)
  const running = app.state.handleSync()
  await app.state.handleSync()
  assert.equal(app.calls.posts.length, 1)
  assert.equal(app.calls.posts[0].url, '/websites/2/sync-fluent-form')
  assert.deepEqual(plain(app.calls.posts[0].data), { form_id: 88 })
  assert.equal(app.calls.posts[0].options.headers.Accept, 'application/json')
  assert.equal(app.calls.posts[0].options.timeout, 25000)
  app.calls.posts[0].resolve(reply('partial', 100, 2)); await flush()
  assert.equal(app.state.isModalOpen.value, true)
  assert.match(app.state.syncProgress.value, /100 new, 2 updated/)
  assert.equal(app.calls.reloads.length, 0)
  app.calls.posts[1].resolve(reply('success', 3, 1)); await running
  assert.equal(app.state.isModalOpen.value, false)
  assert.equal(app.state.isSyncing.value, false)
  assert.match(app.calls.successes[0], /103 new submission\(s\), 3 updated/)
  assert.deepEqual(plain(app.calls.reloads[0]), { only: ['forms'] })
})

test('Submissions page errors preserve selection and never claim success or close the modal', async (t) => {
  const app = submissions(t); await readyForm(app)
  const running = app.state.handleSync()
  app.calls.posts[0].resolve(reply('partial', 100)); await flush()
  app.calls.posts[1].resolve({ status: 409, data: { status: 'error', message: 'This form is already syncing.' } }); await running
  assert.equal(app.state.isModalOpen.value, true)
  assert.equal(app.state.isSyncing.value, false)
  assert.equal(app.state.selectedFormId.value, 88)
  assert.match(app.state.syncError.value, /already syncing/)
  assert.equal(app.calls.successes.length, 0)
  assert.equal(app.calls.reloads.length, 0)
})

test('Submissions Stop aborts the active page and ignores a late result without requesting another page', async (t) => {
  const app = submissions(t); await readyForm(app)
  const running = app.state.handleSync()
  app.calls.posts[0].resolve(reply('partial', 100)); await flush()
  app.state.stopSync()
  assert.equal(app.calls.posts[1].options.signal.aborted, true)
  app.calls.posts[1].resolve(reply('partial', 100)); await running
  assert.equal(app.calls.posts.length, 2)
  assert.equal(app.state.isModalOpen.value, true)
  assert.equal(app.state.canSync.value, true)
  assert.match(app.state.syncProgress.value, /Sync stopped.*resume/)
  assert.equal(app.calls.successes.length, 0)
})

test('Submissions bulk-delete confirmation includes the actual count, form, and website', (t) => {
  const app = submissions(t)
  const form = { website_id: 2, form_id: 17, entry_count: 631, form_name: 'Visa request', website: { name: 'Site B' } }
  let stopped = false
  const event = { stopPropagation() { stopped = true } }
  app.state.deleteForm(form, event)
  assert.equal(stopped, true)
  assert.equal(app.calls.deletes.length, 0)
  assert.match(app.calls.confirms[0], /all 631 submissions from Visa request on Site B/)
  app.confirm(true); app.state.deleteForm(form, event)
  assert.equal(app.calls.deletes[0].url, '/submissions/forms/2/17')
})

test('Form entries uses the same page sync and refreshes entries/name only on completion', async (t) => {
  const app = harness(t, 'pages/Submissions/FormEntries.vue'); app.mount()
  const running = app.state.syncFormSchema()
  assert.equal(app.calls.posts[0].url, '/submissions/forms/2/88/sync-schema')
  app.calls.posts[0].resolve(reply('partial', 10)); await flush()
  assert.match(app.state.syncProgress.value, /10 new/)
  app.calls.posts[1].resolve(reply('success', 5)); await running
  assert.deepEqual(plain(app.calls.reloads[0]), { only: ['entries', 'formName'] })
  assert.match(app.calls.successes[0], /15 new submission/)
  const next = app.state.syncFormSchema()
  app.routerEvents.emit('before', { detail: { visit: { async: false, url: new URL('https://example.test/orders') } } })
  app.calls.posts[2].resolve(reply('partial', 10)); await next
  assert.equal(app.calls.posts.length, 3)
  assert.equal(app.calls.reloads.length, 1)
  assert.match(app.state.syncProgress.value, /Sync stopped/)
  app.dispose(); assert.equal(app.routerEvents.size, 0)
})

test('Fluent sync rejects invalid or unsuccessful replies, including login redirects and validation errors', async (t) => {
  const { runFluentFormsSync } = submissions(t).helpers()
  for (const response of [reply('partial', -1), reply('success', '3'), reply('unknown'), { status: 200, data: '<html>Login</html>' }, { status: 200, data: { props: { flash: { error: 'Failure' } } } }]) {
    await assert.rejects(runFluentFormsSync(async () => response), /valid sync result/)
  }
  await assert.rejects(runFluentFormsSync(async () => ({ status: 422, data: { message: 'Invalid request', errors: { form_id: ['Select a valid form.'] } } })), /Select a valid form/)
  await assert.rejects(runFluentFormsSync(async () => reply('error', 0, 0, { message: 'Remote connection failed.' })), /Remote connection failed/)
  assert.deepEqual(plain(await runFluentFormsSync(async () => reply('success'))), { synced: 0, updated: 0, pages: 1 })
})

test('Fluent sync retries rate limits at most three times with a capped delay and abortable wait', async (t) => {
  const app = submissions(t), { runFluentFormsSync } = app.helpers()
  let requests = 0
  const delays = []
  const result = await runFluentFormsSync(async () => ++requests === 1 ? { status: 429, data: {}, headers: { 'retry-after': '3600' } } : reply('success', 4), undefined, { sleep: async (delay) => { delays.push(delay) } })
  assert.equal(result.synced, 4); assert.deepEqual(delays, [60000])
  requests = 0
  await assert.rejects(runFluentFormsSync(async () => { requests++; return { status: 429, data: { message: 'Too many requests' } } }, undefined, { sleep: async () => {} }), /Too many requests/)
  assert.equal(requests, 4)
  const controller = new AbortController()
  const pending = runFluentFormsSync(async () => ({ status: 429, data: {} }), undefined, { signal: controller.signal })
  await flush(); assert.equal(app.timers.pending, 1)
  controller.abort(); await assert.rejects(pending)
  assert.equal(app.timers.pending, 0)
})
