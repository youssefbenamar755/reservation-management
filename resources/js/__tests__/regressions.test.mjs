// Run with: node --test resources/js/__tests__/regressions.test.mjs
import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { createRequire } from 'node:module'
import test from 'node:test'
import { runInNewContext } from 'node:vm'
import { compileScript, parse } from '@vue/compiler-sfc'
import ts from 'typescript'

const require = createRequire(import.meta.url)
const source = (path) => readFileSync(new URL(`../${path}`, import.meta.url), 'utf8')

// Compile the real application modules with installed dependencies. Only Echo's
// network boundary is replaced; no browser, credentials, or server is required.
function loadModule(content, mocks = {}) {
  const { outputText } = ts.transpileModule(content, {
    compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 },
  })
  const module = { exports: {} }
  runInNewContext(outputText, {
    module,
    exports: module.exports,
    require: (name) => Object.hasOwn(mocks, name) ? mocks[name] : require(name),
    console,
    setTimeout,
    clearTimeout,
    AbortController,
  })
  return module.exports
}

for (const name of ['RevenueChart', 'OrdersChart']) {
  test(`${name} renders on the server and registers the fill plugin`, async () => {
    const { Chart, Filler } = require('chart.js')
    const { createSSRApp } = require('vue')
    const { renderToString } = require('vue/server-renderer')
    Chart.unregister(Filler)
    const { descriptor } = parse(source(`components/charts/${name}.vue`))
    const compiled = compileScript(descriptor, {
      id: name,
      inlineTemplate: true,
      templateOptions: { ssr: true },
    })
    const component = loadModule(compiled.content).default
    const html = await renderToString(createSSRApp(component, {
      data: [{ date: '2026-09-06', revenue: 50, count: 2 }],
    }))
    assert.match(html, /<canvas/)
    assert.equal(Chart.registry.getPlugin('filler'), Filler)
  })
}

function deferred() {
  let resolve
  const promise = new Promise((done) => { resolve = done })
  return { promise, resolve }
}

function fakeEcho() {
  const channels = new Map()
  const subscriptions = []
  const departures = []
  return {
    subscriptions,
    departures,
    private(name) {
      subscriptions.push(name)
      if (!channels.has(name)) {
        channels.set(name, {
          callbacks: [],
          listen(event, callback) {
            assert.equal(event, '.notification')
            this.callbacks.push(callback)
          },
        })
      }
      return channels.get(name)
    },
    leave(name) {
      departures.push(name)
      channels.delete(name)
    },
    emit(name, data) {
      channels.get(name)?.callbacks.forEach((callback) => callback(data))
    },
  }
}

const flushPromises = () => new Promise((resolve) => setImmediate(resolve))
const notifications = (getEcho) => loadModule(source('composables/useEchoNotifications.ts'), {
  '@/lib/echo': { getEcho },
}).useEchoNotifications()

test('concurrent notification consumers subscribe once and receive each event once', async () => {
  const ready = deferred()
  const echo = fakeEcho()
  const { onNotification } = notifications(() => ready.promise)
  const received = []
  for (const key of ['bell', 'orders-index', 'submissions-index']) {
    onNotification(key, () => received.push(key), 7)
  }
  ready.resolve(echo)
  await flushPromises()
  assert.deepEqual(echo.subscriptions, ['App.Models.User.7'])
  echo.emit('App.Models.User.7', { type: 'order' })
  assert.deepEqual(received, ['bell', 'orders-index', 'submissions-index'])
})

