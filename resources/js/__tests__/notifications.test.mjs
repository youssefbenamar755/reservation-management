import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { createRequire } from 'node:module'
import test from 'node:test'
import { runInNewContext } from 'node:vm'
import ts from 'typescript'
import { compileScript, parse } from '@vue/compiler-sfc'

const require = createRequire(import.meta.url)
const source = (path) => readFileSync(new URL(`../${path}`, import.meta.url), 'utf8')
function load(content, mocks = {}, globals = {}) {
  const { outputText } = ts.transpileModule(content, {
    compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 },
  })
  const module = { exports: {} }
  runInNewContext(outputText, {
    module, exports: module.exports,
    require: (name) => Object.hasOwn(mocks, name) ? mocks[name] : require(name),
    console, setTimeout, clearTimeout, AbortController, ...globals,
  })
  return module.exports
}
const helpers = load(source('lib/notifications.ts'))
const { normalizeNotification, createNotificationFeed } = helpers
const raw = (id, fields = {}) => ({ id, type: 'order', message: `Order ${id}`, created_at: '2026-09-06T09:00:00Z', data: { type: 'order', order_id: 5 }, ...fields })
const notification = (id, fields) => normalizeNotification(raw(id, fields))
const flush = () => new Promise((done) => setImmediate(done))
function deferred() {
  let resolve
  const promise = new Promise((done) => { resolve = done })
  return { promise, resolve }
}

function feed(initial = 0) {
  let state
  return { api: createNotificationFeed(initial, (next) => { state = next }), state: () => state }
}

test('notifications normalize legacy Laravel types, submissions, and stable IDs', () => {
  const order = normalizeNotification(raw('uuid', { type: 'App\\Notifications\\NewOrderNotification' }))
  assert.equal(order.type, 'order')
  assert.equal(order.data.type, 'order')
  const form = normalizeNotification({ id: 'form', type: 'App\\Notifications\\NewFormSubmissionNotification', data: { submission_id: 9 } })
  assert.equal(form.type, 'form_submission')
  assert.equal(form.data.type, 'form_submission')
  assert.equal(form.data.submission_id, 9)
  assert.equal(normalizeNotification({ type: 'order' }), null)
  assert.equal(normalizeNotification({ id: '' }), null)
})

test('authoritative snapshots can lower a previously higher prop count to zero', () => {
  const { api, state } = feed(12)
  api.applySnapshot({ notifications: [], unread_count: 0 }, api.revision)
  assert.equal(state().unreadCount, 0)
  api.receive(notification('fresh'))
  assert.equal(state().unreadCount, 1)
})

test('snapshot and push duplicates produce one row and one unread increment', () => {
  const { api, state } = feed()
  api.applySnapshot({ notifications: [raw('known'), raw('known')], unread_count: 1 }, api.revision)
  assert.equal(api.receive(notification('known')), false)
  assert.equal(state().notifications.length, 1)
  assert.equal(state().unreadCount, 1)
  assert.equal(api.receive(notification('new')), true)
  assert.equal(api.receive(notification('new')), false)
  assert.equal(state().notifications.length, 2)
  assert.equal(state().unreadCount, 2)
})

test('a push during GET invalidates that response and a fresh snapshot reconciles it', () => {
  const { api, state } = feed(3)
  const beforePush = api.revision
  api.receive(notification('new'))
  assert.equal(api.applySnapshot({ notifications: [], unread_count: 3 }, beforePush), false)
  assert.equal(state().unreadCount, 4)
  assert.equal(state().notifications[0].id, 'new')
  api.applySnapshot({ notifications: [raw('new')], unread_count: 4 }, api.revision)
  assert.equal(state().unreadCount, 4)
})

test('read mutations invalidate old GETs and use the returned authoritative count', () => {
  const { api, state } = feed()
  api.applySnapshot({ notifications: [raw('old')], unread_count: 10 }, api.revision)
  const oldRequest = api.revision
  const checkpoint = api.beginRead()
  assert.equal(api.applySnapshot({ notifications: [raw('old')], unread_count: 10 }, oldRequest), false)
  api.finishRead(checkpoint, 'old', 9)
  assert.equal(state().unreadCount, 9)
  assert.ok(state().notifications[0].read_at)
  const all = api.beginRead()
  api.finishRead(all, null, 1) // A new server-side notification after the bulk update.
  assert.equal(state().unreadCount, 1)
})

