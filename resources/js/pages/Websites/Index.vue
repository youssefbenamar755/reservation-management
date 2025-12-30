<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue'
import { type BreadcrumbItem } from '@/types'
import { Head, Link, router } from '@inertiajs/vue3'
import {
  Card,
  CardContent,
  CardDescription,
  CardHeader,
  CardTitle,
} from '@/components/ui/card'
import { Badge } from '@/components/ui/badge'
import { Button } from '@/components/ui/button'
import {
  Globe,
  Plus,
  Edit,
  Trash2,
  Copy,
  Check,
  ExternalLink,
  AlertCircle,
  CheckCircle2,
  Clock,
} from 'lucide-vue-next'
import { ref } from 'vue'

interface Website {
  id: number
  name: string
  slug: string
  base_url: string
  timezone?: string
  status: string
  last_webhook_at: string | null
  last_sync_at: string | null
  created_at: string
  webhooks?: {
    woocommerce?: string
    fluentforms?: string
  }
}

interface Props {
  websites: Website[]
}

const props = defineProps<Props>()

const breadcrumbs: BreadcrumbItem[] = [
  {
    title: 'Websites',
    href: '/websites',
  },
]

const copiedId = ref<number | null>(null)

function getWebhookUrl(
  website: Website,
  type: 'woocommerce' | 'fluentforms'
): string {
  if (website.webhooks?.[type]) return website.webhooks[type]!
  const baseUrl = window.location.origin
  return `${baseUrl}/api/v1/webhooks/${type}/${website.slug}`
}

async function copyToClipboard(text: string, websiteId: number) {
  try {
    await navigator.clipboard.writeText(text)
    copiedId.value = websiteId
    setTimeout(() => {
      copiedId.value = null
    }, 2000)
  } catch (err) {
    console.error('Failed to copy:', err)
  }
}

function formatDate(dateString: string | null): string {
  if (!dateString) return 'Never'
  const date = new Date(dateString)
  return new Intl.DateTimeFormat('en-US', {
    month: 'short',
    day: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
  }).format(date)
}

function getStatusColor(status: string): string {
  return status === 'active'
    ? 'bg-green-100 text-green-800 dark:bg-green-900 dark:text-green-200'
    : 'bg-yellow-100 text-yellow-800 dark:bg-yellow-900 dark:text-yellow-200'
}

function deleteWebsite(websiteId: number) {
  if (confirm('Are you sure you want to delete this website? This action cannot be undone.')) {
    router.delete(`/websites/${websiteId}`)
  }
}

function getConnectionStatus(website: Website): {
  status: 'connected' | 'pending' | 'disconnected'
  icon: any
  color: string
  text: string
} {
  if (!website.last_webhook_at) {
    return {
      status: 'disconnected',
      icon: AlertCircle,
      color: 'text-red-500',
      text: 'Not connected',
    }
  }

  const lastWebhook = new Date(website.last_webhook_at)
  const hoursSinceLastWebhook =
    (Date.now() - lastWebhook.getTime()) / (1000 * 60 * 60)

  if (hoursSinceLastWebhook < 24) {
    return {
      status: 'connected',
      icon: CheckCircle2,
      color: 'text-green-500',
      text: 'Connected',
    }
  }

  return {
    status: 'pending',
    icon: Clock,
    color: 'text-yellow-500',
    text: 'No recent activity',
  }
}
</script>