test('leaving Submissions preserves the bell and the last unmount permits a clean remount', async () => {
  const echo = fakeEcho()
  const { onNotification, offNotification } = notifications(async () => echo)
  let bellEvents = 0
  let submissionEvents = 0
  onNotification('bell', () => bellEvents++, 7)
  onNotification('submissions-index', () => submissionEvents++, 7)
  await flushPromises()
  offNotification('submissions-index')
  echo.emit('App.Models.User.7', { type: 'order' })
  assert.equal(bellEvents, 1)
  assert.equal(submissionEvents, 0)
  assert.deepEqual(echo.departures, [])

  offNotification('bell')
  assert.deepEqual(echo.departures, ['App.Models.User.7'])
  onNotification('bell', () => bellEvents++, 7)
  await flushPromises()
  echo.emit('App.Models.User.7', { type: 'order' })
  assert.equal(bellEvents, 2)
  assert.equal(echo.subscriptions.length, 2)
})

test('an unmounted consumer cannot subscribe after delayed Echo initialization', async () => {
  const ready = deferred()
  const echo = fakeEcho()
  const { onNotification, offNotification } = notifications(() => ready.promise)
  onNotification('bell', () => {}, 7)
  offNotification('bell')
  ready.resolve(echo)
  await flushPromises()
  assert.deepEqual(echo.subscriptions, [])

  onNotification('bell', () => {}, 7)
  await flushPromises()
  assert.deepEqual(echo.subscriptions, ['App.Models.User.7'])
})

test('switching users invalidates pending subscriptions and old handlers', async () => {
  const ready = deferred()
  const echo = fakeEcho()
  const { onNotification } = notifications(() => ready.promise)
  const received = []
  onNotification('old-page', () => received.push('old'), 7)
  onNotification('bell', () => received.push('new'), 8)
  ready.resolve(echo)
  await flushPromises()
  assert.deepEqual(echo.subscriptions, ['App.Models.User.8'])
  echo.emit('App.Models.User.8', { type: 'order' })
  assert.deepEqual(received, ['new'])
})

const { readWooCommerceSyncResult, runWooCommerceSync, summarizeWooCommerceSyncResults } = loadModule(source('lib/wooCommerceSync.ts'))
const syncResponse = (flash) => ({ ok: true, status: 200, json: async () => ({ props: { flash } }) })

test('sync failures in successful HTTP redirect responses are reported as failures', async () => {
  await assert.rejects(readWooCommerceSyncResult(syncResponse({ error: 'API credentials are missing.' })), /API credentials/)
  await assert.rejects(readWooCommerceSyncResult(syncResponse({ success: 'Synced.', error: 'Sync failed.' })), /Sync failed/)
  await assert.rejects(readWooCommerceSyncResult(syncResponse({ warning: 'Only some orders synced.' })), /Only some orders/)
})

test('sync requires an explicit success result, including after login redirects or invalid JSON', async () => {
  await assert.rejects(readWooCommerceSyncResult(syncResponse({})), /did not confirm/)
  await assert.rejects(readWooCommerceSyncResult({ ok: true, status: 200, json: async () => { throw new SyntaxError('HTML login page') } }), /valid sync result/)
  await assert.rejects(readWooCommerceSyncResult({ ok: false, status: 403 }), /HTTP 403/)
})

test('sync distinguishes new and updated orders, confirmed zero orders, and unknown counts', async () => {
  const changed = await readWooCommerceSyncResult(syncResponse({ success: 'Synced 12 new WooCommerce order(s); updated 3 existing order(s).' }))
  assert.equal(changed.newOrders, 12)
  assert.equal(changed.updatedOrders, 3)
  const unchanged = await readWooCommerceSyncResult(syncResponse({ success: 'Already up to date — no new orders found.' }))
  assert.equal(unchanged.newOrders, 0)
  assert.equal(unchanged.updatedOrders, 0)
  assert.equal(await readWooCommerceSyncResult(syncResponse({ success: 'Sync completed successfully.' })), null)
  assert.equal(await readWooCommerceSyncResult(syncResponse({ success: 'Synced 0 new WooCommerce order(s).' })), null)
})

