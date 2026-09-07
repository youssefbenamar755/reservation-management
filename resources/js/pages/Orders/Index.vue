<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue'
import { type BreadcrumbItem } from '@/types'
import { Head, router, usePage } from '@inertiajs/vue3'
import { Button } from '@/components/ui/button'
import { Badge } from '@/components/ui/badge'
import { RefreshCw, ChevronLeft, ChevronRight } from 'lucide-vue-next'
import { computed, ref, onMounted, onUnmounted, watch } from 'vue'
import { Link } from '@inertiajs/vue3'
import { useEchoNotifications } from '@/composables/useEchoNotifications'
import { useToast } from '@/composables/useToast'
import { getEcho } from '@/lib/echo'
import { runWooCommerceSync, summarizeWooCommerceSyncResults, type WooCommerceSyncCounts } from '@/lib/wooCommerceSync'
import { createAutoRefresh, refreshOrdersSnapshot, type AutoRefreshState } from '@/lib/liveOrders'
import { subscribeToOrders, type OrdersConnectionState } from '@/lib/ordersPush'

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

const filterInputs = ref(readAppliedFilters())
let searchTimer: ReturnType<typeof setTimeout> | null = null
let filterRequest: { params: string; cancel?: () => void } | null = null
let dispatchingFilters = false
let filtersDisposed = false

function readAppliedFilters() {
  return {
    website_id: String(props.filters.website_id || ''),
    status: props.filters.status || '',
    search: props.filters.search || '',
  }
}

function clearSearchTimer() {
  if (searchTimer !== null) clearTimeout(searchTimer)
  searchTimer = null
}

function cancelFilterRequest() {
  const previous = filterRequest
  filterRequest = null
  previous?.cancel?.()
}

function cancelPendingFilters(restore = false) {
  clearSearchTimer()
  cancelFilterRequest()
  if (restore) filterInputs.value = readAppliedFilters()
}

function submitFilters() {
  clearSearchTimer()
  const params = JSON.stringify(filterInputs.value)
  if (filterRequest?.params === params) return
  cancelFilterRequest()
  if (filtersDisposed || params === JSON.stringify(readAppliedFilters())) return
  const request: { params: string; cancel?: () => void } = { params }
  filterRequest = request
  dispatchingFilters = true
  try {
    router.get('/orders', { ...filterInputs.value }, {
      preserveState: true,
      preserveScroll: true,
      replace: true,
      onCancelToken: (token) => {
        if (filterRequest !== request) token.cancel()
        else request.cancel = () => token.cancel()
      },
      onSuccess: () => {
        if (filterRequest === request) filterInputs.value = readAppliedFilters()
      },
      onFinish: () => {
        if (filterRequest === request) filterRequest = null
      },
    })
  } finally {
    dispatchingFilters = false
  }
}

function updateSearch(value: string) {
  clearSearchTimer()
  cancelFilterRequest()
  filterInputs.value.search = value
  if (JSON.stringify(filterInputs.value) === JSON.stringify(readAppliedFilters())) return
  searchTimer = setTimeout(submitFilters, 300)
}

function updateFilter(key: 'website_id' | 'status', value: string) {
  filterInputs.value[key] = value
  submitFilters()
}

// Live snapshots keep the draft; returned filters and history restore the inputs.
watch(() => props.filters, () => {
  if (!filterRequest && searchTimer === null) filterInputs.value = readAppliedFilters()
}, { deep: true })

const isSyncing = ref(false)
let syncAbortController: AbortController | null = null
const syncProgress = ref('') // e.g. "Syncing Website A (1 / 3)…"
const selectedWebsiteId = computed(() => props.filters.website_id)
const page = usePage()
const toast = useToast()

const { onNotification, offNotification } = useEchoNotifications()

const online = ref(true)
const visible = ref(true)
const connectionState = ref<OrdersConnectionState>('connecting')
const refreshState = ref<AutoRefreshState>({ refreshing: false, lastCheckedAt: null, hasError: false })
const refreshLabel = computed(() => {
  if (!online.value) return 'Live updates disconnected — Sync available'
  if (refreshState.value.refreshing) return 'Updating orders…'
  if (connectionState.value === 'connecting') return 'Connecting to live updates…'
  if (connectionState.value === 'reconnecting') return 'Reconnecting to live updates…'
  if (connectionState.value === 'disconnected') return 'Live updates disconnected — Sync available'
  if (refreshState.value.hasError) return 'Refresh failed — Sync available'
  return 'Live updates connected'
})
const lastChecked = computed(() => refreshState.value.lastCheckedAt === null
  ? ''
  : new Date(refreshState.value.lastCheckedAt).toLocaleTimeString())

let mounted = false
const navigationVisits = new Set<object>()
const cleanupListeners: Array<() => void> = []
let stopOrdersSubscription: (() => void) | null = null

