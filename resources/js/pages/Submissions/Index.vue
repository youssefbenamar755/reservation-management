<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue'
import { type BreadcrumbItem } from '@/types'
import { Head, router, usePage } from '@inertiajs/vue3'
import { Button } from '@/components/ui/button'
import { DownloadCloud, ChevronLeft, ChevronRight, Trash2 } from 'lucide-vue-next'
import { computed, ref, onMounted, onUnmounted } from 'vue'
import { Link } from '@inertiajs/vue3'
import Echo from '@/lib/echo'
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
const selectedWebsiteId = ref<string>('')
const selectedFormId = ref<string>('')
const availableForms = ref<Array<{ id: number; title: string }>>([])
const isLoadingForms = ref(false)
const isSyncing = ref(false)
const deletingFormId = ref<string | null>(null)
const page = usePage()

// Real-time: auto-reload the submissions table when a new form submission arrives
let echoChannel: any = null
onMounted(() => {
  const user = (page.props as any).auth?.user
  if (!user) return
  try {
    echoChannel = Echo.private(`App.Models.User.${user.id}`)
    echoChannel.listen('.notification', (data: any) => {
      if (data?.type === 'form_submission') {
        router.reload({ only: ['forms'] })
        toast.success('New form submission received!')
      }
    })
  } catch (e) {
    console.error('Echo setup failed:', e)
  }
})
onUnmounted(() => {
  const user = (page.props as any).auth?.user
  if (user && echoChannel) {
    try { Echo.leave(`App.Models.User.${user.id}`) } catch {}
  }
})

// Watch for website selection to load forms
async function loadFormsForWebsite(websiteId: string) {
  if (!websiteId) {
    availableForms.value = []
    selectedFormId.value = ''
    return
  }

  isLoadingForms.value = true
  try {
    const response = await axios.get(`/websites/${websiteId}/forms`)
    availableForms.value = response.data.forms || []
  } catch (error: any) {
    toast.error(error.response?.data?.error || 'Failed to load forms')
    availableForms.value = []
  } finally {
    isLoadingForms.value = false
  }
}

function openModal() {
  selectedWebsiteId.value = ''
  selectedFormId.value = ''
  availableForms.value = []
  isModalOpen.value = true
}

function handleSync() {
  if (!selectedWebsiteId.value || !selectedFormId.value) {
    toast.error('Please select both website and form')
    return
  }

  isSyncing.value = true

  router.post(
    `/websites/${selectedWebsiteId.value}/sync-fluent-form`,
    { form_id: parseInt(selectedFormId.value) },
    {
      preserveScroll: true,
      preserveState: true,
      onSuccess: () => {
        isModalOpen.value = false
        isSyncing.value = false
        selectedWebsiteId.value = ''
        selectedFormId.value = ''
        availableForms.value = []
      },
      onError: () => {
        isSyncing.value = false
      },
    }
  )
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

function deleteForm(websiteId: number, formId: number, event: Event) {
  event.stopPropagation()
  
  const formKey = `${websiteId}-${formId}`
  if (!confirm(`Are you sure you want to delete all ${formId} submissions for this form? This action cannot be undone.`)) {
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
                  @change="loadFormsForWebsite(selectedWebsiteId)"
                  class="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
                  :disabled="isLoadingForms || isSyncing"
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
            </div>
            <DialogFooter>
              <Button
                variant="outline"
                @click="isModalOpen = false"
                :disabled="isSyncing"
              >
                Cancel
              </Button>
              <Button @click="handleSync" :disabled="!selectedWebsiteId || !selectedFormId || isSyncing">
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
                    @click.stop="deleteForm(form.website_id, form.form_id, $event)"
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