test('bulk sync reports updated existing orders even when there are no new orders', async () => {
  const result = await readWooCommerceSyncResult(syncResponse({ success: 'Synced 0 new WooCommerce order(s); updated 5 existing order(s).' }))
  assert.equal(summarizeWooCommerceSyncResults([result]), 'All websites synced — 0 new order(s), 5 existing order(s) updated.')

  const anotherWebsite = await readWooCommerceSyncResult(syncResponse({ success: 'Synced 2 new WooCommerce order(s); updated 3 existing order(s).' }))
  assert.equal(summarizeWooCommerceSyncResults([result, anotherWebsite]), 'All websites synced — 2 new order(s), 8 existing order(s) updated.')
})

test('bulk sync claims up to date only when all new and updated counts are known to be zero', async () => {
  const result = await readWooCommerceSyncResult(syncResponse({ success: 'Synced 0 new WooCommerce order(s); updated 0 existing order(s).' }))
  assert.equal(summarizeWooCommerceSyncResults([result]), 'All websites synced — already up to date.')
  assert.equal(summarizeWooCommerceSyncResults([result, null]), 'All websites synced successfully.')
})

const pageResponse = (status, synced, updated, message = 'Sync progress') => ({
  ok: true, status: 200, json: async () => ({ status, synced, updated, message }),
})

test('manual sync requests successive partial pages until success and accumulates progress', async () => {
  const responses = [pageResponse('partial', 2, 3), pageResponse('partial', 0, 5), pageResponse('success', 1, 4)]
  let calls = 0
  const progress = []
  const totals = await runWooCommerceSync(async () => responses[calls++], (counts) => {
    progress.push([counts.newOrders, counts.updatedOrders])
  })
  assert.equal(calls, 3)
  assert.deepEqual(progress, [[2, 3], [2, 8], [3, 12]])
  assert.equal(totals.newOrders, 3)
  assert.equal(totals.updatedOrders, 12)
  assert.equal(summarizeWooCommerceSyncResults([totals], 'My website'), 'My website synced — 3 new order(s), 12 existing order(s) updated.')
})

test('manual sync stops and reports an error after a partial page without claiming completion', async () => {
  const responses = [pageResponse('partial', 2, 3), pageResponse('error', 0, 0, 'WooCommerce timed out.'), pageResponse('success', 5, 0)]
  let calls = 0
  const progress = []
  await assert.rejects(runWooCommerceSync(async () => responses[calls++], (counts) => {
    progress.push([counts.newOrders, counts.updatedOrders])
  }), /WooCommerce timed out/)
  assert.equal(calls, 2)
  assert.deepEqual(progress, [[2, 3]])
})

test('manual sync rejects invalid page statuses and counts', async () => {
  await assert.rejects(runWooCommerceSync(async () => pageResponse('pending', 0, 0)), /valid sync result/)
  await assert.rejects(runWooCommerceSync(async () => pageResponse('partial', -1, 0)), /valid sync result/)
  await assert.rejects(runWooCommerceSync(async () => pageResponse('success', 0, '5')), /valid sync result/)
})

test('manual sync completes after one success page and retains legacy response support', async () => {
  let calls = 0
  const result = await runWooCommerceSync(async () => {
    calls++
    return pageResponse('success', 0, 5)
  })
  assert.equal(calls, 1)
  assert.equal(result.updatedOrders, 5)
  const legacy = await runWooCommerceSync(async () => syncResponse({ success: 'Already up to date — no new orders found.' }))
  assert.equal(legacy.newOrders, 0)
  assert.equal(legacy.updatedOrders, 0)
})

test('manual sync honors Retry-After for a rate limit and then completes', async () => {
  let calls = 0
  const delays = []
  const result = await runWooCommerceSync(async () => {
    calls++
    return calls === 1
      ? { ok: false, status: 429, headers: { get: () => '2' } }
      : pageResponse('success', 2, 5)
  }, undefined, { sleep: async (milliseconds) => { delays.push(milliseconds) } })
  assert.equal(calls, 2)
  assert.deepEqual(delays, [2000])
  assert.equal(result.newOrders, 2)
  assert.equal(result.updatedOrders, 5)
})

