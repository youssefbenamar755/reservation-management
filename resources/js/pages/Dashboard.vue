<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue'
import { type BreadcrumbItem } from '@/types'
import { Head, Link } from '@inertiajs/vue3'
import {
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
} from '@/components/ui/card'
import { Badge } from '@/components/ui/badge'
import {
  Globe,
  ShoppingCart,
  FileText,
  DollarSign,
  Activity,
  AlertCircle,
  TrendingUp,
  Clock,
  CheckCircle2,
  XCircle,
} from 'lucide-vue-next'
import OrdersChart from '@/components/charts/OrdersChart.vue'
import RevenueChart from '@/components/charts/RevenueChart.vue'

interface Props {
  stats: {
    total_websites: number
    active_websites: number
    total_orders: number
    total_submissions: number
    total_revenue: number
    pending_webhooks: number
    failed_webhooks: number
  }
  ordersByStatus: Record<string, number>
  ordersByWebsite: Array<{ name: string; count: number }>
  ordersOverTime: Array<{ date: string; count: number }>
  revenueOverTime: Array<{ date: string; revenue: number }>
  recentOrders: Array<{
    id: number
    wp_order_id: number
    website_name: string
    status: string
    total: number
    currency: string
    customer_email: string
    customer_name: string
    created_at_wp: string
  }>
  recentSubmissions: Array<{
    id: number
    entry_id: number
    form_id: number
    website_name: string
    email: string
    created_at_wp: string
  }>
  websiteHealth: Array<{
    id: number
    name: string
    status: string
    base_url: string
    orders_count: number
    submissions_count: number
    failed_webhooks: number
    last_webhook_at: string | null
    last_sync_at: string | null
  }>
  webhookStatus: {
    queued: number
    processed: number
    failed: number
  }
}

const props = defineProps<Props>()

const breadcrumbs: BreadcrumbItem[] = [
  {
    title: 'Dashboard',
    href: '/dashboard',
  },
]

function formatCurrency(amount: number, currency: string = 'USD'): string {
  return new Intl.NumberFormat('en-US', {
    style: 'currency',
    currency: currency,
  }).format(amount)
}

function formatDate(dateString: string | null): string {
  if (!dateString) return 'Never'
  const date = new Date(dateString)
  return new Intl.DateTimeFormat('en-US', {
    month: 'short',
    day: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  }).format(date)
}

function getStatusColor(status: string): string {
  const colors: Record<string, string> = {
    active: 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200',
    paused: 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-200',
    completed: 'bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-200',
    processing: 'bg-purple-100 text-purple-800 dark:bg-purple-900 dark:text-purple-200',
    pending: 'bg-orange-100 text-orange-800 dark:bg-orange-900 dark:text-orange-200',
    cancelled: 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200',
  }
  return colors[status] || 'bg-gray-100 text-gray-800 dark:bg-gray-900 dark:text-gray-200'
}
</script>

