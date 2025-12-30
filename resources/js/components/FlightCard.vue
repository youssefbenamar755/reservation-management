<script setup lang="ts">
import { computed, ref } from 'vue'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import { 
  Plane, 
  Calendar, 
  Clock, 
  Users, 
  DollarSign, 
  Luggage, 
  MapPin,
  ChevronRight,
  ChevronDown,
  Code,
  Eye,
  EyeOff
} from 'lucide-vue-next'

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
  return itinerary.segments || []
}

// Get route summary (outbound and return)
const routeSummary = computed(() => {
  const itineraries = props.flightData?.itineraries || []
  if (itineraries.length === 0) return null
  
  const routes: Array<{ type: string; from: string; to: string; date: string }> = []
  
  itineraries.forEach((itinerary: any, index: number) => {
    const segments = getSegments(itinerary)
    if (segments.length === 0) return
    
    const firstSegment = segments[0]
    const lastSegment = segments[segments.length - 1]
    
    const from = firstSegment.departure?.iataCode || '—'
    const to = lastSegment.arrival?.iataCode || '—'
    const date = formatDateTime(firstSegment.departure?.at || '').date
    
    routes.push({
      type: index === 0 ? 'Outbound' : 'Return',
      from,
      to,
      date
    })
  })
  
  return routes
})

// Get first route (for Flight Summary display)
const firstRoute = computed(() => {
  if (!routeSummary.value || routeSummary.value.length === 0) return null
  return routeSummary.value[0]
})

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

// Get baggage info
const baggageInfo = computed(() => {
  const travelerPricings = props.flightData?.travelerPricings || []
  if (travelerPricings.length === 0) {
    return {
      checked: { quantity: 0, weight: null },
      cabin: { quantity: 0 }
    }
  }
  
  const firstTraveler = travelerPricings[0]
  const fareDetailsBySegment = firstTraveler?.fareDetailsBySegment || []
  
  if (fareDetailsBySegment.length === 0) {
    return {
      checked: { quantity: 0, weight: null },
      cabin: { quantity: 0 }
    }
  }
  
  const firstSegment = fareDetailsBySegment[0]
  
  return {
    checked: {
      quantity: firstSegment.includedCheckedBags?.quantity || 0,
      weight: firstSegment.includedCheckedBags?.weight || null
    },
    cabin: {
      quantity: firstSegment.includedCabinBags?.quantity || 0
    }
  }
})

// Get fare brand and basis
const fareInfo = computed(() => {
  const travelerPricings = props.flightData?.travelerPricings || []
  if (travelerPricings.length === 0) {
    return {
      brand: null,
      basis: null
    }
  }
  
  const firstTraveler = travelerPricings[0]
  const fareDetailsBySegment = firstTraveler?.fareDetailsBySegment || []
  
  if (fareDetailsBySegment.length === 0) {
    return {
      brand: null,
      basis: null
    }
  }
  
  const firstSegment = fareDetailsBySegment[0]
  
  return {
    brand: firstSegment.brandedFare || firstSegment.fareBasis || null,
    basis: firstSegment.fareBasis || null
  }
})
</script>