test('manual sync caps Retry-After waits and stops after three retries', async () => {
  let calls = 0
  const delays = []
  await assert.rejects(runWooCommerceSync(async () => {
    calls++
    return { ok: false, status: 429, headers: { get: () => '3600' } }
  }, undefined, { sleep: async (milliseconds) => { delays.push(milliseconds) } }), /HTTP 429/)
  assert.equal(calls, 4)
  assert.deepEqual(delays, [60_000, 60_000, 60_000])
})

test('unmount cancellation prevents another partial-page request', async () => {
  const controller = new AbortController()
  let calls = 0
  await assert.rejects(runWooCommerceSync(async () => {
    calls++
    return pageResponse('partial', 2, 5)
  }, () => controller.abort(), { signal: controller.signal }), { name: 'AbortError' })
  assert.equal(calls, 1)
})

test('cancellation during a rate-limit delay prevents another request', async () => {
  const controller = new AbortController()
  let calls = 0
  await assert.rejects(runWooCommerceSync(async () => {
    calls++
    return { ok: false, status: 429, headers: { get: () => '2' } }
  }, undefined, {
    signal: controller.signal,
    sleep: async () => controller.abort(),
  }), { name: 'AbortError' })
  assert.equal(calls, 1)
})

test('the default retry timer rejects immediately when navigation aborts the sync', async () => {
  const controller = new AbortController()
  let calls = 0
  const task = runWooCommerceSync(async () => {
    calls++
    return { ok: false, status: 429, headers: { get: () => '60' } }
  }, undefined, { signal: controller.signal })
  const rejection = assert.rejects(task, { name: 'AbortError' })
  await flushPromises()
  controller.abort()
  await rejection
  assert.equal(calls, 1)
})

const { createAutoRefresh, refreshOrdersSnapshot } = loadModule(source('lib/liveOrders.ts'))

function fakeClock() {
  let time = 0
  let nextId = 0
  const timers = new Map()
  return {
    now: () => time,
    setTimer(callback, delay) {
      const id = ++nextId
      timers.set(id, { at: time + delay, callback })
      return id
    },
    clearTimer: (id) => timers.delete(id),
    count: () => timers.size,
    tick(milliseconds) {
      const target = time + milliseconds
      while (true) {
        const next = [...timers.entries()].sort((a, b) => a[1].at - b[1].at)[0]
        if (!next || next[1].at > target) break
        time = next[1].at
        timers.delete(next[0])
        next[1].callback()
      }
      time = target
    },
  }
}

function autoRefreshFixture() {
  const clock = fakeClock()
  const requests = []
  const states = []
  let available = true
  const coordinator = createAutoRefresh({
    ...clock,
    isAvailable: () => available,
    onState: (state) => states.push(state),
    refresh(request) {
      const entry = { ...request, cancelled: false }
      requests.push(entry)
      return () => {
        entry.cancelled = true
        request.complete('cancelled')
      }
    },
  })
  return { clock, coordinator, requests, states, setAvailable: (value) => { available = value } }
}

test('automatic refresh coalesces push bursts and queues only one follow-up during a slow request', () => {
  const { coordinator, clock, requests } = autoRefreshFixture()
  coordinator.start()
  coordinator.request()
  coordinator.request()
  clock.tick(249)
  assert.equal(requests.length, 0)
  clock.tick(1)
  assert.equal(requests.length, 1)
  coordinator.request()
  coordinator.request()
  clock.tick(30_000)
  assert.equal(requests.length, 1)
  requests[0].complete('success')
  clock.tick(250)
  assert.equal(requests.length, 2)
  requests[1].complete('success')
  clock.tick(600_000)
  assert.equal(requests.length, 2)
  assert.equal(clock.count(), 0)
})