<template>
  <Head title="Websites" />

  <AppLayout :breadcrumbs="breadcrumbs">
    <div
      class="flex h-full flex-1 flex-col gap-6 overflow-x-auto rounded-xl p-4"
    >
      <!-- Header -->
      <div class="flex items-center justify-between">
        <div>
          <h1 class="text-3xl font-bold">Websites</h1>
          <p class="text-muted-foreground mt-1">
            Manage your connected WordPress websites
          </p>
        </div>
        <Link href="/websites/create">
          <Button>
            <Plus class="mr-2 h-4 w-4" />
            Add Website
          </Button>
        </Link>
      </div>

      <!-- Websites Grid -->
      <div v-if="websites.length > 0" class="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
        <Card
          v-for="website in websites"
          :key="website.id"
          class="flex flex-col"
        >
          <CardHeader>
            <div class="flex items-start justify-between">
              <div class="flex items-center gap-2">
                <Globe class="h-5 w-5 text-muted-foreground" />
                <CardTitle class="text-lg">{{ website.name }}</CardTitle>
              </div>
              <Badge :class="getStatusColor(website.status)">
                {{ website.status }}
              </Badge>
            </div>
            <CardDescription class="mt-2">
              <a
                :href="website.base_url"
                target="_blank"
                rel="noopener noreferrer"
                class="flex items-center gap-1 hover:underline"
              >
                {{ website.base_url }}
                <ExternalLink class="h-3 w-3" />
              </a>
            </CardDescription>
          </CardHeader>

          <CardContent class="flex-1 space-y-4">
            <!-- Connection Status -->
            <div class="flex items-center justify-between rounded-lg border p-3">
              <div class="flex items-center gap-2">
                <component
                  :is="getConnectionStatus(website).icon"
                  :class="getConnectionStatus(website).color"
                  class="h-4 w-4"
                />
                <span class="text-sm font-medium">
                  {{ getConnectionStatus(website).text }}
                </span>
              </div>
              <span class="text-xs text-muted-foreground">
                Last webhook: {{ formatDate(website.last_webhook_at) }}
              </span>
            </div>

            <!-- Webhook URLs -->
            <div class="space-y-3">
              <div>
                <label class="mb-1 block text-xs font-medium text-muted-foreground">
                  WooCommerce Webhook URL
                </label>
                <div class="flex items-center gap-2">
                  <input
                    :value="getWebhookUrl(website, 'woocommerce')"
                    readonly
                    class="flex-1 rounded-md border bg-muted px-3 py-2 text-xs font-mono"
                  />
                  <Button
                    variant="outline"
                    size="sm"
                    @click="
                      copyToClipboard(
                        getWebhookUrl(website, 'woocommerce'),
                        website.id
                      )
                    "
                  >
                    <Check
                      v-if="copiedId === website.id"
                      class="h-4 w-4 text-green-500"
                    />
                    <Copy v-else class="h-4 w-4" />
                  </Button>
                </div>
              </div>

              <div>
                <label class="mb-1 block text-xs font-medium text-muted-foreground">
                  Fluent Forms Webhook URL
                </label>
                <div class="flex items-center gap-2">
                  <input
                    :value="getWebhookUrl(website, 'fluentforms')"
                    readonly
                    class="flex-1 rounded-md border bg-muted px-3 py-2 text-xs font-mono"
                  />
                  <Button
                    variant="outline"
                    size="sm"
                    @click="
                      copyToClipboard(
                        getWebhookUrl(website, 'fluentforms'),
                        website.id
                      )
                    "
                  >
                    <Check
                      v-if="copiedId === website.id"
                      class="h-4 w-4 text-green-500"
                    />
                    <Copy v-else class="h-4 w-4" />
                  </Button>
                </div>
              </div>
            </div>

            <!-- Actions -->
            <div class="flex gap-2 pt-2">
              <Link :href="`/websites/${website.id}/edit`" class="flex-1">
                <Button variant="outline" class="w-full">
                  <Edit class="mr-2 h-4 w-4" />
                  Edit
                </Button>
              </Link>
              <Button
                variant="outline"
                class="text-destructive hover:text-destructive"
                @click="deleteWebsite(website.id)"
              >
                <Trash2 class="h-4 w-4" />
              </Button>
            </div>
          </CardContent>
        </Card>
      </div>

      <!-- Empty State -->
      <Card v-else>
        <CardContent class="flex flex-col items-center justify-center py-12">
          <Globe class="mb-4 h-12 w-12 text-muted-foreground" />
          <CardTitle class="mb-2">No websites yet</CardTitle>
          <CardDescription class="mb-4 text-center">
            Get started by adding your first WordPress website
          </CardDescription>
          <Link href="/websites/create">
            <Button>
              <Plus class="mr-2 h-4 w-4" />
              Add Your First Website
            </Button>
          </Link>
        </CardContent>
      </Card>
    </div>
  </AppLayout>
</template>
