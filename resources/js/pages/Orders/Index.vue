<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue'
import { type BreadcrumbItem } from '@/types'
import { Head, router, usePage } from '@inertiajs/vue3'
import { Button } from '@/components/ui/button'
import { Badge } from '@/components/ui/badge'
import { RefreshCw, ChevronLeft, ChevronRight } from 'lucide-vue-next'
import { computed, ref, onMounted, onUnmounted } from 'vue'
import { Link } from '@inertiajs/vue3'
import { useEchoNotifications } from '@/composables/useEchoNotifications'
import { useToast } from '@/composables/useToast'

const props = defineProps<{
  orders: any
  websites: any[]
  filters: {
    website_id?: string
    status?: string
    search?: string
  }
}>()

const breadcrumbs: BreadcrumbItem[] = [
  {
    title: 'Orders',
    href: '/orders',
  },
]

function updateFilter(key: string, value: string) {
  router.get(
    '/orders',
    { ...props.filters, [key]: value },
    { preserveState: true, replace: true }
  )
}

const isSyncing = ref(false)
const selectedWebsiteId = computed(() => props.filters.website_id)
const page = usePage()
const toast = useToast()

const { onNotification, offNotification } = useEchoNotifications()

const onOrderNotification = (data: any) => {
  if (data?.type === 'order') {
    router.reload({ only: ['orders'] })
    toast.success('New order received!')
  }
}
onMounted(() => {
  const user = (page.props as any).auth?.user
  if (!user) return
  try {
    onNotification('orders-index', onOrderNotification, user.id)
  } catch (e) {
    console.error('Echo setup failed:', e)
  }
})
onUnmounted(() => {
  offNotification('orders-index')
})

function syncOrdersFromWooCommerce() {
  isSyncing.value = true

  const url = selectedWebsiteId.value
    ? `/websites/${selectedWebsiteId.value}/sync-woocommerce-orders`
    : `/websites/sync-all-woocommerce-orders`

  router.post(
    url,
    {},
    {
      preserveScroll: true,
      preserveState: true,
      onFinish: () => {
        isSyncing.value = false
      },
    }
  )
}

function goToPage(url: string | null) {
  if (!url) return
  
  // Parse URL to extract path and query parameters
  // Handle both relative (/orders?page=2) and absolute URLs (http://domain.com/orders?page=2)
  try {
    const urlObj = url.startsWith('http') 
      ? new URL(url)
      : new URL(url, window.location.origin)
    
    const path = urlObj.pathname
    const params: Record<string, string> = {}
    
    // Extract query parameters
    urlObj.searchParams.forEach((value, key) => {
      params[key] = value
    })
    
    router.get(path, params, {
      preserveState: true,
      preserveScroll: true,
      replace: false,
    })
  } catch (e) {
    // Fallback: use router.visit if URL parsing fails
    router.visit(url, { preserveState: true, preserveScroll: true })
  }
}

/**
 * Safely decode HTML entities without rendering HTML tags
 * This prevents XSS while still allowing entities like &laquo; and &raquo; to display correctly
 */
function decodeHtmlEntities(text: string): string {
  if (!text) return ''
  const textarea = document.createElement('textarea')
  textarea.innerHTML = text
  return textarea.value
}

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

function formatCurrency(amount: string | number, currency?: string | null) {
  const numAmount = typeof amount === 'string' ? parseFloat(amount) || 0 : amount || 0
  const currencyCode = currency || 'USD'
  return new Intl.NumberFormat('en-US', {
    style: 'currency',
    currency: currencyCode,
  }).format(numAmount)
}

