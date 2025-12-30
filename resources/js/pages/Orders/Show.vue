<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue'
import { type BreadcrumbItem } from '@/types'
import { Head, useForm, router, usePage } from '@inertiajs/vue3'
import { computed, ref, watch } from 'vue'
import {
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
} from '@/components/ui/card'
import { Button } from '@/components/ui/button'
import { Badge } from '@/components/ui/badge'
import { Label } from '@/components/ui/label'
import {
  ArrowLeft,
  User,
  Mail,
  Phone,
  MapPin,
  Package,
  CreditCard,
  Calendar,
  Globe,
  Save,
  TrendingUp,
  History,
  FileText,
  Tag,
  ClipboardList,
  Plane,
  Ticket,
  Loader2,
  CheckCircle2,
  Copy,
} from 'lucide-vue-next'
import { Link } from '@inertiajs/vue3'
import { useToast } from '@/composables/useToast'
import FlightCard from '@/components/FlightCard.vue'

const props = defineProps<{
  order: any
  customerHistory?: any[]
  orderNotes?: any[]
  attribution?: Record<string, any>
  fluentSubmission?: any
}>()

const breadcrumbs = computed<BreadcrumbItem[]>(() => [
  { title: 'Orders', href: '/orders' },
  { title: `#${props.order.wp_order_id}`, href: '#' },
])

const page = usePage()
const toast = useToast()

// Watch for flash messages (without immediate to avoid showing stale messages on load)
watch(
  () => (page.props as any).flash?.success,
  (message) => {
    if (message) {
      toast.success(message)
    }
  }
)

watch(
  () => (page.props as any).flash?.error,
  (message) => {
    if (message) {
      toast.error(message)
    }
  }
)

const form = useForm({
  status: props.order.status,
})

const isGeneratingAmadeusCode = ref(false)
const copiedField = ref<string | null>(null)

// Sync form status when order status changes (e.g., after update)
watch(
  () => props.order.status,
  (newStatus) => {
    if (form.status !== newStatus) {
      form.status = newStatus
    }
  }
)

