<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue'
import { type BreadcrumbItem } from '@/types'
import { Head, router, usePage } from '@inertiajs/vue3'
import { Button } from '@/components/ui/button'
import { DownloadCloud, ChevronLeft, ChevronRight, Trash2 } from 'lucide-vue-next'
import { computed, ref, watch, onMounted, onUnmounted } from 'vue'
import { Link } from '@inertiajs/vue3'
import { useEchoNotifications } from '@/composables/useEchoNotifications'
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogFooter,
  DialogHeader,
  DialogTitle,
  DialogTrigger,
} from '@/components/ui/dialog'
import { Label } from '@/components/ui/label'
import { useToast } from '@/composables/useToast'
import axios from 'axios'
import { requestFluentFormsPage, runFluentFormsSync } from '@/lib/fluentFormsSync'

const props = defineProps<{
  forms: any
  websites: any[]
  filters: {
    website_id?: string
  }
}>()

const breadcrumbs: BreadcrumbItem[] = [
  {
    title: 'Submissions',
    href: '/submissions',
  },
]

const toast = useToast()
const isModalOpen = ref(false)
const selectedWebsiteId = ref<string | number>('')
const selectedFormId = ref<string | number>('')
const availableForms = ref<Array<{ id: number; title: string }>>([])
const isLoadingForms = ref(false)
const isSyncing = ref(false)
const loadedFormsWebsiteId = ref('')
const syncProgress = ref('')
const syncError = ref('')
let formsRequestId = 0
let formsController: AbortController | null = null
let syncController: AbortController | null = null
let disposed = false
let removeNavigationListener: (() => void) | null = null
const canSync = computed(() => !isSyncing.value && !isLoadingForms.value &&
  !!selectedWebsiteId.value && loadedFormsWebsiteId.value === String(selectedWebsiteId.value) &&
  Number.isSafeInteger(Number(selectedFormId.value)) && Number(selectedFormId.value) > 0 &&
  availableForms.value.some((form) => String(form.id) === String(selectedFormId.value)))
const deletingFormId = ref<string | null>(null)
const page = usePage()

// Real-time: auto-reload the submissions table when a new form submission arrives
const { onNotification, offNotification } = useEchoNotifications()
const onSubmissionNotification = (data: any) => {
  if (data?.type === 'form_submission') {
    router.reload({ only: ['forms'] })
    toast.success('New form submission received!')
  }
}
onMounted(() => {
  removeNavigationListener = router.on('before', ({ detail: { visit } }) => {
    if (!visit.async && visit.url.pathname !== '/submissions') {
      syncController?.abort()
      invalidateFormsLoad()
    }
  })
  const user = (page.props as any).auth?.user
  if (!user) return
  onNotification('submissions-index', onSubmissionNotification, user.id)
})
onUnmounted(() => {
  disposed = true
  invalidateFormsLoad()
  syncController?.abort()
  removeNavigationListener?.()
  offNotification('submissions-index')
})

function invalidateFormsLoad() {
  formsRequestId++
  formsController?.abort()
  formsController = null
  availableForms.value = []
  selectedFormId.value = ''
  loadedFormsWebsiteId.value = ''
  isLoadingForms.value = false
}

watch(selectedWebsiteId, (websiteId) => { void loadFormsForWebsite(String(websiteId)) }, { flush: 'sync' })
watch(isModalOpen, (open) => {
  if (!open) {
    syncController?.abort()
    invalidateFormsLoad()
    selectedWebsiteId.value = ''
  }
}, { flush: 'sync' })

async function loadFormsForWebsite(websiteId: string) {
  invalidateFormsLoad()
  syncProgress.value = ''
  syncError.value = ''
  if (!websiteId || !isModalOpen.value || disposed) return

  const requestId = formsRequestId
  const controller = new AbortController()
  formsController = controller
  const isCurrent = () => !disposed && requestId === formsRequestId && !controller.signal.aborted &&
    isModalOpen.value && String(selectedWebsiteId.value) === websiteId
  isLoadingForms.value = true
  try {
    const response = await axios.get(`/websites/${websiteId}/forms`, { signal: controller.signal, timeout: 25_000 })
    if (!isCurrent()) return
    availableForms.value = Array.isArray(response.data.forms) ? response.data.forms : []
    loadedFormsWebsiteId.value = websiteId
  } catch (error: any) {
    if (!isCurrent()) return
    syncError.value = error.response?.data?.error || 'Failed to load forms. Select the website again to retry.'
    toast.error(syncError.value)
  } finally {
    if (isCurrent()) {
      isLoadingForms.value = false
      formsController = null
    }
  }
}