test('mark-all preserves a push arriving during the POST until its next snapshot', () => {
  const { api, state } = feed()
  api.applySnapshot({ notifications: [raw('old')], unread_count: 5 }, api.revision)
  const checkpoint = api.beginRead()
  api.receive(notification('new'))
  api.finishRead(checkpoint, null, 0)
  assert.equal(state().unreadCount, 1)
  assert.equal(state().notifications[0].read_at, null)
  assert.ok(state().notifications[1].read_at)
  api.applySnapshot({ notifications: [raw('new')], unread_count: 1 }, api.revision)
  assert.equal(state().unreadCount, 1)
})

function eventSource() {
  const events = new Map()
  return {
    events,
    bind(event, handler) { if (!events.has(event)) events.set(event, new Set()); events.get(event).add(handler) },
    unbind(event, handler) { events.get(event)?.delete(handler) },
    emit(event, data) { [...(events.get(event) || [])].forEach((handler) => handler(data)) },
  }
}
function echoHarness() {
  const subscription = eventSource()
  const orders = eventSource()
  const departures = []
  const channel = { subscription, listen: orders.bind, stopListening: orders.unbind }
  const echo = { private: () => channel, leave: (name) => departures.push(name) }
  return { echo, subscription, orders, departures }
}
function transport(getEcho, globals) {
  return load(source('composables/useEchoNotifications.ts'), { '@/lib/echo': { getEcho }, '@/lib/notifications': helpers }, globals).useEchoNotifications()
}

test('shared transport deduplicates custom/standard events and isolates consumer failures', async () => {
  const { echo, orders } = echoHarness()
  let failures = 0
  const api = transport(async () => echo, { console: { error: () => failures++ } })
  const received = []
  api.onNotification('broken', () => { throw new Error('consumer') }, 7)
  api.onNotification('bell', (data) => received.push(data), 7)
  await flush()
  const payload = raw('same', { type: 'App\\Notifications\\NewOrderNotification' })
  orders.emit('.notification', payload)
  orders.emit('.Illuminate\\Notifications\\Events\\BroadcastNotificationCreated', payload)
  assert.equal(received.length, 1)
  assert.equal(received[0].type, 'order')
  assert.equal(failures, 1)
  api.offNotification('broken')
  api.offNotification('bell')
})

test('confirmed subscription and resubscription refresh consumers, with exact cleanup', async () => {
  const { echo, subscription, orders, departures } = echoHarness()
  const api = transport(async () => echo)
  let reconnects = 0
  let deliveries = 0
  api.onNotification('bell', () => deliveries++, 7, () => reconnects++)
  await flush()
  assert.equal(reconnects, 0)
  subscription.emit('pusher:subscription_succeeded')
  subscription.emit('pusher:subscription_succeeded')
  assert.equal(reconnects, 2)
  const lateEvent = [...orders.events.get('.notification')][0]
  const lateSubscription = [...subscription.events.get('pusher:subscription_succeeded')][0]
  api.offNotification('bell')
  assert.equal(orders.events.get('.notification').size, 0)
  assert.equal(subscription.events.get('pusher:subscription_succeeded').size, 0)
  assert.deepEqual(departures, ['App.Models.User.7'])
  lateEvent(raw('late'))
  lateSubscription()
  assert.equal(deliveries, 0)
  assert.equal(reconnects, 2)
})

test('a cached authorized notification channel immediately requests reconciliation', async () => {
  const { echo, subscription } = echoHarness()
  subscription.subscribed = true
  const api = transport(async () => echo)
  let checks = 0
  api.onNotification('bell', () => {}, 7, () => checks++)
  await flush()
  assert.equal(checks, 1)
  api.offNotification('bell')
})

