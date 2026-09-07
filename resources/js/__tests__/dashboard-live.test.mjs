import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { createRequire } from 'node:module'
import test from 'node:test'
import { runInNewContext } from 'node:vm'
import ts from 'typescript'

const require = createRequire(import.meta.url)
const vue = require('vue')
const source = (path) => readFileSync(new URL(`../${path}`, import.meta.url), 'utf8')
const flush = async () => { await vue.nextTick(); await new Promise(setImmediate) }
function deferred() {
  let resolve, reject
  const promise = new Promise((done, fail) => { resolve = done; reject = fail })
  return { promise, resolve, reject }
}
function fakeClock() {
  let now = 0, id = 0
  const tasks = new Map()
  return {
    now: () => now,
    setTimeout(callback, delay) { tasks.set(++id, { at: now + delay, callback }); return id },
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
function eventSource(extra = {}) {
  const listeners = new Map()
  return {
    ...extra,
    bind(name, callback) { if (!listeners.has(name)) listeners.set(name, new Set()); listeners.get(name).add(callback) },
    unbind(name, callback) { listeners.get(name)?.delete(callback) },
    emit(name, data) { for (const callback of listeners.get(name) || []) callback(data) },
    callbacks(name) { return [...(listeners.get(name) || [])] },
    get count() { return [...listeners.values()].reduce((sum, handlers) => sum + handlers.size, 0) },
  }
}
function snapshot(overrides = {}) {
  return {
    filters: { website_id: null, range: 'month' }, period: { start: '2026-09-01', end: '2026-09-30' },
    websites: [], summary: { orders: 3 }, revenue: [{ currency: 'USD', current: 10 }, { currency: 'EUR', current: 20 }],
    trend: [], statusBreakdown: [], websitePerformance: [], recentOrders: [], recentSubmissions: [], operations: {}, websiteHealth: [],
    generated_at: '2026-09-07T10:00:00Z', ...overrides,
  }
}

function harness(t, { delayEcho = false, deferApply = false, mount = true } = {}) {
  const clock = fakeClock()
  const mounted = [], unmounted = []
  const routerEvents = eventSource(), windowEvents = eventSource(), documentEvents = eventSource()
  const page = vue.reactive({ url: '/dashboard?range=month', props: { ...snapshot(), auth: { user: { id: 7 } }, flash: { success: 'Keep shared state' } } })
  const selected = vue.ref(null)
  const location = new URL('https://example.test/dashboard?range=month')
  const navigator = { onLine: true }
  const document = { hidden: false, addEventListener: documentEvents.bind, removeEventListener: documentEvents.unbind }
  const connection = eventSource({ state: 'connected' })
  const privateNames = [], departures = [], fetches = [], replacements = [], removedConsumers = []
  const notifications = new Map([['global-order-alerts', { handler() {} }], ['notification-bell', { handler() {} }]])
  const channels = new Map()
  const echo = {
    connector: { pusher: { connection } },
    private(name) {
      privateNames.push(name)
      if (!channels.has(name)) {
        const events = eventSource(), subscription = eventSource({ subscribed: true })
        channels.set(name, { events, subscription, listen: events.bind, stopListening: events.unbind })
      }
      return channels.get(name)
    },
    leave(name) { departures.push(name) },
  }
  const echoReady = deferred()
  const router = {
    on(name, callback) { routerEvents.bind(name, callback); return () => routerEvents.unbind(name, callback) },
    replace(options) {
      replacements.push(options)
      if (!deferApply) { page.props = options.props(page.props); options.onFinish() }
    },
  }
  const mocks = {
    vue: { ...vue, onMounted: (callback) => mounted.push(callback), onUnmounted: (callback) => unmounted.push(callback) },
    '@inertiajs/vue3': { router, usePage: () => page },
    '@/lib/echo': { getEcho: () => delayEcho ? echoReady.promise : Promise.resolve(echo) },
    '@/composables/useEchoNotifications': { useEchoNotifications: () => ({
      onNotification(key, handler, userId, onReconnect) { notifications.set(key, { handler, userId, onReconnect }) },
      offNotification(key) { removedConsumers.push(key); notifications.delete(key) },
    }) },
  }
  const cache = new Map()
  function load(path) {
    if (cache.has(path)) return cache.get(path)
    const { outputText } = ts.transpileModule(source(path), { compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 } })
    const module = { exports: {} }
    runInNewContext(outputText, {
      module, exports: module.exports, console, AbortController, URL, queueMicrotask,
      Date: class extends Date { static now() { return clock.now() } },
      setTimeout: clock.setTimeout, clearTimeout: clock.clearTimeout,
      window: { location, addEventListener: windowEvents.bind, removeEventListener: windowEvents.unbind }, document, navigator,
      fetch: (url, options) => { const response = { url, options, ...deferred() }; fetches.push(response); return response.promise },
      require: (name) => mocks[name] || (name.startsWith('@/') ? load(name.slice(2) + '.ts') : require(name)),
    })
    cache.set(path, module.exports)
    return module.exports
  }
  const scope = vue.effectScope()
  const state = scope.run(() => load('composables/useDashboardLive.ts').useDashboardLive({
    getUserId: () => page.props.auth?.user?.id,
    getWebsiteId: () => selected.value,
  }))
  let disposed = false
  const dispose = () => { if (disposed) return; disposed = true; unmounted.forEach((callback) => callback()); scope.stop() }
  t.after(dispose)
  if (mount) mounted.forEach((callback) => callback())
  return {
    state, page, selected, clock, location, navigator, document, connection, channels, privateNames, departures, fetches, replacements, notifications, removedConsumers,
    routerEvents, windowEvents, documentEvents, echo, echoReady, dispose,
    async start() { await flush(); clock.advance(250); await flush() },
    async respond(index = fetches.length - 1, data = snapshot()) { fetches[index].resolve({ ok: true, json: async () => data }); await flush() },
    apply(index = replacements.length - 1) { const options = replacements[index]; page.props = options.props(page.props); options.onFinish() },
  }
}

test('Dashboard subscribes once, updates all named props atomically, and preserves shared props and unchanged filter references', async (t) => {
  const app = harness(t)
  const filters = app.page.props.filters, auth = app.page.props.auth, flash = app.page.props.flash
  await app.start()
  assert.deepEqual(app.privateNames, ['orders.7'])
  assert.equal(app.notifications.get('dashboard-live').userId, 7)
  assert.equal(app.state.connectionState.value, 'connected')
  assert.equal(app.fetches.length, 1)
  assert.equal(app.fetches[0].url, 'https://example.test/dashboard?range=month')
  assert.equal(app.fetches[0].options.headers.Accept, 'application/json')
  assert.equal(app.fetches[0].options.headers['X-Inertia'], undefined)
  assert.equal(app.fetches[0].options.cache, 'no-store')
  await app.respond(0, snapshot({ summary: { orders: 5 }, auth: { user: { id: 99 } }, flash: { error: 'Do not merge' } }))
  assert.equal(app.replacements.length, 1)
  assert.equal(app.page.props.summary.orders, 5)
  assert.equal(app.page.props.revenue[1].currency, 'EUR')
  assert.equal(app.page.props.auth, auth)
  assert.equal(app.page.props.flash, flash)
  assert.equal(app.page.props.filters, filters)
  assert.equal(app.replacements[0].preserveState, true)
  assert.equal(app.replacements[0].preserveScroll, true)
  assert.equal(app.state.isRefreshing.value, false)
  assert.equal(app.state.refreshState.value.lastCheckedAt, 250)
})

test('Dashboard makes no idle polling requests and coalesces matching pushes, submission notifications, focus and resubscription', async (t) => {
  const app = harness(t); await app.start(); await app.respond()
  app.clock.advance(300000); await flush()
  assert.equal(app.fetches.length, 1)
  app.selected.value = 2
  const channel = app.channels.get('orders.7')
  channel.events.emit('.order.received', { website_id: 1 })
  app.notifications.get('dashboard-live').handler({ type: 'form_submission', data: { website_id: 1 } })
  app.notifications.get('dashboard-live').handler({ type: 'order', data: { website_id: 2 } })
  app.clock.advance(1000); await flush()
  assert.equal(app.fetches.length, 1)
  channel.events.emit('.order.received', { website_id: 2 }) // Backup batch has no order_id.
  channel.events.emit('.order.received', { website_id: 2, id: 55 })
  app.notifications.get('dashboard-live').handler({ type: 'form_submission', data: { website_id: 2 } })
  app.notifications.get('dashboard-live').onReconnect()
  channel.subscription.emit('pusher:subscription_succeeded')
  app.windowEvents.emit('focus')
  app.clock.advance(250); await flush()
  assert.equal(app.fetches.length, 2)
  await app.respond()
  app.clock.advance(300000); await flush()
  assert.equal(app.fetches.length, 2)
})

test('Dashboard is single-flight and queues only one follow-up during a slow response', async (t) => {
  const app = harness(t); await app.start()
  app.state.refresh(); app.state.refresh(); app.state.refresh()
  app.clock.advance(1000); await flush()
  assert.equal(app.fetches.length, 1)
  await app.respond()
  app.clock.advance(250); await flush()
  assert.equal(app.fetches.length, 2)
  await app.respond()
  app.clock.advance(300000); await flush()
  assert.equal(app.fetches.length, 2)
})

test('Dashboard pauses hidden/offline work and catches up once when visible and online', async (t) => {
  const app = harness(t); await app.start(); await app.respond()
  app.document.hidden = true; app.documentEvents.emit('visibilitychange')
  app.state.refresh(); app.clock.advance(300000); await flush()
  assert.equal(app.fetches.length, 1)
  app.navigator.onLine = false; app.windowEvents.emit('offline')
  app.document.hidden = false; app.documentEvents.emit('visibilitychange')
  assert.equal(app.state.connectionState.value, 'disconnected')
  app.clock.advance(300000); await flush(); assert.equal(app.fetches.length, 1)
  app.navigator.onLine = true; app.windowEvents.emit('online'); app.windowEvents.emit('focus')
  app.clock.advance(250); await flush(); assert.equal(app.fetches.length, 2)
})

test('Dashboard discards responses for old filters and checks again inside a deferred Inertia update', async (t) => {
  const app = harness(t, { deferApply: true }); await app.start()
  await app.respond(0, snapshot({ summary: { orders: 900 } }))
  assert.equal(app.replacements.length, 1)
  const visit = { async: false }
  app.routerEvents.emit('before', { detail: { visit } }); app.routerEvents.emit('start', { detail: { visit } })
  assert.equal(app.fetches[0].options.signal.aborted, true)
  app.location.search = '?range=week&website_id=2'
  app.page.url = '/dashboard?range=week&website_id=2'
  app.page.props.filters = { range: 'week', website_id: 2 }
  app.page.props.summary = { orders: 8 }
  app.apply(0); await flush()
  assert.equal(app.page.props.summary.orders, 8)
  app.routerEvents.emit('finish', { detail: { visit } }); app.clock.advance(250); await flush()
  assert.equal(app.fetches.length, 2)
  assert.match(app.fetches[1].url, /range=week&website_id=2/)
  await app.respond(1, snapshot({ filters: { range: 'week', website_id: 2 }, summary: { orders: 9 } }))
  app.apply(1); await flush()
  assert.equal(app.page.props.summary.orders, 9)
})

test('Dashboard history and cancelled navigation resume without refreshing another page', async (t) => {
  const app = harness(t); await app.start(); await app.respond()
  app.routerEvents.emit('before', { detail: { visit: { async: false } } })
  await flush(); app.clock.advance(250); await flush()
  assert.equal(app.fetches.length, 2)
  await app.respond()
  app.windowEvents.emit('popstate')
  app.location.search = '?range=year'; app.page.url = '/dashboard?range=year'
  app.routerEvents.emit('navigate'); app.clock.advance(250); await flush()
  assert.equal(app.fetches.length, 3)
  assert.match(app.fetches[2].url, /range=year/)
  app.windowEvents.emit('popstate')
  app.location.pathname = '/orders'; app.page.url = '/orders'
  app.routerEvents.emit('navigate'); app.clock.advance(300000); await flush()
  assert.equal(app.fetches.length, 3)
  await app.respond(2, snapshot({ summary: { orders: 900 } }))
  assert.notEqual(app.page.props.summary.orders, 900)
})

test('Dashboard timeout bounds stalled requests and deferred applies, with only three retries then external recovery', async (t) => {
  const app = harness(t); await app.start()
  for (const retryDelay of [2000, 4000, 8000]) {
    app.clock.advance(20000); await flush()
    assert.equal(app.state.refreshState.value.hasError, true)
    app.clock.advance(retryDelay); await flush()
  }
  assert.equal(app.fetches.length, 4)
  app.clock.advance(20000); await flush()
  app.clock.advance(300000); await flush(); assert.equal(app.fetches.length, 4)
  assert.ok(app.fetches.every((request) => request.options.signal.aborted))
  app.state.refresh(); app.clock.advance(0); await flush()
  await app.respond(4)
  assert.equal(app.state.refreshState.value.hasError, false)
  assert.equal(app.clock.pending, 0)
  const queued = harness(t, { deferApply: true }); await queued.start(); await queued.respond()
  queued.clock.advance(20000); await flush()
  assert.equal(queued.state.isRefreshing.value, false)
  assert.equal(queued.state.refreshState.value.hasError, true)
  queued.apply(); await flush()
  assert.equal(queued.state.refreshState.value.lastCheckedAt, null)
})

test('Dashboard rejects incomplete, invalid, and failed snapshots without changing displayed data', async (t) => {
  const app = harness(t); await app.start()
  await app.respond(0, { summary: { orders: 1000 } })
  assert.equal(app.replacements.length, 0)
  assert.equal(app.page.props.summary.orders, 3)
  app.state.refresh(); app.clock.advance(0); await flush()
  await app.respond(1, snapshot({ trend: {}, generated_at: 'invalid' }))
  assert.equal(app.replacements.length, 0)
  app.state.refresh(); app.clock.advance(0); await flush()
  app.fetches[2].resolve({ ok: false, status: 500 }); await flush()
  assert.equal(app.page.props.summary.orders, 3)
  assert.equal(app.state.refreshState.value.hasError, true)
})

test('Dashboard user changes dispose only its subscriptions and invalidate old responses', async (t) => {
  const app = harness(t); await app.start()
  const oldChannel = app.channels.get('orders.7')
  const lateOrder = oldChannel.events.callbacks('.order.received')[0]
  app.page.props.auth.user.id = 8; await flush()
  assert.deepEqual(app.privateNames, ['orders.7', 'orders.8'])
  assert.deepEqual(app.departures, ['orders.7'])
  assert.equal(app.fetches[0].options.signal.aborted, true)
  await app.respond(0, snapshot({ summary: { orders: 900 } }))
  assert.notEqual(app.page.props.summary.orders, 900)
  lateOrder({ website_id: 2 })
  app.clock.advance(250); await flush(); assert.equal(app.fetches.length, 2)
  app.dispose()
  await flush()
  assert.deepEqual(app.departures, ['orders.7', 'orders.8'])
  assert.deepEqual([...app.notifications.keys()].sort(), ['global-order-alerts', 'notification-bell'])
  assert.equal(app.routerEvents.count, 0)
  assert.equal(app.windowEvents.count, 0)
  assert.equal(app.documentEvents.count, 0)
  assert.equal(app.connection.count, 0)
  assert.equal(oldChannel.subscription.count, 0)
  assert.equal(app.clock.pending, 0)
})

test('Dashboard delayed Echo resolution after unmount never subscribes', async (t) => {
  const app = harness(t, { delayEcho: true })
  app.dispose(); app.echoReady.resolve(app.echo); await flush()
  assert.deepEqual(app.privateNames, [])
  app.clock.advance(300000); await flush(); assert.equal(app.fetches.length, 0)
})

test('Dashboard setup is inert before mount and ignores late results after unmount', async (t) => {
  const server = harness(t, { mount: false })
  server.clock.advance(300000); await flush()
  assert.deepEqual(server.privateNames, [])
  assert.equal(server.fetches.length, 0)
  const app = harness(t); await app.start(); app.dispose()
  await app.respond(0, snapshot({ summary: { orders: 900 } }))
  assert.equal(app.replacements.length, 0)
  assert.equal(app.state.isRefreshing.value, false)
  assert.equal(app.clock.pending, 0)
})
