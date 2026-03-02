<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue'
import { type BreadcrumbItem } from '@/types'
import { Head, router, Link } from '@inertiajs/vue3'
import { Button } from '@/components/ui/button'
import { Badge } from '@/components/ui/badge'
import { ChevronLeft, ChevronRight, ArrowUpDown, ArrowUp, ArrowDown } from 'lucide-vue-next'
import { ref } from 'vue'

interface Customer {
  email: string
  orders_count: number
  total_spent: number
  average_order_value: number
  websites: string[]
  country: string | null
  first_order_at: string | null
  last_order_at: string | null
}

interface Props {
  customers?: {
    data: Customer[]
    links: any[]
    current_page?: number
    last_page?: number
    per_page?: number
    total?: number
    from?: number
    to?: number
    meta?: {
      current_page: number
      last_page: number
      per_page: number
      total: number
    }
  }
  websites: Array<{ id: number; name: string }>
  countries: string[]
  filters: {
    start_date?: string
    end_date?: string
    website_ids?: number[]
    country?: string
    min_spend?: string
    payment_status?: string
    sort_by?: string
    sort_dir?: string
  }
}

const props = defineProps<Props>()

const breadcrumbs: BreadcrumbItem[] = [
  {
    title: 'Customers',
    href: '/customers',
  },
]

// Filter state
const startDate = ref(props.filters.start_date || '')
const endDate = ref(props.filters.end_date || '')
const selectedWebsiteIds = ref<number[]>(
  Array.isArray(props.filters.website_ids)
    ? props.filters.website_ids.map((id) => Number(id))
    : []
)
const selectedCountry = ref(props.filters.country || '')
const minSpend = ref(props.filters.min_spend || '')
const paymentStatus = ref(props.filters.payment_status || 'all')
const sortBy = ref(props.filters.sort_by || 'last_order_at')
const sortDir = ref(props.filters.sort_dir || 'desc')

function updateFilter(key: string, value: any) {
  const params: Record<string, any> = {
    ...props.filters,
    [key]: value,
  }

  // Remove empty filters
  if (!params.start_date) delete params.start_date
  if (!params.end_date) delete params.end_date
  if (!params.country) delete params.country
  if (!params.min_spend) delete params.min_spend
  if (params.payment_status === 'all') delete params.payment_status
  if (Array.isArray(params.website_ids) && params.website_ids.length === 0) {
    delete params.website_ids
  }

  router.get('/customers', params, {
    preserveState: true,
    replace: true,
  })
}

function applyFilters() {
  const params: Record<string, any> = {}

  if (startDate.value) params.start_date = startDate.value
  if (endDate.value) params.end_date = endDate.value
  if (selectedWebsiteIds.value.length > 0) {
    params.website_ids = selectedWebsiteIds.value
  }
  if (selectedCountry.value) params.country = selectedCountry.value
  if (minSpend.value) params.min_spend = minSpend.value
  if (paymentStatus.value !== 'all') params.payment_status = paymentStatus.value
  if (sortBy.value) params.sort_by = sortBy.value
  if (sortDir.value) params.sort_dir = sortDir.value

  router.get('/customers', params, {
    preserveState: false,
    preserveScroll: false,
  })
}

function toggleWebsite(websiteId: number) {
  const index = selectedWebsiteIds.value.indexOf(websiteId)
  if (index > -1) {
    selectedWebsiteIds.value.splice(index, 1)
  } else {
    selectedWebsiteIds.value.push(websiteId)
  }
  applyFilters()
}

function toggleSort(column: string) {
  if (sortBy.value === column) {
    sortDir.value = sortDir.value === 'asc' ? 'desc' : 'asc'
  } else {
    sortBy.value = column
    sortDir.value = 'desc'
  }
  updateFilter('sort_by', sortBy.value)
  updateFilter('sort_dir', sortDir.value)
}

function getSortIcon(column: string) {
  if (sortBy.value !== column) {
    return ArrowUpDown
  }
  return sortDir.value === 'asc' ? ArrowUp : ArrowDown
}

function formatCurrency(amount: number): string {
  return new Intl.NumberFormat('en-US', {
    style: 'currency',
    currency: 'USD',
  }).format(amount)
}

function formatDate(dateString: string | null): string {
  if (!dateString) return '—'
  const date = new Date(dateString)
  return new Intl.DateTimeFormat('en-US', {
    month: 'short',
    day: 'numeric',
    year: 'numeric',
  }).format(date)
}