test('idle and successfully refreshed pages make no periodic requests over several minutes', () => {
  const { coordinator, clock, requests, states } = autoRefreshFixture()
  coordinator.start()
  clock.tick(300_000)
  assert.equal(requests.length, 0)
  assert.equal(clock.count(), 0)
  coordinator.request(0)
  clock.tick(0)
  assert.equal(requests.length, 1)
  clock.tick(35_000)
  assert.equal(requests.length, 1)
  requests[0].complete('success')
  assert.equal(states.at(-1).lastCheckedAt, 335_000)
  clock.tick(600_000)
  assert.equal(requests.length, 1)
  assert.equal(clock.count(), 0)
})

test('navigation cancels the old request and stale callbacks cannot finish its replacement', () => {
  const { coordinator, clock, requests, states } = autoRefreshFixture()
  coordinator.start()
  coordinator.request(0)
  clock.tick(0)
  coordinator.suspend()
  assert.equal(requests[0].cancelled, true)
  assert.equal(requests[0].isCurrent(), false)
  coordinator.request()
  clock.tick(30_000)
  assert.equal(requests.length, 1)
  coordinator.resume()
  clock.tick(250)
  assert.equal(requests.length, 2)
  requests[0].complete('success')
  assert.equal(states.at(-1).refreshing, true)
  assert.equal(states.at(-1).lastCheckedAt, null)
  requests[1].complete('success')
  assert.equal(states.at(-1).refreshing, false)
})

test('hidden or offline pages stop refreshing and immediately catch up when available again', () => {
  const { coordinator, clock, requests, setAvailable } = autoRefreshFixture()
  coordinator.start()
  coordinator.request(0)
  clock.tick(0)
  setAvailable(false)
  coordinator.availabilityChanged()
  assert.equal(requests[0].cancelled, true)
  coordinator.request()
  clock.tick(120_000)
  assert.equal(requests.length, 1)
  setAvailable(true)
  coordinator.availabilityChanged()
  coordinator.availabilityChanged()
  clock.tick(250)
  assert.equal(requests.length, 2)
})

test('unmount clears pending refreshes and invalidates responses even if cancellation completes late', () => {
  const { coordinator, clock, requests } = autoRefreshFixture()
  coordinator.start()
  coordinator.request(0)
  clock.tick(0)
  coordinator.stop()
  requests[0].complete('success')
  coordinator.request()
  coordinator.resume()
  clock.tick(120_000)
  assert.equal(requests.length, 1)
  assert.equal(requests[0].isCurrent(), false)
  assert.equal(clock.count(), 0)
})

test('failed checks retry at most three times and then wait for a new external trigger', () => {
  const { coordinator, clock, requests, states } = autoRefreshFixture()
  coordinator.start()
  coordinator.request(0)
  clock.tick(0)
  requests[0].complete('error')
  assert.equal(states.at(-1).hasError, true)
  assert.equal(states.at(-1).lastCheckedAt, null)
  clock.tick(1_999)
  assert.equal(requests.length, 1)
  clock.tick(1)
  requests[1].complete('error')
  clock.tick(4_000)
  requests[2].complete('error')
  clock.tick(8_000)
  requests[3].complete('error')
  clock.tick(600_000)
  assert.equal(requests.length, 4)
  assert.equal(clock.count(), 0)
  coordinator.availabilityChanged() // A new focus/online event permits another attempt.
  clock.tick(250)
  assert.equal(requests.length, 5)
  requests[4].complete('success')
  assert.equal(states.at(-1).hasError, false)
  clock.tick(600_000)
  assert.equal(requests.length, 5)
})

const ordersResponse = (orders = { data: [{ id: 42 }], current_page: 3, total: 60 }) => ({
  ok: true, status: 200, json: async () => ({ orders }),
})

