<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue'
import { type BreadcrumbItem } from '@/types'
import { Head, router } from '@inertiajs/vue3'
import { computed, ref } from 'vue'
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card'
import { Button } from '@/components/ui/button'
import { Trash2, ArrowLeft } from 'lucide-vue-next'
import { Link } from '@inertiajs/vue3'
import { useToast } from '@/composables/useToast'

const props = defineProps<{ submission: any }>()

const breadcrumbs = computed<BreadcrumbItem[]>(() => [
  { title: 'Submissions', href: '/submissions' },
  { title: `#${props.submission.entry_id}`, href: '#' },
])

const toast = useToast()
const isDeleting = ref(false)

function deleteSubmission() {
  if (!confirm(`Are you sure you want to delete submission #${props.submission.entry_id}? This action cannot be undone.`)) {
    return
  }

  isDeleting.value = true

  router.delete(`/submissions/${props.submission.id}`, {
    onSuccess: () => {
      router.visit('/submissions')
    },
    onError: () => {
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
</script>

<template>
  <Head title="Submission Details" />

  <AppLayout :breadcrumbs="breadcrumbs">
    <div
      class="flex h-full flex-1 flex-col gap-4 overflow-x-auto rounded-xl p-4"
    >
      <!-- Header -->
      <div class="flex items-center justify-between">
        <Link href="/submissions">
          <Button variant="outline" size="sm">
            <ArrowLeft class="mr-2 h-4 w-4" />
            Back to Submissions
          </Button>
        </Link>
        <Button
          variant="destructive"
          size="sm"
          :disabled="isDeleting"
          @click="deleteSubmission"
        >
          <Trash2 v-if="!isDeleting" class="mr-2 h-4 w-4" />
          <span v-else class="mr-2">Deleting...</span>
          {{ isDeleting ? 'Deleting...' : 'Delete Submission' }}
        </Button>
      </div>

      <!-- Submission Info -->
      <div class="grid grid-cols-1 gap-4 md:grid-cols-2">
        <Card>
          <CardHeader>
            <CardTitle>Submission Information</CardTitle>
            <CardDescription>Basic details about this submission</CardDescription>
          </CardHeader>
          <CardContent class="space-y-3">
            <div class="flex justify-between">
              <span class="text-sm text-muted-foreground">Entry ID:</span>
              <span class="font-semibold">#{{ submission.entry_id }}</span>
            </div>
            <div class="flex justify-between">
              <span class="text-sm text-muted-foreground">Form ID:</span>
              <span class="font-medium">{{ submission.form_id }}</span>
            </div>
            <div class="flex justify-between">
              <span class="text-sm text-muted-foreground">Website:</span>
              <span class="font-medium">{{ submission.website.name }}</span>
            </div>
            <div class="flex justify-between">
              <span class="text-sm text-muted-foreground">Email:</span>
              <span class="font-medium">{{ submission.email || '—' }}</span>
            </div>
            <div class="flex justify-between">
              <span class="text-sm text-muted-foreground">Submitted:</span>
              <span class="font-medium">{{ formatDate(submission.created_at_wp) }}</span>
            </div>
          </CardContent>
        </Card>

        <Card>
          <CardHeader>
            <CardTitle>Raw Payload</CardTitle>
            <CardDescription>Complete submission data from Fluent Forms</CardDescription>
          </CardHeader>
          <CardContent>
            <div class="relative flex-1 rounded-lg border bg-muted/20 p-4">
              <pre class="text-xs overflow-x-auto whitespace-pre-wrap break-words">{{ JSON.stringify(submission.payload, null, 2) }}</pre>
            </div>
          </CardContent>
        </Card>
      </div>

      <!-- Formatted Data (if payload has structured fields) -->
      <Card v-if="submission.payload && typeof submission.payload === 'object'">
        <CardHeader>
          <CardTitle>Form Fields</CardTitle>
          <CardDescription>Extracted form field values</CardDescription>
        </CardHeader>
        <CardContent>
          <div class="grid grid-cols-1 gap-3 md:grid-cols-2">
            <template v-for="(value, key) in submission.payload" :key="key">
              <div v-if="value !== null && typeof value !== 'object'" class="space-y-1">
                <div class="text-xs font-semibold text-muted-foreground uppercase">
                  {{ key }}
                </div>
                <div class="text-sm font-medium">
                  {{ value }}
                </div>
              </div>
            </template>
          </div>
          <div v-if="!submission.payload || Object.keys(submission.payload).length === 0" class="text-center py-8 text-muted-foreground">
            No form fields available
          </div>
        </CardContent>
      </Card>
    </div>
  </AppLayout>
</template>

