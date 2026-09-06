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
