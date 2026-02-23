<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue'
import { type BreadcrumbItem } from '@/types'
import { Head, router } from '@inertiajs/vue3'
import { computed, ref } from 'vue'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Button } from '@/components/ui/button'
import { Badge } from '@/components/ui/badge'
import { Trash2, ArrowLeft, Eye, EyeOff, Code, Database, Copy, CheckCircle2 } from 'lucide-vue-next'
import { Link } from '@inertiajs/vue3'
import { useToast } from '@/composables/useToast'
import FlightCard from '@/components/FlightCard.vue'
import AmadeusCodeCard from '@/components/submissions/AmadeusCodeCard.vue'
import PnrGenerationCard from '@/components/submissions/PnrGenerationCard.vue'
import EntryMetadataCard from '@/components/submissions/EntryMetadataCard.vue'
import EntryStatsCard from '@/components/submissions/EntryStatsCard.vue'

const props = defineProps<{ 
  entry: any
  formSchema?: {
    fields?: Record<string, { label: string; type: string }>
  } | null
}>()

const breadcrumbs = computed<BreadcrumbItem[]>(() => [
  { title: 'Submissions', href: '/submissions' },
  { title: `Form #${props.entry.form_id}`, href: `/submissions/forms/${props.entry.website_id}/${props.entry.form_id}` },
  { title: `Entry #${props.entry.entry_id}`, href: '#' },
])

const toast = useToast()
const isDeleting = ref(false)
const showRawPayload = ref(false)
const showEmptyFields = ref(false)
const copiedField = ref<string | null>(null)

