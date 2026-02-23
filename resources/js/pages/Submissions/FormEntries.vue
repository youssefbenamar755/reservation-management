<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue'
import { type BreadcrumbItem } from '@/types'
import { Head, router } from '@inertiajs/vue3'
import { Button } from '@/components/ui/button'
import { ArrowLeft, ChevronLeft, ChevronRight, Trash2, RefreshCw } from 'lucide-vue-next'
import { computed, ref } from 'vue'
import { Link } from '@inertiajs/vue3'
import { useToast } from '@/composables/useToast'

const props = defineProps<{
  entries: any
  website: { id: number; name: string }
  formId: number
  formName: string
}>()

const breadcrumbs = computed<BreadcrumbItem[]>(() => [
  { title: 'Submissions', href: '/submissions' },
  { title: `${props.formName} (ID: ${props.formId})`, href: '#' },
])

const toast = useToast()
const deletingId = ref<number | null>(null)
const isSyncingSchema = ref(false)

function deleteEntry(entryId: number, submissionId: number, event: Event) {
  event.stopPropagation()
  
  if (!confirm(`Are you sure you want to delete entry #${entryId}? This action cannot be undone.`)) {
    return
  }

  deletingId.value = submissionId

  router.delete(`/submissions/entries/${submissionId}`, {
    preserveScroll: true,
    onSuccess: () => {
      deletingId.value = null
    },
    onError: () => {
      toast.error('Failed to delete entry')
      deletingId.value = null
    },
  })
}

