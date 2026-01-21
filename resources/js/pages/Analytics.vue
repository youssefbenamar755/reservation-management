<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue'
import { type BreadcrumbItem } from '@/types'
import { Head, router } from '@inertiajs/vue3'
import { computed, ref } from 'vue'
import {
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
} from '@/components/ui/card'
import { Button } from '@/components/ui/button'
import { Label } from '@/components/ui/label'
import {
  DollarSign,
  ShoppingCart,
  CheckCircle2,
  CreditCard,
  TrendingUp,
  TrendingDown,
  Globe,
  Calendar,
  Filter,
  BarChart3,
  Target,
  Clock,
  Award,
  Plane,
} from 'lucide-vue-next'
import RevenueChart from '@/components/charts/RevenueChart.vue'
import OrdersChart from '@/components/charts/OrdersChart.vue'
import BarChart from '@/components/charts/BarChart.vue'
import PieChart from '@/components/charts/PieChart.vue'

interface Props {
  stats: {
    total_revenue: number
    total_orders: number
    paid_orders: number
    paypal_fees: number
    average_order_value?: number
    net_revenue?: number
    fee_percentage?: number
    revenue_growth_percent?: number
    orders_growth_percent?: number
  }
  revenueOverTime: Array<{ date: string; revenue: number }>
  ordersOverTime: Array<{ date: string; count: number }>
  revenueByWebsite: Array<{ id: number; name: string; revenue: number }>
  ordersByCountry: Array<{ country: string; count: number }>
  ordersByHour: Array<{ hour: number; count: number }>
  ordersByDayOfWeek: Array<{ day: string; day_number: number; count: number }>
  topCountry?: {
    country: string
    revenue: number
    percentage: number
  }
  peakOrderTime?: {
    hour: string | null
    day: string | null
  }
  websitePerformance?: Array<{
    id: number
    name: string
    revenue: number
    orders: number
    aov: number
    growth_percent: number
  }>
  conversionFunnel?: {
    form_submissions: number
    orders_created: number
    paid_orders: number
    submission_to_order_rate: number
    order_to_paid_rate: number
    submission_to_paid_rate: number
  }
  topDepartureAirports?: Array<{ airport: string; count: number }>
  topArrivalAirports?: Array<{ airport: string; count: number }>
  topRoutes?: Array<{ route: string; count: number }>
  websites: Array<{ id: number; name: string }>
  filters: {
    start_date: string
    end_date: string
    website_ids: number[] | string[]
    payment_status?: string
  }
}

const props = defineProps<Props>()

const breadcrumbs: BreadcrumbItem[] = [
  {
    title: 'Analytics',
    href: '/analytics',
  },
]

// Filter state
const startDate = ref(props.filters.start_date)
const endDate = ref(props.filters.end_date)
const selectedWebsiteIds = ref<number[]>(
  Array.isArray(props.filters.website_ids)
    ? props.filters.website_ids.map((id) => Number(id))
    : []
)
const paymentStatus = ref(props.filters.payment_status || '')

function applyFilters() {
  const params: Record<string, any> = {
    start_date: startDate.value,
    end_date: endDate.value,
  }

  if (selectedWebsiteIds.value.length > 0) {
    params.website_ids = selectedWebsiteIds.value
  }

  if (paymentStatus.value) {
    params.payment_status = paymentStatus.value
  }

  router.get('/analytics', params, {
    preserveState: false,
    preserveScroll: false,
  })
}

function resetFilters() {
  startDate.value = new Date(Date.now() - 30 * 24 * 60 * 60 * 1000)
    .toISOString()
    .split('T')[0]
  endDate.value = new Date().toISOString().split('T')[0]
  selectedWebsiteIds.value = []
  paymentStatus.value = ''
  applyFilters()
}

function toggleWebsite(websiteId: number) {
  const index = selectedWebsiteIds.value.indexOf(websiteId)
  if (index > -1) {
    selectedWebsiteIds.value.splice(index, 1)
  } else {
    selectedWebsiteIds.value.push(websiteId)
  }
}

function formatCurrency(amount: number): string {
  return new Intl.NumberFormat('en-US', {
    style: 'currency',
    currency: 'USD',
  }).format(amount)
}

function formatPercent(value: number): string {
  return `${value >= 0 ? '+' : ''}${value.toFixed(1)}%`
}

