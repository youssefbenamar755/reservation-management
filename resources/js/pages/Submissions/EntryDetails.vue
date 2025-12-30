<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue'
import { type BreadcrumbItem } from '@/types'
import { Head, router, usePage } from '@inertiajs/vue3'
import { computed, ref, watch } from 'vue'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Button } from '@/components/ui/button'
import { Badge } from '@/components/ui/badge'
import { Trash2, ArrowLeft, Mail, Calendar, Globe, FileText, Hash, ClipboardList, Eye, EyeOff, Code, Database, Copy, CheckCircle2, Ticket, Loader2, AlertCircle, Download } from 'lucide-vue-next'
import { Link } from '@inertiajs/vue3'
import { useToast } from '@/composables/useToast'
import FlightCard from '@/components/FlightCard.vue'

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
const isGeneratingAmadeusCode = ref(false)
const isGeneratingPnr = ref(false)
const page = usePage()
const lastProcessedFlash = ref<{ success?: string; error?: string }>({})

watch(
  () => (page.props as any).flash?.success,
  (message, oldMessage) => {
    // Only show toast if message exists and is different from what we last processed
    if (message && message !== lastProcessedFlash.value.success) {
      lastProcessedFlash.value.success = message
      toast.success(message)
    }
  }
)

watch(
  () => (page.props as any).flash?.error,
  (message, oldMessage) => {
    // Only show toast if message exists and is different from what we last processed
    if (message && message !== lastProcessedFlash.value.error) {
      lastProcessedFlash.value.error = message
      toast.error(message)
    }
  }
)

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
  // Check if we have flight JSON data
  const hasFlightJson = formFields.value.some(field => field.isFlight === true)
  
  // Check if we have basic flight fields
  const hasBasicFields = (flightFromField.value && flightToField.value) || 
                         (flightDepartureField.value && flightFromField.value)
  
  return hasFlightJson || hasBasicFields
})

// Generate Amadeus code
function generateAmadeusCode() {
  if (!hasFlightData.value) {
    toast.error('Insufficient flight data to generate ticket')
    return
  }

  isGeneratingAmadeusCode.value = true
  
  router.post(`/submissions/entries/${props.entry.id}/generate-amadeus-code`, {}, {
    preserveScroll: true,
    onSuccess: () => {
      isGeneratingAmadeusCode.value = false
      // Reload the page to show the generated code
      router.reload({ only: ['entry'] })
    },
    onError: (errors) => {
      isGeneratingAmadeusCode.value = false
      toast.error(errors.message || 'Failed to generate Amadeus code')
    },
  })
}

// Generate PNR
function generatePnr() {
  if (!hasFlightData.value) {
    toast.error('Insufficient flight data to generate PNR')
    return
  }

  if (props.entry.pnr) {
    toast.error('PNR already exists for this submission')
    return
  }

  isGeneratingPnr.value = true
  
  router.post(`/submissions/entries/${props.entry.id}/generate-pnr`, {}, {
    preserveScroll: true,
    onSuccess: () => {
      isGeneratingPnr.value = false
      // Reload the page to show the generated PNR
      router.reload({ only: ['entry'] })
    },
    onError: (errors) => {
      isGeneratingPnr.value = false
      toast.error(errors.message || 'Failed to generate PNR')
    },
  })
}

// Download PDF
function downloadPdf() {
  if (!props.entry.pnr_pdf_path) {
    toast.error('PDF not available for this submission')
    return
  }

  window.location.href = `/submissions/entries/${props.entry.id}/download-pdf`
}

function isFieldEmpty(value: any): boolean {
  if (value === null || value === undefined || value === '') return true
  if (typeof value === 'string' && value.trim() === '') return true
  if (Array.isArray(value) && value.length === 0) return true
  if (typeof value === 'object' && Object.keys(value).length === 0) return true
  return false
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

/**
 * Get base field key (remove numeric suffix for repeated fields)
 * Examples: names_5 -> names, names_12 -> names, title_3 -> title
 */
function getBaseFieldKey(key: string): string {
  const match = key.match(/^(.+?)_(\d+)$/)
  if (match) {
    return match[1]
  }
  return key
}

/**
 * Get field label from schema or fallback to formatted key
 */
function formatFieldLabel(key: string): string {
  // If we have form schema, try to get label from it
  if (props.formSchema?.fields) {
    const fields = props.formSchema.fields
    const baseKey = getBaseFieldKey(key)
    
    // Try exact match first
    if (fields[key] && fields[key].label) {
      return fields[key].label
    }
    
    // Try base key match (for repeated fields like names_5 -> names)
    if (fields[baseKey] && fields[baseKey].label) {
      return fields[baseKey].label
    }
  }
  
  // Fallback: format the key (snake_case -> Title Case)
  return String(key)
    .replace(/_/g, ' ')
    .replace(/([A-Z])/g, ' $1')
    .trim()
    .split(' ')
    .map(word => word.charAt(0).toUpperCase() + word.slice(1).toLowerCase())
    .join(' ')
}

function isEmail(value: any): boolean {
  if (typeof value !== 'string') return false
  return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value)
}

function isUrl(value: any): boolean {
  if (typeof value !== 'string') return false
  try {
    new URL(value)
    return true
  } catch {
    return false
  }
}

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

// Fields to exclude from display
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
  // Additional variations
  'fluent form embded post id',
  'fluentform 4',
  'fluentformnonce',
  'form2_passenger_count',
  'title',
  'title_2',
  'title_3',
  'title_4',
  'title_5',
  'title_6',
  'Number_of_Passengers',
  'g-recaptcha-response'
]