test('automatic orders fetch retains the full current filters and pagination without an Inertia request', async () => {
  const url = 'https://example.test/orders?website_id=2&status=processing&search=Alice&page=3'
  const controller = new AbortController()
  let applied = null
  const result = await refreshOrdersSnapshot({
    getUrl: () => url,
    isCurrent: () => true,
    signal: controller.signal,
    fetch: async (requestUrl, options) => {
      assert.equal(requestUrl, url)
      assert.equal(options.headers.Accept, 'application/json')
      assert.equal(options.headers['X-Inertia'], undefined)
      assert.equal(options.signal.aborted, false)
      assert.equal(options.cache, 'no-store')
      return ordersResponse()
    },
    apply: async (orders, isCurrent) => {
      assert.equal(isCurrent(), true)
      applied = orders
      return true
    },
  })
  assert.equal(result, 'success')
  assert.equal(applied.current_page, 3)
})

test('a response for old filters is discarded even when its request ignored cancellation', async () => {
  let url = 'https://example.test/orders?search=old&page=2'
  const ready = deferred()
  let applies = 0
  const task = refreshOrdersSnapshot({
    getUrl: () => url,
    isCurrent: () => true,
    signal: new AbortController().signal,
    fetch: async () => ready.promise,
    apply: async () => { applies++; return true },
  })
  url = 'https://example.test/orders?search=new&page=1'
  ready.resolve(ordersResponse())
  assert.equal(await task, 'cancelled')
  assert.equal(applies, 0)
})

test('the deferred prop updater rechecks navigation generation before applying an orders snapshot', async () => {
  let current = true
  const queued = deferred()
  let applyGuard
  let replaced = false
  const task = refreshOrdersSnapshot({
    getUrl: () => 'https://example.test/orders?page=3',
    isCurrent: () => current,
    signal: new AbortController().signal,
    fetch: async () => ordersResponse(),
    apply: async (_orders, guard) => {
      applyGuard = guard
      await queued.promise
      replaced = guard()
      return replaced
    },
  })
  await flushPromises()
  assert.equal(applyGuard(), true)
  current = false
  queued.resolve()
  assert.equal(await task, 'cancelled')
  assert.equal(replaced, false)
})

test('invalid or failed automatic responses never replace the existing order table', async () => {
  for (const response of [
    { ok: false, status: 500 },
    ordersResponse({ data: [], current_page: 0, total: 0 }),
    { ok: true, status: 200, json: async () => { throw new Error('HTML login page') } },
  ]) {
    let applies = 0
    const result = await refreshOrdersSnapshot({
      getUrl: () => 'https://example.test/orders',
      isCurrent: () => true,
      signal: new AbortController().signal,
      fetch: async () => response,
      apply: async () => { applies++; return true },
    })
    assert.equal(result, 'error')
    assert.equal(applies, 0)
  }
})

test('a stalled orders fetch times out and recovers on its bounded retry without starting polling', async () => {
  const clock = fakeClock()
  const states = []
  let calls = 0
  const coordinator = createAutoRefresh({
    ...clock,
    isAvailable: () => true,
    onState: (state) => states.push(state),
    refresh({ isCurrent, complete }) {
      const controller = new AbortController()
      void refreshOrdersSnapshot({
        ...clock,
        getUrl: () => 'https://example.test/orders?page=2',
        isCurrent,
        signal: controller.signal,
        fetch: async (_url, { signal }) => {
          calls++
          if (calls > 1) return ordersResponse()
          return new Promise((_resolve, reject) => {
            signal.addEventListener('abort', () => reject(new Error('Aborted stalled HTTP request')), { once: true })
          })
        },
        apply: async (_orders, current) => current(),
      }).then(complete)
      return () => controller.abort()
    },
  })
  coordinator.start()
  coordinator.request(0)
  clock.tick(0)
  assert.equal(calls, 1)
  clock.tick(20_000)
  await flushPromises()
  assert.equal(states.at(-1).refreshing, false)
  assert.equal(states.at(-1).hasError, true)
  clock.tick(2_000)
  await flushPromises()
  assert.equal(calls, 2)
  assert.equal(states.at(-1).hasError, false)
  assert.equal(states.at(-1).lastCheckedAt, 22_000)
  clock.tick(600_000)
  assert.equal(calls, 2)
  coordinator.stop()
  assert.equal(clock.count(), 0)
})

