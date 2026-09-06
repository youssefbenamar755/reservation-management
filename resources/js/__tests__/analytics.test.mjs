import assert from 'node:assert/strict'
import { readFileSync } from 'node:fs'
import { createRequire } from 'node:module'
import test from 'node:test'
import { runInNewContext } from 'node:vm'
import ts from 'typescript'
import { compileScript, parse } from '@vue/compiler-sfc'

const require = createRequire(import.meta.url)
const vue = require('vue')
const source = (path) => readFileSync(new URL(`../${path}`, import.meta.url), 'utf8')
function loadSetup(path, mocks = {}) {
  const { descriptor } = parse(source(path))
  const compiled = compileScript(descriptor, { id: path })
  const { outputText } = ts.transpileModule(compiled.content, {
    compilerOptions: { module: ts.ModuleKind.CommonJS, target: ts.ScriptTarget.ES2022 },
  })
  const module = { exports: {} }
  runInNewContext(outputText, {
    module, exports: module.exports,
    require: (name) => Object.hasOwn(mocks, name) ? mocks[name] : name.startsWith('@/') ? {} : require(name),
  })
  return module.exports.default
}

function analytics() {
  const visits = []
  const component = loadSetup('pages/Analytics.vue', {
    '@inertiajs/vue3': { router: { get: (url, data, options) => visits.push({ url, data, options }) } },
  })
  const props = vue.reactive({
    filters: { start_date: '2026-09-01', end_date: '2026-09-06', website_ids: [1], payment_status: '' },
    ordersByHour: [{ hour: 13, count: 4 }],
    ordersByDayOfWeek: [{ day: 'Tuesday', day_number: 2, count: 2 }, { day: 'Monday', day_number: 1, count: 3 }],
  })
  const scope = vue.effectScope()
  const state = scope.run(() => component.setup(props, { expose() {} }))
  return { props, state, visits, stop: () => scope.stop() }
}

test('editing Analytics filters retains chart data references until their source actually changes', () => {
  const page = analytics()
  const hours = page.state.hourlyChartData.value
  const weekdays = page.state.weekdayChartData.value
  page.state.startDate.value = '2026-08-01'
  page.state.toggleWebsite(2)
  assert.equal(page.state.hourlyChartData.value, hours)
  assert.equal(page.state.weekdayChartData.value, weekdays)
  assert.equal(hours[0].hour, '1:00 PM')
  assert.deepEqual(Array.from(weekdays, (item) => item.day), ['Monday', 'Tuesday'])
  assert.equal(page.props.ordersByDayOfWeek[0].day, 'Tuesday') // Never sort the server prop in place.
  page.props.ordersByHour = [{ hour: 14, count: 7 }]
  assert.notEqual(page.state.hourlyChartData.value, hours)
  assert.equal(page.state.hourlyChartData.value[0].count, 7)
  page.stop()
})

test('Analytics filter visits retain chart instances and follow returned/history filters', async () => {
  const page = analytics()
  page.state.startDate.value = '2026-08-01'
  page.state.applyFilters()
  page.state.applyFilters()
  assert.equal(page.visits.length, 1)
  assert.equal(page.visits[0].url, '/analytics')
  assert.equal(page.visits[0].data.start_date, '2026-08-01')
  assert.equal(page.visits[0].options.preserveState, true)
  assert.equal(page.state.isLoading.value, true)
  const before = page.state.lastRefresh.value
  page.visits[0].options.onFinish() // A cancelled/failed visit must not claim fresh data.
  assert.equal(page.state.isLoading.value, false)
  assert.equal(page.state.lastRefresh.value, before)
  page.props.filters = { start_date: '2026-07-01', end_date: '2026-07-31', website_ids: ['2'], payment_status: 'completed' }
  await vue.nextTick()
  assert.equal(page.state.startDate.value, '2026-07-01')
  assert.equal(page.state.endDate.value, '2026-07-31')
  assert.deepEqual(Array.from(page.state.selectedWebsiteIds.value), [2])
  assert.equal(page.state.paymentStatus.value, 'completed')
  page.state.applyFilters()
  page.visits[1].options.onSuccess()
  assert.notEqual(page.state.lastRefresh.value, before)
  page.visits[1].options.onFinish()
  page.stop()
})

test('Analytics charts keep complete data and draw without the default one-second animation', () => {
  for (const name of ['RevenueChart', 'OrdersChart', 'BarChart', 'PieChart']) {
    const component = loadSetup(`components/charts/${name}.vue`)
    const props = { data: [{ date: '2026-09-01', name: 'One', revenue: 50, count: 3 }, { date: '2026-09-02', name: 'Two', revenue: 25, count: 2 }], labelKey: 'name', valueKey: 'count', currency: 'USD' }
    const state = component.setup(props, { expose() {} })
    assert.equal(state.chartOptions.value.animation, false, name)
    assert.equal(state.chartData.value.labels.length, 2, name)
    assert.equal(state.chartData.value.datasets[0].data.length, 2, name)
  }
})