function openModal() {
  invalidateFormsLoad()
  selectedWebsiteId.value = ''
  syncProgress.value = ''
  syncError.value = ''
  isModalOpen.value = true
}

async function handleSync() {
  if (isSyncing.value || isLoadingForms.value) return
  if (!canSync.value) {
    toast.error('Please select a form from the selected website.')
    return
  }

  const websiteId = selectedWebsiteId.value
  const formId = Number(selectedFormId.value)
  const controller = new AbortController()
  syncController = controller
  isSyncing.value = true
  syncError.value = ''
  syncProgress.value = 'Syncing submissions… Completed pages are saved so you can stop and resume.'
  try {
    const result = await runFluentFormsSync(
      () => requestFluentFormsPage(`/websites/${websiteId}/sync-fluent-form`, { form_id: formId }, controller.signal),
      (progress) => {
        syncProgress.value = `${progress.pages} page(s) saved · ${progress.synced} new, ${progress.updated} updated. You can stop and resume.`
      },
      { signal: controller.signal },
    )
    if (disposed || !isModalOpen.value || controller.signal.aborted) return
    syncController = null
    isSyncing.value = false
    isModalOpen.value = false
    toast.success(`Sync complete — ${result.synced} new submission(s), ${result.updated} updated.`)
    router.reload({ only: ['forms'] })
  } catch (error: any) {
    if (disposed || !isModalOpen.value) return
    if (controller.signal.aborted) {
      syncProgress.value = 'Sync stopped. Completed pages are saved; choose Sync to resume.'
    } else {
      syncError.value = error?.message || 'Sync failed. Completed pages are saved; try again to resume.'
      toast.error(syncError.value)
    }
  } finally {
    if (syncController === controller) {
      isSyncing.value = false
      syncController = null
    }
  }
}

function stopSync() {
  syncController?.abort()
}

function updateFilter(key: string, value: string) {
  const newFilters = { ...props.filters, [key]: value }
  router.get(
    '/submissions',
    newFilters,
    { preserveState: true, replace: true }
  )
}