function goToPage(url: string | null) {
  if (!url) return
  
  // Extract path and query from full URL if needed
  try {
    // Check if it's a full URL (starts with http:// or https://)
    if (url.startsWith('http://') || url.startsWith('https://')) {
      const urlObj = new URL(url)
      const path = urlObj.pathname + urlObj.search
      router.get(path, {}, {
        preserveState: true,
        preserveScroll: true,
        only: ['entries']
      })
    } else {
      // It's already a relative path, use it directly
      router.get(url, {}, {
        preserveState: true,
        preserveScroll: true,
        only: ['entries']
      })
    }
  } catch (e) {
    // If URL parsing fails, try using it directly as a path
    router.get(url, {}, {
      preserveState: true,
      preserveScroll: true,
      only: ['entries']
    })
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

// Extract email from entry - check payload.response first, then fallback to entry.email
function getEntryEmail(entry: any): string | null {
  // First check if email is directly on entry
  if (entry.email) {
    return entry.email
  }
  
  // Check payload.response for email
  if (entry.payload && typeof entry.payload === 'object') {
    const payload = entry.payload
    let response = payload.response || {}
    
    // If response is a JSON string, try to parse it
    if (typeof response === 'string') {
      try {
        response = JSON.parse(response)
      } catch (e) {
        // If parsing fails, return null
        return null
      }
    }
    
    // Common email field names in Fluent Forms
    const emailFields = [
      'email',
      'Email',
      'EMAIL',
      'email_address',
      'emailAddress',
      'user_email',
      'contact_email',
    ]
    
    // Check direct fields in response
    if (typeof response === 'object' && response !== null && !Array.isArray(response)) {
      for (const field of emailFields) {
        if (response[field] && typeof response[field] === 'string') {
          const email = response[field].trim()
          if (/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
            return email
          }
        }
      }
    }
  }
  
  return null
}

// Extract payment status from entry
function getPaymentStatus(entry: any): string | null {
  // First check top-level payment_status field (from database)
  if (entry.payment_status && typeof entry.payment_status === 'string') {
    return entry.payment_status.trim()
  }
  
  // Fallback to payload parsing for backwards compatibility
  if (!entry.payload || typeof entry.payload !== 'object') {
    return null
  }
  
  const payload = entry.payload
  
  // Check common payment status locations in payload
  const status = payload.payment_status 
    ?? payload.status 
    ?? payload.paymentStatus
    ?? payload.meta?.submission?.payment_status
    ?? payload.response?.payment_status
    ?? payload.response?.status
    ?? null
  
  if (status && typeof status === 'string') {
    return status.trim()
  }
  
  return null
}

// Extract payment amount from entry
function getPaymentAmount(entry: any): string | null {
  // First check top-level amount field (from database, already in dollars)
  if (entry.amount !== null && entry.amount !== undefined) {
    const numAmount = typeof entry.amount === 'string' ? parseFloat(entry.amount) : Number(entry.amount)
    if (!isNaN(numAmount) && numAmount > 0) {
      return numAmount.toFixed(2)
    }
  }
  
  // Fallback to payload parsing for backwards compatibility
  if (!entry.payload || typeof entry.payload !== 'object') {
    return null
  }
  
  const payload = entry.payload
  
  // Check payment_total first (stored in cents, e.g., 2500 = $25.00)
  const paymentTotal = payload.payment_total 
    ?? payload.meta?.submission?.payment_total
    ?? payload.response?.payment_total
    ?? null
  
  if (paymentTotal !== null && paymentTotal !== undefined) {
    // Convert from cents to dollars
    const numAmount = typeof paymentTotal === 'string' ? parseFloat(paymentTotal) : Number(paymentTotal)
    if (!isNaN(numAmount) && numAmount > 0) {
      // Divide by 100 to convert cents to dollars
      return (numAmount / 100).toFixed(2)
    }
  }
  
  // Check order_items for formatted amounts
  if (payload.order_items && Array.isArray(payload.order_items) && payload.order_items.length > 0) {
    const firstItem = payload.order_items[0]
    const formattedAmount = firstItem?.formatted_line_total ?? firstItem?.formatted_item_price
    if (formattedAmount && typeof formattedAmount === 'string') {
      // Remove currency symbols and commas, then parse
      const cleaned = formattedAmount.replace(/[^\d.]/g, '')
      const numAmount = parseFloat(cleaned)
      if (!isNaN(numAmount) && numAmount > 0) {
        return numAmount.toFixed(2)
      }
    }
  }
  
  // Fallback to other common amount/total locations (already in dollars)
  const amount = payload.total 
    ?? payload.amount 
    ?? payload.payment_amount
    ?? payload.paymentAmount
    ?? payload.response?.total
    ?? payload.response?.amount
    ?? payload.response?.payment_amount
    ?? null
  
  if (amount !== null && amount !== undefined) {
    // Format as number with 2 decimal places
    const numAmount = typeof amount === 'string' ? parseFloat(amount) : Number(amount)
    if (!isNaN(numAmount) && numAmount > 0) {
      return numAmount.toFixed(2)
    }
  }
  
  return null
}

function syncFormSchema() {
  isSyncingSchema.value = true
  router.post(`/submissions/forms/${props.website.id}/${props.formId}/sync-schema`, {}, {
    preserveScroll: true,
    onSuccess: () => {
      isSyncingSchema.value = false
      toast.success('Form schema synced successfully')
    },
    onError: () => {
      isSyncingSchema.value = false
      toast.error('Failed to sync form schema')
    },
  })
}
</script>

<template>
  <Head :title="`${formName} - Entries`" />

  <AppLayout :breadcrumbs="breadcrumbs">
    <div
      class="flex h-full flex-1 flex-col gap-4 overflow-x-auto rounded-xl p-4"
    >
      <!-- Header -->
      <div class="flex items-center justify-between">
        <div class="flex items-center gap-4">
          <Link href="/submissions">
            <Button variant="outline" size="sm">
              <ArrowLeft class="mr-2 h-4 w-4" />
              Back to Forms
            </Button>
          </Link>
          <div >
            <h1 class="text-2xl font-bold">{{ formName }}</h1>
            <p class="text-sm text-muted-foreground">
              Form ID: {{ formId }} • Website: {{ website.name }}
            </p>
          </div>
        </div>
        <Button
          variant="outline"
          size="sm"
          :disabled="isSyncingSchema"
          @click="syncFormSchema"
        >
          <RefreshCw :class="['mr-2 h-4 w-4', isSyncingSchema && 'animate-spin']" />
          {{ isSyncingSchema ? 'Syncing...' : 'Sync Form Schema' }}
        </Button>
      </div>

      <!-- ENTRIES TABLE -->
      <div
        class="relative flex-1 overflow-hidden rounded-lg border bg-card shadow-sm"
      >
        <div class="overflow-x-auto">
          <table class="w-full border-collapse text-sm">
            <thead>
              <tr class="border-b bg-muted/50">
                <th class="px-6 py-4 text-left font-semibold text-foreground">
                  Entry ID
                </th>
                <th class="px-6 py-4 text-left font-semibold text-foreground">
                  Email
                </th>
                <th class="px-6 py-4 text-left font-semibold text-foreground">
                  Payment Status
                </th>
                <th class="px-6 py-4 text-left font-semibold text-foreground">
                  Amount
                </th>
                <th class="px-6 py-4 text-left font-semibold text-foreground">
                  Submission Date
                </th>
                <th class="px-6 py-4 text-left font-semibold text-foreground">
                  Actions
                </th>
              </tr>
            </thead>

            <tbody>
              <tr
                v-for="(entry, index) in entries.data"
                :key="entry.id"
                class="border-b transition-colors cursor-pointer"
                :class="[
                  Number(index) % 2 === 0 ? 'bg-background' : 'bg-muted/20',
                  'hover:bg-muted/50'
                ]"
                @click="router.visit(`/submissions/entries/${entry.id}`)"
              >
                <td class="px-6 py-4">
                  <Link
                    :href="`/submissions/entries/${entry.id}`"
                    class="font-semibold text-primary hover:underline"
                    @click.stop
                  >
                    #{{ entry.entry_id }}
                  </Link>
                </td>

                <td class="px-6 py-4">
                  <a
                    v-if="getEntryEmail(entry)"
                    :href="`mailto:${getEntryEmail(entry)}`"
                    class="text-primary hover:underline"
                    @click.stop
                  >
                    {{ getEntryEmail(entry) }}
                  </a>
                  <span v-else class="text-muted-foreground">—</span>
                </td>

                <td class="px-6 py-4">
                  <span
                    v-if="getPaymentStatus(entry)"
                    class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-medium"
                    :class="{
                      'bg-green-100 text-green-800': getPaymentStatus(entry)?.toLowerCase() === 'paid' || getPaymentStatus(entry)?.toLowerCase() === 'completed',
                      'bg-yellow-100 text-yellow-800': getPaymentStatus(entry)?.toLowerCase() === 'pending' || getPaymentStatus(entry)?.toLowerCase() === 'processing',
                      'bg-red-100 text-red-800': getPaymentStatus(entry)?.toLowerCase() === 'failed' || getPaymentStatus(entry)?.toLowerCase() === 'cancelled',
                      'bg-gray-100 text-gray-800': !['paid', 'completed', 'pending', 'processing', 'failed', 'cancelled'].includes(getPaymentStatus(entry)?.toLowerCase() || '')
                    }"
                  >
                    {{ getPaymentStatus(entry) }}
                  </span>
                  <span v-else class="text-muted-foreground">—</span>
                </td>

                <td class="px-6 py-4">
                  <span v-if="getPaymentAmount(entry)" class="font-medium">
                    ${{ getPaymentAmount(entry) }}
                  </span>
                  <span v-else class="text-muted-foreground">—</span>
                </td>

                <td class="px-6 py-4">
                  <div class="text-muted-foreground whitespace-nowrap">
                    {{ formatDate(entry.created_at_wp) }}
                  </div>
                </td>

                <td class="px-6 py-4">
                  <Button
                    variant="destructive"
                    size="sm"
                    :disabled="deletingId === entry.id"
                    @click.stop="deleteEntry(entry.entry_id, entry.id, $event)"
                  >
                    <Trash2 v-if="deletingId !== entry.id" class="h-4 w-4" />
                    <span v-else class="h-4 w-4 animate-spin"></span>
                  </Button>
                </td>
              </tr>

              <tr v-if="entries.data.length === 0">
                <td
                  colspan="6"
                  class="px-6 py-12 text-center text-muted-foreground"
                >
                  <div class="flex flex-col items-center justify-center gap-2">
                    <p class="text-base font-medium">No entries found</p>
                    <p class="text-sm">This form has no submissions yet</p>
                  </div>
                </td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>

      <!-- Pagination -->
      <!-- Pagination -->
      <div
        v-if="entries && entries.total > 0"
        class="flex flex-col gap-4 border-t bg-muted/30 px-6 py-4 sm:flex-row sm:items-center sm:justify-between rounded-lg border bg-card shadow-sm"
      >
          <!-- Pagination Info -->
          <div class="text-sm text-muted-foreground">
            Showing
            <span class="font-semibold text-foreground">{{ entries.from || 0 }}</span>
            to
            <span class="font-semibold text-foreground">{{ entries.to || 0 }}</span>
            of
            <span class="font-semibold text-foreground">{{ entries.total || 0 }}</span>
            results
            <span v-if="entries.last_page > 1" class="ml-2">
              (Page {{ entries.current_page }} of {{ entries.last_page }})
            </span>
          </div>

          <!-- Pagination Controls -->
          <div
            v-if="entries.links && entries.links.length > 1"
            class="flex items-center gap-2"
          >
            <!-- Previous Button -->
            <Button
              variant="outline"
              size="sm"
              :disabled="!entries.prev_page_url || entries.current_page === 1"
              @click="goToPage(entries.prev_page_url)"
              class="gap-1"
            >
              <ChevronLeft class="h-4 w-4" />
              <span class="hidden sm:inline">Previous</span>
            </Button>

            <!-- Page Numbers -->
            <div class="flex items-center gap-1">
              <template
                v-for="(link, index) in entries.links"
                :key="index"
              >
                <Button
                  v-if="link.label && Number(index) > 0 && Number(index) < entries.links.length - 1"
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
                  v-else-if="link.label === '...'"
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
              :disabled="!entries.next_page_url || entries.current_page === entries.last_page"
              @click="goToPage(entries.next_page_url)"
              class="gap-1"
            >
              <span class="hidden sm:inline">Next</span>
              <ChevronRight class="h-4 w-4" />
            </Button>
          </div>
        </div>
    </div>
  </AppLayout>
</template>

