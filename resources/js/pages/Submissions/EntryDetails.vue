<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue'
import { type BreadcrumbItem } from '@/types'
import { Head, router, usePage } from '@inertiajs/vue3'
import { computed, ref, watch } from 'vue'
import { Card, CardContent, CardDescription, CardHeader } from '@/components/ui/card'
import { Button } from '@/components/ui/button'
import { Badge } from '@/components/ui/badge'
import { Trash2, ArrowLeft, Calendar, Globe, FileText, ClipboardList, Code, Copy, CheckCircle2, Ticket, Loader2, AlertCircle, Download, Users, Plane, ChevronDown } from 'lucide-vue-next'
import { Link } from '@inertiajs/vue3'
import { useToast } from '@/composables/useToast'
import FlightCard from '@/components/FlightCard.vue'
import EntryField from '@/components/submissions/EntryField.vue'
import EntryFieldValue from '@/components/submissions/EntryFieldValue.vue'

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
const entryPdfs = ref<Array<{ passenger_name: string; url: string }>>([])

watch(
  () => (page.props as any).flash?.success,
  (message) => {
    // Only show toast if message exists and is different from what we last processed
    if (message && message !== lastProcessedFlash.value.success) {
      lastProcessedFlash.value.success = message
      toast.success(message)
    }
  }
)

