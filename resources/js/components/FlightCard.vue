<script setup lang="ts">
import { computed, ref } from 'vue'
import { ArrowRight, ChevronDown, Code2, Plane } from 'lucide-vue-next'

interface Props {
  flightData: any
}

const props = defineProps<Props>()
const showRawData = ref(false)

// Validate flight data structure
const isValidFlightData = computed(() => {
  if (!props.flightData || typeof props.flightData !== 'object') {
    return false
  }
  const data = props.flightData
  return (
    Array.isArray(data.itineraries) ||
    (data.validatingAirlineCodes && Array.isArray(data.validatingAirlineCodes)) ||
    (data.price && typeof data.price === 'object')
  )
})

// Format duration (PT14H30M -> "14h 30m")
function formatDuration(duration: string): string {
  if (!duration || !duration.startsWith('PT')) return ''
  
  const hoursMatch = duration.match(/(\d+)H/)
  const minutesMatch = duration.match(/(\d+)M/)
  
  const hours = hoursMatch ? hoursMatch[1] : '0'
  const minutes = minutesMatch ? minutesMatch[1] : '0'
  
  if (minutes === '0') {
    return `${hours}h`
  }
  return `${hours}h ${minutes}m`
}

// Format date and time
function formatDateTime(dateTime: string): { date: string; time: string; fullDate: string } {
  if (!dateTime) return { date: '—', time: '—', fullDate: '—' }
  
  try {
    const date = new Date(dateTime)
    const dateStr = new Intl.DateTimeFormat('en-US', {
      month: 'short',
      day: 'numeric',
      year: 'numeric',
    }).format(date)
    const timeStr = new Intl.DateTimeFormat('en-US', {
      hour: '2-digit',
      minute: '2-digit',
      hour12: false,
    }).format(date)
    const fullDateStr = new Intl.DateTimeFormat('en-US', {
      day: 'numeric',
      month: 'short',
      year: 'numeric',
    }).format(date)
    
    return { date: dateStr, time: timeStr, fullDate: fullDateStr }
  } catch {
    return { date: dateTime, time: '—', fullDate: dateTime }
  }
}

// Format currency
function formatCurrency(amount: string | number, currency: string): string {
  const numAmount = typeof amount === 'string' ? parseFloat(amount) || 0 : amount || 0
  return new Intl.NumberFormat('en-US', {
    style: 'currency',
    currency: currency,
    minimumFractionDigits: 0,
    maximumFractionDigits: 0,
  }).format(numAmount)
}

// Get airport city name from IATA code
function getAirportCity(iataCode: string): string {
  const airportMap: Record<string, string> = {
    'DXB': 'Dubai',
    'BKK': 'Bangkok',
    'LHR': 'London',
    'BOG': 'Bogota',
    'JFK': 'New York',
    'LAX': 'Los Angeles',
    'CDG': 'Paris',
    'FRA': 'Frankfurt',
    'IST': 'Istanbul',
    'AMS': 'Amsterdam',
    'MAD': 'Madrid',
    'FCO': 'Rome',
    'ATH': 'Athens',
    'CAI': 'Cairo',
    'DOH': 'Doha',
    'AUH': 'Abu Dhabi',
    'JED': 'Jeddah',
    'RUH': 'Riyadh',
  }
  return airportMap[iataCode] || iataCode
}

// Get segments for an itinerary
function getSegments(itinerary: any): any[] {
  return Array.isArray(itinerary?.segments) ? itinerary.segments : []
}





// Get airline codes
const airlineCodes = computed(() => {
  const codes = props.flightData?.validatingAirlineCodes || []
  if (codes.length > 0) return codes
  
  // Fallback: get from segments
  const itineraries = props.flightData?.itineraries || []
  const airlineSet = new Set<string>()
  
  itineraries.forEach((itinerary: any) => {
    getSegments(itinerary).forEach((segment: any) => {
      if (segment.carrierCode) {
        airlineSet.add(segment.carrierCode)
      }
    })
  })
  
  return Array.from(airlineSet)
})

// Get passenger count
const passengerCount = computed(() => {
  return props.flightData?.travelerPricings?.length || 0
})