function formatDate(dateString: string | null) {
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
</script>

<template>
  <Head title="Orders" />

  <AppLayout :breadcrumbs="breadcrumbs">
    <div
      class="flex h-full flex-1 flex-col gap-4 overflow-x-auto rounded-xl p-4"
    >

      <!-- FILTERS -->
      <div
        class="grid grid-cols-1 gap-4 rounded-xl border border-sidebar-border/70 p-4 md:grid-cols-5 dark:border-sidebar-border"
      >
        <select
          class="rounded-md border bg-background px-3 py-2 text-sm"
          @change="updateFilter('website_id', ($event.target as HTMLSelectElement).value)"
        >
          <option value="">All Websites</option>
          <option v-for="w in websites" :key="w.id" :value="w.id">
            {{ w.name }}
          </option>
        </select>

        <select
          class="rounded-md border bg-background px-3 py-2 text-sm"
          @change="updateFilter('status', ($event.target as HTMLSelectElement).value)"
        >
          <option value="">All Status</option>
          <option value="completed">Completed</option>
          <option value="processing">Processing</option>
          <option value="pending">Pending</option>
          <option value="cancelled">Cancelled</option>
        </select>

        <input
          type="text"
          class="rounded-md border bg-background px-3 py-2 text-sm"
          placeholder="Search by Order ID, Customer Name, or Email"
          :value="props.filters.search"
          @input="updateFilter('search', ($event.target as HTMLInputElement).value)"
        />

        <Button
          type="button"
          variant="outline"
          class="w-full"
          :disabled="isSyncing"
          @click="syncOrdersFromWooCommerce"
        >
          <RefreshCw v-if="isSyncing" class="mr-2 h-4 w-4 animate-spin" />
          {{ isSyncing ? 'Syncing...' : 'Sync from WooCommerce' }}
        </Button>
      </div>

      <!-- ORDERS TABLE -->
      <div
        class="relative flex-1 overflow-hidden rounded-lg border bg-card shadow-sm"
      >
        <div class="overflow-x-auto">
          <table class="w-full border-collapse text-sm">
            <thead>
              <tr class="border-b bg-muted/50">
                <th class="px-6 py-4 text-left font-semibold text-foreground">
                  Order ID
                </th>
                <th class="px-6 py-4 text-left font-semibold text-foreground">
                  Website
                </th>
                <th class="px-6 py-4 text-left font-semibold text-foreground">
                  Status
                </th>
                <th class="px-6 py-4 text-left font-semibold text-foreground">
                  Total
                </th>
                <th class="px-6 py-4 text-left font-semibold text-foreground">
                  Customer
                </th>
                <th class="px-6 py-4 text-left font-semibold text-foreground">
                  Date
                </th>
              </tr>
            </thead>

            <tbody>
              <tr
                v-for="(order, index) in orders.data"
                :key="order.id"
                class="border-b transition-colors"
                :class="[
                  Number(index) % 2 === 0 ? 'bg-background' : 'bg-muted/20',
                  'hover:bg-muted/50 cursor-pointer'
                ]"
              >
                <td class="px-6 py-4">
                  <Link
                    :href="`/orders/${order.id}`"
                    class="font-semibold text-primary hover:underline"
                  >
                    #{{ order.wp_order_id }}
                  </Link>
                </td>

                <td class="px-6 py-4">
                  <div class="font-medium text-foreground uppercase">
                    {{ order.website.name }}
                  </div>
                </td>

                <td class="px-6 py-4">
                  <Badge
                    :class="getStatusBadgeClass(order.status)"
                    class="font-medium"
                  >
                    {{ order.status }}
                  </Badge>
                </td>

                <td class="px-6 py-4">
                  <span class="font-semibold text-foreground">
                    {{ formatCurrency(order.total, order.currency) }}
                  </span>
                </td>

                <td class="px-6 py-4">
                  <div class="text-muted-foreground">
                    {{ order.customer_email || '—' }}
                  </div>
                  <div
                    v-if="order.customer_name"
                    class="text-xs text-muted-foreground mt-1"
                  >
                    {{ order.customer_name }}
                  </div>
                </td>

                <td class="px-6 py-4">
                  <div class="text-muted-foreground whitespace-nowrap">
                    {{ formatDate(order.created_at_wp) }}
                  </div>
                </td>
              </tr>

              <tr v-if="orders.data.length === 0">
                <td
                  colspan="6"
                  class="px-6 py-12 text-center text-muted-foreground"
                >
                  <div class="flex flex-col items-center justify-center gap-2">
                    <p class="text-base font-medium">No orders found</p>
                    <p class="text-sm">Try adjusting your filters or sync orders from WooCommerce</p>
                  </div>
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <!-- Pagination -->
        <div
          v-if="orders && orders.total > 0"
          class="flex flex-col gap-4 border-t bg-muted/30 px-6 py-4 sm:flex-row sm:items-center sm:justify-between"
        >
          <!-- Pagination Info -->
          <div v-if="orders.total" class="text-sm text-muted-foreground">
            Showing
            <span class="font-semibold text-foreground">{{ orders.from || 0 }}</span>
            to
            <span class="font-semibold text-foreground">{{ orders.to || 0 }}</span>
            of
            <span class="font-semibold text-foreground">{{ orders.total || 0 }}</span>
            results
            <span v-if="orders.last_page > 1" class="ml-2">
              (Page {{ orders.current_page }} of {{ orders.last_page }})
            </span>
          </div>

          <!-- Pagination Controls -->
          <div
            v-if="orders.links && orders.links.length > 1"
            class="flex items-center gap-2"
          >
            <!-- Previous Button -->
            <Button
              variant="outline"
              size="sm"
              :disabled="!orders.links[0]?.url || orders.current_page === 1"
              @click="goToPage(orders.links[0]?.url)"
              class="gap-1"
            >
              <ChevronLeft class="h-4 w-4" />
              <span class="hidden sm:inline">Previous</span>
            </Button>

            <!-- Page Numbers -->
            <div class="flex items-center gap-1">
              <template
                v-for="(link, index) in orders.links"
              >
                <Button
                  v-if="link.label && Number(index) > 0 && Number(index) < orders.links.length - 1"
                  :key="`btn-${index}`"
                  class="hidden sm:block"
                  variant="outline"
                  size="sm"
                  :class="{
                    'bg-primary text-primary-foreground hover:bg-primary/90': link.active,
                    'pointer-events-none opacity-50': !link.url,
                    'min-w-[2.5rem]': true,
                  }"
                  @click="goToPage(link.url)"
                >
                  <span>{{ decodeHtmlEntities(link.label) }}</span>
                </Button>
                <span
                  v-else-if="link.label === '...' && Number(index) > 0 && Number(index) < orders.links.length - 1"
                  :key="`dots-${index}`"
                  class="px-2 py-1 text-muted-foreground hidden sm:block"
                >
                  ...
                </span>
              </template>
            </div>

            <!-- Next Button -->
            <Button
              variant="outline"
              size="sm"
              :disabled="!orders.links[orders.links.length - 1]?.url || orders.current_page === orders.last_page"
              @click="goToPage(orders.links[orders.links.length - 1]?.url)"
              class="gap-1"
            >
              <span class="hidden sm:inline">Next</span>
              <ChevronRight class="h-4 w-4" />
            </Button>
          </div>
        </div>
      </div>

    </div>
  </AppLayout>
</template>