// Exercise the real bell setup with its network and browser boundaries replaced.
function bellHarness() {
  const vue = require('vue')
  const mount = []
  const unmount = []
  const windowEvents = eventSource()
  const documentEvents = eventSource()
  const window = { addEventListener: windowEvents.bind, removeEventListener: windowEvents.unbind, dispatchEvent: (event) => windowEvents.emit(event.type) }
  const document = { visibilityState: 'visible', addEventListener: documentEvents.bind, removeEventListener: documentEvents.unbind }
  const requests = []
  const visits = []
  let refreshOptions
  let cancelled = null
  let stopped = false
  let requestsScheduled = 0
  let notificationHandler
  let reconnectHandler
  const coordinator = {
    start() {}, stop() { stopped = true; cancelled?.() },
    request() { requestsScheduled++ },
    availabilityChanged() { requestsScheduled++ },
    suspend() { cancelled?.() }, resume() { requestsScheduled++ },
  }
  const startRefresh = () => {
    let current = true
    const stop = refreshOptions.refresh({ isCurrent: () => current && !stopped, complete: () => {} })
    cancelled = () => { current = false; stop() }
  }
  const get = (url, config) => { const result = deferred(); requests.push({ method: 'GET', url, config, result }); return result.promise }
  const post = (url, body, config) => { const result = deferred(); requests.push({ method: 'POST', url, body, config, result }); return result.promise }
  const mocks = {
    vue: { ...vue, onMounted: (fn) => mount.push(fn), onUnmounted: (fn) => unmount.push(fn), useId: () => 'test' },
    '@inertiajs/vue3': { usePage: () => ({ props: { auth: { user: { id: 7 } }, notifications: { unread_count: 9 } } }), router: { visit: (url) => visits.push(url) } },
    axios: { default: { get, post } },
    '@/lib/liveOrders': { createAutoRefresh: (options) => { refreshOptions = options; return coordinator } },
    '@/lib/notifications': helpers,
    '@/composables/useEchoNotifications': { useEchoNotifications: () => ({ onNotification: (_key, handler, _id, reconnect) => { notificationHandler = handler; reconnectHandler = reconnect }, offNotification() {} }) },
    '@/components/ui/button': {}, '@/components/ui/badge': {}, '@/components/ui/dropdown-menu': {},
    '@/components/OrderNotificationSound.vue': {}, 'lucide-vue-next': {},
  }
  const compiled = compileScript(parse(source('components/NotificationBell.vue')).descriptor, { id: 'bell-test' })
  const component = load(compiled.content, mocks, { window, document, navigator: { onLine: true }, Event: class { constructor(type) { this.type = type } } }).default
  const state = component.setup({}, { expose() {} })
  mount.forEach((callback) => callback())
  return { state, requests, visits, startRefresh, reconnect: () => reconnectHandler(), push: (data) => notificationHandler(data), windowEvents, documentEvents, scheduled: () => requestsScheduled, unmount: () => unmount.forEach((callback) => callback()) }
}

test('bell reads without page reload, retries on focus/reconnect, and cleans up aborted GET', async () => {
  const bell = bellHarness()
  bell.startRefresh()
  const request = bell.requests[0]
  assert.equal(request.url, '/notifications')
  assert.equal(request.config.timeout, 20_000)
  request.result.resolve({ data: { notifications: [], unread_count: 0 } })
  await flush()
  assert.equal(bell.state.unreadCount.value, 0)
  const before = bell.scheduled()
  bell.reconnect()
  bell.windowEvents.emit('focus')
  assert.equal(bell.scheduled(), before + 2)
  bell.startRefresh()
  bell.unmount()
  assert.equal(bell.requests[1].config.signal.aborted, true)
  bell.requests[1].result.resolve({ data: { notifications: [raw('late')], unread_count: 8 } })
  await flush()
  assert.equal(bell.state.unreadCount.value, 0)
  assert.equal(bell.windowEvents.events.get('focus').size, 0)
  assert.equal(bell.documentEvents.events.get('visibilitychange').size, 0)
  assert.deepEqual(bell.visits, [])
})

test('bell mark-read uses cookie-aware axios, authoritative counts, and does not mutate on failure', async () => {
  const bell = bellHarness()
  bell.push(notification('one'))
  const clicked = bell.state.notifications.value[0]
  const action = bell.state.markRead(clicked)
  const request = bell.requests[0]
  assert.equal(request.method, 'POST')
  assert.equal(request.url, '/notifications/one/read')
  assert.equal(request.config.headers['X-CSRF-TOKEN'], undefined)
  request.result.resolve({ data: { success: true, unread_count: 0, redirect_url: '/orders/5' } })
  await action
  assert.equal(bell.state.unreadCount.value, 0)
  assert.ok(bell.state.notifications.value[0].read_at)
  assert.deepEqual(bell.visits, ['/orders/5'])
  bell.push(notification('two'))
  const failedAction = bell.state.markRead(bell.state.notifications.value[0])
  bell.requests[1].result.resolve({ data: { success: false } })
  await failedAction
  assert.equal(bell.state.unreadCount.value, 1)
  assert.equal(bell.state.notifications.value[0].read_at, null)
  assert.match(bell.state.errorMessage.value, /Could not mark/)
  bell.unmount()
})
