<script setup lang="ts">
import { ref, computed } from 'vue'
import { router } from '@inertiajs/vue3'
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/components/ui/card'
import { Button } from '@/components/ui/button'
import { Badge } from '@/components/ui/badge'
import { Ticket, Loader2, AlertCircle, Copy, CheckCircle2 } from 'lucide-vue-next'
import { useToast } from '@/composables/useToast'

interface Props {
  entryId: number
  amadeusCode: string | null
  generatedAt: string | null
  hasFlightData: boolean
}

const props = defineProps<Props>()
const toast = useToast()

const isGenerating = ref(false)
const copiedField = ref<string | null>(null)

function generateAmadeusCode() {
  if (!props.hasFlightData) {
    toast.error('Insufficient flight data to generate ticket')
    return
  }

  isGenerating.value = true
  
  router.post(`/submissions/entries/${props.entryId}/generate-amadeus-code`, {}, {
    preserveScroll: true,
    onSuccess: () => {
      isGenerating.value = false
      router.reload({ only: ['entry'] })
    },
    onError: (errors) => {
      isGenerating.value = false
      toast.error(errors.message || 'Failed to generate Amadeus code')
    },
  })
}

function copyToClipboard(text: string) {
  navigator.clipboard.writeText(text).then(() => {
    copiedField.value = 'amadeus_code'
    toast.success('Copied to clipboard')
    setTimeout(() => { copiedField.value = null }, 2000)
  }).catch(() => {
    toast.error('Failed to copy')
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
</script>

<template>
  <div class="pt-3 space-y-3 border-t">
    <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-2 sm:gap-4">
      <div class="flex items-center gap-2 text-xs font-semibold text-muted-foreground uppercase tracking-wide flex-shrink-0">
        <Ticket class="h-3.5 w-3.5" />
        Dummy Ticket Code
      </div>
      <Badge v-if="!amadeusCode" variant="outline" class="text-xs w-full sm:w-auto justify-center sm:justify-start">
        Not Generated
      </Badge>
    </div>
    
    <div v-if="amadeusCode" class="mb-3 space-y-2">
      <div class="relative rounded-lg border bg-muted/20 p-2 max-h-32 overflow-y-auto">
        <pre class="text-xs font-mono whitespace-pre-wrap break-words">{{ amadeusCode }}</pre>
      </div>
      <Button
        variant="outline"
        size="sm"
        @click="copyToClipboard(amadeusCode)"
        class="w-full"
      >
        <CheckCircle2 v-if="copiedField === 'amadeus_code'" class="mr-2 h-3 w-3 text-green-500" />
        <Copy v-else class="mr-2 h-3 w-3" />
        {{ copiedField === 'amadeus_code' ? 'Copied!' : 'Copy All' }}
      </Button>
    </div>
    
    <Button
      :disabled="isGenerating || !hasFlightData"
      @click="generateAmadeusCode"
      :variant="amadeusCode ? 'outline' : 'default'"
      class="w-full"
      size="sm"
    >
      <Loader2 v-if="isGenerating" class="mr-2 h-3 w-3 animate-spin" />
      <Ticket v-else class="mr-2 h-3 w-3" />
      {{ isGenerating ? 'Generating...' : (amadeusCode ? 'Regenerate Code' : 'Generate Dummy Ticket Code') }}
    </Button>
    
    <p v-if="!hasFlightData" class="text-xs text-muted-foreground flex items-center gap-1">
      <AlertCircle class="h-3 w-3" />
      Insufficient flight data
    </p>
    
    <p v-if="generatedAt" class="text-xs text-muted-foreground">
      Generated {{ formatDate(generatedAt) }}
    </p>
  </div>
</template>
