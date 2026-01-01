<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue'
import { type BreadcrumbItem } from '@/types'
import { Head, Link, router } from '@inertiajs/vue3'
import { computed } from 'vue'
import {
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
} from '@/components/ui/card'
import { Button } from '@/components/ui/button'
import { Badge } from '@/components/ui/badge'
import {
  ArrowLeft,
  User,
  Mail,
  ShoppingCart,
  DollarSign,
  TrendingUp,
  Globe,
  Calendar,
  MapPin,
} from 'lucide-vue-next'
import RevenueChart from '@/components/charts/RevenueChart.vue'

interface Props {
  customer: {
    email: string
    name: string | null
    total_orders: number
    total_spent: number
    average_order_value: number
    websites: Array<{ id: number; name: string }>
    country: string | null
    first_order_at: string | null
    last_order_at: string | null
  }
  orders: Array<{
    id: number
    wp_order_id: number
    website_name: string
    status: string
    total: number
    currency: string
    created_at_wp: string
  }>
  revenueOverTime: Array<{ date: string; revenue: number }>
  websiteBreakdown: Array<{
    website_id: number
    website_name: string
    orders_count: number
    total_spent: number
  }>
  countryHistory: Array<{ date: string; country: string }>
}

const props = defineProps<Props>()

const breadcrumbs = computed<BreadcrumbItem[]>(() => [
  { title: 'Customers', href: '/customers' },
  { title: props.customer.email, href: '#' },
])

function getStatusBadgeClass(status: string) {
  const statusMap: Record<string, string> = {
    completed: 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200',
    processing: 'bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-200',
    pending: 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-200',
    cancelled: 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200',
    refunded: 'bg-gray-100 text-gray-800 dark:bg-gray-900 dark:text-gray-200',
    on_hold: 'bg-orange-100 text-orange-800 dark:bg-orange-900 dark:text-orange-200',
  }
  return statusMap[status.toLowerCase()] || 'bg-gray-100 text-gray-800 dark:bg-gray-900 dark:text-gray-200'
}

function formatCurrency(amount: number, currency: string = 'USD'): string {
  return new Intl.NumberFormat('en-US', {
    style: 'currency',
    currency: currency,
  }).format(amount)
}

function formatDate(dateString: string | null): string {
  if (!dateString) return '—'
  const date = new Date(dateString)
  return new Intl.DateTimeFormat('en-US', {
    month: 'short',
    day: 'numeric',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  }).format(date)
}

function formatDateShort(dateString: string | null): string {
  if (!dateString) return '—'
  const date = new Date(dateString)
  return new Intl.DateTimeFormat('en-US', {
    month: 'short',
    day: 'numeric',
    year: 'numeric',
  }).format(date)
}
</script>