// Extract form fields from payload.response ONLY
const formFields = computed(() => {
  if (!props.entry.payload || typeof props.entry.payload !== 'object') {
    return []
  }

  const payload = props.entry.payload
  let response = payload.response || {}
  
  // If response is a string (JSON string), try to parse it
  if (typeof response === 'string') {
    try {
      response = JSON.parse(response)
    } catch (e) {
      // If parsing fails, treat it as a regular string value
      // This means the entire response field is a single string
      return [{
        key: 'response',
        value: response,
        label: 'Response',
        isComplex: false,
        isArray: false,
        isEmail: isEmail(response),
        isUrl: isUrl(response),
        isFlight: false
      }]
    }
  }
  
  // If response is not an object after parsing, return empty
  if (typeof response !== 'object' || response === null || Array.isArray(response)) {
    return []
  }

  const fields: Array<{ 
    key: string
    value: any
    label: string
    isComplex: boolean
    isArray: boolean
    isEmail: boolean
    isUrl: boolean
    isFlight: boolean
  }> = []

  // Normalize key to base name (for merging similar fields)
  // This will match "Title 3 Names", "title_3_names", "title-3-names", etc. (legacy: also matches "Dropdown 3 Names")
  const normalizeKey = (k: string): string => {
    return k.toLowerCase().replace(/[\s_-]+/g, '')
  }
  
  // Get base field key (remove numeric suffix) for grouping repeated fields
  const getBaseKey = (k: string): string => {
    const match = k.match(/^(.+?)_(\d+)$/)
    return match ? match[1] : k
  }
  
  // Helper to check if a key is related to "title_3" (legacy: was "Dropdown 3")
  const isDropdown3Related = (key: string): boolean => {
    const normalized = key.toLowerCase().replace(/[\s_-]+/g, '')
    return normalized.includes('title') && normalized.includes('3') && !normalized.includes('names')
  }
  
  // Helper to check if a key is just "Names"
  const isNamesField = (key: string): boolean => {
    const normalized = key.toLowerCase().replace(/[\s_-]+/g, '')
    return normalized === 'names'
  }
  
  // Helper to check if a key matches the pattern: names, names_2, names_3, etc.
  const isNamesVariantField = (key: string): boolean => {
    const normalized = key.toLowerCase().trim()
    // Match "names" optionally followed by underscore and number(s)
    return /^names(_\d+)?$/.test(normalized)
  }

  // Track fields by normalized key for merging
  const fieldMap = new Map<string, { 
    key: string
    value: any
    label: string
    isComplex: boolean
    isArray: boolean
    isEmail: boolean
    isUrl: boolean
    isFlight: boolean
    allKeys: string[]
  }>()

  // Extract passenger count from form2_passenger_count before processing other fields
  let passengerCountFromField: number | null = null
  if (response.form2_passenger_count !== undefined && response.form2_passenger_count !== null) {
    const countValue = response.form2_passenger_count
    // Try to parse as number
    if (typeof countValue === 'number') {
      passengerCountFromField = countValue
    } else if (typeof countValue === 'string') {
      const parsed = parseInt(countValue.trim(), 10)
      if (!isNaN(parsed)) {
        passengerCountFromField = parsed
      }
    }
  }

  for (const [key, value] of Object.entries(response)) {
    // Skip excluded fields (case-insensitive and handle variations)
    const keyLower = key.toLowerCase().replace(/\s+/g, '_').replace(/-/g, '_')
    const shouldExclude = excludedFields.some(excluded => {
      const excludedLower = excluded.toLowerCase().replace(/\s+/g, '_').replace(/-/g, '_')
      return keyLower === excludedLower || keyLower.includes(excludedLower) || excludedLower.includes(keyLower)
    })
    
    if (shouldExclude) {
      continue
    }

    // Skip empty fields if showEmptyFields is false
    if (!showEmptyFields.value && isFieldEmpty(value)) {
      continue
    }

    // Normalize key for merging
    const normalizedKey = normalizeKey(key)

    // Handle JSON strings - try to parse if it looks like JSON
    let parsedValue = value
    if (typeof value === 'string' && (value.trim().startsWith('{') || value.trim().startsWith('['))) {
      try {
        parsedValue = JSON.parse(value)
      } catch (e) {
        // If parsing fails, keep original value
        parsedValue = value
      }
    }
    
    // Determine field type (use parsed value if it was a JSON string)
    const isComplex = typeof parsedValue === 'object' && parsedValue !== null && !Array.isArray(parsedValue)
    const isArray = Array.isArray(parsedValue)
    const isEmailField = !isComplex && !isArray && typeof parsedValue === 'string' && isEmail(parsedValue)
    const isUrlField = !isComplex && !isArray && typeof parsedValue === 'string' && isUrl(parsedValue)
    const isFlightData = isComplex && isFlightJsonData(parsedValue)
    
    // Debug: Log all fields to find the flight JSON data field
    if (key.toLowerCase().includes('flight') || key.toLowerCase().includes('json')) {
      console.log('Field check:', {
        key,
        originalValue: value,
        parsedValue: typeof parsedValue === 'object' && parsedValue !== null ? {
          type: 'object',
          keys: Object.keys(parsedValue as any),
          hasItineraries: Array.isArray((parsedValue as any).itineraries),
          hasValidatingAirlineCodes: Array.isArray((parsedValue as any).validatingAirlineCodes),
          hasPrice: typeof (parsedValue as any).price === 'object' && (parsedValue as any).price !== null,
        } : {
          type: typeof parsedValue,
          value: typeof parsedValue === 'string' ? parsedValue.substring(0, 100) : parsedValue
        },
        isComplex,
        isFlightData,
      })
    }
    
    // Use parsed value for further processing
    const valueToStore = parsedValue

    // Check if we already have a field with similar normalized key
    if (fieldMap.has(normalizedKey)) {
      const existing = fieldMap.get(normalizedKey)!
      // Merge values - prefer array/combined value
      let mergedValue = existing.value
      
      if (Array.isArray(existing.value)) {
        // Add to array if not already present
        if (!existing.value.includes(valueToStore)) {
          mergedValue = [...existing.value, valueToStore]
        }
      } else if (Array.isArray(valueToStore)) {
        // If new value is array, make it the merged value
        mergedValue = valueToStore
      } else if (existing.value !== valueToStore) {
        // Different values - combine into array
        mergedValue = [existing.value, valueToStore]
      }
      
      // Update with merged value and add this key to allKeys
      // Also update label in case schema is now available
      const baseKey = getBaseFieldKey(existing.key)
      fieldMap.set(normalizedKey, {
        ...existing,
        value: mergedValue,
        label: formatFieldLabel(baseKey), // Update label from schema if available
        allKeys: [...existing.allKeys, key],
        isArray: Array.isArray(mergedValue),
        isFlight: existing.isFlight || isFlightData
      })
    } else {
      // New field - use schema label if available, otherwise format the key
      fieldMap.set(normalizedKey, {
        key,
        value: valueToStore,
        label: formatFieldLabel(key), // This will use schema if available
        isComplex,
        isArray,
        isEmail: isEmailField,
        isUrl: isUrlField,
        isFlight: isFlightData,
        allKeys: [key]
      })
    }
  }

  // Post-process: Merge "title_3" (or legacy "Dropdown 3") with "Names" if both exist
  // NOTE: This is legacy logic. If names variant fields exist, skip this as they'll be handled by the new grouping logic below.
  const finalFields = new Map(fieldMap)
  
  // Check if we have names variant fields - if so, skip title_3 merging
  const hasNamesVariants = Array.from(finalFields.values()).some(field => isNamesVariantField(field.key))
  
  const dropdown3Keys: string[] = []
  let namesField: {normKey: string, field: any} | null = null
  
  // Find all "title_3" related fields (legacy: "Dropdown 3") and "Names" field
  for (const [normKey, field] of finalFields.entries()) {
    if (isDropdown3Related(field.key)) {
      dropdown3Keys.push(normKey)
    }
    if (isNamesField(field.key)) {
      namesField = { normKey, field }
    }
  }
  
  // If we have both "title_3" (or legacy "Dropdown 3") and "Names", and no names variants, merge them into "Names"
  // (If names variants exist, they'll be handled by the grouping logic below)
  if (dropdown3Keys.length > 0 && namesField && !hasNamesVariants) {
    const dropdown3Field = finalFields.get(dropdown3Keys[0])!
    
    // Merge values - combine into array if different, or keep single value if same
    let mergedValue: any
    if (Array.isArray(dropdown3Field.value)) {
      if (Array.isArray(namesField.field.value)) {
        mergedValue = [...dropdown3Field.value, ...namesField.field.value]
      } else if (!dropdown3Field.value.includes(namesField.field.value)) {
        mergedValue = [...dropdown3Field.value, namesField.field.value]
      } else {
        mergedValue = dropdown3Field.value
      }
    } else if (Array.isArray(namesField.field.value)) {
      mergedValue = [dropdown3Field.value, ...namesField.field.value]
    } else if (dropdown3Field.value !== namesField.field.value) {
      mergedValue = [dropdown3Field.value, namesField.field.value]
    } else {
      mergedValue = dropdown3Field.value
    }
    
    // Use passenger count from form2_passenger_count if available, otherwise calculate from values
    let mergedCount = passengerCountFromField
    if (mergedCount === null || mergedCount === undefined) {
      // Fallback: calculate from merged values
      if (Array.isArray(mergedValue)) {
        mergedCount = mergedValue.filter(item => {
          if (item === null || item === undefined) return false
          if (typeof item === 'string' && item.trim() === '') return false
          return true
        }).length
      } else if (mergedValue !== null && mergedValue !== undefined && mergedValue !== '') {
        if (typeof mergedValue === 'string' && mergedValue.trim() !== '') {
          mergedCount = 1
        } else if (typeof mergedValue !== 'string') {
          mergedCount = 1
        } else {
          mergedCount = 0
        }
      } else {
        mergedCount = 0
      }
    }
    // Use schema label if available, otherwise fallback to "Names"
    const baseNamesLabel = formatFieldLabel('names')
    const mergedLabel = `${baseNamesLabel} (${mergedCount})`
    
    // Update the names field with merged data, use schema label with count
    finalFields.set(namesField.normKey, {
      ...namesField.field,
      label: mergedLabel,
      value: mergedValue,
      isArray: Array.isArray(mergedValue),
      allKeys: [...(namesField.field.allKeys || [namesField.field.key]), dropdown3Field.key]
    })
    
    // Remove the "title_3" (or legacy "Dropdown 3") field
    finalFields.delete(dropdown3Keys[0])
  }
  
  // Post-process: Merge all names variant fields (names, names_5, names_12, etc.) into a single "names" field
  const namesVariantFields: Array<{normKey: string, field: any, originalKey: string}> = []
  
  // Find all fields that match the names variant pattern
  for (const [normKey, field] of finalFields.entries()) {
    if (isNamesVariantField(field.key)) {
      namesVariantFields.push({ normKey, field, originalKey: field.key })
    }
  }
  
  // Merge all names variant fields into a single "names" field
  if (namesVariantFields.length > 0) {
    // Collect all passenger objects with their original field keys
    // This preserves which field each passenger came from for title matching
    const passengersWithKeys: Array<{
      passenger: any
      originalKey: string
    }> = []
    const allKeys: string[] = []
    
    for (const { field, originalKey } of namesVariantFields) {
      // Collect all keys
      if (field.allKeys && field.allKeys.length > 0) {
        allKeys.push(...field.allKeys)
      } else {
        allKeys.push(field.key)
      }
      
      // Collect passenger objects
      if (typeof field.value === 'object' && field.value !== null && !Array.isArray(field.value)) {
        // Single passenger object
        // Check if it has first_name/last_name structure
        if (field.value.first_name || field.value.last_name || field.value.firstname || field.value.lastname) {
          passengersWithKeys.push({
            passenger: field.value,
            originalKey: originalKey
          })
        }
      } else if (Array.isArray(field.value)) {
        // Array of passengers
        for (const item of field.value) {
          if (typeof item === 'object' && item !== null) {
            if (item.first_name || item.last_name || item.firstname || item.lastname) {
              passengersWithKeys.push({
                passenger: item,
                originalKey: originalKey
              })
            }
          }
        }
      }
    }
    
    // Collect all title fields from original response for matching
    // Match titles to passengers by order: title -> first passenger, title_2 -> second passenger, etc.
    const titleValues: string[] = []
    const responseKeys = Object.keys(response)
    
    // Extract title values in order from response (title, title_2, title_3, ..., title_6)
    // First, try to get title (for first passenger)
    if (response.title !== null && response.title !== undefined) {
      const titleValue = String(response.title).trim()
      if (titleValue) {
        titleValues.push(titleValue)
      }
    }
    
    // Then extract title_2, title_3, ..., title_6 in order
    for (let i = 2; i <= 6; i++) {
      const titleKey = `title_${i}`
      if (response[titleKey] !== null && response[titleKey] !== undefined) {
        const titleValue = String(response[titleKey]).trim()
        if (titleValue) {
          titleValues.push(titleValue)
        }
      }
    }
    
    // Fallback: If no title fields found, try dropdown fields for backward compatibility
    if (titleValues.length === 0) {
      for (const key of responseKeys) {
        const keyLower = key.toLowerCase()
        if (keyLower.startsWith('dropdown') && response[key] !== null && response[key] !== undefined) {
          const dropdownValue = String(response[key]).trim()
          if (dropdownValue) {
            titleValues.push(dropdownValue)
          }
        }
      }
    }
    
    // Match titles to passengers by index
    // If we have titles and passengers, match them in order
    const passengersWithTitles = passengersWithKeys.map(({ passenger, originalKey }, index) => {
      let title: string | null = null
      
      // Try to match title by index (first title -> first passenger)
      if (index < titleValues.length) {
        title = titleValues[index]
      }
      
      // If no match found, check if title is already in passenger object
      if (!title && (passenger.title || passenger.salutation)) {
        title = String(passenger.title || passenger.salutation).trim()
      }
      
      return {
        ...passenger,
        _originalKey: originalKey,
        _title: title
      }
    })
    
    // Determine passenger count
    let passengerCount = passengerCountFromField
    if (passengerCount === null || passengerCount === undefined) {
      passengerCount = passengersWithTitles.length
    }
    
    // Create label with passenger count - use schema label if available
    const baseNamesLabel = formatFieldLabel('names') // Get label from schema or fallback
    const namesLabel = `${baseNamesLabel} (${passengerCount})`
    
    // Create the merged "names" field with passengers array
    // Store passengers in a special structure for display
    const namesNormKey = normalizeKey('names')
    const mergedNamesField = {
      key: 'names',
      value: passengersWithTitles, // Array of passenger objects with titles
      label: namesLabel,
      isComplex: false,
      isArray: true,
      isEmail: false,
      isUrl: false,
      isFlight: false,
      allKeys: allKeys,
      _isPassengerGroup: true // Flag to indicate this is a grouped passenger field
    }
    
    // Remove all names variant fields from finalFields
    for (const { normKey } of namesVariantFields) {
      finalFields.delete(normKey)
    }
    
    // Add the merged "names" field using normalized key
    finalFields.set(namesNormKey, mergedNamesField)
  }
  
  // Convert map to array
  for (const field of finalFields.values()) {
    fields.push({
      key: field.key,
      value: field.value,
      label: field.label,
      isComplex: field.isComplex,
      isArray: field.isArray,
      isEmail: field.isEmail,
      isUrl: field.isUrl,
      isFlight: field.isFlight
    })
  }

  return fields
})