function goToPage(url: string | null) {
  if (!url) return
  router.visit(url, { preserveState: true, preserveScroll: true })
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

function deleteForm(form: { website_id: number; form_id: number; entry_count: number; form_name?: string; website: { name: string } }, event: Event) {
  event.stopPropagation()
  const { website_id: websiteId, form_id: formId } = form
  const formKey = `${websiteId}-${formId}`
  if (!confirm(`Delete all ${form.entry_count} submissions from ${form.form_name || `Form #${formId}`} on ${form.website.name}? This action cannot be undone.`)) {
    return
  }

  deletingFormId.value = formKey

  router.delete(`/submissions/forms/${websiteId}/${formId}`, {
    preserveScroll: true,
    onSuccess: () => {
      deletingFormId.value = null
    },
    onError: () => {
      toast.error('Failed to delete submissions')
      deletingFormId.value = null
    },
  })
}
</script>

<template>
  <Head title="Form Submissions" />

  <AppLayout :breadcrumbs="breadcrumbs">
    <div
      class="flex h-full flex-1 flex-col gap-4 overflow-x-auto rounded-xl p-4"
    >
      <!-- Header with Sync Button -->
      <div class="flex items-center justify-between">
        <h1 class="text-2xl font-bold">Form Submissions</h1>
        <Dialog :open="isModalOpen" @update:open="isModalOpen = $event">
          <DialogTrigger as-child>
            <Button @click="openModal">
              <DownloadCloud class="mr-2 h-4 w-4" />
              Sync Form
            </Button>
          </DialogTrigger>
          <DialogContent>
            <DialogHeader>
              <DialogTitle>Sync Fluent Forms Submissions</DialogTitle>
              <DialogDescription>
                Select a website and form to sync submissions.
              </DialogDescription>
            </DialogHeader>
            <div class="space-y-4 py-4">
              <div class="space-y-2">
                <Label for="sync-website">Website *</Label>
                <select
                  id="sync-website"
                  v-model="selectedWebsiteId"
                  class="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
                  :disabled="isSyncing"
                >
                  <option value="">Select a website</option>
                  <option
                    v-for="website in websites"
                    :key="website.id"
                    :value="website.id"
                  >
                    {{ website.name }}
                  </option>
                </select>
              </div>

              <div class="space-y-2">
                <Label for="sync-form">Form *</Label>
                <select
                  id="sync-form"
                  v-model="selectedFormId"
                  class="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
                  :disabled="!selectedWebsiteId || isLoadingForms || isSyncing"
                >
                  <option value="">Select a form</option>
                  <option
                    v-for="form in availableForms"
                    :key="form.id"
                    :value="form.id"
                  >
                    {{ form.title }} (ID: {{ form.id }})
                  </option>
                </select>
                <p
                  v-if="isLoadingForms"
                  class="text-xs text-muted-foreground"
                >
                  Loading forms...
                </p>
              </div>
              <p v-if="syncProgress" class="text-sm text-muted-foreground" role="status">{{ syncProgress }}</p>
              <p v-if="syncError" class="text-sm text-destructive" role="alert">{{ syncError }}</p>
            </div>
            <DialogFooter>
              <Button
                variant="outline"
                @click="isSyncing ? stopSync() : isModalOpen = false"
              >
                {{ isSyncing ? 'Stop sync' : 'Cancel' }}
              </Button>
              <Button @click="handleSync" :disabled="!canSync">
                <DownloadCloud
                  v-if="isSyncing"
                  class="mr-2 h-4 w-4 animate-spin"
                />
                <DownloadCloud v-else class="mr-2 h-4 w-4" />
                {{ isSyncing ? 'Syncing...' : 'Sync' }}
              </Button>
            </DialogFooter>
          </DialogContent>
        </Dialog>
      </div>

      <!-- Filters -->
      <div
        class="grid grid-cols-1 gap-4 rounded-xl border border-sidebar-border/70 p-4 md:grid-cols-3 dark:border-sidebar-border"
      >
        <select
          class="rounded-md border bg-background px-3 py-2 text-sm"
          :value="props.filters.website_id || ''"
          @change="updateFilter('website_id', ($event.target as HTMLSelectElement).value)"
        >
          <option value="">All Websites</option>
          <option v-for="w in websites" :key="w.id" :value="w.id">
            {{ w.name }}
          </option>
        </select>
      </div>

      <!-- FORMS TABLE -->
      <div
        class="relative flex-1 overflow-hidden rounded-lg border bg-card shadow-sm"
      >
        <div class="overflow-x-auto">
          <table class="w-full border-collapse text-sm">
            <thead>
              <tr class="border-b bg-muted/50">
                <th class="px-6 py-4 text-left font-semibold text-foreground">
                  Website
                </th>
                <th class="px-6 py-4 text-left font-semibold text-foreground">
                  Form Name
                </th>
                <th class="px-6 py-4 text-left font-semibold text-foreground">
                  Form ID
                </th>
                <th class="px-6 py-4 text-left font-semibold text-foreground">
                  Latest Submission
                </th>
                <th class="px-6 py-4 text-left font-semibold text-foreground">
                  Entry Count
                </th>
                <th class="px-6 py-4 text-left font-semibold text-foreground">
                  Actions
                </th>
              </tr>
            </thead>

            <tbody>
              <tr
                v-for="(form, index) in forms.data"
                :key="`${form.website_id}-${form.form_id}`"
                class="border-b transition-colors cursor-pointer"
                :class="[
                  Number(index) % 2 === 0 ? 'bg-background' : 'bg-muted/20',
                  'hover:bg-muted/50'
                ]"
                @click="router.visit(`/submissions/forms/${form.website_id}/${form.form_id}`)"
              >
                <td class="px-6 py-4">
                  <div class="font-medium text-foreground">
                    {{ form.website.name }}
                  </div>
                </td>

                <td class="px-6 py-4">
                  <Link
                    :href="`/submissions/forms/${form.website_id}/${form.form_id}`"
                    class="font-semibold text-primary hover:underline"
                    @click.stop
                  >
                    {{ form.form_name || `Form #${form.form_id}` }}
                  </Link>
                </td>

                <td class="px-6 py-4">
                  <span class="text-muted-foreground">
                    {{ form.form_id }}
                  </span>
                </td>

                <td class="px-6 py-4">
                  <div class="text-muted-foreground whitespace-nowrap">
                    {{ formatDate(form.latest_submission_date) }}
                  </div>
                </td>
                <td class="px-6 py-4">
                  <span class="font-semibold text-foreground">
                    {{ form.entry_count }}
                  </span>
                </td>
                <td class="px-6 py-4">
                  <Button
                    variant="destructive"
                    size="sm"
                    :disabled="deletingFormId === `${form.website_id}-${form.form_id}`"
                    @click.stop="deleteForm(form, $event)"
                  >
                    <Trash2 v-if="deletingFormId !== `${form.website_id}-${form.form_id}`" class="h-4 w-4" />
                    <span v-else class="h-4 w-4 animate-spin">⏳</span>
                  </Button>
                </td>
              </tr>

              <tr v-if="forms.data.length === 0">
                <td
                  colspan="6"
                  class="px-6 py-12 text-center text-muted-foreground"
                >
                  <div class="flex flex-col items-center justify-center gap-2">
                    <p class="text-base font-medium">No forms found</p>
                    <p class="text-sm">Sync a form to get started</p>
                  </div>
                </td>
              </tr>
            </tbody>
          </table>
        </div>

        <!-- Pagination -->
        <div
          v-if="forms.meta && forms.meta.total > 0"
          class="flex flex-col gap-4 border-t bg-muted/30 px-6 py-4 sm:flex-row sm:items-center sm:justify-between"
        >
          <!-- Pagination Info -->
          <div class="text-sm text-muted-foreground">
            Showing
            <span class="font-semibold text-foreground">{{ forms.meta?.from || 0 }}</span>
            to
            <span class="font-semibold text-foreground">{{ forms.meta?.to || 0 }}</span>
            of
            <span class="font-semibold text-foreground">{{ forms.meta?.total || 0 }}</span>
            results
            <span v-if="forms.meta?.last_page > 1" class="ml-2">
              (Page {{ forms.meta?.current_page }} of {{ forms.meta?.last_page }})
            </span>
          </div>

          <!-- Pagination Controls -->
          <div
            v-if="forms.links && forms.links.length > 1"
            class="flex items-center gap-2"
          >
            <!-- Previous Button -->
            <Button
              variant="outline"
              size="sm"
              :disabled="!forms.links[0]?.url || forms.meta?.current_page === 1"
              @click="goToPage(forms.links[0]?.url)"
              class="gap-1"
            >
              <ChevronLeft class="h-4 w-4" />
              <span class="hidden sm:inline">Previous</span>
            </Button>

            <!-- Page Numbers -->
            <div class="flex items-center gap-1">
              <template
                v-for="(link, index) in forms.links"
              >
                <Button
                  v-if="link.label && Number(index) > 0 && Number(index) < forms.links.length - 1"
                  :key="`btn-${index}`"
                  variant="outline"
                  size="sm"
                  :class="{
                    'bg-primary text-primary-foreground hover:bg-primary/90': link.active,
                    'pointer-events-none opacity-50': !link.url,
                    'min-w-[2.5rem]': true,
                  }"
                  @click="goToPage(link.url)"
                >
                  {{ link.label }}
                </Button>
                <span
                  v-else-if="link.label === '...'"
                  :key="`dots-${index}`"
                  class="px-2 py-1 text-muted-foreground"
                >
                  ...
                </span>
              </template>
            </div>

            <!-- Next Button -->
            <Button
              variant="outline"
              size="sm"
              :disabled="!forms.links[forms.links.length - 1]?.url || forms.meta?.current_page === forms.meta?.last_page"
              @click="goToPage(forms.links[forms.links.length - 1]?.url)"
              class="gap-1"
            >
              <span class="hidden sm:inline">Next</span>
              <ChevronRight class="h-4 w-4" />
            </Button>
          </div>
        </div>
      </div>
    </div>
  </AppLayout>
</template>