const liveRefresh = createAutoRefresh({
  isAvailable: () => mounted && online.value && visible.value,
  onState: (state) => { refreshState.value = state },
  refresh: ({ isCurrent, complete }) => {
    const controller = new AbortController()
    void refreshOrdersSnapshot({
      getUrl: () => window.location.href,
      isCurrent,
      signal: controller.signal,
      apply: (orders, stillCurrent) => new Promise<boolean>((resolve) => {
        let applied = false
        // replaceProp preserves URL, filters, scroll, and pagination. Its updater
        // runs from Inertia's queue, so check the generation and URL again here.
        router.replaceProp('orders', (currentOrders: unknown) => {
          if (!stillCurrent()) return currentOrders
          applied = true
          return orders
        }, { onFinish: () => resolve(applied) })
      }),
    }).then(complete)
    return () => controller.abort()
  },
})

function requestOrderRefresh(data?: any) {
  const websiteId = data?.website_id ?? data?.data?.website_id
  if (selectedWebsiteId.value && websiteId && String(websiteId) !== String(selectedWebsiteId.value)) return
  liveRefresh.request()
}

const onOrderNotification = (data: any) => {
  if (data?.type === 'order') {
    requestOrderRefresh(data)
  }
}

const onOrderReceived = (data: any) => {
  requestOrderRefresh(data)
}

function updateAvailability() {
  online.value = navigator.onLine
  visible.value = !document.hidden
  liveRefresh.availabilityChanged()
}

function suspendForHistoryNavigation() {
  cancelPendingFilters(true)
  liveRefresh.suspend()
}

// Query changes within this component must invalidate a previous snapshot too.
watch(() => page.url, () => {
  if (!mounted) return
  liveRefresh.suspend()
  if (navigationVisits.size === 0) liveRefresh.resume()
}, { flush: 'sync' })

onMounted(() => {
  mounted = true
  const user = (page.props as any).auth?.user
  if (!user) return
  online.value = navigator.onLine
  visible.value = !document.hidden

  cleanupListeners.push(
    router.on('before', ({ detail: { visit } }) => {
      if (visit.async) return
      if (!dispatchingFilters) cancelPendingFilters(true)
      liveRefresh.suspend()
      // A navigation cancelled by another listener has no start/finish event.
      queueMicrotask(() => {
        if (mounted && navigationVisits.size === 0) liveRefresh.resume()
      })
    }),
    router.on('start', ({ detail: { visit } }) => {
      if (visit.async) return
      navigationVisits.add(visit)
      liveRefresh.suspend()
    }),
    router.on('finish', ({ detail: { visit } }) => {
      if (visit.async) return
      navigationVisits.delete(visit)
      if (mounted && navigationVisits.size === 0) liveRefresh.resume()
    }),
    router.on('navigate', () => {
      if (mounted && navigationVisits.size === 0) liveRefresh.resume()
    }),
  )
  document.addEventListener('visibilitychange', updateAvailability)
  window.addEventListener('online', updateAvailability)
  window.addEventListener('offline', updateAvailability)
  window.addEventListener('focus', updateAvailability)
  window.addEventListener('popstate', suspendForHistoryNavigation)

  try {
    onNotification('orders-index', onOrderNotification, user.id)
    stopOrdersSubscription = subscribeToOrders({
      userId: user.id,
      getEcho,
      onOrder: onOrderReceived,
      onSubscribed: () => liveRefresh.request(),
      onState: (state) => { connectionState.value = state },
    })
  } catch (e) {
    console.error('Echo setup failed:', e)
  }
  liveRefresh.start()
})
onUnmounted(() => {
  mounted = false
  filtersDisposed = true
  cancelPendingFilters()
  liveRefresh.stop()
  syncAbortController?.abort()
  offNotification('orders-index')
  stopOrdersSubscription?.()
  cleanupListeners.forEach((remove) => remove())
  navigationVisits.clear()
  document.removeEventListener('visibilitychange', updateAvailability)
  window.removeEventListener('online', updateAvailability)
  window.removeEventListener('offline', updateAvailability)
  window.removeEventListener('focus', updateAvailability)
  window.removeEventListener('popstate', suspendForHistoryNavigation)
})