// Get cabin class
const cabinClass = computed(() => {
  const travelerPricings = props.flightData?.travelerPricings || []
  if (travelerPricings.length === 0) return 'N/A'
  
  const firstTraveler = travelerPricings[0]
  const fareDetailsBySegment = firstTraveler?.fareDetailsBySegment || []
  
  if (fareDetailsBySegment.length === 0) return 'N/A'
  
  const cabinMap: Record<string, string> = {
    'ECONOMY': 'Economy',
    'PREMIUM_ECONOMY': 'Premium Economy',
    'BUSINESS': 'Business',
    'FIRST': 'First',
  }
  
  const cabin = fareDetailsBySegment[0].cabin || 'ECONOMY'
  return cabinMap[cabin] || cabin
})

// Get price info
const priceInfo = computed(() => {
  const priceData = props.flightData?.price || {}
  return {
    total: priceData.total || priceData.grandTotal || '0',
    base: priceData.base || '0',
    currency: priceData.currency || 'USD',
    fees: priceData.fees || []
  }
})


</script>

<template>
    <div v-if="isValidFlightData" class="min-w-0 space-y-4">
        <dl
            class="grid grid-cols-2 gap-x-4 gap-y-3 rounded-lg border bg-muted/20 p-3 sm:grid-cols-4 sm:p-4"
        >
            <div class="min-w-0">
                <dt class="text-xs text-muted-foreground">Airline</dt>
                <dd class="mt-1 text-sm font-semibold break-words">
                    {{ airlineCodes.join(', ') || '—' }}
                </dd>
            </div>
            <div class="min-w-0">
                <dt class="text-xs text-muted-foreground">Passengers</dt>
                <dd class="mt-1 text-sm font-semibold">
                    {{ passengerCount }}
                    {{ passengerCount === 1 ? 'person' : 'people' }}
                </dd>
            </div>
            <div class="min-w-0">
                <dt class="text-xs text-muted-foreground">Class</dt>
                <dd class="mt-1 text-sm font-semibold break-words">
                    {{ cabinClass }}
                </dd>
            </div>
            <div class="min-w-0">
                <dt class="text-xs text-muted-foreground">Total price</dt>
                <dd class="mt-1 text-base font-bold break-words text-primary">
                    {{ formatCurrency(priceInfo.total, priceInfo.currency) }}
                </dd>
            </div>
        </dl>

        <section
            v-for="(itinerary, itineraryIndex) in props.flightData
                .itineraries || []"
            :key="itineraryIndex"
            class="min-w-0 overflow-hidden rounded-lg border"
            :aria-label="
                itineraryIndex === 0
                    ? 'Outbound flight'
                    : itineraryIndex === 1
                      ? 'Return flight'
                      : `Flight ${Number(itineraryIndex) + 1}`
            "
        >
            <div
                class="flex flex-wrap items-center justify-between gap-2 border-b bg-muted/30 px-3 py-2.5 sm:px-4"
            >
                <h4 class="flex items-center gap-2 text-sm font-semibold">
                    <Plane
                        class="h-4 w-4 shrink-0 text-primary"
                        aria-hidden="true"
                    />
                    {{
                        itineraryIndex === 0
                            ? 'Outbound flight'
                            : itineraryIndex === 1
                              ? 'Return flight'
                              : `Flight ${Number(itineraryIndex) + 1}`
                    }}
                </h4>
                <span class="text-xs text-muted-foreground">
                    {{ getSegments(itinerary).length }}
                    {{
                        getSegments(itinerary).length === 1
                            ? 'segment'
                            : 'segments'
                    }}
                </span>
            </div>

            <template
                v-for="(segment, segmentIndex) in getSegments(itinerary)"
                :key="segmentIndex"
            >
                <div class="min-w-0 space-y-3 p-3 sm:p-4">
                    <div
                        class="flex flex-wrap items-center justify-between gap-x-3 gap-y-1 text-xs"
                    >
                        <span class="font-medium"
                            >{{ segment.carrierCode }}
                            {{ segment.number }}</span
                        >
                        <span
                            class="flex flex-wrap items-center gap-x-2 text-muted-foreground"
                        >
                            <span v-if="segment.duration">{{
                                formatDuration(segment.duration)
                            }}</span>
                            <span v-if="segment.numberOfStops === 0"
                                >Non-stop</span
                            >
                            <span
                                v-else-if="
                                    typeof segment.numberOfStops === 'number'
                                "
                            >
                                {{ segment.numberOfStops }}
                                {{
                                    segment.numberOfStops === 1
                                        ? 'stop'
                                        : 'stops'
                                }}
                            </span>
                        </span>
                    </div>

                    <div
                        class="grid grid-cols-[minmax(0,1fr)_auto_minmax(0,1fr)] items-start gap-2 sm:gap-4"
                    >
                        <div class="min-w-0">
                            <p class="text-xs text-muted-foreground">
                                Departure
                            </p>
                            <p
                                class="mt-1 text-xl font-bold tracking-tight sm:text-2xl"
                            >
                                {{
                                    formatDateTime(segment.departure?.at || '')
                                        .time
                                }}
                            </p>
                            <p class="mt-1 text-base font-semibold">
                                {{ segment.departure?.iataCode || '—' }}
                            </p>
                            <p
                                class="text-xs break-words text-muted-foreground"
                            >
                                {{
                                    getAirportCity(
                                        segment.departure?.iataCode || '',
                                    )
                                }}
                            </p>
                            <p class="mt-2 text-xs break-words">
                                {{
                                    formatDateTime(segment.departure?.at || '')
                                        .date
                                }}
                            </p>
                            <p
                                v-if="segment.departure?.terminal"
                                class="mt-0.5 text-xs break-words text-muted-foreground"
                            >
                                Terminal {{ segment.departure.terminal }}
                            </p>
                        </div>
                        <ArrowRight
                            class="mt-8 h-4 w-4 shrink-0 text-muted-foreground"
                            aria-hidden="true"
                        />
                        <div class="min-w-0 text-right">
                            <p class="text-xs text-muted-foreground">Arrival</p>
                            <p
                                class="mt-1 text-xl font-bold tracking-tight sm:text-2xl"
                            >
                                {{
                                    formatDateTime(segment.arrival?.at || '')
                                        .time
                                }}
                            </p>
                            <p class="mt-1 text-base font-semibold">
                                {{ segment.arrival?.iataCode || '—' }}
                            </p>
                            <p
                                class="text-xs break-words text-muted-foreground"
                            >
                                {{
                                    getAirportCity(
                                        segment.arrival?.iataCode || '',
                                    )
                                }}
                            </p>
                            <p class="mt-2 text-xs break-words">
                                {{
                                    formatDateTime(segment.arrival?.at || '')
                                        .date
                                }}
                            </p>
                            <p
                                v-if="segment.arrival?.terminal"
                                class="mt-0.5 text-xs break-words text-muted-foreground"
                            >
                                Terminal {{ segment.arrival.terminal }}
                            </p>
                        </div>
                    </div>
                </div>

                <div
                    v-if="
                        Number(segmentIndex) < getSegments(itinerary).length - 1
                    "
                    class="flex flex-wrap items-center gap-x-2 gap-y-1 border-y border-dashed bg-muted/20 px-3 py-2 text-xs sm:px-4"
                >
                    <span class="font-medium"
                        >Connection at
                        {{ segment.arrival?.iataCode || '—' }}</span
                    >
                    <span class="text-muted-foreground">
                        Next flight:
                        {{
                            getSegments(itinerary)[Number(segmentIndex) + 1]
                                ?.carrierCode
                        }}
                        {{
                            getSegments(itinerary)[Number(segmentIndex) + 1]
                                ?.number
                        }}
                    </span>
                </div>
            </template>
            <p
                v-if="getSegments(itinerary).length === 0"
                class="p-3 text-sm text-muted-foreground sm:p-4"
            >
                No segment details available.
            </p>
        </section>

        <details
            class="group min-w-0 rounded-lg border"
            @toggle="showRawData = ($event.target as HTMLDetailsElement).open"
        >
            <summary
                class="flex cursor-pointer list-none items-center gap-2 rounded-lg px-3 py-3 text-sm font-medium outline-none focus-visible:ring-2 focus-visible:ring-ring sm:px-4 [&::-webkit-details-marker]:hidden"
            >
                <Code2
                    class="h-4 w-4 text-muted-foreground"
                    aria-hidden="true"
                />
                Flight data
                <ChevronDown
                    class="ml-auto h-4 w-4 text-muted-foreground transition-transform group-open:rotate-180"
                    aria-hidden="true"
                />
            </summary>
            <div v-if="showRawData" class="border-t bg-muted/20 p-3 sm:p-4">
                <pre
                    class="max-h-80 overflow-auto font-mono text-xs break-words whitespace-pre-wrap"
                    >{{ JSON.stringify(props.flightData, null, 2) }}</pre
                >
            </div>
        </details>
    </div>

    <div
        v-else
        class="rounded-lg border border-destructive/50 bg-destructive/10 p-4"
    >
        <p class="text-sm text-destructive">Invalid flight data structure</p>
    </div>
</template>