watch(
  () => (page.props as any).flash?.error,
  (message) => {
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
  if (Number.isNaN(date.getTime())) return dateString
  return new Intl.DateTimeFormat('en-US', {
    month: 'short',
    day: 'numeric',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  }).format(date)
}

function copyToClipboard(text: unknown, fieldKey: string) {
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
      
      // Reload entry data to show the generated PNR (partial reload, no full page refresh)
      router.reload({ 
        only: ['entry'],
        onSuccess: (reloadedPage) => {
          // Check for PDFs in flash data (new format with multiple PDFs)
          const flash = (reloadedPage.props as any).flash
          if (flash?.pdfs && Array.isArray(flash.pdfs) && flash.pdfs.length > 0) {
            // Multiple PDFs - show success message with count
            toast.success(`PNR generated successfully. ${flash.pdfs.length} PDF(s) generated.`)
            // Store PDFs for display
            entryPdfs.value = flash.pdfs
          } else {
            // Fallback to single PDF (backward compatibility)
            const entry = (reloadedPage.props as any).entry
            if (entry?.pnr_pdf_path) {
              // Construct PDF URL - Laravel's storage link
              const pdfUrl = `/storage/${entry.pnr_pdf_path}`
              // Open PDF in new tab
              openUrl(pdfUrl)
              toast.success('PNR generated successfully. Dummy Ticket PDF opened in new tab.')
            } else if (flash?.pdf_url) {
              openUrl(flash.pdf_url)
              toast.success('PNR generated successfully. Dummy Ticket PDF opened in new tab.')
            }
          }
        }
      })
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

  if (typeof window !== 'undefined') {
    window.location.href = `/submissions/entries/${props.entry.id}/download-pdf`
  }
}

// Open URL in new tab
function openUrl(url: string) {
  if (typeof window !== 'undefined') {
    window.open(url, '_blank')
  }
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
    } catch {
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
    } catch {
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
    } catch {
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
      } catch {
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
    // Name suffixes are form-builder IDs, not passenger numbers: names_5 can
    // be the second passenger. Keep response order, including unanswered slots.
    const positionsByKey = new Map<string, number>()
    let nextPosition = 0
    for (const [key, value] of Object.entries(response)) {
      if (!isNamesVariantField(key)) continue
      const normalizedKey = normalizeKey(key)
      if (positionsByKey.has(normalizedKey)) continue
      let parsed = value
      if (typeof value === 'string') {
        try { parsed = JSON.parse(value) } catch { /* Plain names stay plain text. */ }
      }
      positionsByKey.set(normalizedKey, nextPosition)
      nextPosition += Array.isArray(parsed) ? Math.max(1, parsed.length) : 1
    }

    // Preserve every submitted name value. Only recognized name records receive
    // display annotations; strings, unfamiliar objects, and nested arrays remain intact.
    const passengersWithKeys: Array<{
      passenger: any
      originalKey: string
      position: number
    }> = []
    const allKeys: string[] = []
    
    for (const { field, originalKey } of namesVariantFields) {
      // Collect all keys
      if (field.allKeys && field.allKeys.length > 0) {
        allKeys.push(...field.allKeys)
      } else {
        allKeys.push(field.key)
      }
      
      const values = Array.isArray(field.value) ? field.value : [field.value]
      const firstPosition = positionsByKey.get(normalizeKey(originalKey)) ?? passengersWithKeys.length
      values.forEach((passenger: any, index: number) => {
        passengersWithKeys.push({ passenger, originalKey, position: firstPosition + index })
      })
    }
    
    // Collect all title fields from original response for matching
    // Match titles to passengers by order: title -> first passenger, title_2 -> second passenger, etc.
    const titleValues: Array<string | null> = []
    const responseKeys = Object.keys(response)
    
    // Do not compact missing titles: title_2 belongs to the second passenger.
    for (let i = 1; i <= 6; i++) {
      const titleKey = i === 1 ? 'title' : `title_${i}`
      titleValues.push(response[titleKey] == null ? null : String(response[titleKey]).trim() || null)
    }
    
    // Fallback: If no title fields found, try dropdown fields for backward compatibility
    if (!titleValues.some(Boolean)) {
      titleValues.length = 0
      for (const key of responseKeys) {
        const keyLower = key.toLowerCase()
        if (keyLower.startsWith('dropdown')) {
          titleValues.push(response[key] == null ? null : String(response[key]).trim() || null)
        }
      }
    }
    
    // Match titles to passengers by index
    // If we have titles and passengers, match them in order
    const passengersWithTitles = passengersWithKeys.map(({ passenger, originalKey, position }) => {
      const recognized = passenger !== null && typeof passenger === 'object' && !Array.isArray(passenger) &&
        (passenger.first_name || passenger.last_name || passenger.firstname || passenger.lastname)
      if (!recognized) return passenger
      let title: string | null = null
      
      // Try to match title by index (first title -> first passenger)
      if (position < titleValues.length) {
        title = titleValues[position]
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
    field.key !== flightFromField.value?.key && field.key !== flightToField.value?.key && (
    field.label.toLowerCase().includes('flight departure') || 
    field.label.toLowerCase().includes('departure') ||
    field.key.toLowerCase().includes('flight_departure') ||
    field.key.toLowerCase().includes('flightdeparture') ||
    field.key.toLowerCase().includes('departure'))
  )
})

// Identify Flight Arrival field
const flightArrivalField = computed(() => {
  return formFields.value.find(field =>
    field.key !== flightFromField.value?.key && field.key !== flightToField.value?.key && field.key !== flightDepartureField.value?.key && (
    field.label.toLowerCase().includes('flight arrival') || 
    field.label.toLowerCase().includes('arrival') ||
    field.label.toLowerCase().includes('return date') ||
    field.key.toLowerCase().includes('flight_arrival') ||
    field.key.toLowerCase().includes('flightarrival') ||
    field.key.toLowerCase().includes('arrival') ||
    field.key.toLowerCase().includes('return_date'))
  )
})

// Identify Names field for display
const namesFieldDisplay = computed(() => {
  return formFields.value.find(field => 
    field.key.toLowerCase() === 'names' || 
    field.label.toLowerCase().startsWith('names')
  )
})

// Each response field belongs to one visible section, regardless of form schema.
type DisplayField = (typeof formFields.value)[number]
function uniqueFields(candidates: Array<DisplayField | undefined>) {
  const seen = new Set<string>()
  return candidates.filter((field): field is DisplayField => {
    if (!field || seen.has(field.key)) return false
    seen.add(field.key)
    return true
  })
}

const flightFields = computed(() => formFields.value.filter(field => field.isFlight))
const contactFields = computed(() => {
  const fields = uniqueFields([emailField.value, phoneField.value]).filter(field => !field.isFlight && field.key !== namesFieldDisplay.value?.key)
  if (!emailField.value && props.entry.email) {
    fields.unshift({ key: 'entry_email', label: 'Email', value: props.entry.email, isEmail: true, isUrl: false, isArray: false, isComplex: false, isFlight: false })
  }
  return fields
})
const travelFields = computed(() => {
  const contactKeys = new Set(contactFields.value.map(field => field.key))
  return uniqueFields([flightFromField.value, flightToField.value, flightDepartureField.value, flightArrivalField.value])
    .filter(field => !field.isFlight && !contactKeys.has(field.key) && field.key !== namesFieldDisplay.value?.key)
})
const additionalFields = computed(() => {
  const assigned = new Set([...contactFields.value, ...travelFields.value, ...flightFields.value].map(field => field.key))
  if (namesFieldDisplay.value) assigned.add(namesFieldDisplay.value.key)
  return formFields.value.filter(field => !assigned.has(field.key))
})
const passengerRows = computed(() => {
  const value = namesFieldDisplay.value?.value
  if (Array.isArray(value)) return value
  return isPassengerRecord(value) ? [value] : []
})
function isPassengerRecord(value: any) {
  return value !== null && typeof value === 'object' && !Array.isArray(value) &&
    Boolean(value.first_name || value.firstname || value.last_name || value.lastname)
}
const technicalMetadata = computed(() => [
  { label: 'IP address', value: submissionMeta.value.userIP },
  { label: 'Source URL', value: submissionMeta.value.sourceURL },
  { label: 'Browser', value: submissionMeta.value.browser },
  { label: 'Device / OS', value: submissionMeta.value.device },
  { label: 'User', value: submissionMeta.value.user },
].filter(item => !isFieldEmpty(item.value)))
</script>

<template>
    <Head :title="`Submission #${entry.entry_id}`" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div
            class="mx-auto flex w-full max-w-[1600px] min-w-0 flex-1 flex-col gap-6 p-4 sm:p-6 lg:p-8"
        >
            <header class="space-y-5">
                <Link
                    :href="`/submissions/forms/${entry.website_id}/${entry.form_id}`"
                    class="inline-flex items-center gap-2 text-sm text-muted-foreground transition-colors hover:text-foreground"
                >
                    <ArrowLeft class="h-4 w-4" /> Back to entries
                </Link>
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div class="min-w-0 space-y-2">
                        <div
                            class="flex flex-wrap items-center gap-2 text-xs font-medium text-muted-foreground"
                        >
                            <Globe class="h-3.5 w-3.5" />
                            <span class="[overflow-wrap:anywhere]">{{
                                entry.website?.name || 'Website'
                            }}</span>
                            <span aria-hidden="true">/</span
                            ><span>Form #{{ entry.form_id }}</span>
                        </div>
                        <h1
                            class="text-2xl font-semibold tracking-tight sm:text-3xl"
                        >
                            Submission #{{ entry.entry_id }}
                        </h1>
                        <p
                            class="flex items-center gap-2 text-sm text-muted-foreground"
                        >
                            <Calendar class="h-4 w-4 shrink-0" />
                            {{ formatDate(entry.created_at_wp) }}
                        </p>
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        <Badge
                            v-if="submissionMeta.status"
                            variant="secondary"
                            class="capitalize"
                            >{{ submissionMeta.status }}</Badge
                        >
                        <Badge
                            variant="outline"
                            class="gap-1.5 px-3 py-1.5 font-normal"
                        >
                            <span
                                class="h-1.5 w-1.5 rounded-full"
                                :class="
                                    entry.pnr
                                        ? 'bg-emerald-500'
                                        : 'bg-muted-foreground'
                                "
                            />
                            {{
                                entry.pnr
                                    ? 'PNR generated'
                                    : 'PNR not generated'
                            }}
                        </Badge>
                    </div>
                </div>
                <nav
                    aria-label="Submission sections"
                    class="flex flex-wrap gap-1 border-b border-border pb-3"
                >
                    <a
                        href="#customer-details"
                        class="rounded-md px-3 py-2 text-sm text-muted-foreground hover:bg-muted hover:text-foreground"
                        >Customer & passengers</a
                    >
                    <a
                        v-if="travelFields.length || flightFields.length"
                        href="#travel-details"
                        class="rounded-md px-3 py-2 text-sm text-muted-foreground hover:bg-muted hover:text-foreground"
                        >Travel details</a
                    >
                    <a
                        href="#booking-tools"
                        class="rounded-md px-3 py-2 text-sm text-muted-foreground hover:bg-muted hover:text-foreground"
                        >Booking tools</a
                    >
                    <a
                        v-if="additionalFields.length"
                        href="#additional-details"
                        class="rounded-md px-3 py-2 text-sm text-muted-foreground hover:bg-muted hover:text-foreground"
                        >Additional information</a
                    >
                </nav>
            </header>

            <div
                class="grid min-w-0 items-start gap-6 xl:grid-cols-[minmax(0,1fr)_340px]"
            >
                <div class="min-w-0 space-y-6">
                    <Card
                        id="customer-details"
                        class="min-w-0 scroll-mt-6 gap-0 shadow-none"
                    >
                        <CardHeader class="border-b border-border pb-5">
                            <h2
                                class="flex items-center gap-2 text-base font-semibold"
                            >
                                <Users class="h-4 w-4 text-muted-foreground" />
                                Customer & passengers
                            </h2>
                            <CardDescription
                                >Contact information and passenger names from
                                this submission.</CardDescription
                            >
                        </CardHeader>
                        <CardContent class="space-y-6 pt-5">
                            <div
                                v-if="contactFields.length"
                                class="grid min-w-0 gap-3 sm:grid-cols-2"
                            >
                                <EntryField
                                    v-for="field in contactFields"
                                    :key="field.key"
                                    :field="field"
                                    :copied-field="copiedField"
                                    @copy="copyToClipboard"
                                />
                            </div>
                            <div
                                v-if="namesFieldDisplay"
                                class="min-w-0 space-y-3"
                            >
                                <div
                                    class="flex items-center justify-between gap-3"
                                >
                                    <h3 class="text-sm font-medium">
                                        Passengers
                                        <span
                                            v-if="passengerRows.length"
                                            class="ml-1 text-muted-foreground"
                                            >({{ passengerRows.length }})</span
                                        >
                                    </h3>
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        class="h-8 gap-2 text-xs text-muted-foreground"
                                        @click="
                                            copyToClipboard(
                                                namesFieldDisplay.value,
                                                namesFieldDisplay.key,
                                            )
                                        "
                                    >
                                        <CheckCircle2
                                            v-if="
                                                copiedField ===
                                                namesFieldDisplay.key
                                            "
                                            class="h-3.5 w-3.5 text-emerald-600 dark:text-emerald-400"
                                        />
                                        <Copy v-else class="h-3.5 w-3.5" /> Copy
                                        passengers
                                    </Button>
                                </div>
                                <ol
                                    v-if="passengerRows.length"
                                    class="divide-y divide-border rounded-lg border border-border"
                                >
                                    <li
                                        v-for="(
                                            passenger, index
                                        ) in passengerRows"
                                        :key="index"
                                        class="flex min-w-0 items-start gap-3 p-4 sm:gap-4"
                                    >
                                        <span
                                            class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-muted text-xs font-semibold tabular-nums"
                                            >{{
                                                String(index + 1).padStart(
                                                    2,
                                                    '0',
                                                )
                                            }}</span
                                        >
                                        <div class="min-w-0 flex-1">
                                            <dl
                                                v-if="
                                                    isPassengerRecord(passenger)
                                                "
                                                class="grid min-w-0 grid-cols-2 gap-x-4 gap-y-3 sm:grid-cols-[70px_minmax(0,1fr)_minmax(0,1fr)]"
                                            >
                                                <div
                                                    class="col-span-2 min-w-0 sm:col-span-1"
                                                >
                                                    <dt
                                                        class="mb-1 text-xs text-muted-foreground"
                                                    >
                                                        Title
                                                    </dt>
                                                    <dd
                                                        class="text-sm font-medium [overflow-wrap:anywhere]"
                                                    >
                                                        {{
                                                            passenger._title ||
                                                            passenger.title ||
                                                            passenger.salutation ||
                                                            '—'
                                                        }}
                                                    </dd>
                                                </div>
                                                <div class="min-w-0">
                                                    <dt
                                                        class="mb-1 text-xs text-muted-foreground"
                                                    >
                                                        First name
                                                    </dt>
                                                    <dd
                                                        class="text-sm font-medium [overflow-wrap:anywhere]"
                                                    >
                                                        {{
                                                            passenger.first_name ||
                                                            passenger.firstname ||
                                                            '—'
                                                        }}
                                                    </dd>
                                                </div>
                                                <div class="min-w-0">
                                                    <dt
                                                        class="mb-1 text-xs text-muted-foreground"
                                                    >
                                                        Last name
                                                    </dt>
                                                    <dd
                                                        class="text-sm font-medium [overflow-wrap:anywhere]"
                                                    >
                                                        {{
                                                            passenger.last_name ||
                                                            passenger.lastname ||
                                                            '—'
                                                        }}
                                                    </dd>
                                                </div>
                                            </dl>
                                            <EntryFieldValue
                                                v-else
                                                :value="passenger"
                                            />
                                        </div>
                                    </li>
                                </ol>
                                <EntryFieldValue
                                    v-else
                                    :value="namesFieldDisplay.value"
                                />
                            </div>
                            <p
                                v-if="
                                    !contactFields.length && !namesFieldDisplay
                                "
                                class="py-2 text-sm text-muted-foreground"
                            >
                                No contact or passenger information was
                                provided.
                            </p>
                        </CardContent>
                    </Card>

                    <Card
                        v-if="travelFields.length || flightFields.length"
                        id="travel-details"
                        class="min-w-0 scroll-mt-6 gap-0 shadow-none"
                    >
                        <CardHeader class="border-b border-border pb-5">
                            <h2
                                class="flex items-center gap-2 text-base font-semibold"
                            >
                                <Plane class="h-4 w-4 text-muted-foreground" />
                                Travel details
                            </h2>
                            <CardDescription
                                >Requested route, travel dates, and selected
                                flights.</CardDescription
                            >
                        </CardHeader>
                        <CardContent class="min-w-0 space-y-6 pt-5">
                            <div
                                v-if="travelFields.length"
                                class="grid min-w-0 gap-3 sm:grid-cols-2"
                            >
                                <EntryField
                                    v-for="field in travelFields"
                                    :key="field.key"
                                    :field="field"
                                    :copied-field="copiedField"
                                    @copy="copyToClipboard"
                                />
                            </div>
                            <section
                                v-for="field in flightFields"
                                :key="field.key"
                                class="min-w-0 space-y-3"
                            >
                                <div
                                    class="flex min-w-0 items-start justify-between gap-3"
                                >
                                    <h3
                                        class="min-w-0 text-sm font-medium [overflow-wrap:anywhere]"
                                    >
                                        {{
                                            flightFields.length > 1
                                                ? field.label
                                                : 'Flight itinerary'
                                        }}
                                    </h3>
                                    <Button
                                        variant="ghost"
                                        size="icon"
                                        class="h-7 w-7 shrink-0"
                                        :aria-label="`Copy ${field.label}`"
                                        title="Copy flight data"
                                        @click="
                                            copyToClipboard(
                                                field.value,
                                                field.key,
                                            )
                                        "
                                    >
                                        <CheckCircle2
                                            v-if="copiedField === field.key"
                                            class="h-3.5 w-3.5 text-emerald-600 dark:text-emerald-400"
                                        /><Copy v-else class="h-3.5 w-3.5" />
                                    </Button>
                                </div>
                                <FlightCard
                                    :flight-data="
                                        ensureParsedFlightData(field.value)
                                    "
                                />
                            </section>
                        </CardContent>
                    </Card>

                    <Card
                        id="additional-details"
                        class="min-w-0 scroll-mt-6 gap-0 shadow-none"
                    >
                        <CardHeader
                            class="flex flex-wrap items-start justify-between gap-3 border-b border-border pb-5"
                        >
                            <div class="space-y-1.5">
                                <h2
                                    class="flex items-center gap-2 text-base font-semibold"
                                >
                                    <FileText
                                        class="h-4 w-4 text-muted-foreground"
                                    />
                                    Additional information
                                </h2>
                                <CardDescription
                                    >Other answers submitted with this
                                    form.</CardDescription
                                >
                            </div>
                            <label
                                class="flex cursor-pointer items-center gap-2 py-1 text-xs text-muted-foreground"
                            >
                                <input
                                    v-model="showEmptyFields"
                                    type="checkbox"
                                    class="h-4 w-4 rounded border-input accent-primary"
                                />
                                Show empty fields
                            </label>
                        </CardHeader>
                        <CardContent class="pt-5">
                            <div
                                v-if="additionalFields.length"
                                class="grid min-w-0 gap-3 sm:grid-cols-2"
                            >
                                <EntryField
                                    v-for="field in additionalFields"
                                    :key="field.key"
                                    :field="field"
                                    :copied-field="copiedField"
                                    @copy="copyToClipboard"
                                />
                            </div>
                            <p
                                v-else
                                class="py-2 text-sm text-muted-foreground"
                            >
                                No additional information
                                {{
                                    showEmptyFields
                                        ? 'was submitted.'
                                        : 'to display. Enable “Show empty fields” to include unanswered fields.'
                                }}
                            </p>
                        </CardContent>
                    </Card>
                </div>

                <aside
                    class="min-w-0 space-y-6"
                    aria-label="Booking tools and submission information"
                >
                    <Card
                        id="booking-tools"
                        class="min-w-0 scroll-mt-6 gap-0 shadow-none"
                    >
                        <CardHeader class="border-b border-border pb-5">
                            <h2
                                class="flex items-center gap-2 text-base font-semibold"
                            >
                                <Ticket class="h-4 w-4 text-muted-foreground" />
                                Booking tools
                            </h2>
                            <CardDescription
                                >PNR, ticket code, and
                                documents.</CardDescription
                            >
                        </CardHeader>
                        <CardContent class="min-w-0 space-y-5 pt-5">
                            <section
                                class="space-y-3"
                                aria-labelledby="pnr-heading"
                            >
                                <div
                                    class="flex items-center justify-between gap-3"
                                >
                                    <h3
                                        id="pnr-heading"
                                        class="text-sm font-medium"
                                    >
                                        PNR confirmation
                                    </h3>
                                    <span
                                        v-if="!entry.pnr"
                                        class="text-xs text-muted-foreground"
                                        >Not generated</span
                                    >
                                </div>
                                <div v-if="entry.pnr" class="space-y-3">
                                    <div
                                        class="flex min-w-0 items-center justify-between gap-3 rounded-lg border border-emerald-500/20 bg-emerald-500/5 p-4"
                                    >
                                        <div class="min-w-0">
                                            <p
                                                class="font-mono text-xl font-semibold tracking-widest [overflow-wrap:anywhere]"
                                            >
                                                {{ entry.pnr }}
                                            </p>
                                            <p
                                                v-if="entry.pnr_source"
                                                class="mt-1 text-xs text-muted-foreground"
                                            >
                                                {{
                                                    entry.pnr_source ===
                                                    'amadeus_direct'
                                                        ? 'Direct'
                                                        : 'Search'
                                                }}
                                                booking
                                            </p>
                                        </div>
                                        <Button
                                            variant="ghost"
                                            size="icon"
                                            class="h-8 w-8 shrink-0"
                                            aria-label="Copy PNR"
                                            @click="
                                                copyToClipboard(
                                                    entry.pnr,
                                                    'pnr',
                                                )
                                            "
                                            ><CheckCircle2
                                                v-if="copiedField === 'pnr'"
                                                class="h-4 w-4 text-emerald-600 dark:text-emerald-400" /><Copy
                                                v-else
                                                class="h-4 w-4"
                                        /></Button>
                                    </div>
                                    <div
                                        v-if="entryPdfs.length"
                                        class="space-y-2"
                                    >
                                        <Button
                                            v-for="(pdf, index) in entryPdfs"
                                            :key="index"
                                            variant="outline"
                                            size="sm"
                                            class="h-auto min-h-9 w-full justify-start gap-2 py-2 text-left whitespace-normal"
                                            @click="openUrl(pdf.url)"
                                            ><Download
                                                class="h-4 w-4 shrink-0"
                                            /><span
                                                class="min-w-0 [overflow-wrap:anywhere]"
                                                >Download
                                                {{
                                                    pdf.passenger_name ||
                                                    `PDF ${index + 1}`
                                                }}</span
                                            ></Button
                                        >
                                    </div>
                                    <Button
                                        v-else-if="entry.pnr_pdf_path"
                                        variant="outline"
                                        size="sm"
                                        class="w-full gap-2"
                                        @click="downloadPdf"
                                        ><Download class="h-4 w-4" /> Download
                                        PDF</Button
                                    >
                                </div>
                                <Button
                                    :disabled="
                                        isGeneratingPnr ||
                                        !hasFlightData ||
                                        !!entry.pnr
                                    "
                                    :variant="entry.pnr ? 'outline' : 'default'"
                                    class="w-full gap-2"
                                    @click="generatePnr"
                                >
                                    <Loader2
                                        v-if="isGeneratingPnr"
                                        class="h-4 w-4 animate-spin"
                                    /><CheckCircle2
                                        v-else-if="entry.pnr"
                                        class="h-4 w-4"
                                    /><Ticket v-else class="h-4 w-4" />
                                    {{
                                        isGeneratingPnr
                                            ? 'Generating PNR…'
                                            : entry.pnr
                                              ? 'PNR generated'
                                              : 'Generate PNR'
                                    }}
                                </Button>
                                <p
                                    v-if="entry.pnr_generated_at"
                                    class="text-xs leading-relaxed text-muted-foreground"
                                >
                                    Generated
                                    {{ formatDate(entry.pnr_generated_at) }}
                                </p>
                            </section>
                            <section
                                class="min-w-0 space-y-3 border-t border-border pt-5"
                                aria-labelledby="ticket-code-heading"
                            >
                                <div
                                    class="flex flex-wrap items-center justify-between gap-2"
                                >
                                    <h3
                                        id="ticket-code-heading"
                                        class="text-sm font-medium"
                                    >
                                        Dummy ticket code
                                    </h3>
                                    <span
                                        v-if="!entry.amadeus_command_block"
                                        class="text-xs text-muted-foreground"
                                        >Not generated</span
                                    >
                                </div>
                                <p
                                    class="text-xs leading-relaxed text-muted-foreground"
                                >
                                    Amadeus command block for this itinerary.
                                </p>
                                <div
                                    v-if="entry.amadeus_command_block"
                                    class="min-w-0 space-y-2"
                                >
                                    <pre
                                        class="max-h-56 min-w-0 overflow-auto rounded-lg border border-border bg-muted/30 p-3 font-mono text-xs leading-relaxed [overflow-wrap:anywhere] whitespace-pre-wrap"
                                        >{{ entry.amadeus_command_block }}</pre
                                    >
                                    <Button
                                        variant="outline"
                                        size="sm"
                                        class="w-full gap-2"
                                        @click="
                                            copyToClipboard(
                                                entry.amadeus_command_block,
                                                'amadeus_command_block',
                                            )
                                        "
                                        ><CheckCircle2
                                            v-if="
                                                copiedField ===
                                                'amadeus_command_block'
                                            "
                                            class="h-3.5 w-3.5 text-emerald-600 dark:text-emerald-400"
                                        /><Copy v-else class="h-3.5 w-3.5" />{{
                                            copiedField ===
                                            'amadeus_command_block'
                                                ? 'Copied!'
                                                : 'Copy all code'
                                        }}</Button
                                    >
                                </div>
                                <Button
                                    :disabled="
                                        isGeneratingAmadeusCode ||
                                        !hasFlightData
                                    "
                                    variant="outline"
                                    class="w-full gap-2"
                                    @click="generateAmadeusCode"
                                    ><Loader2
                                        v-if="isGeneratingAmadeusCode"
                                        class="h-4 w-4 animate-spin"
                                    /><Code v-else class="h-4 w-4" />{{
                                        isGeneratingAmadeusCode
                                            ? 'Generating code…'
                                            : entry.amadeus_command_block
                                              ? 'Regenerate code'
                                              : 'Generate dummy ticket code'
                                    }}</Button
                                >
                                <p
                                    v-if="entry.amadeus_generated_at"
                                    class="text-xs leading-relaxed text-muted-foreground"
                                >
                                    Generated
                                    {{ formatDate(entry.amadeus_generated_at) }}
                                </p>
                            </section>
                            <p
                                v-if="!hasFlightData"
                                class="flex items-start gap-2 rounded-lg bg-muted/40 p-3 text-xs leading-relaxed text-muted-foreground"
                            >
                                <AlertCircle
                                    class="mt-0.5 h-4 w-4 shrink-0"
                                />Flight details are required to generate a PNR
                                or ticket code.
                            </p>
                        </CardContent>
                    </Card>

                    <Card class="min-w-0 gap-0 shadow-none">
                        <CardHeader class="pb-4"
                            ><h2
                                class="flex items-center gap-2 text-base font-semibold"
                            >
                                <ClipboardList
                                    class="h-4 w-4 text-muted-foreground"
                                />
                                Submission information
                            </h2></CardHeader
                        >
                        <CardContent class="min-w-0 space-y-4">
                            <dl class="divide-y divide-border text-sm">
                                <div
                                    class="flex flex-wrap justify-between gap-2 pb-3"
                                >
                                    <dt class="text-muted-foreground">
                                        Submission ID
                                    </dt>
                                    <dd class="font-medium">
                                        #{{
                                            submissionMeta.serialNumber ||
                                            entry.entry_id
                                        }}
                                    </dd>
                                </div>
                                <div
                                    class="flex flex-wrap justify-between gap-2 py-3"
                                >
                                    <dt class="text-muted-foreground">
                                        Form ID
                                    </dt>
                                    <dd class="font-medium">
                                        {{ entry.form_id }}
                                    </dd>
                                </div>
                                <div
                                    class="flex flex-wrap justify-between gap-2 py-3"
                                >
                                    <dt class="text-muted-foreground">
                                        Fields displayed
                                    </dt>
                                    <dd class="font-medium tabular-nums">
                                        {{ formFields.length }}
                                    </dd>
                                </div>
                            </dl>
                            <details
                                v-if="technicalMetadata.length"
                                class="group min-w-0 rounded-lg border border-border"
                            >
                                <summary
                                    class="flex cursor-pointer list-none items-center justify-between gap-2 p-3 text-sm font-medium [&::-webkit-details-marker]:hidden"
                                >
                                    Technical details<ChevronDown
                                        class="h-4 w-4 text-muted-foreground transition-transform group-open:rotate-180"
                                    />
                                </summary>
                                <dl
                                    class="min-w-0 space-y-3 border-t border-border p-3"
                                >
                                    <div
                                        v-for="item in technicalMetadata"
                                        :key="item.label"
                                        class="min-w-0"
                                    >
                                        <dt
                                            class="mb-1 text-xs text-muted-foreground"
                                        >
                                            {{ item.label }}
                                        </dt>
                                        <dd class="min-w-0">
                                            <a
                                                v-if="
                                                    item.label === 'IP address'
                                                "
                                                :href="`https://whois.domaintools.com/${item.value}`"
                                                target="_blank"
                                                rel="noopener noreferrer"
                                                class="text-sm [overflow-wrap:anywhere] hover:underline"
                                                >{{ item.value }}</a
                                            ><EntryFieldValue
                                                v-else
                                                :value="item.value"
                                            />
                                        </dd>
                                    </div>
                                </dl>
                            </details>
                            <div class="border-t border-border pt-4">
                                <Button
                                    variant="ghost"
                                    size="sm"
                                    :disabled="isDeleting"
                                    class="w-full gap-2 text-destructive hover:bg-destructive/10 hover:text-destructive"
                                    @click="deleteEntry"
                                    ><Loader2
                                        v-if="isDeleting"
                                        class="h-4 w-4 animate-spin"
                                    /><Trash2 v-else class="h-4 w-4" />{{
                                        isDeleting
                                            ? 'Deleting…'
                                            : 'Delete entry'
                                    }}</Button
                                >
                            </div>
                        </CardContent>
                    </Card>
                </aside>
            </div>

            <details
                class="group min-w-0 rounded-xl border border-border bg-card text-card-foreground"
                @toggle="showRawPayload = ($event.target as HTMLDetailsElement).open"
            >
                <summary
                    class="flex cursor-pointer list-none items-center justify-between gap-3 p-4 sm:px-6 [&::-webkit-details-marker]:hidden"
                >
                    <span class="flex items-center gap-2 text-sm font-medium"
                        ><Code class="h-4 w-4 text-muted-foreground" /> Raw
                        submission data</span
                    ><ChevronDown
                        class="h-4 w-4 text-muted-foreground transition-transform group-open:rotate-180"
                    />
                </summary>
                <div
                    v-if="showRawPayload"
                    class="min-w-0 space-y-3 border-t border-border p-4 sm:p-6"
                >
                    <div
                        class="flex flex-wrap items-center justify-between gap-3"
                    >
                        <p class="text-xs text-muted-foreground">
                            Complete original payload from Fluent Forms.
                        </p>
                        <Button
                            variant="outline"
                            size="sm"
                            class="gap-2"
                            @click="
                                copyToClipboard(entry.payload, 'raw_payload')
                            "
                            ><CheckCircle2
                                v-if="copiedField === 'raw_payload'"
                                class="h-3.5 w-3.5 text-emerald-600 dark:text-emerald-400"
                            /><Copy v-else class="h-3.5 w-3.5" />Copy
                            JSON</Button
                        >
                    </div>
                    <pre
                        class="max-h-96 min-w-0 overflow-auto rounded-lg bg-muted/30 p-4 font-mono text-xs leading-relaxed [overflow-wrap:anywhere] whitespace-pre-wrap"
                        >{{ JSON.stringify(entry.payload, null, 2) }}</pre
                    >
                </div>
            </details>
        </div>
    </AppLayout>
</template>