// Format hour for display
function formatHour(hour: number): string {
  const h = hour % 12 || 12
  const ampm = hour < 12 ? 'AM' : 'PM'
  return `${h}:00 ${ampm}`
}
</script>

<template>
  <Head title="Analytics" />

  <AppLayout :breadcrumbs="breadcrumbs">
    <div
      class="flex h-full flex-1 flex-col gap-4 sm:gap-6 overflow-x-auto rounded-xl p-3 sm:p-4 md:p-6"
    >
      <!-- Filters -->
      <Card>
        <CardHeader>
          <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-2">
            <div>
              <CardTitle class="text-base sm:text-lg">Filters</CardTitle>
              <CardDescription class="text-xs sm:text-sm">Filter analytics data by date, website, and order status</CardDescription>
            </div>
            <Filter class="h-5 w-5 text-muted-foreground hidden sm:block" />
          </div>
        </CardHeader>
        <CardContent>
          <div class="grid gap-4 md:grid-cols-2 lg:grid-cols-4">
            <!-- Date Range -->
            <div class="space-y-2">
              <Label for="start_date">Start Date</Label>
              <div class="relative">
                <input
                  id="start_date"
                  v-model="startDate"
                  type="date"
                  class="date-input-with-icon flex h-10 w-full rounded-md border border-input bg-background pl-3 pr-10 py-2 text-sm cursor-pointer"
                />
                <Calendar class="absolute right-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground pointer-events-none" />
              </div>
            </div>
            <div class="space-y-2">
              <Label for="end_date">End Date</Label>
              <div class="relative">
                <input
                  id="end_date"
                  v-model="endDate"
                  type="date"
                  class="date-input-with-icon flex h-10 w-full rounded-md border border-input bg-background pl-3 pr-10 py-2 text-sm cursor-pointer"
                />
                <Calendar class="absolute right-3 top-1/2 h-4 w-4 -translate-y-1/2 text-muted-foreground pointer-events-none" />
              </div>
            </div>

            <!-- Order Status -->
            <div class="space-y-2">
              <Label for="payment_status">Order Status</Label>
              <select
                id="payment_status"
                v-model="paymentStatus"
                class="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
              >
                <option value="">All Statuses</option>
                <option value="pending">Pending</option>
                <option value="processing">Processing</option>
                <option value="on-hold">On Hold</option>
                <option value="completed">Completed</option>
                <option value="cancelled">Cancelled</option>
                <option value="refunded">Refunded</option>
                <option value="failed">Failed</option>
              </select>
            </div>

            <!-- Actions -->
            <div class="flex flex-col sm:flex-row items-stretch sm:items-end gap-2">
              <Button @click="applyFilters" class="flex-1 w-full sm:w-auto">Apply Filters</Button>
              <Button variant="outline" @click="resetFilters" class="w-full sm:w-auto">Reset</Button>
            </div>
          </div>

          <!-- Website Multi-Select -->
          <div class="mt-4 space-y-2">
            <Label class="text-sm">Websites (Select multiple)</Label>
            <div class="flex flex-wrap gap-2">
              <Button
                v-for="website in websites"
                :key="website.id"
                :variant="selectedWebsiteIds.includes(website.id) ? 'default' : 'outline'"
                size="sm"
                class="text-xs sm:text-sm"
                @click="toggleWebsite(website.id)"
              >
                {{ website.name }}
              </Button>
            </div>
            <p class="text-xs text-muted-foreground mt-2">
              {{ selectedWebsiteIds.length > 0 ? `${selectedWebsiteIds.length} website(s) selected` : 'All websites selected' }}
            </p>
          </div>
        </CardContent>
      </Card>

      <!-- Statistics Cards -->
      <div class="grid gap-3 sm:gap-4 grid-cols-1 sm:grid-cols-2 lg:grid-cols-4">
        <Card>
          <CardHeader class="flex flex-row items-center justify-between space-y-0 pb-2">
            <CardTitle class="text-xs sm:text-sm font-medium">Total Revenue</CardTitle>
            <DollarSign class="h-4 w-4 text-muted-foreground flex-shrink-0" />
          </CardHeader>
          <CardContent>
            <div class="text-xl sm:text-2xl font-bold break-words">{{ formatCurrency(stats.total_revenue) }}</div>
            <p class="text-xs text-muted-foreground mt-1">Completed orders only</p>
            <div
              v-if="stats.revenue_growth_percent !== undefined"
              class="mt-2 flex items-center gap-1 text-xs"
            >
              <TrendingUp
                v-if="stats.revenue_growth_percent >= 0"
                class="h-3 w-3 text-green-600"
              />
              <TrendingDown
                v-else
                class="h-3 w-3 text-red-600"
              />
              <span
                :class="[
                  'font-medium',
                  stats.revenue_growth_percent >= 0 ? 'text-green-600' : 'text-red-600',
                ]"
              >
                {{ formatPercent(stats.revenue_growth_percent) }}
              </span>
              <span class="text-muted-foreground">vs previous period</span>
            </div>
          </CardContent>
        </Card>

        <Card>
          <CardHeader class="flex flex-row items-center justify-between space-y-0 pb-2">
            <CardTitle class="text-xs sm:text-sm font-medium">Total Orders</CardTitle>
            <ShoppingCart class="h-4 w-4 text-muted-foreground flex-shrink-0" />
          </CardHeader>
          <CardContent>
            <div class="text-xl sm:text-2xl font-bold">{{ stats.total_orders.toLocaleString() }}</div>
            <p class="text-xs text-muted-foreground mt-1">All orders in date range</p>
            <div
              v-if="stats.orders_growth_percent !== undefined"
              class="mt-2 flex items-center gap-1 text-xs"
            >
              <TrendingUp
                v-if="stats.orders_growth_percent >= 0"
                class="h-3 w-3 text-green-600"
              />
              <TrendingDown
                v-else
                class="h-3 w-3 text-red-600"
              />
              <span
                :class="[
                  'font-medium',
                  stats.orders_growth_percent >= 0 ? 'text-green-600' : 'text-red-600',
                ]"
              >
                {{ formatPercent(stats.orders_growth_percent) }}
              </span>
              <span class="text-muted-foreground">vs previous period</span>
            </div>
          </CardContent>
        </Card>

        <Card>
          <CardHeader class="flex flex-row items-center justify-between space-y-0 pb-2">
            <CardTitle class="text-xs sm:text-sm font-medium">Completed Orders</CardTitle>
            <CheckCircle2 class="h-4 w-4 text-muted-foreground flex-shrink-0" />
          </CardHeader>
          <CardContent>
            <div class="text-xl sm:text-2xl font-bold">{{ stats.paid_orders.toLocaleString() }}</div>
            <p class="text-xs text-muted-foreground mt-1">Orders with completed status</p>
          </CardContent>
        </Card>

        <Card>
          <CardHeader class="flex flex-row items-center justify-between space-y-0 pb-2">
            <CardTitle class="text-xs sm:text-sm font-medium">PayPal Fees</CardTitle>
            <CreditCard class="h-4 w-4 text-muted-foreground flex-shrink-0" />
          </CardHeader>
          <CardContent>
            <div class="text-xl sm:text-2xl font-bold break-words">{{ formatCurrency(stats.paypal_fees) }}</div>
            <p class="text-xs text-muted-foreground mt-1">Total PayPal transaction fees</p>
          </CardContent>
        </Card>
      </div>

      <!-- Additional KPI Cards: AOV and Net Revenue -->
      <div class="grid gap-3 sm:gap-4 grid-cols-1 sm:grid-cols-2 lg:grid-cols-3">
        <Card>
          <CardHeader class="flex flex-row items-center justify-between space-y-0 pb-2">
            <CardTitle class="text-xs sm:text-sm font-medium">Average Order Value</CardTitle>
            <BarChart3 class="h-4 w-4 text-muted-foreground flex-shrink-0" />
          </CardHeader>
          <CardContent>
            <div class="text-xl sm:text-2xl font-bold break-words">
              {{ stats.average_order_value ? formatCurrency(stats.average_order_value) : '$0.00' }}
            </div>
            <p class="text-xs text-muted-foreground mt-1">Revenue per completed order</p>
          </CardContent>
        </Card>

        <Card>
          <CardHeader class="flex flex-row items-center justify-between space-y-0 pb-2">
            <CardTitle class="text-xs sm:text-sm font-medium">Net Revenue</CardTitle>
            <DollarSign class="h-4 w-4 text-muted-foreground flex-shrink-0" />
          </CardHeader>
          <CardContent>
            <div class="text-xl sm:text-2xl font-bold break-words">
              {{ stats.net_revenue ? formatCurrency(stats.net_revenue) : '$0.00' }}
            </div>
            <p class="text-xs text-muted-foreground mt-1">
              After PayPal fees
              <span v-if="stats.fee_percentage !== undefined">
                ({{ stats.fee_percentage.toFixed(2) }}% fees)
              </span>
            </p>
          </CardContent>
        </Card>

        <!-- Top Performing Country Card -->
        <Card v-if="topCountry">
          <CardHeader class="flex flex-row items-center justify-between space-y-0 pb-2">
            <CardTitle class="text-xs sm:text-sm font-medium">Top Country</CardTitle>
            <Award class="h-4 w-4 text-muted-foreground flex-shrink-0" />
          </CardHeader>
          <CardContent>
            <div class="text-xl sm:text-2xl font-bold">{{ topCountry.country }}</div>
            <p class="text-xs text-muted-foreground mt-1">
              {{ formatCurrency(topCountry.revenue) }} ({{ topCountry.percentage.toFixed(1) }}% of total)
            </p>
          </CardContent>
        </Card>
      </div>

      <!-- Revenue Over Time Chart -->
      <Card>
        <CardHeader>
          <CardTitle class="text-base sm:text-lg">Revenue Over Time</CardTitle>
          <CardDescription class="text-xs sm:text-sm">Daily revenue trends</CardDescription>
        </CardHeader>
        <CardContent>
          <RevenueChart
            v-if="revenueOverTime.length > 0"
            :data="revenueOverTime"
          />
          <div
            v-else
            class="flex h-[250px] sm:h-[300px] items-center justify-center text-muted-foreground text-sm"
          >
            No revenue data available
          </div>
        </CardContent>
      </Card>

      <!-- Orders Over Time Chart -->
      <Card>
        <CardHeader>
          <CardTitle class="text-base sm:text-lg">Orders Over Time</CardTitle>
          <CardDescription class="text-xs sm:text-sm">Daily order trends</CardDescription>
        </CardHeader>
        <CardContent>
          <OrdersChart
            v-if="ordersOverTime.length > 0"
            :data="ordersOverTime"
          />
          <div
            v-else
            class="flex h-[250px] sm:h-[300px] items-center justify-center text-muted-foreground text-sm"
          >
            No order data available
          </div>
        </CardContent>
      </Card>

      <!-- Revenue by Website & Orders by Country -->
      <div class="grid gap-3 sm:gap-4 grid-cols-1 md:grid-cols-2">
        <Card>
          <CardHeader>
            <CardTitle class="text-base sm:text-lg">Revenue by Website</CardTitle>
            <CardDescription class="text-xs sm:text-sm">Distribution of revenue across websites</CardDescription>
          </CardHeader>
          <CardContent>
            <PieChart
              v-if="revenueByWebsite.length > 0"
              :data="revenueByWebsite"
              label-key="name"
              value-key="revenue"
            />
            <div
              v-else
              class="flex h-[250px] sm:h-[300px] items-center justify-center text-muted-foreground text-sm"
            >
              No revenue data available
            </div>
          </CardContent>
        </Card>

        <Card>
          <CardHeader>
            <CardTitle class="text-base sm:text-lg">Orders by Country</CardTitle>
            <CardDescription class="text-xs sm:text-sm">Top 20 countries by order count</CardDescription>
          </CardHeader>
          <CardContent>
            <BarChart
              v-if="ordersByCountry.length > 0"
              :data="ordersByCountry"
              label-key="country"
              value-key="count"
              label="Orders"
              color="rgb(34, 197, 94)"
            />
            <div
              v-else
              class="flex h-[250px] sm:h-[300px] items-center justify-center text-muted-foreground text-sm"
            >
              No country data available
            </div>
          </CardContent>
        </Card>
      </div>

      <!-- Orders by Hour & Orders by Day of Week -->
      <div class="grid gap-3 sm:gap-4 grid-cols-1 md:grid-cols-2">
        <Card>
          <CardHeader>
            <CardTitle class="text-base sm:text-lg">Orders by Hour of Day</CardTitle>
            <CardDescription class="text-xs sm:text-sm">Order distribution throughout the day</CardDescription>
          </CardHeader>
          <CardContent>
            <BarChart
              v-if="ordersByHour.length > 0"
              :data="ordersByHour.map((item) => ({ hour: formatHour(item.hour), count: item.count }))"
              label-key="hour"
              value-key="count"
              label="Orders"
              color="rgb(168, 85, 247)"
            />
            <div
              v-else
              class="flex h-[250px] sm:h-[300px] items-center justify-center text-muted-foreground text-sm"
            >
              No hourly data available
            </div>
          </CardContent>
        </Card>

        <Card>
          <CardHeader>
            <CardTitle class="text-base sm:text-lg">Orders by Day of Week</CardTitle>
            <CardDescription class="text-xs sm:text-sm">Order distribution by weekday</CardDescription>
          </CardHeader>
          <CardContent>
            <BarChart
              v-if="ordersByDayOfWeek.length > 0"
              :data="[...ordersByDayOfWeek].sort((a, b) => a.day_number - b.day_number)"
              label-key="day"
              value-key="count"
              label="Orders"
              color="rgb(245, 158, 11)"
            />
            <div
              v-else
              class="flex h-[250px] sm:h-[300px] items-center justify-center text-muted-foreground text-sm"
            >
              No day-of-week data available
            </div>
          </CardContent>
        </Card>
      </div>

      <!-- Peak Order Time Insight -->
      <Card v-if="peakOrderTime && peakOrderTime.hour && peakOrderTime.day">
        <CardHeader>
          <div class="flex items-center gap-2">
            <Clock class="h-4 w-4 sm:h-5 sm:w-5 text-muted-foreground flex-shrink-0" />
            <CardTitle class="text-base sm:text-lg">Peak Order Time Insight</CardTitle>
          </div>
          <CardDescription class="text-xs sm:text-sm">Optimal times for marketing campaigns</CardDescription>
        </CardHeader>
        <CardContent>
          <p class="text-base sm:text-lg">
            Most orders occur at
            <span class="font-semibold">{{ peakOrderTime.hour }}</span>
            on
            <span class="font-semibold">{{ peakOrderTime.day }}s</span>
          </p>
        </CardContent>
      </Card>

      <!-- Website Performance Ranking -->
      <Card v-if="websitePerformance && websitePerformance.length > 0">
        <CardHeader>
          <CardTitle class="text-base sm:text-lg">Website Performance Ranking</CardTitle>
          <CardDescription class="text-xs sm:text-sm">Sorted by revenue with AOV and growth metrics</CardDescription>
        </CardHeader>
        <CardContent>
          <div class="overflow-x-auto -mx-3 sm:mx-0">
            <table class="w-full text-xs sm:text-sm min-w-[600px]">
              <thead>
                <tr class="border-b">
                  <th class="text-left p-2 font-medium">Website</th>
                  <th class="text-right p-2 font-medium">Revenue</th>
                  <th class="text-right p-2 font-medium hidden sm:table-cell">Orders</th>
                  <th class="text-right p-2 font-medium hidden md:table-cell">AOV</th>
                  <th class="text-right p-2 font-medium">Growth %</th>
                </tr>
              </thead>
              <tbody>
                <tr
                  v-for="(website, index) in websitePerformance"
                  :key="website.id"
                  class="border-b hover:bg-muted/50"
                >
                  <td class="p-2">
                    <div class="flex items-center gap-2">
                      <span
                        class="flex h-5 w-5 sm:h-6 sm:w-6 items-center justify-center rounded-full bg-primary/10 text-xs font-medium flex-shrink-0"
                      >
                        {{ index + 1 }}
                      </span>
                      <span class="truncate max-w-[120px] sm:max-w-none">{{ website.name }}</span>
                    </div>
                  </td>
                  <td class="p-2 text-right font-medium whitespace-nowrap">{{ formatCurrency(website.revenue) }}</td>
                  <td class="p-2 text-right hidden sm:table-cell">{{ website.orders.toLocaleString() }}</td>
                  <td class="p-2 text-right hidden md:table-cell whitespace-nowrap">{{ formatCurrency(website.aov) }}</td>
                  <td class="p-2 text-right">
                    <span
                      :class="[
                        'font-medium whitespace-nowrap',
                        website.growth_percent >= 0 ? 'text-green-600' : 'text-red-600',
                      ]"
                    >
                      {{ formatPercent(website.growth_percent) }}
                    </span>
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </CardContent>
      </Card>

      <!-- Conversion Funnel -->
      <Card v-if="conversionFunnel">
        <CardHeader>
          <div class="flex items-center gap-2">
            <Target class="h-4 w-4 sm:h-5 sm:w-5 text-muted-foreground flex-shrink-0" />
            <CardTitle class="text-base sm:text-lg">Conversion Funnel</CardTitle>
          </div>
          <CardDescription class="text-xs sm:text-sm">Fluent Forms submissions → Orders → Paid orders</CardDescription>
        </CardHeader>
        <CardContent>
          <div class="space-y-6">
            <!-- Funnel Stages -->
            <div class="space-y-4">
              <!-- Form Submissions -->
              <div>
                <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-1 sm:gap-0 mb-2">
                  <span class="font-medium text-sm sm:text-base">Form Submissions</span>
                  <span class="text-xs sm:text-sm text-muted-foreground">
                    {{ conversionFunnel.form_submissions.toLocaleString() }}
                  </span>
                </div>
                <div class="h-8 w-full rounded-md bg-muted overflow-hidden">
                  <div class="h-full bg-blue-500 rounded-md" style="width: 100%"></div>
                </div>
              </div>

              <!-- Orders Created -->
              <div>
                <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-1 sm:gap-0 mb-2">
                  <span class="font-medium text-sm sm:text-base">Orders Created</span>
                  <span class="text-xs sm:text-sm text-muted-foreground">
                    {{ conversionFunnel.orders_created.toLocaleString() }}
                    <span class="ml-1 sm:ml-2 font-medium text-orange-600">
                      ({{ conversionFunnel.submission_to_order_rate.toFixed(1) }}%)
                    </span>
                  </span>
                </div>
                <div class="h-8 w-full rounded-md bg-muted overflow-hidden">
                  <div
                    class="h-full bg-orange-500 rounded-md"
                    :style="`width: ${conversionFunnel.submission_to_order_rate}%`"
                  ></div>
                </div>
              </div>

              <!-- Paid Orders -->
              <div>
                <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-1 sm:gap-0 mb-2">
                  <span class="font-medium text-sm sm:text-base">Paid Orders</span>
                  <span class="text-xs sm:text-sm text-muted-foreground">
                    {{ conversionFunnel.paid_orders.toLocaleString() }}
                    <span class="ml-1 sm:ml-2 font-medium text-green-600">
                      ({{ conversionFunnel.submission_to_paid_rate.toFixed(1) }}%)
                    </span>
                  </span>
                </div>
                <div class="h-8 w-full rounded-md bg-muted overflow-hidden">
                  <div
                    class="h-full bg-green-500 rounded-md"
                    :style="`width: ${conversionFunnel.submission_to_paid_rate}%`"
                  ></div>
                </div>
              </div>
            </div>

            <!-- Conversion Rates Summary -->
            <div class="grid gap-3 sm:gap-4 grid-cols-1 sm:grid-cols-3 pt-4 border-t">
              <div class="text-center">
                <div class="text-xl sm:text-2xl font-bold text-orange-600">
                  {{ conversionFunnel.submission_to_order_rate.toFixed(1) }}%
                </div>
                <div class="text-xs text-muted-foreground mt-1">Submissions → Orders</div>
              </div>
              <div class="text-center">
                <div class="text-xl sm:text-2xl font-bold text-green-600">
                  {{ conversionFunnel.order_to_paid_rate.toFixed(1) }}%
                </div>
                <div class="text-xs text-muted-foreground mt-1">Orders → Paid</div>
              </div>
              <div class="text-center">
                <div class="text-xl sm:text-2xl font-bold text-blue-600">
                  {{ conversionFunnel.submission_to_paid_rate.toFixed(1) }}%
                </div>
                <div class="text-xs text-muted-foreground mt-1">Overall Conversion</div>
              </div>
            </div>
          </div>
        </CardContent>
      </Card>

      <!-- Flight Route Analytics -->
      <div class="grid gap-3 sm:gap-4 grid-cols-1 md:grid-cols-2">
        <!-- Top Departure Airports -->
        <Card>
          <CardHeader>
            <div class="flex items-center gap-2">
              <Plane class="h-4 w-4 sm:h-5 sm:w-5 text-muted-foreground flex-shrink-0" />
              <CardTitle class="text-base sm:text-lg">Top Departure Airports</CardTitle>
            </div>
            <CardDescription class="text-xs sm:text-sm">Most requested departure airports</CardDescription>
          </CardHeader>
          <CardContent>
            <BarChart
              v-if="topDepartureAirports && topDepartureAirports.length > 0"
              :data="topDepartureAirports"
              label-key="airport"
              value-key="count"
              label="Requests"
              color="rgb(59, 130, 246)"
            />
            <div
              v-else
              class="flex h-[250px] sm:h-[300px] items-center justify-center text-muted-foreground text-sm"
            >
              No departure airport data available
            </div>
          </CardContent>
        </Card>

        <!-- Top Arrival Airports -->
        <Card>
          <CardHeader>
            <div class="flex items-center gap-2">
              <Plane class="h-4 w-4 sm:h-5 sm:w-5 text-muted-foreground flex-shrink-0" />
              <CardTitle class="text-base sm:text-lg">Top Arrival Airports</CardTitle>
            </div>
            <CardDescription class="text-xs sm:text-sm">Most requested arrival airports</CardDescription>
          </CardHeader>
          <CardContent>
            <BarChart
              v-if="topArrivalAirports && topArrivalAirports.length > 0"
              :data="topArrivalAirports"
              label-key="airport"
              value-key="count"
              label="Requests"
              color="rgb(34, 197, 94)"
            />
            <div
              v-else
              class="flex h-[250px] sm:h-[300px] items-center justify-center text-muted-foreground text-sm"
            >
              No arrival airport data available
            </div>
          </CardContent>
        </Card>
      </div>

      <!-- Top Routes Table -->
      <Card>
        <CardHeader>
          <div class="flex items-center gap-2">
            <Plane class="h-4 w-4 sm:h-5 sm:w-5 text-muted-foreground flex-shrink-0" />
            <CardTitle class="text-base sm:text-lg">Top Flight Routes</CardTitle>
          </div>
          <CardDescription class="text-xs sm:text-sm">Most popular routes (FROM → TO) across all websites</CardDescription>
        </CardHeader>
        <CardContent>
          <div v-if="topRoutes && topRoutes.length > 0" class="overflow-x-auto -mx-3 sm:mx-0">
            <table class="w-full text-xs sm:text-sm min-w-[300px]">
              <thead>
                <tr class="border-b">
                  <th class="text-left p-2 font-medium">Rank</th>
                  <th class="text-left p-2 font-medium">Route</th>
                  <th class="text-right p-2 font-medium">Requests</th>
                </tr>
              </thead>
              <tbody>
                <tr
                  v-for="(routeItem, index) in topRoutes"
                  :key="routeItem.route"
                  class="border-b hover:bg-muted/50"
                >
                  <td class="p-2">
                    <span
                      class="flex h-5 w-5 sm:h-6 sm:w-6 items-center justify-center rounded-full bg-primary/10 text-xs font-medium"
                    >
                      {{ index + 1 }}
                    </span>
                  </td>
                  <td class="p-2">
                    <span class="font-medium break-words">{{ routeItem.route }}</span>
                  </td>
                  <td class="p-2 text-right font-medium whitespace-nowrap">{{ routeItem.count.toLocaleString() }}</td>
                </tr>
              </tbody>
            </table>
          </div>
          <div
            v-else
            class="flex h-[200px] items-center justify-center text-muted-foreground text-sm"
          >
            No flight route data available
          </div>
        </CardContent>
      </Card>
    </div>
  </AppLayout>
</template>

<style scoped>
.date-input-with-icon {
  cursor: pointer;
}

.date-input-with-icon::-webkit-calendar-picker-indicator {
  opacity: 0;
  position: absolute;
  right: 0;
  width: 100%;
  height: 100%;
  cursor: pointer;
}

.date-input-with-icon::-moz-calendar-picker-indicator {
  opacity: 0;
  cursor: pointer;
  width: 100%;
  height: 100%;
}

.date-input-with-icon::-ms-calendar-picker-indicator {
  opacity: 0;
  cursor: pointer;
}
</style>

