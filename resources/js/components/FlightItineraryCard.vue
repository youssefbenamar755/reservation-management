<script setup lang="ts">
import { computed } from 'vue'
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card'
import { Badge } from '@/components/ui/badge'
import { 
  Plane, 
  Clock, 
  DollarSign, 
  Luggage
} from 'lucide-vue-next'

interface Props {
  flightData: any
}

const props = defineProps<Props>()

// Detect if this is a valid flight JSON structure
const isValidFlightData = computed(() => {
  if (!props.flightData || typeof props.flightData !== 'object') {
    console.warn('Invalid flight data: not an object', props.flightData)
    return false
  }
  const data = props.flightData
  const isValid = (
    Array.isArray(data.itineraries) ||
    (data.validatingAirlineCodes && Array.isArray(data.validatingAirlineCodes)) ||
    (data.price && typeof data.price === 'object')
  )
  if (!isValid) {
    console.warn('Flight data validation failed', data)
  }
  return isValid
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

// Get segments for an itinerary
function getSegments(itinerary: any): any[] {
  return itinerary.segments || []
}

// Get first segment of first itinerary
const firstSegment = computed(() => {
  const itineraries = props.flightData?.itineraries || []
  if (itineraries.length === 0) {
    console.warn('No itineraries found in flight data', props.flightData)
    return null
  }
  const segments = getSegments(itineraries[0])
  if (segments.length === 0) {
    console.warn('No segments found in first itinerary', itineraries[0])
    return null
  }
  return segments[0]
})

// Get last segment of first itinerary (for return flights)
const lastSegment = computed(() => {
  const itineraries = props.flightData?.itineraries || []
  if (itineraries.length === 0) return null
  const firstItinerary = itineraries[0]
  const segments = getSegments(firstItinerary)
  return segments.length > 0 ? segments[segments.length - 1] : null
})

// Get airline info
const airlineInfo = computed(() => {
  const airlines = props.flightData?.validatingAirlineCodes || []
  const airline = airlines.length > 0 ? airlines[0] : 'N/A'
  
  if (!firstSegment.value) return { code: airline, name: airline, flightNumber: '' }
  
  return {
    code: firstSegment.value.carrierCode || airline,
    name: airline,
    flightNumber: firstSegment.value.number || '',
  }
})

// Get cabin class and baggage info
const cabinAndBaggage = computed(() => {
  const travelerPricings = props.flightData?.travelerPricings || []
  if (travelerPricings.length === 0) return { cabin: 'N/A', bags: 0 }
  
  const firstTraveler = travelerPricings[0]
  const fareDetailsBySegment = firstTraveler?.fareDetailsBySegment || []
  
  if (fareDetailsBySegment.length === 0) return { cabin: 'N/A', bags: 0 }
  
  const firstSegment = fareDetailsBySegment[0]
  
  // Map cabin codes to readable names
  const cabinMap: Record<string, string> = {
    'ECONOMY': 'Economy',
    'PREMIUM_ECONOMY': 'Premium Economy',
    'BUSINESS': 'Business',
    'FIRST': 'First',
  }
  
  const cabin = cabinMap[firstSegment.cabin] || firstSegment.cabin || 'Economy'
  const bags = firstSegment.includedCheckedBags?.quantity || 0
  
  return { cabin, bags }
})

// Get price
const price = computed(() => {
  const priceData = props.flightData?.price || {}
  return {
    total: priceData.total || '0',
    currency: priceData.currency || 'USD',
  }
})

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

// Get airport city name from IATA code (you could enhance this with a lookup table)
function getAirportCity(iataCode: string): string {
  // Common airport codes mapping - you might want to expand this or use an API
  const airportMap: Record<string, string> = {
    'DXB': 'Dubai',
    'BKK': 'Bangkok',
    'LHR': 'London',
    'BOG': 'Bogota',
    'JFK': 'New York',
    'LAX': 'Los Angeles',
    'CDG': 'Paris',
    'FRA': 'Frankfurt',
  }
  return airportMap[iataCode] || iataCode
}

if (!isValidFlightData.value) {
  console.warn('Invalid flight data passed to FlightItineraryCard')
}
</script>

<template>
  <div v-if="isValidFlightData" class="space-y-4 w-full">
    <!-- Summary Card - Only show if we have segment data -->
    <Card v-if="firstSegment" class="overflow-hidden">
      <CardContent class="p-6">
        <div class="flex items-start justify-between mb-6">
          <!-- Airline Info -->
          <div class="flex items-center gap-3">
            <div class="flex items-center justify-center w-10 h-10 rounded-full bg-primary/10">
              <Plane class="h-5 w-5 text-primary" />
            </div>
            <div>
              <div class="flex items-center gap-2">
                <span class="font-semibold text-lg">{{ airlineInfo.code }} {{ airlineInfo.flightNumber }}</span>
              </div>
              <p class="text-sm text-muted-foreground">{{ airlineInfo.name }}</p>
            </div>
          </div>
          
          <!-- Cabin & Baggage -->
          <div class="flex items-center gap-4">
            <div class="flex items-center gap-2">
              <DollarSign class="h-4 w-4 text-muted-foreground" />
              <span class="text-sm font-medium">{{ cabinAndBaggage.cabin }}</span>
            </div>
            <div class="flex items-center gap-2">
              <Luggage class="h-4 w-4 text-muted-foreground" />
              <span class="text-sm font-medium">{{ cabinAndBaggage.bags }} {{ cabinAndBaggage.bags === 1 ? 'bag' : 'bags' }}</span>
            </div>
          </div>
        </div>
        
        <!-- Flight Route -->
        <div class="flex items-center justify-between">
          <!-- Departure -->
          <div class="flex-1">
            <div class="text-sm text-muted-foreground mb-1">
              {{ formatDateTime(firstSegment.departure?.at || '').fullDate }}
            </div>
            <div class="text-2xl font-bold mb-1">
              {{ formatDateTime(firstSegment.departure?.at || '').time }}
            </div>
            <div class="text-lg font-semibold">
              {{ firstSegment.departure?.iataCode || '—' }}
            </div>
            <div class="text-sm text-muted-foreground">
              {{ getAirportCity(firstSegment.departure?.iataCode || '') }}
            </div>
            <div v-if="firstSegment.departure?.terminal" class="text-xs text-muted-foreground mt-1">
              Terminal {{ firstSegment.departure.terminal }}
            </div>
          </div>
          
          <!-- Flight Duration (Center) -->
          <div class="flex flex-col items-center px-4">
            <div class="w-full border-t-2 border-dashed border-muted-foreground/30 relative">
              <div class="absolute left-1/2 top-1/2 -translate-x-1/2 -translate-y-1/2 bg-background px-2">
                <Plane class="h-4 w-4 text-muted-foreground" />
              </div>
            </div>
            <div class="mt-2 px-3 py-1 bg-muted rounded-full">
              <span class="text-xs font-medium text-muted-foreground">
                {{ formatDuration(firstSegment.duration || '') }}
              </span>
            </div>
            <div v-if="getSegments(props.flightData?.itineraries?.[0] || {}).length > 1" class="mt-1">
              <Badge variant="outline" class="text-xs">
                {{ getSegments(props.flightData?.itineraries?.[0] || {}).length - 1 }} {{ getSegments(props.flightData?.itineraries?.[0] || {}).length === 2 ? 'stop' : 'stops' }}
              </Badge>
            </div>
          </div>
          
          <!-- Arrival -->
          <div class="flex-1 text-right">
            <div class="text-sm text-muted-foreground mb-1">
              {{ formatDateTime((lastSegment?.arrival?.at || firstSegment.arrival?.at) || '').fullDate }}
            </div>
            <div class="text-2xl font-bold mb-1">
              {{ formatDateTime((lastSegment?.arrival?.at || firstSegment.arrival?.at) || '').time }}
            </div>
            <div class="text-lg font-semibold">
              {{ (lastSegment?.arrival?.iataCode || firstSegment.arrival?.iataCode) || '—' }}
            </div>
            <div class="text-sm text-muted-foreground">
              {{ getAirportCity((lastSegment?.arrival?.iataCode || firstSegment.arrival?.iataCode) || '') }}
            </div>
            <div v-if="(lastSegment?.arrival?.terminal || firstSegment.arrival?.terminal)" class="text-xs text-muted-foreground mt-1">
              Terminal {{ lastSegment?.arrival?.terminal || firstSegment.arrival?.terminal }}
            </div>
          </div>
        </div>
        
        <!-- Price -->
        <div class="mt-6 pt-6 border-t flex items-center justify-between">
          <span class="text-sm text-muted-foreground">Total Price</span>
          <span class="text-2xl font-bold">{{ formatCurrency(price.total, price.currency) }}</span>
        </div>
      </CardContent>
    </Card>

    <!-- Detailed Itinerary Card -->
    <Card v-if="props.flightData.itineraries && props.flightData.itineraries.length > 0">
      <CardContent class="p-6">
        <template v-for="(itinerary, itineraryIndex) in props.flightData.itineraries" :key="itineraryIndex">
          <!-- Itinerary Header -->
          <div v-if="props.flightData.itineraries.length > 1" class="mb-6">
            <h3 class="text-lg font-semibold mb-1">Trip {{ Number(itineraryIndex) + 1 }}</h3>
            <p class="text-sm text-muted-foreground">
              {{ formatDateTime(getSegments(itinerary)[0]?.departure?.at || '').date }} - 
              {{ formatDateTime(getSegments(itinerary)[getSegments(itinerary).length - 1]?.arrival?.at || '').date }}
            </p>
          </div>
          
          <!-- Segments -->
          <div class="space-y-6">
            <div
              v-for="(segment, segmentIndex) in getSegments(itinerary)"
              :key="segmentIndex"
              class="relative"
            >
              <!-- Connection Line -->
              <div v-if="segmentIndex > 0" class="absolute left-6 top-0 bottom-1/2 w-0.5 border-l-2 border-dashed border-muted-foreground/30"></div>
              
              <div class="flex gap-4">
                <!-- Timeline Dot -->
                <div class="relative z-10 flex-shrink-0">
                  <div 
                    class="w-3 h-3 rounded-full border-2 bg-background"
                    :class="segmentIndex === 0 ? 'border-green-500 bg-green-500' : 'border-muted-foreground'"
                  ></div>
                </div>
                
                <!-- Segment Details -->
                <div class="flex-1 pb-6 last:pb-0">
                  <!-- Departure -->
                  <div class="flex items-start justify-between mb-4">
                    <div>
                      <div class="text-2xl font-semibold mb-1">
                        {{ formatDateTime(segment.departure?.at || '').time }}
                      </div>
                      <div class="font-medium text-lg mb-1">
                        {{ segment.departure?.iataCode || '—' }} Airport
                      </div>
                      <div class="text-sm text-muted-foreground">
                        {{ getAirportCity(segment.departure?.iataCode || '') }}
                        <span v-if="segment.departure?.terminal">, Terminal {{ segment.departure.terminal }}</span>
                      </div>
                    </div>
                  </div>
                  
                  <!-- Flight Info -->
                  <div class="flex items-center gap-4 text-sm text-muted-foreground mb-4 pl-4 border-l-2 border-muted-foreground/20">
                    <span class="flex items-center gap-1">
                      <Plane class="h-3.5 w-3.5" />
                      {{ segment.carrierCode }} {{ segment.number }}
                    </span>
                    <span class="flex items-center gap-1">
                      <Clock class="h-3.5 w-3.5" />
                      {{ formatDuration(segment.duration || '') }}
                    </span>
                    <Badge v-if="segment.numberOfStops === 0" variant="outline" class="text-xs">
                      Non-stop
                    </Badge>
                    <Badge v-else variant="outline" class="text-xs">
                      {{ segment.numberOfStops }} {{ segment.numberOfStops === 1 ? 'stop' : 'stops' }}
                    </Badge>
                  </div>
                  
                  <!-- Arrival -->
                  <div class="flex items-start justify-between">
                    <div>
                      <div class="text-2xl font-semibold mb-1">
                        {{ formatDateTime(segment.arrival?.at || '').time }}
                      </div>
                      <div class="font-medium text-lg mb-1">
                        {{ segment.arrival?.iataCode || '—' }} Airport
                      </div>
                      <div class="text-sm text-muted-foreground">
                        {{ getAirportCity(segment.arrival?.iataCode || '') }}
                        <span v-if="segment.arrival?.terminal">, Terminal {{ segment.arrival.terminal }}</span>
                      </div>
                    </div>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </template>
      </CardContent>
    </Card>

    <!-- Pricing Breakdown (Collapsible) -->
    <Card v-if="props.flightData.price">
      <CardHeader>
        <CardTitle class="text-base flex items-center gap-2">
          <DollarSign class="h-4 w-4" />
          Pricing Details
        </CardTitle>
      </CardHeader>
      <CardContent>
        <div class="space-y-2">
          <div class="flex justify-between items-center">
            <span class="text-sm text-muted-foreground">Base Price</span>
            <span class="text-sm font-medium">{{ formatCurrency(props.flightData.price.base || '0', props.flightData.price.currency || 'USD') }}</span>
          </div>
          
          <template v-if="props.flightData.price.fees && props.flightData.price.fees.length > 0">
            <div
              v-for="(fee, index) in props.flightData.price.fees"
              :key="index"
              class="flex justify-between items-center"
            >
              <span class="text-sm text-muted-foreground">{{ fee.type || 'Fee' }}</span>
              <span class="text-sm font-medium">{{ formatCurrency(fee.amount || 0, props.flightData.price.currency || 'USD') }}</span>
            </div>
          </template>
          
          <div class="flex justify-between items-center pt-2 border-t font-semibold">
            <span class="text-base">Grand Total</span>
            <span class="text-base">{{ formatCurrency(props.flightData.price.grandTotal || props.flightData.price.total || '0', props.flightData.price.currency || 'USD') }}</span>
          </div>
        </div>
      </CardContent>
    </Card>
  </div>
</template>