const { subscribeToOrders } = loadModule(source('lib/ordersPush.ts'))

function eventSource(extra = {}) {
  const handlers = new Map()
  return {
    ...extra,
    bind(event, handler) {
      if (!handlers.has(event)) handlers.set(event, new Set())
      handlers.get(event).add(handler)
    },
    unbind(event, handler) { handlers.get(event)?.delete(handler) },
    emit(event, value) { handlers.get(event)?.forEach((handler) => handler(value)) },
    callbacks: (event) => [...(handlers.get(event) ?? [])],
    count: () => [...handlers.values()].reduce((count, values) => count + values.size, 0),
  }
}

function pushEchoFixture() {
  const connection = eventSource({ state: 'connected' })
  const subscription = eventSource({ subscribed: false })
  const changes = eventSource()
  const names = []
  const departures = []
  const echo = {
    connector: { pusher: { connection } },
    private(name) {
      names.push(name)
      return { subscription, listen: changes.bind, stopListening: changes.unbind }
    },
    leave: (name) => departures.push(name),
  }
  return { echo, connection, subscription, changes, names, departures }
}

test('live status requires confirmed private subscription and changes on connection or authorization failure', async () => {
  const { echo, connection, subscription } = pushEchoFixture()
  const states = []
  let checks = 0
  const stop = subscribeToOrders({
    userId: 7,
    getEcho: async () => echo,
    onState: (state) => states.push(state),
    onSubscribed: () => checks++,
    onOrder: () => {},
  })
  await flushPromises()
  assert.equal(states.at(-1), 'connecting')
  assert.equal(checks, 0)
  connection.emit('state_change', { current: 'connected' })
  assert.equal(states.at(-1), 'connecting')
  subscription.emit('pusher:subscription_succeeded')
  assert.equal(states.at(-1), 'connected')
  assert.equal(checks, 1)
  connection.emit('state_change', { current: 'unavailable' })
  assert.equal(states.at(-1), 'disconnected')
  connection.emit('state_change', { current: 'connecting' })
  assert.equal(states.at(-1), 'reconnecting')
  connection.emit('state_change', { current: 'connected' })
  assert.equal(states.at(-1), 'reconnecting')
  subscription.emit('pusher:subscription_succeeded')
  assert.equal(states.at(-1), 'connected')
  assert.equal(checks, 2)
  subscription.emit('pusher:subscription_error')
  assert.equal(states.at(-1), 'disconnected')
  stop()
})

test('subscription, push, and focus refreshes coalesce and resubscription recovers a missed push', async () => {
  const { echo, connection, subscription, changes } = pushEchoFixture()
  const { coordinator, clock, requests } = autoRefreshFixture()
  coordinator.start()
  const stop = subscribeToOrders({
    userId: 7,
    getEcho: async () => echo,
    onState: () => {},
    onSubscribed: () => coordinator.request(),
    onOrder: () => coordinator.request(),
  })
  await flushPromises()
  subscription.emit('pusher:subscription_succeeded')
  changes.emit('.order.received', { website_id: 2 })
  coordinator.availabilityChanged() // Focus at the same time as subscription/push.
  clock.tick(250)
  assert.equal(requests.length, 1)
  requests[0].complete('success')
  clock.tick(600_000)
  assert.equal(requests.length, 1)

  connection.emit('state_change', { current: 'disconnected' })
  connection.emit('state_change', { current: 'connected' })
  clock.tick(5_000)
  assert.equal(requests.length, 1)
  // No order event was delivered while disconnected; confirmation triggers a check.
  subscription.emit('pusher:subscription_succeeded')
  clock.tick(250)
  assert.equal(requests.length, 2)
  requests[1].complete('success')
  clock.tick(600_000)
  assert.equal(requests.length, 2)
  assert.equal(clock.count(), 0)
  stop()
  coordinator.stop()
})