// Extract submission metadata from payload
const submissionMeta = computed(() => {
  const meta: Record<string, any> = {}
  const payload = props.entry.payload || {}

  // Extract metadata fields
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

// Identify Flight From and Flight To fields
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

// Identify Email field
const emailField = computed(() => {
  return formFields.value.find(field => 
    field.isEmail || 
    field.label.toLowerCase().includes('email') || 
    field.key.toLowerCase().includes('email') ||
    field.key.toLowerCase().includes('e_mail') ||
    field.key.toLowerCase().includes('e-mail')
  )
})

// Identify Phone field
const phoneField = computed(() => {
  return formFields.value.find(field => 
    field.label.toLowerCase().includes('phone') || 
    field.key.toLowerCase().includes('phone') ||
    field.key.toLowerCase().includes('telephone') ||
    field.key.toLowerCase().includes('mobile') ||
    field.key.toLowerCase().includes('cell')
  )
})

// Identify Flight Departure field
const flightDepartureField = computed(() => {
  return formFields.value.find(field => 
    field.label.toLowerCase().includes('flight departure') || 
    field.label.toLowerCase().includes('departure') ||
    field.key.toLowerCase().includes('flight_departure') ||
    field.key.toLowerCase().includes('flightdeparture') ||
    field.key.toLowerCase().includes('departure')
  )
})

// Identify Flight Arrival field
const flightArrivalField = computed(() => {
  return formFields.value.find(field => 
    field.label.toLowerCase().includes('flight arrival') || 
    field.label.toLowerCase().includes('arrival') ||
    field.key.toLowerCase().includes('flight_arrival') ||
    field.key.toLowerCase().includes('flightarrival') ||
    field.key.toLowerCase().includes('arrival')
  )
})

// Identify Names field for display
const namesFieldDisplay = computed(() => {
  return formFields.value.find(field => 
    field.key.toLowerCase() === 'names' || 
    field.label.toLowerCase().startsWith('names')
  )
})

// Get remaining fields (excluding Flight From, Flight To, Email, Phone, Flight Departure, Flight Arrival, and Names)
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
      class="flex h-full flex-1 flex-col gap-6 overflow-x-auto rounded-xl p-4"
    >
      <!-- Header -->
      <div class="flex items-center justify-between">
        <div class="flex items-center gap-4">
          <Link :href="`/submissions/forms/${entry.website_id}/${entry.form_id}`">
            <Button variant="outline" size="sm">
              <ArrowLeft class="mr-2 h-4 w-4" />
              Back to Entries
            </Button>
          </Link>
        
        </div>
        <Button
          variant="destructive"
          size="sm"
          :disabled="isDeleting"
          @click="deleteEntry"
        >
          <Trash2 v-if="!isDeleting" class="mr-2 h-4 w-4" />
          <span v-else class="mr-2 h-4 w-4 animate-spin">⏳</span>
          {{ isDeleting ? 'Deleting...' : 'Delete Entry' }}
        </Button>
      </div>

      <!-- Main Content & Sidebar Layout -->
      <div class="grid gap-6 lg:grid-cols-3">
        <!-- Left Column: Form Entry Data -->
        <div class="lg:col-span-2 space-y-6">
          <!-- Form Entry Data -->
          <Card>
            <CardHeader>
              <div class="flex items-center justify-between">
                <div>
                  <CardTitle class="flex items-center gap-2">
                    <Database class="h-5 w-5" />
                    Form Entry #{{ entry.entry_id }}
                  </CardTitle>
                  <CardDescription>
                    Form ID: {{ entry.form_id }} • {{ entry.website.name }}
                  </CardDescription>
                </div>
                <div class="flex items-center gap-2">
                  <label class="flex items-center gap-2 text-sm cursor-pointer hover:text-foreground text-muted-foreground">
                    <input
                      type="checkbox"
                      v-model="showEmptyFields"
                      class="h-4 w-4 rounded border-input"
                    />
                    <span>Show empty fields</span>
                  </label>
                </div>
              </div>
            </CardHeader>
            <CardContent>
              <div v-if="formFields.length > 0" class="space-y-4">
                <!-- Flight From and Flight To - Side by Side -->
                <div v-if="flightFromField || flightToField" class="grid grid-cols-1 md:grid-cols-2 gap-4">
                  <!-- Flight From Field -->
                  <div v-if="flightFromField" class="p-4 rounded-lg border bg-card hover:border-primary/50 transition-colors">
                    <div class="space-y-3">
                      <div class="flex items-start justify-between gap-4">
                        <div class="flex items-center gap-2 flex-1 min-w-0">
                          <p class="text-sm font-semibold text-muted-foreground tracking-wide">
                            {{ flightFromField.label }}
                          </p>
                        </div>
                        <Button
                          variant="ghost"
                          size="sm"
                          class="h-8 w-8 p-0 flex-shrink-0"
                          @click="copyToClipboard(flightFromField.value, flightFromField.key)"
                          :title="copiedField === flightFromField.key ? 'Copied!' : 'Copy value'"
                        >
                          <CheckCircle2 v-if="copiedField === flightFromField.key" class="h-4 w-4 text-green-500" />
                          <Copy v-else class="h-4 w-4" />
                        </Button>
                      </div>
                      
                      <!-- Simple Value (String) -->
                      <div v-if="!flightFromField.isComplex && !flightFromField.isArray" class="pl-4">
                        <a
                          v-if="flightFromField.isEmail"
                          :href="`mailto:${flightFromField.value}`"
                          class="text-base font-medium text-primary hover:underline break-words block"
                        >
                          {{ flightFromField.value }}
                        </a>
                        <a
                          v-else-if="flightFromField.isUrl"
                          :href="flightFromField.value"
                          target="_blank"
                          rel="noopener noreferrer"
                          class="text-base font-medium text-primary hover:underline break-words block"
                        >
                          {{ flightFromField.value }}
                        </a>
                        <p v-else class="text-base font-medium text-foreground break-words whitespace-pre-wrap">
                          {{ flightFromField.value || '(empty)' }}
                        </p>
                      </div>

                      <!-- Array Value -->
                      <div v-else-if="flightFromField.isArray" class="pl-4">
                        <div class="space-y-2">
                          <div
                            v-for="(item, index) in flightFromField.value"
                            :key="index"
                            class="p-2 rounded border bg-muted/20 text-sm font-medium"
                            :class="typeof item === 'object' ? 'max-h-64 overflow-y-auto' : ''"
                          >
                            <template v-if="typeof item === 'object'">
                              <pre class="text-xs overflow-x-auto whitespace-pre-wrap break-words font-mono">{{ JSON.stringify(item, null, 2) }}</pre>
                            </template>
                            <template v-else>
                              {{ item }}
                            </template>
                          </div>
                        </div>
                      </div>

                      <!-- Complex Value (Object/JSON) -->
                      <div v-else-if="flightFromField.isComplex" class="pl-4">
                        <div class="relative rounded-lg border bg-muted/20 p-3 max-h-64 overflow-y-auto">
                          <pre class="text-xs overflow-x-auto whitespace-pre-wrap break-words font-mono text-foreground">{{ JSON.stringify(flightFromField.value, null, 2) }}</pre>
                        </div>
                      </div>
                    </div>
                  </div>

                  <!-- Flight To Field -->
                  <div v-if="flightToField" class="p-4 rounded-lg border bg-card hover:border-primary/50 transition-colors">
                    <div class="space-y-3">
                      <div class="flex items-start justify-between gap-4">
                        <div class="flex items-center gap-2 flex-1 min-w-0">
                          <p class="text-sm font-semibold text-muted-foreground tracking-wide">
                            {{ flightToField.label }}
                          </p>
                        </div>
                        <Button
                          variant="ghost"
                          size="sm"
                          class="h-8 w-8 p-0 flex-shrink-0"
                          @click="copyToClipboard(flightToField.value, flightToField.key)"
                          :title="copiedField === flightToField.key ? 'Copied!' : 'Copy value'"
                        >
                          <CheckCircle2 v-if="copiedField === flightToField.key" class="h-4 w-4 text-green-500" />
                          <Copy v-else class="h-4 w-4" />
                        </Button>
                      </div>
                      
                      <!-- Simple Value (String) -->
                      <div v-if="!flightToField.isComplex && !flightToField.isArray" class="pl-4">
                        <a
                          v-if="flightToField.isEmail"
                          :href="`mailto:${flightToField.value}`"
                          class="text-base font-medium text-primary hover:underline break-words block"
                        >
                          {{ flightToField.value }}
                        </a>
                        <a
                          v-else-if="flightToField.isUrl"
                          :href="flightToField.value"
                          target="_blank"
                          rel="noopener noreferrer"
                          class="text-base font-medium text-primary hover:underline break-words block"
                        >
                          {{ flightToField.value }}
                        </a>
                        <p v-else class="text-base font-medium text-foreground break-words whitespace-pre-wrap">
                          {{ flightToField.value || '(empty)' }}
                        </p>
                      </div>

                      <!-- Array Value -->
                      <div v-else-if="flightToField.isArray" class="pl-4">
                        <div class="space-y-2">
                          <div
                            v-for="(item, index) in flightToField.value"
                            :key="index"
                            class="p-2 rounded border bg-muted/20 text-sm font-medium"
                            :class="typeof item === 'object' ? 'max-h-64 overflow-y-auto' : ''"
                          >
                            <template v-if="typeof item === 'object'">
                              <pre class="text-xs overflow-x-auto whitespace-pre-wrap break-words font-mono">{{ JSON.stringify(item, null, 2) }}</pre>
                            </template>
                            <template v-else>
                              {{ item }}
                            </template>
                          </div>
                        </div>
                      </div>

                      <!-- Complex Value (Object/JSON) -->
                      <div v-else-if="flightToField.isComplex" class="pl-4">
                        <div class="relative rounded-lg border bg-muted/20 p-3 max-h-64 overflow-y-auto">
                          <pre class="text-xs overflow-x-auto whitespace-pre-wrap break-words font-mono text-foreground">{{ JSON.stringify(flightToField.value, null, 2) }}</pre>
                        </div>
                      </div>
                    </div>
                  </div>
                </div>

                <!-- Email and Phone - Side by Side -->
                <div v-if="emailField || phoneField" class="grid grid-cols-1 md:grid-cols-2 gap-4">
                  <!-- Email Field -->
                  <div v-if="emailField" class="p-4 rounded-lg border bg-card hover:border-primary/50 transition-colors">
                    <div class="space-y-3">
                      <div class="flex items-start justify-between gap-4">
                        <div class="flex items-center gap-2 flex-1 min-w-0">
                          <p class="text-sm font-semibold text-muted-foreground tracking-wide">
                            {{ emailField.label }}
                          </p>
                        </div>
                        <Button
                          variant="ghost"
                          size="sm"
                          class="h-8 w-8 p-0 flex-shrink-0"
                          @click="copyToClipboard(emailField.value, emailField.key)"
                          :title="copiedField === emailField.key ? 'Copied!' : 'Copy value'"
                        >
                          <CheckCircle2 v-if="copiedField === emailField.key" class="h-4 w-4 text-green-500" />
                          <Copy v-else class="h-4 w-4" />
                        </Button>
                      </div>
                      
                      <!-- Simple Value (String) -->
                      <div v-if="!emailField.isComplex && !emailField.isArray" class="pl-4">
                        <a
                          v-if="emailField.isEmail"
                          :href="`mailto:${emailField.value}`"
                          class="text-base font-medium text-primary hover:underline break-words block"
                        >
                          {{ emailField.value }}
                        </a>
                        <a
                          v-else-if="emailField.isUrl"
                          :href="emailField.value"
                          target="_blank"
                          rel="noopener noreferrer"
                          class="text-base font-medium text-primary hover:underline break-words block"
                        >
                          {{ emailField.value }}
                        </a>
                        <p v-else class="text-base font-medium text-foreground break-words whitespace-pre-wrap">
                          {{ emailField.value || '(empty)' }}
                        </p>
                      </div>

                      <!-- Array Value -->
                      <div v-else-if="emailField.isArray" class="pl-4">
                        <div class="space-y-2">
                          <div
                            v-for="(item, index) in emailField.value"
                            :key="index"
                            class="p-2 rounded border bg-muted/20 text-sm font-medium"
                            :class="typeof item === 'object' ? 'max-h-64 overflow-y-auto' : ''"
                          >
                            <template v-if="typeof item === 'object'">
                              <pre class="text-xs overflow-x-auto whitespace-pre-wrap break-words font-mono">{{ JSON.stringify(item, null, 2) }}</pre>
                            </template>
                            <template v-else>
                              {{ item }}
                            </template>
                          </div>
                        </div>
                      </div>

                      <!-- Complex Value (Object/JSON) -->
                      <div v-else-if="emailField.isComplex" class="pl-4">
                        <div class="relative rounded-lg border bg-muted/20 p-3 max-h-64 overflow-y-auto">
                          <pre class="text-xs overflow-x-auto whitespace-pre-wrap break-words font-mono text-foreground">{{ JSON.stringify(emailField.value, null, 2) }}</pre>
                        </div>
                      </div>
                    </div>
                  </div>

                  <!-- Phone Field -->
                  <div v-if="phoneField" class="p-4 rounded-lg border bg-card hover:border-primary/50 transition-colors">
                    <div class="space-y-3">
                      <div class="flex items-start justify-between gap-4">
                        <div class="flex items-center gap-2 flex-1 min-w-0">
                          <p class="text-sm font-semibold text-muted-foreground tracking-wide">
                            {{ phoneField.label }}
                          </p>
                        </div>
                        <Button
                          variant="ghost"
                          size="sm"
                          class="h-8 w-8 p-0 flex-shrink-0"
                          @click="copyToClipboard(phoneField.value, phoneField.key)"
                          :title="copiedField === phoneField.key ? 'Copied!' : 'Copy value'"
                        >
                          <CheckCircle2 v-if="copiedField === phoneField.key" class="h-4 w-4 text-green-500" />
                          <Copy v-else class="h-4 w-4" />
                        </Button>
                      </div>
                      
                      <!-- Simple Value (String) -->
                      <div v-if="!phoneField.isComplex && !phoneField.isArray" class="pl-4">
                        <a
                          v-if="phoneField.isEmail"
                          :href="`mailto:${phoneField.value}`"
                          class="text-base font-medium text-primary hover:underline break-words block"
                        >
                          {{ phoneField.value }}
                        </a>
                        <a
                          v-else-if="phoneField.isUrl"
                          :href="phoneField.value"
                          target="_blank"
                          rel="noopener noreferrer"
                          class="text-base font-medium text-primary hover:underline break-words block"
                        >
                          {{ phoneField.value }}
                        </a>
                        <p v-else class="text-base font-medium text-foreground break-words whitespace-pre-wrap">
                          {{ phoneField.value || '(empty)' }}
                        </p>
                      </div>

                      <!-- Array Value -->
                      <div v-else-if="phoneField.isArray" class="pl-4">
                        <div class="space-y-2">
                          <div
                            v-for="(item, index) in phoneField.value"
                            :key="index"
                            class="p-2 rounded border bg-muted/20 text-sm font-medium"
                            :class="typeof item === 'object' ? 'max-h-64 overflow-y-auto' : ''"
                          >
                            <template v-if="typeof item === 'object'">
                              <pre class="text-xs overflow-x-auto whitespace-pre-wrap break-words font-mono">{{ JSON.stringify(item, null, 2) }}</pre>
                            </template>
                            <template v-else>
                              {{ item }}
                            </template>
                          </div>
                        </div>
                      </div>

                      <!-- Complex Value (Object/JSON) -->
                      <div v-else-if="phoneField.isComplex" class="pl-4">
                        <div class="relative rounded-lg border bg-muted/20 p-3 max-h-64 overflow-y-auto">
                          <pre class="text-xs overflow-x-auto whitespace-pre-wrap break-words font-mono text-foreground">{{ JSON.stringify(phoneField.value, null, 2) }}</pre>
                        </div>
                      </div>
                    </div>
                  </div>
                </div>

                <!-- Flight Departure and Flight Arrival - Side by Side -->
                <div v-if="flightDepartureField || flightArrivalField" class="grid grid-cols-1 md:grid-cols-2 gap-4">
                  <!-- Flight Departure Field -->
                  <div v-if="flightDepartureField" class="p-4 rounded-lg border bg-card hover:border-primary/50 transition-colors">
                    <div class="space-y-3">
                      <div class="flex items-start justify-between gap-4">
                        <div class="flex items-center gap-2 flex-1 min-w-0">
                          <p class="text-sm font-semibold text-muted-foreground tracking-wide">
                            {{ flightDepartureField.label }}
                          </p>
                        </div>
                        <Button
                          variant="ghost"
                          size="sm"
                          class="h-8 w-8 p-0 flex-shrink-0"
                          @click="copyToClipboard(flightDepartureField.value, flightDepartureField.key)"
                          :title="copiedField === flightDepartureField.key ? 'Copied!' : 'Copy value'"
                        >
                          <CheckCircle2 v-if="copiedField === flightDepartureField.key" class="h-4 w-4 text-green-500" />
                          <Copy v-else class="h-4 w-4" />
                        </Button>
                      </div>
                      
                      <!-- Simple Value (String) -->
                      <div v-if="!flightDepartureField.isComplex && !flightDepartureField.isArray" class="pl-4">
                        <a
                          v-if="flightDepartureField.isEmail"
                          :href="`mailto:${flightDepartureField.value}`"
                          class="text-base font-medium text-primary hover:underline break-words block"
                        >
                          {{ flightDepartureField.value }}
                        </a>
                        <a
                          v-else-if="flightDepartureField.isUrl"
                          :href="flightDepartureField.value"
                          target="_blank"
                          rel="noopener noreferrer"
                          class="text-base font-medium text-primary hover:underline break-words block"
                        >
                          {{ flightDepartureField.value }}
                        </a>
                        <p v-else class="text-base font-medium text-foreground break-words whitespace-pre-wrap">
                          {{ flightDepartureField.value || '(empty)' }}
                        </p>
                      </div>

                      <!-- Array Value -->
                      <div v-else-if="flightDepartureField.isArray" class="pl-4">
                        <div class="space-y-2">
                          <div
                            v-for="(item, index) in flightDepartureField.value"
                            :key="index"
                            class="p-2 rounded border bg-muted/20 text-sm font-medium"
                            :class="typeof item === 'object' ? 'max-h-64 overflow-y-auto' : ''"
                          >
                            <template v-if="typeof item === 'object'">
                              <pre class="text-xs overflow-x-auto whitespace-pre-wrap break-words font-mono">{{ JSON.stringify(item, null, 2) }}</pre>
                            </template>
                            <template v-else>
                              {{ item }}
                            </template>
                          </div>
                        </div>
                      </div>

                      <!-- Complex Value (Object/JSON) -->
                      <div v-else-if="flightDepartureField.isComplex" class="pl-4">
                        <div class="relative rounded-lg border bg-muted/20 p-3 max-h-64 overflow-y-auto">
                          <pre class="text-xs overflow-x-auto whitespace-pre-wrap break-words font-mono text-foreground">{{ JSON.stringify(flightDepartureField.value, null, 2) }}</pre>
                        </div>
                      </div>
                    </div>
                  </div>

                  <!-- Flight Arrival Field -->
                  <div v-if="flightArrivalField" class="p-4 rounded-lg border bg-card hover:border-primary/50 transition-colors">
                    <div class="space-y-3">
                      <div class="flex items-start justify-between gap-4">
                        <div class="flex items-center gap-2 flex-1 min-w-0">
                          <p class="text-sm font-semibold text-muted-foreground tracking-wide">
                            {{ flightArrivalField.label }}
                          </p>
                        </div>
                        <Button
                          variant="ghost"
                          size="sm"
                          class="h-8 w-8 p-0 flex-shrink-0"
                          @click="copyToClipboard(flightArrivalField.value, flightArrivalField.key)"
                          :title="copiedField === flightArrivalField.key ? 'Copied!' : 'Copy value'"
                        >
                          <CheckCircle2 v-if="copiedField === flightArrivalField.key" class="h-4 w-4 text-green-500" />
                          <Copy v-else class="h-4 w-4" />
                        </Button>
                      </div>
                      
                      <!-- Simple Value (String) -->
                      <div v-if="!flightArrivalField.isComplex && !flightArrivalField.isArray" class="pl-4">
                        <a
                          v-if="flightArrivalField.isEmail"
                          :href="`mailto:${flightArrivalField.value}`"
                          class="text-base font-medium text-primary hover:underline break-words block"
                        >
                          {{ flightArrivalField.value }}
                        </a>
                        <a
                          v-else-if="flightArrivalField.isUrl"
                          :href="flightArrivalField.value"
                          target="_blank"
                          rel="noopener noreferrer"
                          class="text-base font-medium text-primary hover:underline break-words block"
                        >
                          {{ flightArrivalField.value }}
                        </a>
                        <p v-else class="text-base font-medium text-foreground break-words whitespace-pre-wrap">
                          {{ flightArrivalField.value || '(empty)' }}
                        </p>
                      </div>

                      <!-- Array Value -->
                      <div v-else-if="flightArrivalField.isArray" class="pl-4">
                        <div class="space-y-2">
                          <div
                            v-for="(item, index) in flightArrivalField.value"
                            :key="index"
                            class="p-2 rounded border bg-muted/20 text-sm font-medium"
                            :class="typeof item === 'object' ? 'max-h-64 overflow-y-auto' : ''"
                          >
                            <template v-if="typeof item === 'object'">
                              <pre class="text-xs overflow-x-auto whitespace-pre-wrap break-words font-mono">{{ JSON.stringify(item, null, 2) }}</pre>
                            </template>
                            <template v-else>
                              {{ item }}
                            </template>
                          </div>
                        </div>
                      </div>

                      <!-- Complex Value (Object/JSON) -->
                      <div v-else-if="flightArrivalField.isComplex" class="pl-4">
                        <div class="relative rounded-lg border bg-muted/20 p-3 max-h-64 overflow-y-auto">
                          <pre class="text-xs overflow-x-auto whitespace-pre-wrap break-words font-mono text-foreground">{{ JSON.stringify(flightArrivalField.value, null, 2) }}</pre>
                        </div>
                      </div>
                    </div>
                  </div>
                </div>

                <!-- Names Field -->
                <div v-if="namesFieldDisplay" class="p-4 rounded-lg border bg-card hover:border-primary/50 transition-colors">
                  <div class="space-y-3">
                    <div class="flex items-start justify-between gap-4">
                      <div class="flex items-center gap-2 flex-1 min-w-0">
                        <p class="text-sm font-semibold text-muted-foreground tracking-wide">
                          {{ namesFieldDisplay.label }}
                        </p>
                      </div>
                      <Button
                        variant="ghost"
                        size="sm"
                        class="h-8 w-8 p-0 flex-shrink-0"
                        @click="copyToClipboard(namesFieldDisplay.value, namesFieldDisplay.key)"
                        :title="copiedField === namesFieldDisplay.key ? 'Copied!' : 'Copy value'"
                      >
                        <CheckCircle2 v-if="copiedField === namesFieldDisplay.key" class="h-4 w-4 text-green-500" />
                        <Copy v-else class="h-4 w-4" />
                      </Button>
                    </div>
                    
                    <!-- Passenger List (Grouped Names) -->
                    <div v-if="namesFieldDisplay.isArray && Array.isArray(namesFieldDisplay.value) && namesFieldDisplay.value.length > 0" class="pl-4">
                      <div class="space-y-2">
                        <div
                          v-for="(passenger, index) in namesFieldDisplay.value"
                          :key="index"
                          class="p-3 rounded border bg-muted/20"
                        >
                          <div class="flex items-start gap-2 text-sm font-medium text-foreground">
                            <span class="text-muted-foreground">•</span>
                            <span class="flex-1 break-words">
                              <template v-if="passenger._title || passenger.title || passenger.salutation">
                                <span class="font-semibold">{{ passenger._title || passenger.title || passenger.salutation }}</span>
                                <span class="mx-2 text-muted-foreground">—</span>
                              </template>
                              <span class="text-sm">
  <strong>Fn:</strong>
  {{ passenger.first_name || passenger.firstname || '—' }}
  &nbsp;
  <strong>Ln:</strong>
  {{ passenger.last_name || passenger.lastname || '—' }}
</span>

                            </span>
                          </div>
                        </div>
                      </div>
                    </div>

                    <!-- Simple Value (String) -->
                    <div v-else-if="!namesFieldDisplay.isComplex && !namesFieldDisplay.isArray" class="pl-4">
                      <a
                        v-if="namesFieldDisplay.isEmail"
                        :href="`mailto:${namesFieldDisplay.value}`"
                        class="text-base font-medium text-primary hover:underline break-words block"
                      >
                        {{ namesFieldDisplay.value }}
                      </a>
                      <a
                        v-else-if="namesFieldDisplay.isUrl"
                        :href="namesFieldDisplay.value"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="text-base font-medium text-primary hover:underline break-words block"
                      >
                        {{ namesFieldDisplay.value }}
                      </a>
                      <p v-else class="text-base font-medium text-foreground break-words whitespace-pre-wrap">
                        {{ namesFieldDisplay.value || '(empty)' }}
                      </p>
                    </div>

                    <!-- Complex Value (Object/JSON) - Fallback for non-passenger objects -->
                    <div v-else-if="namesFieldDisplay.isComplex" class="pl-4">
                      <div class="relative rounded-lg border bg-muted/20 p-3 max-h-64 overflow-y-auto">
                        <pre class="text-xs overflow-x-auto whitespace-pre-wrap break-words font-mono text-foreground">{{ JSON.stringify(namesFieldDisplay.value, null, 2) }}</pre>
                      </div>
                    </div>
                  </div>
                </div>

                <!-- Generate Amadeus Code Section (if basic flight fields exist but no flight JSON) -->
                <div v-if="hasFlightData && !formFields.some(f => f.isFlight === true)" class="p-4 rounded-lg border bg-card">
                  <div class="space-y-4">
                    <div>
                      <h4 class="text-sm font-semibold mb-1">Generate Dummy Ticket Code</h4>
                      <p class="text-xs text-muted-foreground mb-4">
                        Generate a full Amadeus-style dummy ticket command block for sellingplatformconnect
                      </p>
                      <div v-if="entry.amadeus_command_block" class="mb-4 space-y-3">
                        <div class="relative rounded-lg border bg-muted/20 p-4">
                          <pre class="text-xs font-mono whitespace-pre-wrap break-words">{{ entry.amadeus_command_block }}</pre>
                        </div>
                        <div class="flex items-center gap-2">
                          <Button
                            variant="outline"
                            size="sm"
                            @click="copyToClipboard(entry.amadeus_command_block, 'amadeus_command_block')"
                            class="flex-1"
                          >
                            <CheckCircle2 v-if="copiedField === 'amadeus_command_block'" class="mr-2 h-4 w-4 text-green-500" />
                            <Copy v-else class="mr-2 h-4 w-4" />
                            {{ copiedField === 'amadeus_command_block' ? 'Copied!' : 'Copy All' }}
                          </Button>
                        </div>
                        <span v-if="entry.amadeus_generated_at" class="text-xs text-muted-foreground block">
                          Generated {{ formatDate(entry.amadeus_generated_at) }}
                        </span>
                      </div>
                    </div>
                    <Button
                      :disabled="isGeneratingAmadeusCode"
                      @click="generateAmadeusCode"
                      :variant="entry.amadeus_command_block ? 'outline' : 'default'"
                      class="w-full"
                    >
                      <Loader2 v-if="isGeneratingAmadeusCode" class="mr-2 h-4 w-4 animate-spin" />
                      <Ticket v-else class="mr-2 h-4 w-4" />
                      {{ isGeneratingAmadeusCode ? 'Generating...' : (entry.amadeus_command_block ? 'Regenerate Dummy Ticket Code' : 'Generate Dummy Ticket Code') }}
                    </Button>
                  </div>
                </div>

                <!-- Remaining Fields -->
                <template v-for="field in remainingFields" :key="field.key">
                  <!-- Flight JSON Data (Custom UI) - Display with card component -->
                  <div
                    v-if="field.isFlight === true"
                    class="space-y-3"
                  >
                    <div class="flex items-start justify-between gap-4">
                      <div class="flex items-center gap-2 flex-1 min-w-0">
                        <p class="text-sm font-semibold text-muted-foreground tracking-wide">
                          {{ field.label }}
                        </p>
                      </div>
                      <Button
                        variant="ghost"
                        size="sm"
                        class="h-8 w-8 p-0 flex-shrink-0"
                        @click="copyToClipboard(field.value, field.key)"
                        :title="copiedField === field.key ? 'Copied!' : 'Copy flight data'"
                      >
                        <CheckCircle2 v-if="copiedField === field.key" class="h-4 w-4 text-green-500" />
                        <Copy v-else class="h-4 w-4" />
                      </Button>
                    </div>
                    <FlightCard 
                      :flight-data="ensureParsedFlightData(field.value)" 
                    />
                    
                    <!-- Generate Amadeus Code Button -->
                    <div class="pt-4 border-t">
                      <div class="flex items-center justify-between gap-4">
                        <div class="flex-1">
                          <h4 class="text-sm font-semibold mb-1">Dummy Ticket Code</h4>
                          <p class="text-xs text-muted-foreground">
                            Generate a full Amadeus-style dummy ticket command block
                          </p>
                          <div v-if="entry.amadeus_command_block" class="mt-3 space-y-2">
                            <div class="relative rounded-lg border bg-muted/20 p-3 max-h-48 overflow-y-auto">
                              <pre class="text-xs font-mono whitespace-pre-wrap break-words">{{ entry.amadeus_command_block }}</pre>
                            </div>
                            <Button
                              variant="outline"
                              size="sm"
                              @click="copyToClipboard(entry.amadeus_command_block, 'amadeus_command_block')"
                              class="w-full"
                            >
                              <CheckCircle2 v-if="copiedField === 'amadeus_command_block'" class="mr-2 h-3 w-3 text-green-500" />
                              <Copy v-else class="mr-2 h-3 w-3" />
                              {{ copiedField === 'amadeus_command_block' ? 'Copied!' : 'Copy All' }}
                            </Button>
                            <span v-if="entry.amadeus_generated_at" class="text-xs text-muted-foreground block">
                              Generated {{ formatDate(entry.amadeus_generated_at) }}
                            </span>
                          </div>
                        </div>
                        <Button
                          :disabled="isGeneratingAmadeusCode || !hasFlightData"
                          @click="generateAmadeusCode"
                          :variant="entry.amadeus_command_block ? 'outline' : 'default'"
                          class="flex-shrink-0"
                        >
                          <Loader2 v-if="isGeneratingAmadeusCode" class="mr-2 h-4 w-4 animate-spin" />
                          <Ticket v-else class="mr-2 h-4 w-4" />
                          {{ isGeneratingAmadeusCode ? 'Generating...' : (entry.amadeus_command_block ? 'Regenerate Code' : 'Generate Dummy Ticket Code') }}
                        </Button>
                      </div>
                      <div v-if="!hasFlightData" class="mt-2 flex items-center gap-2 text-xs text-muted-foreground">
                        <AlertCircle class="h-3 w-3" />
                        <span>Insufficient flight data to generate ticket</span>
                      </div>
                    </div>
                  </div>
                  
                  <!-- Other field types - Display with field label wrapper -->
                  <div
                    v-else
                    class="p-4 rounded-lg border bg-card hover:border-primary/50 transition-colors"
                  >
                    <div class="space-y-3">
                      <div class="flex items-start justify-between gap-4">
                        <div class="flex items-center gap-2 flex-1 min-w-0">
                          <p class="text-sm font-semibold text-muted-foreground  tracking-wide">
                            {{ field.label }}
                          </p>
                        </div>
                        <Button
                          variant="ghost"
                          size="sm"
                          class="h-8 w-8 p-0 flex-shrink-0"
                          @click="copyToClipboard(field.value, field.key)"
                          :title="copiedField === field.key ? 'Copied!' : 'Copy value'"
                        >
                          <CheckCircle2 v-if="copiedField === field.key" class="h-4 w-4 text-green-500" />
                          <Copy v-else class="h-4 w-4" />
                        </Button>
                      </div>
                      
                      <!-- Simple Value (String) -->
                      <div v-if="!field.isComplex && !field.isArray" class="pl-4">
                        <a
                          v-if="field.isEmail"
                          :href="`mailto:${field.value}`"
                          class="text-base font-medium text-primary hover:underline break-words block"
                        >
                          {{ field.value }}
                        </a>
                        <a
                          v-else-if="field.isUrl"
                          :href="field.value"
                          target="_blank"
                          rel="noopener noreferrer"
                          class="text-base font-medium text-primary hover:underline break-words block"
                        >
                          {{ field.value }}
                        </a>
                        <p v-else class="text-base font-medium text-foreground break-words whitespace-pre-wrap">
                          {{ field.value || '(empty)' }}
                        </p>
                      </div>

                      <!-- Array Value -->
                      <div v-else-if="field.isArray" class="pl-4">
                        <div class="space-y-2">
                          <div
                            v-for="(item, index) in field.value"
                            :key="index"
                            class="p-2 rounded border bg-muted/20 text-sm font-medium"
                            :class="typeof item === 'object' ? 'max-h-64 overflow-y-auto' : ''"
                          >
                            <template v-if="typeof item === 'object'">
                              <pre class="text-xs overflow-x-auto whitespace-pre-wrap break-words font-mono">{{ JSON.stringify(item, null, 2) }}</pre>
                            </template>
                            <template v-else>
                              {{ item }}
                            </template>
                          </div>
                        </div>
                      </div>

                      <!-- Complex Value (Object/JSON) - Fallback for non-flight JSON -->
                      <div v-else-if="field.isComplex" class="pl-4">
                        <div class="relative rounded-lg border bg-muted/20 p-3 max-h-64 overflow-y-auto">
                          <pre class="text-xs overflow-x-auto whitespace-pre-wrap break-words font-mono text-foreground">{{ JSON.stringify(field.value, null, 2) }}</pre>
                        </div>
                      </div>
                    </div>
                  </div>
                </template>
              </div>
              
              <!-- Empty State -->
              <div v-else class="text-center py-12">
                <FileText class="h-12 w-12 mx-auto mb-4 text-muted-foreground opacity-50" />
                <p class="text-sm font-medium text-muted-foreground mb-1">No form fields available</p>
                <p class="text-xs text-muted-foreground">
                  {{ showEmptyFields ? 'No fields found in response data' : 'Enable "Show empty fields" to see all fields' }}
                </p>
              </div>
            </CardContent>
          </Card>

          <!-- Raw Payload -->
          <Card>
            <CardHeader>
              <div class="flex items-center justify-between">
                <div>
                  <CardTitle class="flex items-center gap-2">
                    <Code class="h-5 w-5" />
                    Raw Payload
                  </CardTitle>
                  <CardDescription>Complete entry data from Fluent Forms</CardDescription>
                </div>
                <Button
                  variant="outline"
                  size="sm"
                  @click="showRawPayload = !showRawPayload"
                >
                  <Eye v-if="!showRawPayload" class="mr-2 h-4 w-4" />
                  <EyeOff v-else class="mr-2 h-4 w-4" />
                  {{ showRawPayload ? 'Hide' : 'Show' }} Payload
                </Button>
              </div>
            </CardHeader>
            <CardContent v-if="showRawPayload">
              <div class="relative rounded-lg border bg-muted/20 p-4 max-h-96 overflow-y-auto">
                <pre class="text-xs overflow-x-auto whitespace-pre-wrap break-words font-mono">{{ JSON.stringify(entry.payload, null, 2) }}</pre>
              </div>
            </CardContent>
            <CardContent v-else>
              <div class="text-center py-6 text-muted-foreground">
                <Code class="h-8 w-8 mx-auto mb-2 opacity-50" />
                <p class="text-sm">Click "Show Payload" to view the complete raw data</p>
              </div>
            </CardContent>
          </Card>
        </div>

        <!-- Right Column: Submission Info -->
        <div class="lg:col-span-1 space-y-6">
          <!-- Submission Information -->
          <Card>
            <CardHeader>
              <CardTitle class="flex items-center gap-2">
                <ClipboardList class="h-5 w-5" />
                Submission Info
              </CardTitle>
              <CardDescription>Entry details and metadata</CardDescription>
            </CardHeader>
            <CardContent class="space-y-3">
              <!-- Submission ID -->
              <div class="flex items-center justify-between gap-4 pb-3 border-b">
                <div class="flex items-center gap-2 text-xs font-semibold text-muted-foreground uppercase tracking-wide">
                  <Hash class="h-3.5 w-3.5" />
                  Submission ID
                </div>
                <p class="text-sm font-bold text-foreground text-right">
                  #{{ submissionMeta.serialNumber || entry.entry_id }}
                </p>
              </div>

              <!-- Form ID -->
              <div class="flex items-center justify-between gap-4 pb-3 border-b">
                <div class="flex items-center gap-2 text-xs font-semibold text-muted-foreground uppercase tracking-wide">
                  <FileText class="h-3.5 w-3.5" />
                  Form ID
                </div>
                <p class="text-sm font-semibold text-foreground text-right">
                  {{ entry.form_id }}
                </p>
              </div>

              <!-- Website -->
              <div class="flex items-center justify-between gap-4 pb-3 border-b">
                <div class="flex items-center gap-2 text-xs font-semibold text-muted-foreground uppercase tracking-wide">
                  <Globe class="h-3.5 w-3.5" />
                  Website
                </div>
                <p class="text-sm font-medium text-foreground text-right">
                  {{ entry.website.name }}
                </p>
              </div>

              <!-- User IP -->
              <div v-if="submissionMeta.userIP" class="flex items-center justify-between gap-4 pb-3 border-b">
                <div class="flex items-center gap-2 text-xs font-semibold text-muted-foreground uppercase tracking-wide">
                  <Hash class="h-3.5 w-3.5" />
                  User IP
                </div>
                <a
                  :href="`https://whois.domaintools.com/${submissionMeta.userIP}`"
                  target="_blank"
                  rel="noopener noreferrer"
                  class="text-sm font-medium text-primary hover:underline text-right break-words"
                >
                  {{ submissionMeta.userIP }}
                </a>
              </div>

              <!-- Source URL -->
              <div v-if="submissionMeta.sourceURL" class="flex items-center justify-between gap-4 pb-3 border-b">
                <div class="flex items-center gap-2 text-xs font-semibold text-muted-foreground uppercase tracking-wide">
                  <Globe class="h-3.5 w-3.5" />
                  Source URL
                </div>
                <a
                  :href="submissionMeta.sourceURL"
                  target="_blank"
                  rel="noopener noreferrer"
                  class="text-sm font-medium text-primary hover:underline text-right break-words max-w-[60%]"
                >
                  {{ submissionMeta.sourceURL }}
                </a>
              </div>

              <!-- Browser -->
              <div v-if="submissionMeta.browser" class="flex items-center justify-between gap-4 pb-3 border-b">
                <div class="flex items-center gap-2 text-xs font-semibold text-muted-foreground uppercase tracking-wide">
                  <Globe class="h-3.5 w-3.5" />
                  Browser
                </div>
                <p class="text-sm font-medium text-foreground text-right">
                  {{ submissionMeta.browser }}
                </p>
              </div>

              <!-- Device / OS -->
              <div v-if="submissionMeta.device" class="flex items-center justify-between gap-4 pb-3 border-b">
                <div class="flex items-center gap-2 text-xs font-semibold text-muted-foreground uppercase tracking-wide">
                  <Hash class="h-3.5 w-3.5" />
                  Device / OS
                </div>
                <p class="text-sm font-medium text-foreground text-right">
                  {{ submissionMeta.device }}
                </p>
              </div>

              <!-- User -->
              <div v-if="submissionMeta.user" class="flex items-center justify-between gap-4 pb-3 border-b">
                <div class="flex items-center gap-2 text-xs font-semibold text-muted-foreground uppercase tracking-wide">
                  <Hash class="h-3.5 w-3.5" />
                  User
                </div>
                <p class="text-sm font-medium text-foreground text-right">
                  {{ submissionMeta.user }}
                </p>
              </div>

              <!-- Status -->
              <div v-if="submissionMeta.status" class="flex items-center justify-between gap-4 pb-3 border-b">
                <div class="flex items-center gap-2 text-xs font-semibold text-muted-foreground uppercase tracking-wide">
                  <Hash class="h-3.5 w-3.5" />
                  Status
                </div>
                <Badge variant="outline" class="font-semibold text-xs">
                  {{ submissionMeta.status }}
                </Badge>
              </div>

              <!-- Email -->
              <div v-if="entry.email" class="flex items-center justify-between gap-4 pb-3 border-b">
                <div class="flex items-center gap-2 text-xs font-semibold text-muted-foreground uppercase tracking-wide">
                  <Mail class="h-3.5 w-3.5" />
                  E-mail
                </div>
                <a
                  :href="`mailto:${entry.email}`"
                  class="text-sm font-medium text-primary hover:underline text-right break-words max-w-[60%]"
                >
                  {{ entry.email }}
                </a>
              </div>

              <!-- Submission Date -->
              <div class="flex items-center justify-between gap-4 pb-3 border-b">
                <div class="flex items-center gap-2 text-xs font-semibold text-muted-foreground uppercase tracking-wide">
                  <Calendar class="h-3.5 w-3.5" />
                  Submitted On
                </div>
                <p class="text-sm font-medium text-foreground text-right">
                  {{ formatDate(entry.created_at_wp) }}
                </p>
              </div>

              <!-- PNR Section -->
              <div class="pt-3 space-y-3 border-t">
                <div class="flex items-center justify-between gap-4">
                  <div class="flex items-center gap-2 text-xs font-semibold text-muted-foreground uppercase tracking-wide">
                    <Ticket class="h-3.5 w-3.5" />
                    PNR
                  </div>
                  <Badge v-if="!entry.pnr" variant="outline" class="text-xs">
                    Not Generated
                  </Badge>
                  <Badge v-else variant="default" class="text-xs">
                    {{ entry.pnr }}
                  </Badge>
                </div>
                <div v-if="entry.pnr" class="mb-3 space-y-2">
                  <div class="p-3 rounded-lg border bg-muted/20">
                    <div class="text-xs text-muted-foreground mb-1">Confirmation Number</div>
                    <div class="text-sm font-bold font-mono">{{ entry.pnr }}</div>
                    <div v-if="entry.pnr_source" class="text-xs text-muted-foreground mt-1">
                      Source: {{ entry.pnr_source === 'amadeus_direct' ? 'Direct' : 'Search' }}
                    </div>
                  </div>
                  <Button
                    v-if="entry.pnr_pdf_path"
                    variant="outline"
                    size="sm"
                    @click="downloadPdf"
                    class="w-full"
                  >
                    <FileText class="mr-2 h-3 w-3" />
                    Download PDF
                  </Button>
                </div>
                <Button
                  :disabled="isGeneratingPnr || !hasFlightData || !!entry.pnr"
                  @click="generatePnr"
                  :variant="entry.pnr ? 'outline' : 'default'"
                  class="w-full"
                  size="sm"
                >
                  <Loader2 v-if="isGeneratingPnr" class="mr-2 h-3 w-3 animate-spin" />
                  <Ticket v-else class="mr-2 h-3 w-3" />
                  {{ isGeneratingPnr ? 'Generating...' : (entry.pnr ? 'PNR Generated' : 'Generate PNR') }}
                </Button>
                <p v-if="!hasFlightData" class="text-xs text-muted-foreground flex items-center gap-1">
                  <AlertCircle class="h-3 w-3" />
                  Insufficient flight data
                </p>
                <p v-if="entry.pnr_generated_at" class="text-xs text-muted-foreground">
                  Generated {{ formatDate(entry.pnr_generated_at) }}
                </p>
              </div>

              <!-- Amadeus Code Section -->
              <div class="pt-3 space-y-3 border-t">
                <div class="flex items-center justify-between gap-4">
                  <div class="flex items-center gap-2 text-xs font-semibold text-muted-foreground uppercase tracking-wide">
                    <Ticket class="h-3.5 w-3.5" />
                    Dummy Ticket Code
                  </div>
                  <Badge v-if="!entry.amadeus_command_block" variant="outline" class="text-xs">
                    Not Generated
                  </Badge>
                </div>
                <div v-if="entry.amadeus_command_block" class="mb-3 space-y-2">
                  <div class="relative rounded-lg border bg-muted/20 p-2 max-h-32 overflow-y-auto">
                    <pre class="text-xs font-mono whitespace-pre-wrap break-words">{{ entry.amadeus_command_block }}</pre>
                  </div>
                  <Button
                    variant="outline"
                    size="sm"
                    @click="copyToClipboard(entry.amadeus_command_block, 'amadeus_command_block')"
                    class="w-full"
                  >
                    <CheckCircle2 v-if="copiedField === 'amadeus_command_block'" class="mr-2 h-3 w-3 text-green-500" />
                    <Copy v-else class="mr-2 h-3 w-3" />
                    {{ copiedField === 'amadeus_command_block' ? 'Copied!' : 'Copy All' }}
                  </Button>
                </div>
                <Button
                  :disabled="isGeneratingAmadeusCode || !hasFlightData"
                  @click="generateAmadeusCode"
                  :variant="entry.amadeus_command_block ? 'outline' : 'default'"
                  class="w-full"
                  size="sm"
                >
                  <Loader2 v-if="isGeneratingAmadeusCode" class="mr-2 h-3 w-3 animate-spin" />
                  <Ticket v-else class="mr-2 h-3 w-3" />
                  {{ isGeneratingAmadeusCode ? 'Generating...' : (entry.amadeus_command_block ? 'Regenerate Code' : 'Generate Dummy Ticket Code') }}
                </Button>
                <p v-if="!hasFlightData" class="text-xs text-muted-foreground flex items-center gap-1">
                  <AlertCircle class="h-3 w-3" />
                  Insufficient flight data
                </p>
                <p v-if="entry.amadeus_generated_at" class="text-xs text-muted-foreground">
                  Generated {{ formatDate(entry.amadeus_generated_at) }}
                </p>
              </div>
            </CardContent>
          </Card>

          <!-- Quick Stats -->
          <Card>
            <CardHeader>
              <CardTitle>Statistics</CardTitle>
              <CardDescription>Entry summary</CardDescription>
            </CardHeader>
            <CardContent class="space-y-3">
              <div class="flex justify-between items-center p-3 rounded-lg bg-muted/30">
                <span class="text-sm font-medium text-muted-foreground">Form Fields</span>
                <Badge variant="outline" class="font-semibold">
                  {{ formFields.length }}
                </Badge>
              </div>
              <div v-if="submissionMeta.status" class="flex justify-between items-center p-3 rounded-lg bg-muted/30">
                <span class="text-sm font-medium text-muted-foreground">Status</span>
                <Badge variant="outline" class="font-semibold">
                  {{ submissionMeta.status }}
                </Badge>
              </div>
            </CardContent>
          </Card>
        </div>
      </div>
    </div>
  </AppLayout>
</template>
