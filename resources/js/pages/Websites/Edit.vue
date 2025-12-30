<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue'
import { type BreadcrumbItem } from '@/types'
import { Head, useForm, Link, router } from '@inertiajs/vue3'
import {
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
} from '@/components/ui/card'
import { Button } from '@/components/ui/button'
import { Input } from '@/components/ui/input'
import { Label } from '@/components/ui/label'
import { Badge } from '@/components/ui/badge'
import {
  Globe,
  ArrowLeft,
  Copy,
  Check,
  ExternalLink,
  RefreshCw,
} from 'lucide-vue-next'
import { ref, watch } from 'vue'
import { usePage } from '@inertiajs/vue3'
import { useToast } from '@/composables/useToast'

interface Website {
  id: number
  name: string
  slug: string
  base_url: string
  status: string
  timezone: string
  last_webhook_at: string | null
  last_sync_at: string | null
  webhooks?: {
    woocommerce?: string
    fluentforms?: string
  }
}

interface Props {
  website: Website
}

const props = defineProps<Props>()

const breadcrumbs: BreadcrumbItem[] = [
  {
    title: 'Websites',
    href: '/websites',
  },
  {
    title: 'Edit Website',
    href: `/websites/${props.website.id}/edit`,
  },
]

const copiedId = ref<string | null>(null)
const page = usePage()
const toast = useToast()

// Watch for flash messages
watch(
  () => (page.props as any).flash?.success,
  (message) => {
    if (message) {
      toast.success(message)
    }
  },
  { immediate: true }
)

watch(
  () => (page.props as any).flash?.error,
  (message) => {
    if (message) {
      toast.error(message)
    }
  },
  { immediate: true }
)

const form = useForm({
  name: props.website.name,
  base_url: props.website.base_url,
  wc_consumer_key: '',
  wc_consumer_secret: '',
  ff_username: '',
  ff_app_password: '',
  status: props.website.status,
  timezone: props.website.timezone,
})

function getWebhookUrl(type: 'woocommerce' | 'fluentforms'): string {
  if (props.website.webhooks?.[type]) return props.website.webhooks[type]!
  const baseUrl = window.location.origin
  return `${baseUrl}/api/v1/webhooks/${type}/${props.website.slug}`
}

async function copyToClipboard(text: string, id: string) {
  try {
    await navigator.clipboard.writeText(text)
    copiedId.value = id
    setTimeout(() => {
      copiedId.value = null
    }, 2000)
  } catch (err) {
    console.error('Failed to copy:', err)
  }
}

function submit() {
  form.put(`/websites/${props.website.id}`, {
    preserveScroll: true,
    onSuccess: () => {
      // Show success toast - flash message will be handled by watcher
      // But also show immediately in case redirect happens too fast
      toast.success('Website updated successfully')
    },
    onError: (errors) => {
      console.error('Validation errors:', errors)
      if (errors && Object.keys(errors).length > 0) {
        toast.error('Please fix the errors and try again')
      }
    },
  })
}

function testWooCommerceApi() {
  router.post(
    `/websites/${props.website.id}/test-woocommerce`,
    {},
    {
      preserveScroll: true,
      preserveState: true,
    }
  )
}

function testFluentFormsApi() {
  router.post(
    `/websites/${props.website.id}/test-fluent-forms`,
    {},
    {
      preserveScroll: true,
      preserveState: true,
    }
  )
}