test('push cleanup removes exact subscription/connection handlers and ignores late callbacks', async () => {
  const { echo, connection, subscription, changes, names, departures } = pushEchoFixture()
  let notifications = 0
  let checks = 0
  const states = []
  const stop = subscribeToOrders({
    userId: 7,
    getEcho: async () => echo,
    onState: (state) => states.push(state),
    onSubscribed: () => checks++,
    onOrder: () => notifications++,
  })
  await flushPromises()
  const lateSubscribed = subscription.callbacks('pusher:subscription_succeeded')[0]
  const lateOrder = changes.callbacks('.order.received')[0]
  assert.deepEqual(names, ['orders.7'])
  stop()
  const stateCount = states.length
  lateSubscribed()
  lateOrder({ website_id: 2 })
  connection.emit('state_change', { current: 'connected' })
  assert.equal(checks, 0)
  assert.equal(notifications, 0)
  assert.equal(states.length, stateCount)
  assert.equal(connection.count(), 0)
  assert.equal(subscription.count(), 0)
  assert.equal(changes.count(), 0)
  assert.deepEqual(departures, ['orders.7'])
})

test('delayed Echo initialization never subscribes after unmount', async () => {
  const ready = deferred()
  const { echo, names, departures } = pushEchoFixture()
  const stop = subscribeToOrders({
    userId: 7,
    getEcho: () => ready.promise,
    onState: () => {},
    onSubscribed: () => assert.fail('Unmounted page must not refresh'),
    onOrder: () => assert.fail('Unmounted page must not receive orders'),
  })
  stop()
  ready.resolve(echo)
  await flushPromises()
  assert.deepEqual(names, [])
  assert.deepEqual(departures, [])
})

test('an already-authorized channel checks once and unavailable Echo reports disconnected', async () => {
  const { echo, subscription } = pushEchoFixture()
  subscription.subscribed = true
  const states = []
  let checks = 0
  const stop = subscribeToOrders({ userId: 7, getEcho: async () => echo,
    onState: (state) => states.push(state), onSubscribed: () => checks++, onOrder: () => {} })
  await flushPromises()
  assert.equal(states.at(-1), 'connected')
  assert.equal(checks, 1)
  stop()
  subscribeToOrders({ userId: 7, getEcho: async () => null,
    onState: (state) => states.push(state), onSubscribed: () => assert.fail('No subscription'), onOrder: () => {} })()
  await flushPromises()
  // Disposed handlers remain silent; a mounted unavailable connection reports failure.
  const stopMissing = subscribeToOrders({ userId: 7, getEcho: async () => null,
    onState: (state) => states.push(state), onSubscribed: () => assert.fail('No subscription'), onOrder: () => {} })
  await flushPromises()
  assert.equal(states.at(-1), 'disconnected')
  stopMissing()
})

test('navigation abort propagates to the HTTP request and clears its deadline without a retry error', async () => {
  const clock = fakeClock()
  const controller = new AbortController()
  let fetchSignal
  const task = refreshOrdersSnapshot({
    ...clock,
    getUrl: () => 'https://example.test/orders',
    isCurrent: () => true,
    signal: controller.signal,
    fetch: async (_url, { signal }) => {
      fetchSignal = signal
      return new Promise((_resolve, reject) => {
        signal.addEventListener('abort', () => reject(new Error('Navigation aborted')), { once: true })
      })
    },
    apply: async () => { assert.fail('Aborted data must not be applied') },
  })
  controller.abort()
  assert.equal(await task, 'cancelled')
  assert.equal(fetchSignal.aborted, true)
  assert.equal(clock.count(), 0)
})