<template>
  <div v-if="isValidFlightData" class="space-y-4 w-full">
    <!-- B) ITINERARIES (Timeline / Cards) -->
    <Card v-if="props.flightData.itineraries && props.flightData.itineraries.length > 0">
      <CardHeader class="pb-4">
        <CardTitle class="text-base">Itineraries</CardTitle>
      </CardHeader>
      <CardContent class="space-y-10">
        <template v-for="(itinerary, itineraryIndex) in props.flightData.itineraries" :key="itineraryIndex">
          <!-- Itinerary Header -->
          <div v-if="props.flightData.itineraries.length > 1" class="pb-4 border-b">
            <h3 class="text-lg font-semibold mb-1">{{ itineraryIndex === 0 ? 'Outbound Flight' : 'Return Flight' }}</h3>
            <p class="text-sm text-muted-foreground">
              {{ formatDateTime(getSegments(itinerary)[0]?.departure?.at || '').date }} - 
              {{ formatDateTime(getSegments(itinerary)[getSegments(itinerary).length - 1]?.arrival?.at || '').date }}
            </p>
          </div>
          
          <!-- Segments -->
          <div class="space-y-10">
            <div
              v-for="(segment, segmentIndex) in getSegments(itinerary)"
              :key="segmentIndex"
              class="space-y-6"
            >
              <!-- Flight Route -->
              <div class="grid grid-cols-1 md:grid-cols-3 gap-8 items-center">
                <!-- Departure -->
                <div class="space-y-2">
                  <p class="text-sm font-medium text-muted-foreground">Departure</p>
                  <div class="space-y-1">
                    <div class="text-3xl font-bold">{{ formatDateTime(segment.departure?.at || '').time }}</div>
                    <div class="text-xl font-semibold">{{ segment.departure?.iataCode || '—' }}</div>
                    <div class="text-base text-muted-foreground">{{ getAirportCity(segment.departure?.iataCode || '') }}</div>
                    <div class="text-sm text-muted-foreground mt-2">
                      {{ formatDateTime(segment.departure?.at || '').date }}
                      <span v-if="segment.departure?.terminal" class="ml-2">Terminal {{ segment.departure.terminal }}</span>
                    </div>
                  </div>
                </div>

                <!-- Flight Connection -->
                <div class="flex flex-col items-center gap-3 py-4">
                  <div class="flex items-center gap-3 w-full">
                    <div class="flex-1 h-0.5 bg-border"></div>
                    <div class="flex flex-col items-center gap-1">
                      <Plane class="h-5 w-5 text-primary" />
                      <span class="text-xs font-medium text-muted-foreground">{{ formatDuration(segment.duration || '') }}</span>
                    </div>
                    <div class="flex-1 h-0.5 bg-border"></div>
                  </div>
                  <div class="text-center space-y-1">
                    <p class="text-sm font-medium">{{ segment.carrierCode }} {{ segment.number }}</p>
                    <p class="text-xs text-muted-foreground">
                      <span v-if="segment.numberOfStops === 0">Non-stop flight</span>
                      <span v-else>{{ segment.numberOfStops }} {{ segment.numberOfStops === 1 ? 'stop' : 'stops' }}</span>
                    </p>
                  </div>
                </div>

                <!-- Arrival -->
                <div class="space-y-2 md:text-right">
                  <p class="text-sm font-medium text-muted-foreground">Arrival</p>
                  <div class="space-y-1">
                    <div class="text-3xl font-bold">{{ formatDateTime(segment.arrival?.at || '').time }}</div>
                    <div class="text-xl font-semibold">{{ segment.arrival?.iataCode || '—' }}</div>
                    <div class="text-base text-muted-foreground">{{ getAirportCity(segment.arrival?.iataCode || '') }}</div>
                    <div class="text-sm text-muted-foreground mt-2 md:text-right">
                      {{ formatDateTime(segment.arrival?.at || '').date }}
                      <span v-if="segment.arrival?.terminal" class="ml-2">Terminal {{ segment.arrival.terminal }}</span>
                    </div>
                  </div>
                </div>
              </div>

              <!-- Connection Info (if not last segment) -->
              <div v-if="segmentIndex < getSegments(itinerary).length - 1" class="pt-4 border-t">
                <p class="text-sm text-muted-foreground text-center">
                  Connection at {{ segment.arrival?.iataCode }} • 
                  Next flight: {{ getSegments(itinerary)[segmentIndex + 1]?.carrierCode }} {{ getSegments(itinerary)[segmentIndex + 1]?.number }}
                </p>
              </div>
            </div>
          </div>
        </template>
              <!-- Flight Details -->
              <div class="pt-6 border-t">
          <div class="grid grid-cols-2 md:grid-cols-4 gap-8">
            <div class="text-center md:text-left">
              <p class="text-sm text-muted-foreground mb-2">Airline</p>
              <p class="text-base font-semibold">
                <span v-for="(code, index) in airlineCodes" :key="index">
                  {{ code }}<span v-if="Number(index) < airlineCodes.length - 1">, </span>
                </span>
              </p>
            </div>
            <div class="text-center md:text-left">
              <p class="text-sm text-muted-foreground mb-2">Passengers</p>
              <p class="text-base font-semibold">{{ passengerCount }} {{ passengerCount === 1 ? 'person' : 'people' }}</p>
            </div>
            <div class="text-center md:text-left">
              <p class="text-sm text-muted-foreground mb-2">Class</p>
              <p class="text-base font-semibold">{{ cabinClass }}</p>
            </div>
            <div class="text-center md:text-left">
              <p class="text-sm text-muted-foreground mb-2">Total Price</p>
              <p class="text-xl font-bold text-primary">
                {{ formatCurrency(priceInfo.total, priceInfo.currency) }}
              </p>
            </div>
          </div>
        </div>
      </CardContent>
    </Card>

    <!-- Raw Flight Data (Collapsible) -->
    <Card>
      <CardHeader class="pb-4">
        <div class="flex items-center justify-between">
          <CardTitle class="text-base">Raw Data</CardTitle>
          <Button
            variant="ghost"
            size="sm"
            @click="showRawData = !showRawData"
          >
            <Eye v-if="!showRawData" class="h-4 w-4" />
            <EyeOff v-else class="h-4 w-4" />
          </Button>
        </div>
      </CardHeader>
      <CardContent v-if="showRawData">
        <div class="relative rounded-lg border bg-muted/20 p-4 max-h-96 overflow-y-auto">
          <pre class="text-xs overflow-x-auto whitespace-pre-wrap break-words font-mono">{{ JSON.stringify(props.flightData, null, 2) }}</pre>
        </div>
      </CardContent>
    </Card>
  </div>
  
  <!-- Fallback if invalid data -->
  <div v-else class="p-4 rounded-lg border border-destructive/50 bg-destructive/10">
    <p class="text-sm text-destructive">Invalid flight data structure</p>
  </div>
</template>