<template>
  <Head title="Dashboard" />

  <AppLayout :breadcrumbs="breadcrumbs">
    <div
      class="flex h-full flex-1 flex-col gap-6 overflow-x-auto rounded-xl p-4"
    >
      <!-- Statistics Cards -->
      <div class="grid gap-4 md:grid-cols-2 lg:grid-cols-4">
        <Card>
          <CardHeader class="flex flex-row items-center justify-between space-y-0 pb-2">
            <CardTitle class="text-sm font-medium">Total Websites</CardTitle>
            <Globe class="h-4 w-4 text-muted-foreground" />
          </CardHeader>
          <CardContent>
            <div class="text-2xl font-bold">{{ stats.total_websites }}</div>
            <p class="text-xs text-muted-foreground">
              {{ stats.active_websites }} active
            </p>
          </CardContent>
        </Card>

        <Card>
          <CardHeader class="flex flex-row items-center justify-between space-y-0 pb-2">
            <CardTitle class="text-sm font-medium">Total Orders</CardTitle>
            <ShoppingCart class="h-4 w-4 text-muted-foreground" />
          </CardHeader>
          <CardContent>
            <div class="text-2xl font-bold">{{ stats.total_orders.toLocaleString() }}</div>
            <p class="text-xs text-muted-foreground">
              Across all websites
            </p>
          </CardContent>
        </Card>

        <Card>
          <CardHeader class="flex flex-row items-center justify-between space-y-0 pb-2">
            <CardTitle class="text-sm font-medium">Form Submissions</CardTitle>
            <FileText class="h-4 w-4 text-muted-foreground" />
          </CardHeader>
          <CardContent>
            <div class="text-2xl font-bold">{{ stats.total_submissions.toLocaleString() }}</div>
            <p class="text-xs text-muted-foreground">
              Fluent Forms entries
            </p>
          </CardContent>
        </Card>

        <Card>
          <CardHeader class="flex flex-row items-center justify-between space-y-0 pb-2">
            <CardTitle class="text-sm font-medium">Total Revenue</CardTitle>
            <DollarSign class="h-4 w-4 text-muted-foreground" />
          </CardHeader>
          <CardContent>
            <div class="text-2xl font-bold">
              {{ formatCurrency(stats.total_revenue) }}
            </div>
            <p class="text-xs text-muted-foreground">
              Completed orders only
            </p>
          </CardContent>
        </Card>
      </div>

      <!-- Webhook Status Cards -->
      <div class="grid gap-4 md:grid-cols-3">
        <Card>
          <CardHeader class="flex flex-row items-center justify-between space-y-0 pb-2">
            <CardTitle class="text-sm font-medium">Webhook Status</CardTitle>
            <Activity class="h-4 w-4 text-muted-foreground" />
          </CardHeader>
          <CardContent>
            <div class="space-y-2">
              <div class="flex items-center justify-between">
                <span class="text-sm text-muted-foreground">Queued</span>
                <Badge variant="outline" class="bg-yellow-50 dark:bg-yellow-950">
                  <Clock class="mr-1 h-3 w-3" />
                  {{ webhookStatus.queued }}
                </Badge>
              </div>
              <div class="flex items-center justify-between">
                <span class="text-sm text-muted-foreground">Processed</span>
                <Badge variant="outline" class="bg-green-50 dark:bg-green-950">
                  <CheckCircle2 class="mr-1 h-3 w-3" />
                  {{ webhookStatus.processed }}
                </Badge>
              </div>
              <div class="flex items-center justify-between">
                <span class="text-sm text-muted-foreground">Failed</span>
                <Badge variant="outline" class="bg-red-50 dark:bg-red-950">
                  <XCircle class="mr-1 h-3 w-3" />
                  {{ webhookStatus.failed }}
                </Badge>
              </div>
            </div>
          </CardContent>
        </Card>

        <Card>
          <CardHeader class="flex flex-row items-center justify-between space-y-0 pb-2">
            <CardTitle class="text-sm font-medium">Orders by Status</CardTitle>
            <TrendingUp class="h-4 w-4 text-muted-foreground" />
          </CardHeader>
          <CardContent>
            <div class="space-y-2">
              <div
                v-for="(count, status) in ordersByStatus"
                :key="status"
                class="flex items-center justify-between"
              >
                <span class="text-sm capitalize text-muted-foreground">{{ status }}</span>
                <span class="text-sm font-semibold">{{ count.toLocaleString() }}</span>
              </div>
            </div>
          </CardContent>
        </Card>

        <Card>
          <CardHeader class="flex flex-row items-center justify-between space-y-0 pb-2">
            <CardTitle class="text-sm font-medium">Top Websites</CardTitle>
            <Globe class="h-4 w-4 text-muted-foreground" />
          </CardHeader>
          <CardContent>
            <div class="space-y-2">
              <div
                v-for="website in ordersByWebsite.slice(0, 5)"
                :key="website.name"
                class="flex items-center justify-between"
              >
                <span class="text-sm text-muted-foreground truncate">{{ website.name }}</span>
                <span class="text-sm font-semibold">{{ website.count }}</span>
              </div>
            </div>
          </CardContent>
        </Card>
      </div>

      <!-- Charts Section -->
      <div class="grid gap-4 md:grid-cols-2">
        <Card>
          <CardHeader>
            <CardTitle>Orders Over Time</CardTitle>
            <CardDescription>Last 30 days order trends</CardDescription>
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

        <Card>
          <CardHeader>
            <CardTitle>Revenue Over Time</CardTitle>
            <CardDescription>Last 30 days revenue trends</CardDescription>
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
      </div>

      <!-- Recent Activity -->
      <div class="grid gap-4 md:grid-cols-2">
        <!-- Recent Orders -->
        <Card>
          <CardHeader>
            <div class="flex items-center justify-between">
              <div>
                <CardTitle>Recent Orders</CardTitle>
                <CardDescription>Latest 10 orders from all websites</CardDescription>
              </div>
              <Link
                href="/orders"
                class="text-sm text-primary hover:underline"
              >
                View all
              </Link>
            </div>
          </CardHeader>
          <CardContent>
            <div class="space-y-4">
              <div
                v-for="order in recentOrders"
                :key="order.id"
                class="flex items-center justify-between border-b pb-3 last:border-0"
              >
                <div class="flex-1">
                  <div class="flex items-center gap-2">
                    <Link
                      :href="`/orders/${order.id}`"
                      class="font-medium hover:underline"
                    >
                      #{{ order.wp_order_id }}
                    </Link>
                    <Badge :class="getStatusColor(order.status)" class="text-xs">
                      {{ order.status }}
                    </Badge>
                  </div>
                  <p class="text-sm text-muted-foreground">
                    {{ order.website_name }}
                  </p>
                  <p class="text-xs text-muted-foreground">
                    {{ order.customer_name || order.customer_email }}
                  </p>
                </div>
                <div class="text-right">
                  <div class="font-semibold">
                    {{ formatCurrency(order.total, order.currency) }}
                  </div>
                  <div class="text-xs text-muted-foreground">
                    {{ formatDate(order.created_at_wp) }}
                  </div>
                </div>
              </div>
              <div v-if="recentOrders.length === 0" class="text-center py-8 text-muted-foreground">
                No orders yet
              </div>
            </div>
          </CardContent>
        </Card>

        <!-- Recent Submissions -->
        <Card>
          <CardHeader>
            <CardTitle>Recent Form Submissions</CardTitle>
            <CardDescription>Latest 10 Fluent Forms submissions</CardDescription>
          </CardHeader>
          <CardContent>
            <div class="space-y-4">
              <div
                v-for="submission in recentSubmissions"
                :key="submission.id"
                class="flex items-center justify-between border-b pb-3 last:border-0"
              >
                <div class="flex-1">
                  <div class="font-medium">
                    Entry #{{ submission.entry_id }}
                  </div>
                  <p class="text-sm text-muted-foreground">
                    {{ submission.website_name }}
                  </p>
                  <p class="text-xs text-muted-foreground">
                    Form ID: {{ submission.form_id }}
                  </p>
                  <p v-if="submission.email" class="text-xs text-muted-foreground">
                    {{ submission.email }}
                  </p>
                </div>
                <div class="text-right">
                  <div class="text-xs text-muted-foreground">
                    {{ formatDate(submission.created_at_wp) }}
                  </div>
                </div>
              </div>
              <div v-if="recentSubmissions.length === 0" class="text-center py-8 text-muted-foreground">
                No submissions yet
              </div>
            </div>
          </CardContent>
        </Card>
      </div>

      <!-- Website Health -->
      <Card>
        <CardHeader>
            <div class="flex items-center justify-between">
              <div>
                <CardTitle>Website Health Status</CardTitle>
                <CardDescription>Monitor your connected websites</CardDescription>
              </div>
              <Link
                href="/websites"
                class="text-sm text-primary hover:underline"
              >
                Manage websites
              </Link>
            </div>
        </CardHeader>
        <CardContent>
          <div class="overflow-x-auto">
            <table class="w-full text-sm">
              <thead class="bg-muted/50 text-muted-foreground">
                <tr>
                  <th class="px-4 py-3 text-left">Website</th>
                  <th class="px-4 py-3 text-left">Status</th>
                  <th class="px-4 py-3 text-left">Orders</th>
                  <th class="px-4 py-3 text-left">Submissions</th>
                  <th class="px-4 py-3 text-left">Failed Webhooks</th>
                  <th class="px-4 py-3 text-left">Last Webhook</th>
                </tr>
              </thead>
              <tbody>
                <tr
                  v-for="website in websiteHealth"
                  :key="website.id"
                  class="border-t hover:bg-muted/40 transition"
                >
                  <td class="px-4 py-3">
                    <div class="font-medium">{{ website.name }}</div>
                    <div class="text-xs text-muted-foreground truncate max-w-xs">
                      {{ website.base_url }}
                    </div>
                  </td>
                  <td class="px-4 py-3">
                    <Badge :class="getStatusColor(website.status)">
                      {{ website.status }}
                    </Badge>
                  </td>
                  <td class="px-4 py-3">{{ website.orders_count.toLocaleString() }}</td>
                  <td class="px-4 py-3">{{ website.submissions_count.toLocaleString() }}</td>
                  <td class="px-4 py-3">
                    <div class="flex items-center gap-1">
                      <span>{{ website.failed_webhooks }}</span>
                      <AlertCircle
                        v-if="website.failed_webhooks > 0"
                        class="h-4 w-4 text-red-500"
                      />
                    </div>
                  </td>
                  <td class="px-4 py-3 text-xs text-muted-foreground">
                    {{ formatDate(website.last_webhook_at) }}
                  </td>
                </tr>
                <tr v-if="websiteHealth.length === 0">
                  <td colspan="6" class="px-4 py-8 text-center text-muted-foreground">
                    No websites configured yet
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </CardContent>
      </Card>
    </div>
  </AppLayout>
</template>