function goToPage(url: string | null) {
  if (!url) return
  
  try {
    const urlObj = url.startsWith('http') 
      ? new URL(url)
      : new URL(url, window.location.origin)
    
    const path = urlObj.pathname
    const params: Record<string, string> = {}
    
    urlObj.searchParams.forEach((value, key) => {
      params[key] = value
    })
    
    router.get(path, params, {
      preserveState: true,
      preserveScroll: true,
      replace: false,
    })
  } catch (e) {
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

const customerEmail = (email: string) => encodeURIComponent(email)
</script>

<template>
  <Head title="Customers" />

  <AppLayout :breadcrumbs="breadcrumbs">
    <div
      class="flex h-full flex-1 flex-col gap-4 overflow-x-auto rounded-xl p-4"
    >
      <!-- FILTERS -->
      <div
        class="grid grid-cols-1 gap-4 rounded-xl border border-sidebar-border/70 p-4 md:grid-cols-6 dark:border-sidebar-border"
      >
        <div class="md:col-span-2">
          <label class="mb-1 block text-xs font-medium text-muted-foreground">
            Date Range
          </label>
          <div class="flex gap-2">
            <input
              type="date"
              class="flex-1 rounded-md border bg-background px-3 py-2 text-sm"
              :value="startDate"
              @change="updateFilter('start_date', ($event.target as HTMLInputElement).value)"
            />
            <input
              type="date"
              class="flex-1 rounded-md border bg-background px-3 py-2 text-sm"
              :value="endDate"
              @change="updateFilter('end_date', ($event.target as HTMLInputElement).value)"
            />
          </div>
        </div>

        <div>
          <label class="mb-1 block text-xs font-medium text-muted-foreground">
            Website(s)
          </label>
          <select
            class="w-full rounded-md border bg-background px-3 py-2 text-sm"
            @change="toggleWebsite(Number(($event.target as HTMLSelectElement).value))"
          >
            <option value="">Select Website</option>
            <option
              v-for="w in websites"
              :key="w.id"
              :value="w.id"
              :selected="selectedWebsiteIds.includes(w.id)"
            >
              {{ w.name }}
            </option>
          </select>
          <div v-if="selectedWebsiteIds.length > 0" class="mt-2 flex flex-wrap gap-1">
            <Badge
              v-for="id in selectedWebsiteIds"
              :key="id"
              variant="secondary"
              class="cursor-pointer"
              @click="toggleWebsite(id)"
            >
              {{ websites.find((w) => w.id === id)?.name }}
              ×
            </Badge>
          </div>
        </div>

        <div>
          <label class="mb-1 block text-xs font-medium text-muted-foreground">
            Country
          </label>
          <select
            class="w-full rounded-md border bg-background px-3 py-2 text-sm"
            :value="selectedCountry"
            @change="updateFilter('country', ($event.target as HTMLSelectElement).value)"
          >
            <option value="">All Countries</option>
            <option v-for="c in countries" :key="c" :value="c">
              {{ c }}
            </option>
          </select>
        </div>

        <div>
          <label class="mb-1 block text-xs font-medium text-muted-foreground">
            Min Spend
          </label>
          <input
            type="number"
            step="0.01"
            class="w-full rounded-md border bg-background px-3 py-2 text-sm"
            placeholder="0.00"
            :value="minSpend"
            @input="updateFilter('min_spend', ($event.target as HTMLInputElement).value)"
          />
        </div>

        <div>
          <label class="mb-1 block text-xs font-medium text-muted-foreground">
            Order Status
          </label>
          <select
            class="w-full rounded-md border bg-background px-3 py-2 text-sm"
            :value="paymentStatus"
            @change="updateFilter('payment_status', ($event.target as HTMLSelectElement).value)"
          >
            <option value="all">All</option>
            <option value="paid">Paid</option>
            <option value="pending">Pending</option>
          </select>
        </div>
      </div>

      <!-- CUSTOMERS TABLE -->
      <div
        class="relative flex-1 overflow-hidden rounded-lg border bg-card shadow-sm"
      >
        <div class="overflow-x-auto">
          <table class="w-full border-collapse text-sm">
            <thead>
              <tr class="border-b bg-muted/50">
                <th class="px-6 py-4 text-left font-semibold text-foreground">
                  Customer Email
                </th>
                <th
                  class="px-6 py-4 text-left font-semibold text-foreground cursor-pointer hover:bg-muted/70"
                  @click="toggleSort('orders_count')"
                >
                  <div class="flex items-center gap-2">
                    Total Orders
                    <component :is="getSortIcon('orders_count')" class="h-4 w-4" />
                  </div>
                </th>
                <th
                  class="px-6 py-4 text-left font-semibold text-foreground cursor-pointer hover:bg-muted/70"
                  @click="toggleSort('total_spent')"
                >
                  <div class="flex items-center gap-2">
                    Total Spend
                    <component :is="getSortIcon('total_spent')" class="h-4 w-4" />
                  </div>
                </th>
                <th class="px-6 py-4 text-left font-semibold text-foreground">
                  AOV
                </th>
                <th class="px-6 py-4 text-left font-semibold text-foreground">
                  Website(s)
                </th>
                <th class="px-6 py-4 text-left font-semibold text-foreground">
                  Country
                </th>
                <th class="px-6 py-4 text-left font-semibold text-foreground">
                  First Order
                </th>
                <th
                  class="px-6 py-4 text-left font-semibold text-foreground cursor-pointer hover:bg-muted/70"
                  @click="toggleSort('last_order_at')"
                >
                  <div class="flex items-center gap-2">
                    Last Order
                    <component :is="getSortIcon('last_order_at')" class="h-4 w-4" />
                  </div>
                </th>
              </tr>
            </thead>
            <tbody>
              <tr
                v-for="customer in customers?.data || []"
                :key="customer.email"
                class="border-b transition-colors hover:bg-muted/50 cursor-pointer"
                @click="router.visit(`/customers/${customerEmail(customer.email)}`)"
              >
                <td class="px-6 py-4">
                  <Link
                    :href="`/customers/${customerEmail(customer.email)}`"
                    class="font-medium text-primary hover:underline"
                    @click.stop
                  >
                    {{ customer.email }}
                  </Link>
                </td>
                <td class="px-6 py-4">{{ customer.orders_count }}</td>
                <td class="px-6 py-4">
                  {{ formatCurrency(customer.total_spent) }}
                </td>
                <td class="px-6 py-4">
                  {{ formatCurrency(customer.average_order_value) }}
                </td>
                <td class="px-6 py-4">
                  <div class="flex flex-wrap gap-1">
                    <Badge
                      v-for="website in customer.websites"
                      :key="website"
                      variant="outline"
                    >
                      {{ website }}
                    </Badge>
                  </div>
                </td>
                <td class="px-6 py-4">
                  {{ customer.country || '—' }}
                </td>
                <td class="px-6 py-4">
                  {{ formatDate(customer.first_order_at) }}
                </td>
                <td class="px-6 py-4">
                  {{ formatDate(customer.last_order_at) }}
                </td>
              </tr>
              <tr v-if="!customers?.data || customers.data.length === 0">
                <td colspan="8" class="px-6 py-8 text-center text-muted-foreground">
                  No customers found
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <!-- PAGINATION -->
        <div
          v-if="customers && (customers.total || (customers.meta?.total ?? 0)) > 0"
          class="flex flex-col gap-4 border-t bg-muted/30 px-6 py-4 sm:flex-row sm:items-center sm:justify-between"
        >
          <!-- Pagination Info -->
          <div v-if="customers.total || customers.meta?.total" class="text-sm text-muted-foreground">
            Showing
            <span class="font-semibold text-foreground">
              {{ customers.from || (customers.meta?.current_page ? (customers.meta.current_page - 1) * (customers.meta.per_page || 15) + 1 : 1) }}
            </span>
            to
            <span class="font-semibold text-foreground">
              {{ customers.to || (customers.meta?.current_page ? Math.min(customers.meta.current_page * (customers.meta.per_page || 15), customers.meta.total || customers.total || 0) : 0) }}
            </span>
            of
            <span class="font-semibold text-foreground">{{ customers.total || customers.meta?.total || 0 }}</span>
            customers
            <span v-if="(customers.last_page || customers.meta?.last_page || 1) > 1" class="ml-2">
              (Page {{ customers.current_page || customers.meta?.current_page || 1 }} of {{ customers.last_page || customers.meta?.last_page || 1 }})
            </span>
          </div>

          <!-- Pagination Controls -->
          <div
            v-if="customers.links && customers.links.length > 1"
            class="flex items-center gap-2"
          >
            <!-- Previous Button -->
            <Button
              variant="outline"
              size="sm"
              :disabled="!customers.links[0]?.url || (customers.current_page || customers.meta?.current_page || 1) === 1"
              @click="goToPage(customers.links[0]?.url)"
              class="gap-1"
            >
              <ChevronLeft class="h-4 w-4" />
              <span class="hidden sm:inline">Previous</span>
            </Button>

            <!-- Page Numbers -->
            <div class="flex items-center gap-1">
              <template
                v-for="(link, index) in customers.links"
                :key="index"
              >
                <Button
                  v-if="link.label && Number(index) > 0 && Number(index) < customers.links.length - 1"
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
                  v-else-if="link.label === '...' && Number(index) > 0 && Number(index) < customers.links.length - 1"
                  class="px-2 py-1 text-muted-foreground"
                >
                  ...
                </span>
              </template>
            </div>

            <!-- Next Button -->
            <Button
              variant="outline"
              size="sm"
              :disabled="!customers.links[customers.links.length - 1]?.url || (customers.current_page || customers.meta?.current_page || 1) === (customers.last_page || customers.meta?.last_page || 1)"
              @click="goToPage(customers.links[customers.links.length - 1]?.url)"
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