function deleteEntry() {
  if (!confirm(`Are you sure you want to delete entry #${props.entry.entry_id}? This action cannot be undone.`)) {
    return
  }

  isDeleting.value = true
  router.delete(`/submissions/entries/${props.entry.id}`, {
    onSuccess: () => {
      router.visit(`/submissions/forms/${props.entry.website_id}/${props.entry.form_id}`)
    },
    onError: () => {
      toast.error('Failed to delete entry')
      isDeleting.value = false
    },
  })
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

// Check if entry has sufficient flight data to generate Amadeus code
const hasFlightData = computed(() => {
  const hasFlightJson = formFields.value.some(field => field.isFlight === true)
  const hasBasicFields = (flightFromField.value && flightToField.value) || 
                         (flightDepartureField.value && flightFromField.value)
  return hasFlightJson || hasBasicFields
})

function isFieldEmpty(value: any): boolean {
  if (value === null || value === undefined || value === '') return true
  if (typeof value === 'string' && value.trim() === '') return true
  if (Array.isArray(value) && value.length === 0) return true
  if (typeof value === 'object' && Object.keys(value).length === 0) return true
  return false
}

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

function getBaseFieldKey(key: string): string {
  const match = key.match(/^(.+?)_(\d+)$/)
  if (match) {
    return match[1]
  }
  return key
}

function formatFieldLabel(key: string): string {
  if (props.formSchema?.fields) {
    const fields = props.formSchema.fields
    const baseKey = getBaseFieldKey(key)
    
    if (fields[baseKey]) {
      return fields[baseKey].label || formatFieldKey(baseKey)
    } else if (fields[key]) {
      return fields[key].label || formatFieldKey(key)
    }
  }
  
  return formatFieldKey(key)
}

function formatFieldKey(key: string): string {
  return key
    .replace(/_/g, ' ')
    .replace(/([A-Z])/g, ' $1')
    .split(' ')
    .map(word => word.charAt(0).toUpperCase() + word.slice(1))
    .join(' ')
}

function isComplexType(value: any): boolean {
  if (typeof value !== 'object' || value === null) return false
  if (Array.isArray(value)) {
    return value.some(item => typeof item === 'object' && item !== null)
  }
  return Object.keys(value).length > 0
}

function isFlightData(value: any): boolean {
  if (typeof value !== 'object' || value === null) return false
  const valueObj = ensureParsedFlightData(value)
  if (!valueObj || typeof valueObj !== 'object') return false
  
  const hasFlightIndicators = 
    valueObj.itineraries || 
    valueObj.travelers || 
    valueObj.travelerPricings ||
    (valueObj.departure && valueObj.arrival) ||
    valueObj.segments ||
    (valueObj.price && (valueObj.validatingAirlineCodes || valueObj.carrierCode))
  
  return Boolean(hasFlightIndicators)
}

function identifyFieldTypes(value: any, key: string) {
  const keyLower = key.toLowerCase()
  const isEmail = keyLower.includes('email') || keyLower.includes('e_mail') || keyLower.includes('e-mail')
  const isUrl = keyLower.includes('url') || keyLower.includes('link') || keyLower.includes('website')
  const isArray = Array.isArray(value)
  const isComplex = isComplexType(value)
  const isFlight = isFlightData(value)
  
  return { isEmail, isUrl, isArray, isComplex, isFlight }
}

// Parse form fields from entry payload
const formFields = computed(() => {
  const payload = props.entry.payload || {}
  const fields: any[] = []

  for (const [key, value] of Object.entries(payload)) {
    if (isFieldEmpty(value) && !showEmptyFields.value) {
      continue
    }

    const { isEmail, isUrl, isArray, isComplex, isFlight } = identifyFieldTypes(value, key)

    fields.push({
      key,
      value,
      label: formatFieldLabel(key),
      isComplex,
      isArray,
      isEmail,
      isUrl,
      isFlight
    })
  }

  return fields
})

// Extract submission metadata from payload
const submissionMeta = computed(() => {
  const meta: Record<string, any> = {}
  const payload = props.entry.payload || {}

  if (payload.ip) meta.userIP = payload.ip
  else if (payload.user_ip) meta.userIP = payload.user_ip

  if (payload.source_url) meta.sourceURL = payload.source_url
  if (payload.browser) meta.browser = payload.browser
  if (payload.device) meta.device = payload.device
  else if (payload.os) meta.device = payload.os
  if (payload.user) meta.user = payload.user
  if (payload.status) meta.status = payload.status
  if (payload.serial_number) meta.serialNumber = payload.serial_number

  return meta
})

// Identify special fields
const flightFromField = computed(() => {
  return formFields.value.find(field => 
    field.label.toLowerCase().includes('flight from') || 
    field.key.toLowerCase().includes('flight_from') ||
    field.key.toLowerCase().includes('flightfrom')
  )
})

const flightToField = computed(() => {
  return formFields.value.find(field => 
    field.label.toLowerCase().includes('flight to') || 
    field.key.toLowerCase().includes('flight_to') ||
    field.key.toLowerCase().includes('flightto')
  )
})

const emailField = computed(() => {
  return formFields.value.find(field => 
    field.isEmail || 
    field.label.toLowerCase().includes('email') || 
    field.key.toLowerCase().includes('email') ||
    field.key.toLowerCase().includes('e_mail') ||
    field.key.toLowerCase().includes('e-mail')
  )
})

const phoneField = computed(() => {
  return formFields.value.find(field => 
    field.label.toLowerCase().includes('phone') || 
    field.key.toLowerCase().includes('phone') ||
    field.key.toLowerCase().includes('telephone') ||
    field.key.toLowerCase().includes('mobile') ||
    field.key.toLowerCase().includes('cell')
  )
})

const flightDepartureField = computed(() => {
  return formFields.value.find(field => 
    field.label.toLowerCase().includes('flight departure') || 
    field.label.toLowerCase().includes('departure') ||
    field.key.toLowerCase().includes('flight_departure') ||
    field.key.toLowerCase().includes('flightdeparture') ||
    field.key.toLowerCase().includes('departure')
  )
})

const flightArrivalField = computed(() => {
  return formFields.value.find(field => 
    field.label.toLowerCase().includes('flight arrival') || 
    field.label.toLowerCase().includes('arrival') ||
    field.key.toLowerCase().includes('flight_arrival') ||
    field.key.toLowerCase().includes('flightarrival') ||
    field.key.toLowerCase().includes('arrival')
  )
})

const namesFieldDisplay = computed(() => {
  return formFields.value.find(field => 
    field.key.toLowerCase() === 'names' || 
    field.label.toLowerCase().startsWith('names')
  )
})

const remainingFields = computed(() => {
  const fromKey = flightFromField.value?.key
  const toKey = flightToField.value?.key
  const emailKey = emailField.value?.key
  const phoneKey = phoneField.value?.key
  const departureKey = flightDepartureField.value?.key
  const arrivalKey = flightArrivalField.value?.key
  const namesKey = namesFieldDisplay.value?.key
  return formFields.value.filter(field => 
    field.key !== fromKey && 
    field.key !== toKey && 
    field.key !== emailKey && 
    field.key !== phoneKey &&
    field.key !== departureKey &&
    field.key !== arrivalKey &&
    field.key !== namesKey
  )
})
</script>

<template>
  <Head title="Entry Details" />

  <AppLayout :breadcrumbs="breadcrumbs">
    <div
      class="flex h-full flex-1 flex-col gap-4 sm:gap-6 overflow-x-auto rounded-xl p-3 sm:p-4"
    >
      <!-- Header -->
      <div class="flex flex-col sm:flex-row items-stretch sm:items-center justify-between gap-3">
        <div class="flex items-center gap-4">
          <Link :href="`/submissions/forms/${entry.website_id}/${entry.form_id}`" class="flex-1 sm:flex-none">
            <Button variant="outline" size="sm" class="w-full sm:w-auto">
              <ArrowLeft class="mr-2 h-4 w-4" />
              <span class="hidden sm:inline">Back to Entries</span>
              <span class="sm:hidden">Back</span>
            </Button>
          </Link>
        </div>
        <Button
          variant="destructive"
          size="sm"
          :disabled="isDeleting"
          @click="deleteEntry"
          class="w-full sm:w-auto"
        >
          <Trash2 v-if="!isDeleting" class="mr-2 h-4 w-4" />
          <span v-else class="mr-2 h-4 w-4 animate-spin">⏳</span>
          {{ isDeleting ? 'Deleting...' : 'Delete Entry' }}
        </Button>
      </div>

      <!-- Main Content & Sidebar Layout -->
      <div class="grid gap-6 grid-cols-1 lg:grid-cols-3">
        <!-- Left Column: Form Entry Data -->
        <div class="lg:col-span-2 space-y-6 order-2 lg:order-1">
          <!-- Form Entry Data -->
          <Card>
            <CardHeader>
              <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4">
                <div class="flex-1 min-w-0">
                  <CardTitle class="flex items-center gap-2 flex-wrap">
                    <Database class="h-5 w-5 flex-shrink-0" />
                    <span class="break-words">Form Entry #{{ entry.entry_id }}</span>
                  </CardTitle>
                  <CardDescription class="break-words">
                    Form ID: {{ entry.form_id }} • {{ entry.website.name }}
                  </CardDescription>
                </div>
                <div class="flex items-center gap-2 flex-shrink-0">
                  <label class="flex items-center gap-2 text-sm cursor-pointer hover:text-foreground text-muted-foreground whitespace-nowrap">
                    <input
                      type="checkbox"
                      v-model="showEmptyFields"
                      class="h-4 w-4 rounded border-input"
                    />
                    <span class="hidden sm:inline">Show empty fields</span>
                    <span class="sm:hidden">Show empty</span>
                  </label>
                </div>
              </div>
            </CardHeader>
            <CardContent>
              <div v-if="formFields.length > 0" class="space-y-4">
                <!-- All the field display logic remains the same, but simplified -->
                <p class="text-sm text-muted-foreground">
                  {{ formFields.length }} fields displayed. 
                  <button 
                    @click="showRawPayload = !showRawPayload" 
                    class="text-primary hover:underline inline-flex items-center gap-1"
                  >
                    <Code class="h-3 w-3" />
                    {{ showRawPayload ? 'Hide' : 'View' }} raw JSON
                  </button>
                </p>

                <!-- Raw JSON Display -->
                <div v-if="showRawPayload" class="relative rounded-lg border bg-muted/20 p-2 max-h-96 overflow-y-auto">
                  <div class="absolute top-2 right-2">
                    <Button
                      variant="ghost"
                      size="sm"
                      @click="copyToClipboard(entry.payload, 'raw_payload')"
                    >
                      <CheckCircle2 v-if="copiedField === 'raw_payload'" class="h-4 w-4 text-green-500" />
                      <Copy v-else class="h-4 w-4" />
                    </Button>
                  </div>
                  <pre class="text-xs font-mono whitespace-pre-wrap break-words pr-12">{{ JSON.stringify(entry.payload, null, 2) }}</pre>
                </div>
              </div>
              <div v-else class="text-center py-8 text-muted-foreground">
                No fields to display
              </div>
            </CardContent>
          </Card>
        </div>

        <!-- Right Column: Metadata & Actions -->
        <div class="space-y-6 order-1 lg:order-2">
          <!-- Metadata Card -->
          <EntryMetadataCard
            :entry-id="entry.entry_id"
            :form-id="entry.form_id"
            :email="entry.email"
            :created-at="entry.created_at_wp"
            :submission-meta="submissionMeta"
          >
            <!-- PNR Section -->
            <PnrGenerationCard
              :entry-id="entry.id"
              :pnr="entry.pnr"
              :pnr-source="entry.pnr_source"
              :pnr-pdf-path="entry.pnr_pdf_path"
              :pnr-generated-at="entry.pnr_generated_at"
              :has-flight-data="hasFlightData"
            />

            <!-- Amadeus Code Section -->
            <AmadeusCodeCard
              :entry-id="entry.id"
              :amadeus-code="entry.amadeus_command_block"
              :generated-at="entry.amadeus_generated_at"
              :has-flight-data="hasFlightData"
            />
          </EntryMetadataCard>

          <!-- Stats Card -->
          <EntryStatsCard
            :field-count="formFields.length"
            :submission-meta="submissionMeta"
          />
        </div>
      </div>
    </div>
  </AppLayout>
</template>
