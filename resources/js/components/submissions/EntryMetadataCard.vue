<script setup lang="ts">
import { Card, CardContent, CardHeader, CardTitle, CardDescription } from '@/components/ui/card'
import { Badge } from '@/components/ui/badge'
import { Mail, Calendar, Globe, FileText, Hash, ClipboardList } from 'lucide-vue-next'

interface SubmissionMeta {
  userIP?: string
  sourceURL?: string
  browser?: string
  device?: string
  user?: string
  status?: string
  serialNumber?: string
}

interface Props {
  entryId: number
  formId: number
  email: string | null
  createdAt: string | null
  submissionMeta: SubmissionMeta
}

const props = defineProps<Props>()

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
  <Card>
    <CardHeader>
      <CardTitle>Submission Metadata</CardTitle>
      <CardDescription>Details about this form submission</CardDescription>
    </CardHeader>
    <CardContent class="space-y-3">
      <!-- Entry ID -->
      <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-2 sm:gap-4 pb-3 border-b">
        <div class="flex items-center gap-2 text-xs font-semibold text-muted-foreground uppercase tracking-wide flex-shrink-0">
          <Hash class="h-3.5 w-3.5" />
          Entry ID
        </div>
        <Badge variant="outline" class="font-semibold text-xs w-full sm:w-auto justify-center sm:justify-start">
          #{{ entryId }}
        </Badge>
      </div>

      <!-- Form ID -->
      <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-2 sm:gap-4 pb-3 border-b">
        <div class="flex items-center gap-2 text-xs font-semibold text-muted-foreground uppercase tracking-wide flex-shrink-0">
          <FileText class="h-3.5 w-3.5" />
          Form ID
        </div>
        <p class="text-sm font-medium text-foreground break-words sm:text-right w-full sm:w-auto">
          {{ formId }}
        </p>
      </div>

      <!-- Serial Number -->
      <div v-if="submissionMeta.serialNumber" class="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-2 sm:gap-4 pb-3 border-b">
        <div class="flex items-center gap-2 text-xs font-semibold text-muted-foreground uppercase tracking-wide flex-shrink-0">
          <ClipboardList class="h-3.5 w-3.5" />
          Serial Number
        </div>
        <p class="text-sm font-medium text-foreground break-words sm:text-right w-full sm:w-auto">
          {{ submissionMeta.serialNumber }}
        </p>
      </div>

      <!-- User IP -->
      <div v-if="submissionMeta.userIP" class="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-2 sm:gap-4 pb-3 border-b">
        <div class="flex items-center gap-2 text-xs font-semibold text-muted-foreground uppercase tracking-wide flex-shrink-0">
          <Hash class="h-3.5 w-3.5" />
          User IP
        </div>
        <a
          :href="`https://whois.domaintools.com/${submissionMeta.userIP}`"
          target="_blank"
          rel="noopener noreferrer"
          class="text-sm font-medium text-primary hover:underline break-words sm:text-right w-full sm:w-auto"
        >
          {{ submissionMeta.userIP }}
        </a>
      </div>

      <!-- Source URL -->
      <div v-if="submissionMeta.sourceURL" class="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-2 sm:gap-4 pb-3 border-b">
        <div class="flex items-center gap-2 text-xs font-semibold text-muted-foreground uppercase tracking-wide flex-shrink-0">
          <Globe class="h-3.5 w-3.5" />
          Source URL
        </div>
        <a
          :href="submissionMeta.sourceURL"
          target="_blank"
          rel="noopener noreferrer"
          class="text-sm font-medium text-primary hover:underline break-words sm:text-right sm:max-w-[60%] w-full sm:w-auto"
        >
          {{ submissionMeta.sourceURL }}
        </a>
      </div>

      <!-- Browser -->
      <div v-if="submissionMeta.browser" class="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-2 sm:gap-4 pb-3 border-b">
        <div class="flex items-center gap-2 text-xs font-semibold text-muted-foreground uppercase tracking-wide flex-shrink-0">
          <Globe class="h-3.5 w-3.5" />
          Browser
        </div>
        <p class="text-sm font-medium text-foreground break-words sm:text-right w-full sm:w-auto">
          {{ submissionMeta.browser }}
        </p>
      </div>

      <!-- Device / OS -->
      <div v-if="submissionMeta.device" class="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-2 sm:gap-4 pb-3 border-b">
        <div class="flex items-center gap-2 text-xs font-semibold text-muted-foreground uppercase tracking-wide flex-shrink-0">
          <Hash class="h-3.5 w-3.5" />
          Device / OS
        </div>
        <p class="text-sm font-medium text-foreground break-words sm:text-right w-full sm:w-auto">
          {{ submissionMeta.device }}
        </p>
      </div>

      <!-- User -->
      <div v-if="submissionMeta.user" class="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-2 sm:gap-4 pb-3 border-b">
        <div class="flex items-center gap-2 text-xs font-semibold text-muted-foreground uppercase tracking-wide flex-shrink-0">
          <Hash class="h-3.5 w-3.5" />
          User
        </div>
        <p class="text-sm font-medium text-foreground break-words sm:text-right w-full sm:w-auto">
          {{ submissionMeta.user }}
        </p>
      </div>

      <!-- Status -->
      <div v-if="submissionMeta.status" class="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-2 sm:gap-4 pb-3 border-b">
        <div class="flex items-center gap-2 text-xs font-semibold text-muted-foreground uppercase tracking-wide flex-shrink-0">
          <Hash class="h-3.5 w-3.5" />
          Status
        </div>
        <Badge variant="outline" class="font-semibold text-xs w-full sm:w-auto justify-center sm:justify-start">
          {{ submissionMeta.status }}
        </Badge>
      </div>

      <!-- Email -->
      <div v-if="email" class="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-2 sm:gap-4 pb-3 border-b">
        <div class="flex items-center gap-2 text-xs font-semibold text-muted-foreground uppercase tracking-wide flex-shrink-0">
          <Mail class="h-3.5 w-3.5" />
          E-mail
        </div>
        <a
          :href="`mailto:${email}`"
          class="text-sm font-medium text-primary hover:underline break-words sm:text-right sm:max-w-[60%] w-full sm:w-auto"
        >
          {{ email }}
        </a>
      </div>

      <!-- Submission Date -->
      <div class="flex flex-col sm:flex-row items-start sm:items-center justify-between gap-2 sm:gap-4 pb-3 border-b">
        <div class="flex items-center gap-2 text-xs font-semibold text-muted-foreground uppercase tracking-wide flex-shrink-0">
          <Calendar class="h-3.5 w-3.5" />
          Submitted On
        </div>
        <p class="text-sm font-medium text-foreground break-words sm:text-right w-full sm:w-auto">
          {{ formatDate(createdAt) }}
        </p>
      </div>
    </CardContent>
  </Card>
</template>
