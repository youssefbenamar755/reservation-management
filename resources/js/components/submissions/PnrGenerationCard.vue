<script setup lang="ts">
import { ref } from 'vue'
import { router, usePage } from '@inertiajs/vue3'
import { Button } from '@/components/ui/button'
import { Badge } from '@/components/ui/badge'
import { Ticket, Loader2, AlertCircle, FileText, Download } from 'lucide-vue-next'
import { useToast } from '@/composables/useToast'

interface PdfInfo {
  passenger_name: string
  url: string
}

interface Props {
  entryId: number
  pnr: string | null
  pnrSource: string | null
  pnrPdfPath: string | null
  pnrGeneratedAt: string | null
  hasFlightData: boolean
}

const props = defineProps<Props>()
const toast = useToast()

const isGenerating = ref(false)
const entryPdfs = ref<PdfInfo[]>([])

function generatePnr() {
  if (!props.hasFlightData) {
    toast.error('Insufficient flight data to generate PNR')
    return
  }

  if (props.pnr) {
    toast.error('PNR already exists for this submission')
    return
  }

  isGenerating.value = true
  
  router.post(`/submissions/entries/${props.entryId}/generate-pnr`, {}, {
    preserveScroll: true,
    onSuccess: (page) => {
      isGenerating.value = false
      
      router.reload({ 
        only: ['entry'],
        onSuccess: (reloadedPage) => {
          const flash = (reloadedPage.props as any).flash
          if (flash?.pdfs && Array.isArray(flash.pdfs) && flash.pdfs.length > 0) {
            toast.success(`PNR generated successfully. ${flash.pdfs.length} PDF(s) generated.`)
            entryPdfs.value = flash.pdfs
          } else {
            const entry = (reloadedPage.props as any).entry
            if (entry?.pnr_pdf_path) {
              const pdfUrl = `/storage/${entry.pnr_pdf_path}`
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
      isGenerating.value = false
      toast.error(errors.message || 'Failed to generate PNR')
    },
  })
}

function downloadPdf() {
  if (!props.pnrPdfPath) {
    toast.error('PDF not available for this submission')
    return
  }

  if (typeof window !== 'undefined') {
    window.location.href = `/submissions/entries/${props.entryId}/download-pdf`
  }
}

function openUrl(url: string) {
  if (typeof window !== 'undefined') {
    window.open(url, '_blank')
  }
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
        PNR
      </div>
      <Badge v-if="!pnr" variant="outline" class="text-xs w-full sm:w-auto justify-center sm:justify-start">
        Not Generated
      </Badge>
      <Badge v-else variant="default" class="text-xs w-full sm:w-auto justify-center sm:justify-start">
        {{ pnr }}
      </Badge>
    </div>
    
    <div v-if="pnr" class="mb-3 space-y-2">
      <div class="p-3 rounded-lg border bg-muted/20">
        <div class="text-xs text-muted-foreground mb-1">Confirmation Number</div>
        <div class="text-sm font-bold font-mono">{{ pnr }}</div>
        <div v-if="pnrSource" class="text-xs text-muted-foreground mt-1">
          Source: {{ pnrSource === 'amadeus_direct' ? 'Direct' : 'Search' }}
        </div>
      </div>
      
      <!-- Multiple PDFs (new format) -->
      <div v-if="entryPdfs.length > 0" class="space-y-2 w-full">
        <div v-for="(pdf, index) in entryPdfs" :key="index" class="w-full">
          <Button
            variant="outline"
            size="sm"
            @click="() => openUrl(pdf.url)"
            class="w-full"
          >
            <Download class="mr-2 h-3 w-3" />
            Download {{ pdf.passenger_name || `PDF ${index + 1}` }}
          </Button>
        </div>
      </div>
      
      <!-- Single PDF (backward compatibility) -->
      <Button
        v-else-if="pnrPdfPath"
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
      :disabled="isGenerating || !hasFlightData || !!pnr"
      @click="generatePnr"
      :variant="pnr ? 'outline' : 'default'"
      class="w-full"
      size="sm"
    >
      <Loader2 v-if="isGenerating" class="mr-2 h-3 w-3 animate-spin" />
      <Ticket v-else class="mr-2 h-3 w-3" />
      {{ isGenerating ? 'Generating...' : (pnr ? 'PNR Generated' : 'Generate PNR') }}
    </Button>
    
    <p v-if="!hasFlightData" class="text-xs text-muted-foreground flex items-center gap-1">
      <AlertCircle class="h-3 w-3" />
      Insufficient flight data
    </p>
    
    <p v-if="pnrGeneratedAt" class="text-xs text-muted-foreground">
      Generated {{ formatDate(pnrGeneratedAt) }}
    </p>
  </div>
</template>