function getStatusBadgeClass(status: string) {
  const statusMap: Record<string, string> = {
    completed: 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200',
    processing: 'bg-blue-100 text-blue-800 dark:bg-blue-900 dark:text-blue-200',
    pending: 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-200',
    cancelled: 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200',
    refunded: 'bg-gray-100 text-gray-800 dark:bg-gray-900 dark:text-gray-200',
    'on-hold': 'bg-orange-100 text-orange-800 dark:bg-orange-900 dark:text-orange-200',
    failed: 'bg-red-100 text-red-800 dark:bg-red-900 dark:text-red-200',
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

function decodeHtml(html: string | null | undefined): string {
  if (!html) return ''
  const txt = document.createElement('textarea')
  txt.innerHTML = html
  return txt.value
}

function updateStatus() {
  form.put(`/orders/${props.order.id}`, {
    preserveScroll: true,
    onSuccess: () => {
      toast.success('Order status updated successfully')
    },
    onError: () => {
      toast.error('Failed to update order status')
    },
  })
}

// Check if we have sufficient flight data to generate Amadeus code
const hasFlightData = computed(() => {
  return !!flightData.value
})

// Generate Amadeus code
function generateAmadeusCode() {
  if (!hasFlightData.value) {
    toast.error('Insufficient flight data to generate ticket')
    return
  }

  if (!fluentSubmission.value) {
    toast.error('No Fluent Forms submission linked to this order')
    return
  }

  isGeneratingAmadeusCode.value = true
  
  router.post(`/orders/${props.order.id}/generate-amadeus-code`, {}, {
    preserveScroll: true,
    onSuccess: () => {
      isGeneratingAmadeusCode.value = false
      // Reload the page to show the generated code
      router.reload({ only: ['order', 'fluentSubmission'] })
    },
    onError: (errors) => {
      isGeneratingAmadeusCode.value = false
      toast.error(errors.message || 'Failed to generate Amadeus code')
    },
  })
}

function copyToClipboard(text: string, fieldKey: string) {
  const textToCopy = typeof text === 'object' ? JSON.stringify(text, null, 2) : String(text)
  navigator.clipboard.writeText(textToCopy).then(() => {
    copiedField.value = fieldKey
    toast.success('Copied to clipboard')
    setTimeout(() => { copiedField.value = null }, 2000)
  }).catch(() => {
    toast.error('Failed to copy')
  })
}

// Extract data from payload
const payload = computed(() => props.order.payload || {})
const billing = computed(() => payload.value.billing || {})
const shipping = computed(() => payload.value.shipping || {})
const lineItems = computed(() => payload.value.line_items || [])
const paymentMethod = computed(() => payload.value.payment_method_title || payload.value.payment_method || 'N/A')
const customerNote = computed(() => payload.value.customer_note || null)
const orderKey = computed(() => payload.value.order_key || null)
const orderNumber = computed(() => payload.value.number || props.order.wp_order_id)

// Ensure props have default values
const customerHistory = computed(() => props.customerHistory || [])
const orderNotes = computed(() => props.orderNotes || [])
const attribution = computed(() => props.attribution || {})
const fluentSubmission = computed(() => props.fluentSubmission || null)

// Extract form fields from payload.response ONLY (same logic as EntryDetails.vue)
const formFieldsPreview = computed(() => {
  if (!fluentSubmission.value?.payload || typeof fluentSubmission.value.payload !== 'object') {
    return []
  }

  const payload = fluentSubmission.value.payload
  let response = payload.response || {}
  
  // If response is a string (JSON string), try to parse it
  if (typeof response === 'string') {
    try {
      response = JSON.parse(response)
    } catch (e) {
      // If parsing fails, return empty
      return []
    }
  }
  
  // If response is not an object after parsing, return empty
  if (typeof response !== 'object' || response === null || Array.isArray(response)) {
    return []
  }

  const fields: Array<{ key: string; value: any; label: string }> = []

  // Fields to exclude
  const excludedFields = [
    'fluent_form_embded_post_id',
    'fluent_form_embedded_post_id',
    'fluentform',
    'fluentform_4',
    '_fluentform_4',
    'fluentformnonce',
    '_fluentformnonce',
    'wp_http_referer',
    '_wp_http_referer',
    'custom-payment-amount',
    'flight_json_data',
    'terms-n-condition'

  ]

  for (const [key, value] of Object.entries(response)) {
    // Skip excluded fields (case-insensitive)
    const keyLower = key.toLowerCase().replace(/\s+/g, '_').replace(/-/g, '_')
    const shouldExclude = excludedFields.some(excluded => {
      const excludedLower = excluded.toLowerCase().replace(/\s+/g, '_').replace(/-/g, '_')
      return keyLower === excludedLower || keyLower.includes(excludedLower) || excludedLower.includes(keyLower)
    })
    
    if (shouldExclude) {
      continue
    }

    // Skip empty fields
    if (value === null || value === undefined || value === '' || 
        (typeof value === 'string' && value.trim() === '') ||
        (Array.isArray(value) && value.length === 0) ||
        (typeof value === 'object' && Object.keys(value).length === 0)) {
      continue
    }

    // Format label
    const label = String(key)
      .replace(/_/g, ' ')
      .replace(/([A-Z])/g, ' $1')
      .trim()
      .split(' ')
      .map(word => word.charAt(0).toUpperCase() + word.slice(1).toLowerCase())
      .join(' ')

    fields.push({
      key,
      value,
      label
    })
  }

  return fields
})

// Detect if value is a flight JSON structure
function isFlightJsonData(value: any): boolean {
  if (!value) {
    return false
  }
  
  // Handle JSON strings
  let data = value
  if (typeof value === 'string') {
    try {
      data = JSON.parse(value)
    } catch (e) {
      return false
    }
  }
  
  // Must be an object, not an array
  if (typeof data !== 'object' || data === null || Array.isArray(data)) {
    return false
  }
  
  // Schema-based detection: Check for flight JSON indicators
  // Must have at least one of these key indicators:
  const hasItineraries = Array.isArray(data.itineraries) && data.itineraries.length > 0
  const hasValidatingAirlines = Array.isArray(data.validatingAirlineCodes) && data.validatingAirlineCodes.length > 0
  const hasPrice = data.price && typeof data.price === 'object' && (data.price.total || data.price.grandTotal || data.price.base)
  const hasTravelerPricings = Array.isArray(data.travelerPricings) && data.travelerPricings.length > 0
  
  // Additional checks: verify itineraries have segments structure
  let hasValidSegments = false
  if (hasItineraries) {
    hasValidSegments = data.itineraries.some((itinerary: any) => {
      return Array.isArray(itinerary.segments) && itinerary.segments.length > 0 &&
             itinerary.segments.some((segment: any) => 
               segment.departure?.iataCode && segment.arrival?.iataCode
             )
    })
  }
  
  // Must have itineraries with valid segments OR (validatingAirlineCodes + price)
  // This ensures we're detecting actual flight booking data, not just any object
  return (hasItineraries && hasValidSegments) || (hasValidatingAirlines && hasPrice)
}

// Ensure flight data is parsed (handles both object and JSON string)
function ensureParsedFlightData(value: any): any {
  if (!value) return value
  if (typeof value === 'string') {
    try {
      return JSON.parse(value)
    } catch (e) {
      return value
    }
  }
  return value
}

// Extract flight data from form fields
const flightData = computed(() => {
  if (!fluentSubmission.value?.payload || typeof fluentSubmission.value.payload !== 'object') {
    return null
  }

  const payload = fluentSubmission.value.payload
  let response = payload.response || {}
  
  // If response is a string (JSON string), try to parse it
  if (typeof response === 'string') {
    try {
      response = JSON.parse(response)
    } catch (e) {
      return null
    }
  }
  
  // If response is not an object after parsing, return null
  if (typeof response !== 'object' || response === null || Array.isArray(response)) {
    return null
  }

  // Search for flight data in the response
  for (const [key, value] of Object.entries(response)) {
    if (isFlightJsonData(value)) {
      return ensureParsedFlightData(value)
    }
  }
  
  return null
})
</script>

<template>
  <Head title="Order Details" />

  <AppLayout :breadcrumbs="breadcrumbs">
    <div
      class="flex h-full flex-1 flex-col gap-6 overflow-x-auto rounded-xl p-4"
    >
      <!-- Header -->
      <div class="flex items-center justify-between">
        <div class="flex items-center gap-4">
          <Link href="/orders">
            <Button variant="outline" size="sm">
              <ArrowLeft class="mr-2 h-4 w-4" />
              Back to Orders
            </Button>
          </Link>
          
        </div>
        <div class="flex items-center gap-2">
          <Badge
            :class="getStatusBadgeClass(order.status)"
            class="text-sm font-medium px-3 py-1"
          >
            {{ order.status }}
          </Badge>
        </div>
      </div>

      <!-- Main Content & Sidebar Layout -->
      <div class="grid gap-6 lg:grid-cols-3">
        <!-- Left Column: Main Content -->
        <div class="lg:col-span-2 space-y-6">
          <div class="grid gap-6 md:grid-cols-2">
            <!-- Order Summary -->
            <Card>
          <CardHeader>
            <CardTitle>Order #{{ orderNumber }}</CardTitle>
            <CardDescription>{{ formatDate(order.created_at_wp) }}</CardDescription>
          </CardHeader>
          <CardContent class="space-y-4">
            <div class="grid grid-cols-2 gap-4">
              <div>
                <p class="text-sm text-muted-foreground">Order Total</p>
                <p class="text-2xl font-bold">
                  {{ formatCurrency(order.total, order.currency) }}
                </p>
              </div>
              <div>
                <p class="text-sm text-muted-foreground">Status</p>
                <Badge
                  :class="getStatusBadgeClass(order.status)"
                  class="text-sm font-medium"
                >
                  {{ order.status }}
                </Badge>
              </div>
              <div>
                <p class="text-sm text-muted-foreground">Website</p>
                <p class="font-medium">{{ order.website.name }}</p>
              </div>
              <div>
                <p class="text-sm text-muted-foreground">Order Key</p>
                <p class="font-mono text-xs">{{ orderKey || 'N/A' }}</p>
              </div>
            </div>

            <div class="border-t pt-4 space-y-2">
              <div class="flex justify-between text-sm">
                <span class="text-muted-foreground">Subtotal</span>
                <span class="font-medium">
                  {{ formatCurrency(payload.subtotal || order.total, order.currency) }}
                </span>
              </div>
              <div
                v-if="payload.total_tax"
                class="flex justify-between text-sm"
              >
                <span class="text-muted-foreground">Tax</span>
                <span class="font-medium">
                  {{ formatCurrency(payload.total_tax, order.currency) }}
                </span>
              </div>
              <div
                v-if="payload.shipping_total"
                class="flex justify-between text-sm"
              >
                <span class="text-muted-foreground">Shipping</span>
                <span class="font-medium">
                  {{ formatCurrency(payload.shipping_total, order.currency) }}
                </span>
              </div>
              <div class="flex justify-between text-sm font-semibold border-t pt-2">
                <span>Total</span>
                <span>{{ formatCurrency(order.total, order.currency) }}</span>
              </div>
            </div>
          </CardContent>
        </Card>

        <!-- Billing & Shipping Information -->
        <Card>
          <CardHeader>
            <CardTitle>Billing & Shipping Information</CardTitle>
          </CardHeader>
          <CardContent class="space-y-6">
            <!-- Payment & Shipping Methods -->
            <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
              <div>
                <div class="flex items-center gap-2 mb-2">
                  <CreditCard class="h-4 w-4 text-muted-foreground" />
                  <p class="text-sm font-medium">Payment Method</p>
                </div>
                <div
                  class="text-sm text-muted-foreground"
                  v-html="decodeHtml(paymentMethod)"
                ></div>
              </div>

              <div v-if="payload.shipping_lines && payload.shipping_lines.length > 0">
                <div class="flex items-center gap-2 mb-2">
                  <Package class="h-4 w-4 text-muted-foreground" />
                  <p class="text-sm font-medium">Shipping Method</p>
                </div>
                <p
                  v-for="(shippingMethod, index) in payload.shipping_lines"
                  :key="index"
                  class="text-sm text-muted-foreground"
                >
                  {{ shippingMethod.method_title || shippingMethod.method_id }}
                </p>
              </div>
            </div>

            <div v-if="customerNote" class="pt-4 border-t">
              <div class="flex items-center gap-2 mb-2">
                <Calendar class="h-4 w-4 text-muted-foreground" />
                <p class="text-sm font-medium">Customer Note</p>
              </div>
              <p class="text-sm text-muted-foreground italic">
                "{{ customerNote }}"
              </p>
            </div>

            <!-- Billing Address -->
            <div class="pt-4 border-t">
              <h4 class="text-sm font-semibold mb-3">Billing Address</h4>
              <div class="space-y-3">
                <div class="flex items-start gap-3">
                  <User class="h-4 w-4 mt-0.5 text-muted-foreground" />
                  <div>
                    <p class="font-medium">
                      {{ billing.first_name }} {{ billing.last_name }}
                    </p>
                    <p v-if="billing.company" class="text-sm text-muted-foreground">
                      {{ billing.company }}
                    </p>
                  </div>
                </div>

                <div v-if="billing.email" class="flex items-center gap-3">
                  <Mail class="h-4 w-4 text-muted-foreground" />
                  <a
                    :href="`mailto:${billing.email}`"
                    class="text-sm text-primary hover:underline"
                  >
                    {{ billing.email }}
                  </a>
                </div>

                <div v-if="billing.phone" class="flex items-center gap-3">
                  <Phone class="h-4 w-4 text-muted-foreground" />
                  <a
                    :href="`tel:${billing.phone}`"
                    class="text-sm text-primary hover:underline"
                  >
                    {{ billing.phone }}
                  </a>
                </div>

                <div v-if="billing.address_1" class="flex items-start gap-3">
                  <MapPin class="h-4 w-4 mt-0.5 text-muted-foreground" />
                  <div class="text-sm">
                    <p>{{ billing.address_1 }}</p>
                    <p v-if="billing.address_2">{{ billing.address_2 }}</p>
                    <p>
                      {{ billing.city }}{{ billing.state ? `, ${billing.state}` : '' }}
                      {{ billing.postcode }}
                    </p>
                    <p>{{ billing.country }}</p>
                  </div>
                </div>
              </div>
            </div>

            <!-- Shipping Address -->
            <div v-if="shipping.address_1" class="pt-4 border-t">
              <h4 class="text-sm font-semibold mb-3">Shipping Address</h4>
              <div class="space-y-3">
                <div class="flex items-start gap-3">
                  <User class="h-4 w-4 mt-0.5 text-muted-foreground" />
                  <div>
                    <p class="font-medium">
                      {{ shipping.first_name }} {{ shipping.last_name }}
                    </p>
                    <p v-if="shipping.company" class="text-sm text-muted-foreground">
                      {{ shipping.company }}
                    </p>
                  </div>
                </div>

                <div class="flex items-start gap-3">
                  <MapPin class="h-4 w-4 mt-0.5 text-muted-foreground" />
                  <div class="text-sm">
                    <p>{{ shipping.address_1 }}</p>
                    <p v-if="shipping.address_2">{{ shipping.address_2 }}</p>
                    <p>
                      {{ shipping.city }}{{ shipping.state ? `, ${shipping.state}` : '' }}
                      {{ shipping.postcode }}
                    </p>
                    <p>{{ shipping.country }}</p>
                  </div>
                </div>
              </div>
            </div>
          </CardContent>
        </Card>
          </div>

        <!-- Fluent Forms Submission -->
        <Card v-if="fluentSubmission">
          <CardHeader>
            <CardTitle class="flex items-center gap-2">
              <ClipboardList class="h-5 w-5" />
              Fluent Forms Submission
            </CardTitle>
            <CardDescription>
              Form submission linked to this order
            </CardDescription>
          </CardHeader>
          <CardContent class="space-y-4">
            <!-- Submission Details -->
            <div class="space-y-2">
              <div class="flex justify-between items-center">
                <span class="text-sm text-muted-foreground">Entry ID:</span>
                <Link
                  :href="`/submissions/entries/${fluentSubmission.id}`"
                  class="text-sm font-semibold text-primary hover:underline"
                >
                  #{{ fluentSubmission.entry_id }}
                </Link>
              </div>
              <div class="flex justify-between items-center">
                <span class="text-sm text-muted-foreground">Form ID:</span>
                <span class="text-sm font-medium">{{ fluentSubmission.form_id }}</span>
              </div>
              <div v-if="fluentSubmission.email" class="flex justify-between items-center">
                <span class="text-sm text-muted-foreground">Email:</span>
                <a
                  :href="`mailto:${fluentSubmission.email}`"
                  class="text-sm font-medium text-primary hover:underline"
                >
                  {{ fluentSubmission.email }}
                </a>
              </div>
              <div v-if="fluentSubmission.created_at_wp" class="flex justify-between items-center">
                <span class="text-sm text-muted-foreground">Submitted:</span>
                <span class="text-sm font-medium">{{ formatDate(fluentSubmission.created_at_wp) }}</span>
              </div>
            </div>

            <!-- Form Fields Preview -->
            <div
              v-if="formFieldsPreview.length > 0"
              class="pt-4 border-t space-y-2"
            >
              <div class="text-xs font-semibold text-muted-foreground mb-2">
                Form Fields Preview
              </div>
              <div class="space-y-2 max-h-64 overflow-y-auto p-5">
                <div
                  v-for="field in formFieldsPreview"
                  :key="field.key"
                  class="flex justify-between items-start gap-2 text-xs pb-3 border-b"
                >
                  <span class="text-muted-foreground font-medium capitalize">
                    {{ field.label }}:
                  </span>
                  <span class="text-right font-medium text-foreground break-words">
                    {{ typeof field.value === 'object' || Array.isArray(field.value) 
                      ? JSON.stringify(field.value).substring(0, 50) + '...' 
                      : String(field.value).length > 50 
                        ? String(field.value).substring(0, 50) + '...' 
                        : field.value }}
                  </span>
                </div>
              </div>
            </div>

            <!-- View Full Submission Link -->
            <div class="pt-2 border-t">
              <Link
                :href="`/submissions/entries/${fluentSubmission.id}`"
                class="w-full"
              >
                <Button variant="outline" size="sm" class="w-full">
                  <FileText class="mr-2 h-4 w-4" />
                  View Full Submission
                </Button>
              </Link>
            </div>
          </CardContent>
        </Card>

        <!-- Flight Card -->
        <Card v-if="flightData">
          <CardHeader>
            <CardTitle class="flex items-center gap-2">
              <Plane class="h-5 w-5" />
              Flight Information
            </CardTitle>
            <CardDescription>
              Flight booking details from form submission
            </CardDescription>
          </CardHeader>
          <CardContent>
            <FlightCard :flight-data="flightData" />
          </CardContent>
        </Card>

    

        <!-- Raw Payload (Collapsible) -->
        <Card>
          <CardHeader>
            <CardTitle>Raw Payload</CardTitle>
            <CardDescription>Complete order data from WooCommerce</CardDescription>
          </CardHeader>
          <CardContent>
            <div class="relative rounded-lg border bg-muted/20 p-4">
              <pre class="text-xs overflow-x-auto whitespace-pre-wrap break-words max-h-64 overflow-y-auto">{{
                JSON.stringify(payload, null, 2)
              }}</pre>
            </div>
          </CardContent>
        </Card>
        </div>

        <!-- Right Column: Sidebar -->
        <div class="lg:col-span-1 space-y-6">
          <!-- Update Order Status -->
          <Card>
            <CardHeader>
              <CardTitle>Update Order Status</CardTitle>
              <CardDescription>Change the status of this order</CardDescription>
            </CardHeader>
            <CardContent>
              <form @submit.prevent="updateStatus" class="space-y-4">
                <div class="space-y-2">
                  <Label for="status">Order Status</Label>
                  <select
                    id="status"
                    v-model="form.status"
                    class="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
                  >
                    <option value="pending">Pending</option>
                    <option value="processing">Processing</option>
                    <option value="on-hold">On Hold</option>
                    <option value="completed">Completed</option>
                    <option value="cancelled">Cancelled</option>
                    <option value="refunded">Refunded</option>
                    <option value="failed">Failed</option>
                  </select>
                </div>
                <div class="flex justify-end">
                  <Button type="submit" :disabled="form.processing" class="w-full">
                    <Save class="mr-2 h-4 w-4" />
                    {{ form.processing ? 'Saving...' : 'Save Changes' }}
                  </Button>
                </div>
              </form>

              <!-- Generate Amadeus Code Section -->
              <div v-if="fluentSubmission && hasFlightData" class="mt-6 pt-6 border-t space-y-4">
                <div>
                  <h4 class="text-sm font-semibold mb-2">Amadeus Dummy Ticket Code</h4>
                  <p class="text-xs text-muted-foreground mb-3">
                    Generate a full Amadeus-style dummy ticket command block
                  </p>
                  
                  <div v-if="fluentSubmission.amadeus_command_block" class="mb-3 space-y-2">
                    <div class="relative rounded-lg border bg-muted/20 p-3">
                      <pre class="text-xs font-mono whitespace-pre-wrap break-words max-h-48 overflow-y-auto">{{ fluentSubmission.amadeus_command_block }}</pre>
                    </div>
                    <Button
                      variant="outline"
                      size="sm"
                      class="w-full"
                      @click="copyToClipboard(fluentSubmission.amadeus_command_block, 'amadeus_command_block')"
                    >
                      <CheckCircle2 v-if="copiedField === 'amadeus_command_block'" class="mr-2 h-3 w-3 text-green-500" />
                      <Copy v-else class="mr-2 h-3 w-3" />
                      {{ copiedField === 'amadeus_command_block' ? 'Copied!' : 'Copy Code' }}
                    </Button>
                    <p v-if="fluentSubmission.amadeus_generated_at" class="text-xs text-muted-foreground text-center">
                      Generated {{ formatDate(fluentSubmission.amadeus_generated_at) }}
                    </p>
                  </div>

                  <Button
                    :disabled="isGeneratingAmadeusCode || !hasFlightData"
                    @click="generateAmadeusCode"
                    :variant="fluentSubmission.amadeus_command_block ? 'outline' : 'default'"
                    class="w-full"
                    size="sm"
                  >
                    <Loader2 v-if="isGeneratingAmadeusCode" class="mr-2 h-4 w-4 animate-spin" />
                    <Ticket v-else class="mr-2 h-4 w-4" />
                    {{ isGeneratingAmadeusCode ? 'Generating...' : (fluentSubmission.amadeus_command_block ? 'Regenerate Code' : 'Generate Dummy Ticket Code') }}
                  </Button>
                </div>
              </div>
            </CardContent>
          </Card>

          <!-- Customer History -->
          <Card v-if="customerHistory.length > 0">
          <CardHeader>
            <CardTitle class="flex items-center gap-2">
              <History class="h-5 w-5" />
              Customer History
            </CardTitle>
            <CardDescription>
              {{ customerHistory.length }} previous order(s) from this customer
            </CardDescription>
          </CardHeader>
          <CardContent>
            <div class="space-y-3 max-h-96 overflow-y-auto">
              <Link
                v-for="historyOrder in customerHistory"
                :key="historyOrder.id"
                :href="`/orders/${historyOrder.id}`"
                class="flex items-center justify-between gap-4 p-3 rounded-lg border hover:bg-muted/50 transition-colors block"
              >
                <div class="flex-1 min-w-0">
                  <div class="flex items-center gap-2 mb-1">
                    <span class="font-semibold text-primary">
                      #{{ historyOrder.wp_order_id }}
                    </span>
                    <Badge
                      :class="getStatusBadgeClass(historyOrder.status)"
                      class="text-xs"
                    >
                      {{ historyOrder.status }}
                    </Badge>
                  </div>
                  <div class="text-xs text-muted-foreground">
                    {{ formatDate(historyOrder.created_at_wp) }}
                  </div>
                </div>
                <div class="text-right flex-shrink-0">
                  <div class="font-semibold">
                    {{ formatCurrency(historyOrder.total, historyOrder.currency) }}
                  </div>
                </div>
              </Link>
            </div>
          </CardContent>
        </Card>

          <!-- Order Attribution -->
          <Card v-if="Object.keys(attribution).length > 0">
            <CardHeader>
              <CardTitle class="flex items-center gap-2">
                <TrendingUp class="h-5 w-5" />
                Order Attribution
              </CardTitle>
              <CardDescription>Marketing and source attribution data</CardDescription>
            </CardHeader>
            <CardContent>
              <div class="space-y-3">
                <div
                  v-for="(value, key) in attribution"
                  :key="key"
                  class="flex justify-between items-start gap-4 pb-3 border-b last:border-0"
                >
                  <div class="flex items-center gap-2 min-w-0 flex-1">
                    <Tag class="h-4 w-4 text-muted-foreground flex-shrink-0" />
                    <span class="text-sm font-medium text-muted-foreground capitalize">
                      {{ key.replace(/_/g, ' ').replace('utm ', 'UTM ').replace('wca ', 'WCA ') }}
                    </span>
                  </div>
                  <span class="text-sm font-semibold text-foreground text-right">
                    {{ value }}
                  </span>
                </div>
              </div>
            </CardContent>
          </Card>

          <!-- Order Notes -->
          <Card v-if="orderNotes.length > 0">
            <CardHeader>
              <CardTitle class="flex items-center gap-2">
                <FileText class="h-5 w-5" />
                Order Notes
              </CardTitle>
              <CardDescription>
                {{ orderNotes.length }} note(s) for this order
              </CardDescription>
            </CardHeader>
            <CardContent>
          <div class="space-y-4">
            <div
              v-for="(note, index) in orderNotes"
              :key="index"
              class="border-l-4 pl-4 py-2"
              :class="{
                'border-blue-500 bg-blue-50 dark:bg-blue-950/20': note.customer_note === true,
                'border-green-500 bg-green-50 dark:bg-green-950/20': note.customer_note === false,
                'border-gray-500 bg-gray-50 dark:bg-gray-950/20': note.customer_note === undefined,
              }"
            >
              <div class="flex items-start justify-between gap-4 mb-2">
                <div class="flex items-center gap-2">
                  <Badge
                    variant="outline"
                    class="text-xs"
                    :class="{
                      'border-blue-500 text-blue-700 dark:text-blue-300':
                        note.customer_note === true,
                      'border-green-500 text-green-700 dark:text-green-300':
                        note.customer_note === false,
                    }"
                  >
                    {{ note.customer_note === true ? 'Customer Note' : note.customer_note === false ? 'Private Note' : 'Note' }}
                  </Badge>
                  <span
                    v-if="note.author"
                    class="text-xs text-muted-foreground"
                  >
                    by {{ note.author }}
                  </span>
                </div>
                <span
                  v-if="note.date_created"
                  class="text-xs text-muted-foreground whitespace-nowrap"
                >
                  {{ formatDate(note.date_created) }}
                </span>
              </div>
              <p class="text-sm whitespace-pre-wrap">
                {{ note.note }}
              </p>
            </div>
          </div>
            </CardContent>
          </Card>

              <!-- Order Items -->
        <Card>
        <CardHeader>
          <CardTitle>Order Items</CardTitle>
          <CardDescription>{{ lineItems.length }} item(s) in this order</CardDescription>
        </CardHeader>
        <CardContent>
          <div class="overflow-x-auto">
            <table class="w-full text-sm">
              <thead class="bg-muted/50 text-muted-foreground">
                <tr>
                  <th class="px-4 py-3 text-left">Product</th>
                  <th class="px-4 py-3 text-right">Quantity</th>
                  <th class="px-4 py-3 text-right">Price</th>
                  <th class="px-4 py-3 text-right">Total</th>
                </tr>
              </thead>
              <tbody>
                <tr
                  v-for="(item, index) in lineItems"
                  :key="index"
                  class="border-t"
                >
                  <td class="px-4 py-3">
                    <div class="font-medium">{{ item.name }}</div>
                    <div
                      v-if="item.variation_id"
                      class="text-xs text-muted-foreground"
                    >
                      Variation ID: {{ item.variation_id }}
                    </div>
                  </td>
                  <td class="px-4 py-3 text-right">{{ item.quantity }}</td>
                  <td class="px-4 py-3 text-right">
                    {{ formatCurrency(item.price, order.currency) }}
                  </td>
                  <td class="px-4 py-3 text-right font-medium">
                    {{ formatCurrency(item.total, order.currency) }}
                  </td>
                </tr>
                <tr v-if="lineItems.length === 0">
                  <td colspan="5" class="px-4 py-8 text-center text-muted-foreground">
                    No items found
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
          </CardContent>
        </Card>
        </div>
      </div>
    </div>
  </AppLayout>
</template>
