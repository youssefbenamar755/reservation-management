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
      class="flex h-full flex-1 flex-col gap-6 overflow-x-auto rounded-xl p-4"
    >
      <!-- Filters -->
      <Card>
        <CardHeader>
          <div class="flex items-center justify-between">
            <div>
              <CardTitle>Filters</CardTitle>
              <CardDescription>Filter analytics data by date, website, and order status</CardDescription>
            </div>
            <Filter class="h-5 w-5 text-muted-foreground" />
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
            <div class="flex items-end gap-2">
              <Button @click="applyFilters" class="flex-1">Apply Filters</Button>
              <Button variant="outline" @click="resetFilters">Reset</Button>
            </div>
          </div>

          <!-- Website Multi-Select -->
          <div class="mt-4 space-y-2">
            <Label>Websites (Select multiple)</Label>
            <div class="flex flex-wrap gap-2">
              <Button
                v-for="website in websites"
                :key="website.id"
                :variant="selectedWebsiteIds.includes(website.id) ? 'default' : 'outline'"
                size="sm"
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
      <div class="grid gap-4 md:grid-cols-2 lg:grid-cols-4">
        <Card>
          <CardHeader class="flex flex-row items-center justify-between space-y-0 pb-2">
            <CardTitle class="text-sm font-medium">Total Revenue</CardTitle>
            <DollarSign class="h-4 w-4 text-muted-foreground" />
          </CardHeader>
          <CardContent>
            <div class="text-2xl font-bold">{{ formatCurrency(stats.total_revenue) }}</div>
            <p class="text-xs text-muted-foreground">Completed orders only</p>
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
            <CardTitle class="text-sm font-medium">Total Orders</CardTitle>
            <ShoppingCart class="h-4 w-4 text-muted-foreground" />
          </CardHeader>
          <CardContent>
            <div class="text-2xl font-bold">{{ stats.total_orders.toLocaleString() }}</div>
            <p class="text-xs text-muted-foreground">All orders in date range</p>
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
            <CardTitle class="text-sm font-medium">Completed Orders</CardTitle>
            <CheckCircle2 class="h-4 w-4 text-muted-foreground" />
          </CardHeader>
          <CardContent>
            <div class="text-2xl font-bold">{{ stats.paid_orders.toLocaleString() }}</div>
            <p class="text-xs text-muted-foreground">Orders with completed status</p>
          </CardContent>
        </Card>

        <Card>
          <CardHeader class="flex flex-row items-center justify-between space-y-0 pb-2">
            <CardTitle class="text-sm font-medium">PayPal Fees</CardTitle>
            <CreditCard class="h-4 w-4 text-muted-foreground" />
          </CardHeader>
          <CardContent>
            <div class="text-2xl font-bold">{{ formatCurrency(stats.paypal_fees) }}</div>
            <p class="text-xs text-muted-foreground">Total PayPal transaction fees</p>
          </CardContent>
        </Card>
      </div>

      <!-- Additional KPI Cards: AOV and Net Revenue -->
      <div class="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
        <Card>
          <CardHeader class="flex flex-row items-center justify-between space-y-0 pb-2">
            <CardTitle class="text-sm font-medium">Average Order Value</CardTitle>
            <BarChart3 class="h-4 w-4 text-muted-foreground" />
          </CardHeader>
          <CardContent>
            <div class="text-2xl font-bold">
              {{ stats.average_order_value ? formatCurrency(stats.average_order_value) : '$0.00' }}
            </div>
            <p class="text-xs text-muted-foreground">Revenue per completed order</p>
          </CardContent>
        </Card>

        <Card>
          <CardHeader class="flex flex-row items-center justify-between space-y-0 pb-2">
            <CardTitle class="text-sm font-medium">Net Revenue</CardTitle>
            <DollarSign class="h-4 w-4 text-muted-foreground" />
          </CardHeader>
          <CardContent>
            <div class="text-2xl font-bold">
              {{ stats.net_revenue ? formatCurrency(stats.net_revenue) : '$0.00' }}
            </div>
            <p class="text-xs text-muted-foreground">
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
            <CardTitle class="text-sm font-medium">Top Country</CardTitle>
            <Award class="h-4 w-4 text-muted-foreground" />
          </CardHeader>
          <CardContent>
            <div class="text-2xl font-bold">{{ topCountry.country }}</div>
            <p class="text-xs text-muted-foreground">
              {{ formatCurrency(topCountry.revenue) }} ({{ topCountry.percentage.toFixed(1) }}% of total)
            </p>
          </CardContent>
        </Card>
      </div>

      <!-- Revenue Over Time Chart -->
      <Card>
        <CardHeader>
          <CardTitle>Revenue Over Time</CardTitle>
          <CardDescription>Daily revenue trends</CardDescription>
        </CardHeader>
        <CardContent>
          <RevenueChart
            v-if="revenueOverTime.length > 0"
            :data="revenueOverTime"
          />
          <div
            v-else
            class="flex h-[300px] items-center justify-center text-muted-foreground"
          >
            No revenue data available
          </div>
        </CardContent>
      </Card>

      <!-- Orders Over Time Chart -->
      <Card>
        <CardHeader>
          <CardTitle>Orders Over Time</CardTitle>
          <CardDescription>Daily order trends</CardDescription>
        </CardHeader>
        <CardContent>
          <OrdersChart
            v-if="ordersOverTime.length > 0"
            :data="ordersOverTime"
          />
          <div
            v-else
            class="flex h-[300px] items-center justify-center text-muted-foreground"
          >
            No order data available
          </div>
        </CardContent>
      </Card>

      <!-- Revenue by Website & Orders by Country -->
      <div class="grid gap-4 md:grid-cols-2">
        <Card>
          <CardHeader>
            <CardTitle>Revenue by Website</CardTitle>
            <CardDescription>Distribution of revenue across websites</CardDescription>
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
              class="flex h-[300px] items-center justify-center text-muted-foreground"
            >
              No revenue data available
            </div>
          </CardContent>
        </Card>

        <Card>
          <CardHeader>
            <CardTitle>Orders by Country</CardTitle>
            <CardDescription>Top 20 countries by order count</CardDescription>
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
              class="flex h-[300px] items-center justify-center text-muted-foreground"
            >
              No country data available
            </div>
          </CardContent>
        </Card>
      </div>

      <!-- Orders by Hour & Orders by Day of Week -->
      <div class="grid gap-4 md:grid-cols-2">
        <Card>
          <CardHeader>
            <CardTitle>Orders by Hour of Day</CardTitle>
            <CardDescription>Order distribution throughout the day</CardDescription>
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
              class="flex h-[300px] items-center justify-center text-muted-foreground"
            >
              No hourly data available
            </div>
          </CardContent>
        </Card>

        <Card>
          <CardHeader>
            <CardTitle>Orders by Day of Week</CardTitle>
            <CardDescription>Order distribution by weekday</CardDescription>
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
              class="flex h-[300px] items-center justify-center text-muted-foreground"
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
            <Clock class="h-5 w-5 text-muted-foreground" />
            <CardTitle>Peak Order Time Insight</CardTitle>
          </div>
          <CardDescription>Optimal times for marketing campaigns</CardDescription>
        </CardHeader>
        <CardContent>
          <p class="text-lg">
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
          <CardTitle>Website Performance Ranking</CardTitle>
          <CardDescription>Sorted by revenue with AOV and growth metrics</CardDescription>
        </CardHeader>
        <CardContent>
          <div class="overflow-x-auto">
            <table class="w-full text-sm">
              <thead>
                <tr class="border-b">
                  <th class="text-left p-2 font-medium">Website</th>
                  <th class="text-right p-2 font-medium">Revenue</th>
                  <th class="text-right p-2 font-medium">Orders</th>
                  <th class="text-right p-2 font-medium">AOV</th>
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
                        class="flex h-6 w-6 items-center justify-center rounded-full bg-primary/10 text-xs font-medium"
                      >
                        {{ index + 1 }}
                      </span>
                      <span>{{ website.name }}</span>
                    </div>
                  </td>
                  <td class="p-2 text-right font-medium">{{ formatCurrency(website.revenue) }}</td>
                  <td class="p-2 text-right">{{ website.orders.toLocaleString() }}</td>
                  <td class="p-2 text-right">{{ formatCurrency(website.aov) }}</td>
                  <td class="p-2 text-right">
                    <span
                      :class="[
                        'font-medium',
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
            <Target class="h-5 w-5 text-muted-foreground" />
            <CardTitle>Conversion Funnel</CardTitle>
          </div>
          <CardDescription>Fluent Forms submissions → Orders → Paid orders</CardDescription>
        </CardHeader>
        <CardContent>
          <div class="space-y-6">
            <!-- Funnel Stages -->
            <div class="space-y-4">
              <!-- Form Submissions -->
              <div>
                <div class="flex items-center justify-between mb-2">
                  <span class="font-medium">Form Submissions</span>
                  <span class="text-sm text-muted-foreground">
                    {{ conversionFunnel.form_submissions.toLocaleString() }}
                  </span>
                </div>
                <div class="h-8 w-full rounded-md bg-muted overflow-hidden">
                  <div class="h-full bg-blue-500 rounded-md" style="width: 100%"></div>
                </div>
              </div>

              <!-- Orders Created -->
              <div>
                <div class="flex items-center justify-between mb-2">
                  <span class="font-medium">Orders Created</span>
                  <span class="text-sm text-muted-foreground">
                    {{ conversionFunnel.orders_created.toLocaleString() }}
                    <span class="ml-2 font-medium text-orange-600">
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
                <div class="flex items-center justify-between mb-2">
                  <span class="font-medium">Paid Orders</span>
                  <span class="text-sm text-muted-foreground">
                    {{ conversionFunnel.paid_orders.toLocaleString() }}
                    <span class="ml-2 font-medium text-green-600">
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
            <div class="grid gap-4 md:grid-cols-3 pt-4 border-t">
              <div class="text-center">
                <div class="text-2xl font-bold text-orange-600">
                  {{ conversionFunnel.submission_to_order_rate.toFixed(1) }}%
                </div>
                <div class="text-xs text-muted-foreground">Submissions → Orders</div>
              </div>
              <div class="text-center">
                <div class="text-2xl font-bold text-green-600">
                  {{ conversionFunnel.order_to_paid_rate.toFixed(1) }}%
                </div>
                <div class="text-xs text-muted-foreground">Orders → Paid</div>
              </div>
              <div class="text-center">
                <div class="text-2xl font-bold text-blue-600">
                  {{ conversionFunnel.submission_to_paid_rate.toFixed(1) }}%
                </div>
                <div class="text-xs text-muted-foreground">Overall Conversion</div>
              </div>
            </div>
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