<template>
  <Head :title="`Customer: ${customer.email}`" />

  <AppLayout :breadcrumbs="breadcrumbs">
    <div class="flex h-full flex-1 flex-col gap-4 overflow-x-auto rounded-xl p-4">
      <!-- HEADER -->
      <div class="flex items-center justify-between">
        <div class="flex items-center gap-4">
          <Button variant="ghost" size="sm" @click="router.visit('/customers')">
            <ArrowLeft class="mr-2 h-4 w-4" />
            Back to Customers
          </Button>
        </div>
      </div>

      <!-- CUSTOMER SUMMARY CARDS -->
      <div class="grid grid-cols-1 gap-4 md:grid-cols-2 lg:grid-cols-4">
        <Card>
          <CardHeader class="flex flex-row items-center justify-between space-y-0 pb-2">
            <CardTitle class="text-sm font-medium">Total Orders</CardTitle>
            <ShoppingCart class="h-4 w-4 text-muted-foreground" />
          </CardHeader>
          <CardContent>
            <div class="text-2xl font-bold">{{ customer.total_orders }}</div>
          </CardContent>
        </Card>

        <Card>
          <CardHeader class="flex flex-row items-center justify-between space-y-0 pb-2">
            <CardTitle class="text-sm font-medium">Total Spend</CardTitle>
            <DollarSign class="h-4 w-4 text-muted-foreground" />
          </CardHeader>
          <CardContent>
            <div class="text-2xl font-bold">
              {{ formatCurrency(customer.total_spent) }}
            </div>
          </CardContent>
        </Card>

        <Card>
          <CardHeader class="flex flex-row items-center justify-between space-y-0 pb-2">
            <CardTitle class="text-sm font-medium">Average Order Value</CardTitle>
            <TrendingUp class="h-4 w-4 text-muted-foreground" />
          </CardHeader>
          <CardContent>
            <div class="text-2xl font-bold">
              {{ formatCurrency(customer.average_order_value) }}
            </div>
          </CardContent>
        </Card>

        <Card>
          <CardHeader class="flex flex-row items-center justify-between space-y-0 pb-2">
            <CardTitle class="text-sm font-medium">Websites</CardTitle>
            <Globe class="h-4 w-4 text-muted-foreground" />
          </CardHeader>
          <CardContent>
            <div class="text-2xl font-bold">{{ customer.websites.length }}</div>
            <p class="text-xs text-muted-foreground mt-1">
              {{ customer.websites.map((w) => w.name).join(', ') }}
            </p>
          </CardContent>
        </Card>
      </div>

      <!-- CUSTOMER INFO -->
      <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
        <Card>
          <CardHeader>
            <CardTitle>Customer Information</CardTitle>
          </CardHeader>
          <CardContent class="space-y-4">
            <div class="flex items-center gap-3">
              <Mail class="h-5 w-5 text-muted-foreground" />
              <div>
                <div class="text-sm font-medium">Email</div>
                <div class="text-sm text-muted-foreground">{{ customer.email }}</div>
              </div>
            </div>
            <div v-if="customer.name" class="flex items-center gap-3">
              <User class="h-5 w-5 text-muted-foreground" />
              <div>
                <div class="text-sm font-medium">Name</div>
                <div class="text-sm text-muted-foreground">{{ customer.name }}</div>
              </div>
            </div>
            <div v-if="customer.country" class="flex items-center gap-3">
              <MapPin class="h-5 w-5 text-muted-foreground" />
              <div>
                <div class="text-sm font-medium">Country</div>
                <div class="text-sm text-muted-foreground">{{ customer.country }}</div>
              </div>
            </div>
            <div class="flex items-center gap-3">
              <Calendar class="h-5 w-5 text-muted-foreground" />
              <div>
                <div class="text-sm font-medium">First Order</div>
                <div class="text-sm text-muted-foreground">
                  {{ formatDateShort(customer.first_order_at) }}
                </div>
              </div>
            </div>
            <div class="flex items-center gap-3">
              <Calendar class="h-5 w-5 text-muted-foreground" />
              <div>
                <div class="text-sm font-medium">Last Order</div>
                <div class="text-sm text-muted-foreground">
                  {{ formatDateShort(customer.last_order_at) }}
                </div>
              </div>
            </div>
          </CardContent>
        </Card>

        <!-- REVENUE OVER TIME CHART -->
        <Card>
          <CardHeader>
            <CardTitle>Revenue Over Time</CardTitle>
            <CardDescription>Customer revenue by date</CardDescription>
          </CardHeader>
          <CardContent>
            <RevenueChart
              v-if="revenueOverTime.length > 0"
              :data="revenueOverTime"
              :height="200"
            />
            <p v-else class="text-sm text-muted-foreground text-center py-8">
              No revenue data available
            </p>
          </CardContent>
        </Card>
      </div>

      <!-- WEBSITE BREAKDOWN -->
      <Card>
        <CardHeader>
          <CardTitle>Website Breakdown</CardTitle>
          <CardDescription>Orders and revenue by website</CardDescription>
        </CardHeader>
        <CardContent>
          <div class="overflow-x-auto">
            <table class="w-full text-sm">
              <thead>
                <tr class="border-b">
                  <th class="px-4 py-3 text-left font-semibold">Website</th>
                  <th class="px-4 py-3 text-left font-semibold">Orders</th>
                  <th class="px-4 py-3 text-left font-semibold">Total Spend</th>
                </tr>
              </thead>
              <tbody>
                <tr
                  v-for="website in websiteBreakdown"
                  :key="website.website_id"
                  class="border-b"
                >
                  <td class="px-4 py-3">{{ website.website_name }}</td>
                  <td class="px-4 py-3">{{ website.orders_count }}</td>
                  <td class="px-4 py-3">
                    {{ formatCurrency(website.total_spent) }}
                  </td>
                </tr>
                <tr v-if="websiteBreakdown.length === 0">
                  <td colspan="3" class="px-4 py-8 text-center text-muted-foreground">
                    No website data available
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </CardContent>
      </Card>

      <!-- COUNTRY HISTORY -->
      <Card v-if="countryHistory.length > 0">
        <CardHeader>
          <CardTitle>Country History</CardTitle>
          <CardDescription>Countries from customer orders</CardDescription>
        </CardHeader>
        <CardContent>
          <div class="space-y-2">
            <div
              v-for="(entry, index) in countryHistory"
              :key="index"
              class="flex items-center justify-between border-b pb-2"
            >
              <div class="flex items-center gap-2">
                <MapPin class="h-4 w-4 text-muted-foreground" />
                <span class="font-medium">{{ entry.country }}</span>
              </div>
              <span class="text-sm text-muted-foreground">
                {{ formatDateShort(entry.date) }}
              </span>
            </div>
          </div>
        </CardContent>
      </Card>

      <!-- ORDERS LIST -->
      <Card>
        <CardHeader>
          <CardTitle>Orders</CardTitle>
          <CardDescription>All orders for this customer</CardDescription>
        </CardHeader>
        <CardContent>
          <div class="overflow-x-auto">
            <table class="w-full text-sm">
              <thead>
                <tr class="border-b">
                  <th class="px-4 py-3 text-left font-semibold">Order ID</th>
                  <th class="px-4 py-3 text-left font-semibold">Website</th>
                  <th class="px-4 py-3 text-left font-semibold">Status</th>
                  <th class="px-4 py-3 text-left font-semibold">Total</th>
                  <th class="px-4 py-3 text-left font-semibold">Date</th>
                </tr>
              </thead>
              <tbody>
                <tr
                  v-for="order in orders"
                  :key="order.id"
                  class="border-b transition-colors hover:bg-muted/50"
                >
                  <td class="px-4 py-3">
                    <Link
                      :href="`/orders/${order.id}`"
                      class="font-medium text-primary hover:underline"
                    >
                      #{{ order.wp_order_id }}
                    </Link>
                  </td>
                  <td class="px-4 py-3">{{ order.website_name }}</td>
                  <td class="px-4 py-3">
                    <Badge :class="getStatusBadgeClass(order.status)">
                      {{ order.status }}
                    </Badge>
                  </td>
                  <td class="px-4 py-3">
                    {{ formatCurrency(order.total, order.currency) }}
                  </td>
                  <td class="px-4 py-3">
                    {{ formatDate(order.created_at_wp) }}
                  </td>
                </tr>
                <tr v-if="orders.length === 0">
                  <td colspan="5" class="px-4 py-8 text-center text-muted-foreground">
                    No orders found
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