function formatDate(dateString: string | null): string {
  if (!dateString) return 'Never'
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
  <Head title="Edit Website" />

  <AppLayout :breadcrumbs="breadcrumbs">
    <div
      class="flex h-full flex-1 flex-col gap-6 overflow-x-auto rounded-xl p-4"
    >
      <!-- Header -->
      <div class="flex items-center gap-4">
        <Link href="/websites">
          <Button variant="outline" size="sm">
            <ArrowLeft class="mr-2 h-4 w-4" />
            Back
          </Button>
        </Link>
        <div>
          <h1 class="text-3xl font-bold">Edit Website</h1>
          <p class="text-muted-foreground mt-1">
            Update website settings and manage webhook connections
          </p>
        </div>
      </div>

      <div class="grid gap-6 md:grid-cols-3">
        <!-- Main Form -->
        <form @submit.prevent="submit" class="md:col-span-2 space-y-6">
          <!-- Basic Information -->
          <Card>
            <CardHeader>
              <CardTitle>Basic Information</CardTitle>
              <CardDescription>
                Update the basic details about your website
              </CardDescription>
            </CardHeader>
            <CardContent class="space-y-4">
              <div class="space-y-2">
                <Label for="name">Website Name *</Label>
                <Input
                  id="name"
                  v-model="form.name"
                  :class="{ 'border-destructive': form.errors.name }"
                />
                <p v-if="form.errors.name" class="text-sm text-destructive">
                  {{ form.errors.name }}
                </p>
              </div>

              <div class="space-y-2">
                <Label for="base_url">Base URL *</Label>
                <Input
                  id="base_url"
                  v-model="form.base_url"
                  type="url"
                  :class="{ 'border-destructive': form.errors.base_url }"
                />
                <p v-if="form.errors.base_url" class="text-sm text-destructive">
                  {{ form.errors.base_url }}
                </p>
              </div>

              <div class="space-y-2">
                <Label for="status">Status *</Label>
                <select
                  id="status"
                  v-model="form.status"
                  class="flex h-10 w-full rounded-md border border-input bg-background px-3 py-2 text-sm"
                >
                  <option value="active">Active</option>
                  <option value="paused">Paused</option>
                </select>
                <p class="text-xs text-muted-foreground">
                  Paused websites will not process webhooks
                </p>
              </div>

              <div class="space-y-2">
                <Label for="timezone">Timezone</Label>
                <Input id="timezone" v-model="form.timezone" />
              </div>
            </CardContent>
          </Card>

          <!-- Webhook URLs -->
          <Card>
            <CardHeader>
              <CardTitle>Webhook URLs</CardTitle>
              <CardDescription>
                Use these URLs to configure webhooks in your WordPress site
              </CardDescription>
            </CardHeader>
            <CardContent class="space-y-4">
              <div>
                <Label class="mb-2 block">WooCommerce Webhook URL</Label>
                <div class="flex items-center gap-2">
                  <Input
                    :value="getWebhookUrl('woocommerce')"
                    readonly
                    class="font-mono text-xs"
                  />
                  <Button
                    variant="outline"
                    size="sm"
                    @click="
                      copyToClipboard(
                        getWebhookUrl('woocommerce'),
                        'wc-url'
                      )
                    "
                  >
                    <Check
                      v-if="copiedId === 'wc-url'"
                      class="h-4 w-4 text-green-500"
                    />
                    <Copy v-else class="h-4 w-4" />
                  </Button>
                </div>
                <p class="mt-1 text-xs text-muted-foreground">
                  Configure this in WooCommerce → Settings → Advanced → Webhooks
                </p>
              </div>

              <div>
                <Label class="mb-2 block">Fluent Forms Webhook URL</Label>
                <div class="flex items-center gap-2">
                  <Input
                    :value="getWebhookUrl('fluentforms')"
                    readonly
                    class="font-mono text-xs"
                  />
                  <Button
                    variant="outline"
                    size="sm"
                    @click="
                      copyToClipboard(
                        getWebhookUrl('fluentforms'),
                        'ff-url'
                      )
                    "
                  >
                    <Check
                      v-if="copiedId === 'ff-url'"
                      class="h-4 w-4 text-green-500"
                    />
                    <Copy v-else class="h-4 w-4" />
                  </Button>
                </div>
                <p class="mt-1 text-xs text-muted-foreground">
                  Add this URL in your Fluent Forms webhook integration
                </p>
              </div>
            </CardContent>
          </Card>

          <!-- API Credentials -->
          <Card>
            <CardHeader>
              <CardTitle>API Credentials</CardTitle>
              <CardDescription>
                Update API credentials (leave blank to keep current values)
              </CardDescription>
            </CardHeader>
            <CardContent class="space-y-6">
              <!-- WooCommerce Credentials -->
              <div class="space-y-4">
                <div class="flex items-center justify-between">
                  <div>
                    <h4 class="font-semibold text-sm">WooCommerce</h4>
                    <p class="text-xs text-muted-foreground">Consumer Key & Secret</p>
                  </div>
                  <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    @click="testWooCommerceApi"
                  >
                    Test Connection
                  </Button>
                </div>
                <div class="space-y-2">
                  <Label for="wc_consumer_key">Consumer Key</Label>
                  <Input
                    id="wc_consumer_key"
                    v-model="form.wc_consumer_key"
                    type="password"
                    placeholder="Leave blank to keep current"
                    autocomplete="off"
                  />
                </div>
                <div class="space-y-2">
                  <Label for="wc_consumer_secret">Consumer Secret</Label>
                  <Input
                    id="wc_consumer_secret"
                    v-model="form.wc_consumer_secret"
                    type="password"
                    placeholder="Leave blank to keep current"
                    autocomplete="off"
                  />
                </div>
              </div>

              <div class="border-t"></div>

              <!-- Fluent Forms Credentials -->
              <div class="space-y-4">
                <div class="flex items-center justify-between">
                  <div>
                    <h4 class="font-semibold text-sm">Fluent Forms</h4>
                    <p class="text-xs text-muted-foreground">WordPress Application Password</p>
                  </div>
                  <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    @click="testFluentFormsApi"
                  >
                    Test Connection
                  </Button>
                </div>
                <div class="space-y-2">
                  <Label for="ff_username">Username</Label>
                  <Input
                    id="ff_username"
                    v-model="form.ff_username"
                    type="text"
                    placeholder="WordPress username"
                    autocomplete="off"
                  />
                  <p class="text-xs text-muted-foreground">
                    WordPress username for Application Password authentication
                  </p>
                </div>
                <div class="space-y-2">
                  <Label for="ff_app_password">Application Password</Label>
                  <Input
                    id="ff_app_password"
                    v-model="form.ff_app_password"
                    type="password"
                    placeholder="Application password"
                    autocomplete="off"
                  />
                  <p class="text-xs text-muted-foreground">
                    Generate from Users → Profile → Application Passwords in WordPress
                  </p>
                </div>
              </div>
            </CardContent>
          </Card>

          <!-- Connection Status -->
          <Card>
            <CardHeader>
              <CardTitle>Connection Status</CardTitle>
            </CardHeader>
            <CardContent class="space-y-3">
              <div class="flex items-center justify-between">
                <span class="text-sm text-muted-foreground">Last Webhook Received</span>
                <span class="text-sm font-medium">
                  {{ formatDate(website.last_webhook_at) }}
                </span>
              </div>
              <div class="flex items-center justify-between">
                <span class="text-sm text-muted-foreground">Last Sync</span>
                <span class="text-sm font-medium">
                  {{ formatDate(website.last_sync_at) }}
                </span>
              </div>
              <div class="flex items-center justify-between">
                <span class="text-sm text-muted-foreground">Website Slug</span>
                <Badge variant="outline" class="font-mono text-xs">
                  {{ website.slug }}
                </Badge>
              </div>
            </CardContent>
          </Card>

          <!-- Submit Button -->
          <div class="flex justify-end gap-2">
            <Link href="/websites">
              <Button variant="outline" type="button">Cancel</Button>
            </Link>
            <Button
              type="submit"
              @click.prevent="submit"
              :disabled="form.processing"
            >
              <RefreshCw
                v-if="form.processing"
                class="mr-2 h-4 w-4 animate-spin"
              />
              {{ form.processing ? 'Saving...' : 'Save Changes' }}
            </Button>
          </div>
        </form>

        <!-- Info Sidebar -->
        <div class="space-y-6">
          <Card>
            <CardHeader>
              <CardTitle>Quick Info</CardTitle>
            </CardHeader>
            <CardContent class="space-y-3 text-sm">
              <div>
                <p class="font-medium text-foreground">Website ID</p>
                <p class="text-muted-foreground">{{ website.id }}</p>
              </div>
              <div>
                <p class="font-medium text-foreground">Slug</p>
                <p class="text-muted-foreground font-mono text-xs">
                  {{ website.slug }}
                </p>
              </div>
              <div>
                <p class="font-medium text-foreground">Status</p>
                <Badge :class="website.status === 'active' ? 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200' : 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-200'">
                  {{ website.status }}
                </Badge>
              </div>
              <div>
                <p class="font-medium text-foreground">Website URL</p>
                <a
                  :href="website.base_url"
                  target="_blank"
                  rel="noopener noreferrer"
                  class="flex items-center gap-1 text-primary hover:underline"
                >
                  {{ website.base_url }}
                  <ExternalLink class="h-3 w-3" />
                </a>
              </div>
            </CardContent>
          </Card>
        </div>
      </div>
    </div>
  </AppLayout>
</template>