async function syncOrdersFromWooCommerce() {
  if (isSyncing.value) return
  isSyncing.value = true
  syncProgress.value = ''

  // Both modes request one page at a time; the server keeps the resume cursor.
  const websiteId = selectedWebsiteId.value
  const websites: { id: number; name: string }[] = websiteId
    ? props.websites.filter((website) => String(website.id) === String(websiteId))
    : props.websites

  if (!websites.length) {
    isSyncing.value = false
    toast.error('No websites configured.')
    return
  }

  const syncResults: Array<WooCommerceSyncCounts | null> = []
  const errors: string[] = []
  const controller = new AbortController()
  syncAbortController = controller

  for (let i = 0; i < websites.length; i++) {
    const w = websites[i]
    syncProgress.value = `Syncing ${w.name} (${i + 1} / ${websites.length})…`

    try {
      const csrfMeta = document.querySelector('meta[name="csrf-token"]') as HTMLMetaElement | null
      const csrf = csrfMeta?.content ?? ''

      const result = await runWooCommerceSync(
        () => fetch(`/websites/${w.id}/sync-woocommerce-orders`, {
          method: 'POST',
          signal: controller.signal,
          headers: {
            'Content-Type': 'application/json',
            'X-CSRF-TOKEN': csrf,
            Accept: 'application/json',
          },
          body: JSON.stringify({}),
        }),
        (counts) => {
          syncProgress.value = `Syncing ${w.name} (${i + 1} / ${websites.length}) — ${counts.newOrders} new, ${counts.updatedOrders} updated…`
        },
        { signal: controller.signal },
      )
      syncResults.push(result)
    } catch (e: any) {
      if (controller.signal.aborted) {
        isSyncing.value = false
        syncProgress.value = ''
        syncAbortController = null
        return
      }
      errors.push(`${w.name}: ${e?.message ?? 'network error'}`)
    }
  }

  isSyncing.value = false
  syncProgress.value = ''
  syncAbortController = null

  liveRefresh.request(0)

  if (errors.length === 0) {
    toast.success(summarizeWooCommerceSyncResults(syncResults, websiteId ? websites[0].name : 'All websites'))
  } else {
    toast.error(`Synced with errors: ${errors.slice(0, 3).join(', ')}`)
  }
}

function goToPage(url: string | null) {
  if (!url) return
  cancelPendingFilters(true)
  
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
  } catch {
    // Fallback: use router.visit if URL parsing fails
    router.visit(url, { preserveState: true, preserveScroll: true })
  }
}

/**
 * Safely decode HTML entities without rendering HTML tags.
 * SSR-safe: uses DOM when available, otherwise pure JS decode for Laravel pagination entities.
 */
function decodeHtmlEntities(text: string): string {
  if (!text) return ''
  if (typeof document !== 'undefined') {
    const textarea = document.createElement('textarea')
    textarea.innerHTML = text
    return textarea.value
  }
  return text
    .replace(/&laquo;/g, '\u00AB')
    .replace(/&raquo;/g, '\u00BB')
    .replace(/&lsaquo;/g, '\u2039')
    .replace(/&rsaquo;/g, '\u203A')
    .replace(/&#(\d+);/g, (_, n) => String.fromCharCode(parseInt(n, 10)))
    .replace(/&#x([0-9a-fA-F]+);/g, (_, n) => String.fromCharCode(parseInt(n, 16)))
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
      <div class="flex flex-wrap items-center justify-between gap-2">
        <h1 class="text-2xl font-bold">Orders</h1>
        <div class="flex flex-wrap items-center gap-2 text-xs text-muted-foreground" aria-label="Automatic order updates">
          <span
            aria-hidden="true"
            class="h-2 w-2 rounded-full"
            :class="!online || connectionState !== 'connected' || refreshState.hasError ? 'bg-amber-500' : 'bg-green-500'"
          />
          <span>{{ refreshLabel }}</span>
          <span v-if="lastChecked">· Last checked {{ lastChecked }}</span>
        </div>
      </div>

      <!-- FILTERS -->
      <div
        class="grid grid-cols-1 gap-4 rounded-xl border border-sidebar-border/70 p-4 md:grid-cols-5 dark:border-sidebar-border"
      >
        <select
          class="rounded-md border bg-background px-3 py-2 text-sm"
          aria-label="Website"
          :value="filterInputs.website_id"
          @change="updateFilter('website_id', ($event.target as HTMLSelectElement).value)"
        >
          <option value="">All Websites</option>
          <option v-for="w in websites" :key="w.id" :value="w.id">
            {{ w.name }}
          </option>
        </select>

        <select
          class="rounded-md border bg-background px-3 py-2 text-sm"
          aria-label="Order status"
          :value="filterInputs.status"
          @change="updateFilter('status', ($event.target as HTMLSelectElement).value)"
        >
          <option value="">All Status</option>
          <option value="completed">Completed</option>
          <option value="processing">Processing</option>
          <option value="pending">Pending</option>
          <option value="cancelled">Cancelled</option>
        </select>

        <input
          type="search"
          class="rounded-md border bg-background px-3 py-2 text-sm"
          aria-label="Search orders"
          placeholder="Search by Order ID, Customer Name, or Email"
          :value="filterInputs.search"
          @input="updateSearch(($event.target as HTMLInputElement).value)"
          @keydown.enter.prevent="submitFilters"
        />

        <Button
          type="button"
          variant="outline"
          class="w-full"
          :disabled="isSyncing"
          @click="syncOrdersFromWooCommerce"
        >
          <RefreshCw v-if="isSyncing" class="mr-2 h-4 w-4 animate-spin" />
          {{ syncProgress || (isSyncing ? 'Syncing...' : 'Sync from WooCommerce') }}
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
