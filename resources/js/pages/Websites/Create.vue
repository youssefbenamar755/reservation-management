<script setup lang="ts">
import AppLayout from '@/layouts/AppLayout.vue'
import { type BreadcrumbItem } from '@/types'
import { Head, useForm } from '@inertiajs/vue3'
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
import { Globe, ArrowLeft, Info } from 'lucide-vue-next'
import { Link } from '@inertiajs/vue3'

const breadcrumbs: BreadcrumbItem[] = [
  {
    title: 'Websites',
    href: '/websites',
  },
  {
    title: 'Add Website',
    href: '/websites/create',
  },
]

const form = useForm({
  name: '',
  base_url: '',
  wc_consumer_key: '',
  wc_consumer_secret: '',
  ff_username: '',
  ff_app_password: '',
  timezone: 'UTC',
})

function submit() {
  form.post('/websites', {
    onSuccess: () => {
      // Success handled by redirect
    },
  })
}
</script>

<template>
  <Head title="Add Website" />

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
          <h1 class="text-3xl font-bold">Add Website</h1>
          <p class="text-muted-foreground mt-1">
            Connect a new WordPress website to start receiving data
          </p>
        </div>
      </div>

      <div class="grid gap-6 md:grid-cols-3">
        <!-- Main Form -->
        <div class="md:col-span-2 space-y-6">
          <!-- Basic Information -->
          <Card>
            <CardHeader>
              <CardTitle>Basic Information</CardTitle>
              <CardDescription>
                Enter the basic details about your WordPress website
              </CardDescription>
            </CardHeader>
            <CardContent class="space-y-4">
              <div class="space-y-2">
                <Label for="name">Website Name *</Label>
                <Input
                  id="name"
                  v-model="form.name"
                  placeholder="My WordPress Site"
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
                  placeholder="https://example.com"
                  :class="{ 'border-destructive': form.errors.base_url }"
                />
                <p v-if="form.errors.base_url" class="text-sm text-destructive">
                  {{ form.errors.base_url }}
                </p>
                <p class="text-xs text-muted-foreground">
                  The full URL of your WordPress website (without trailing slash)
                </p>
              </div>

              <div class="space-y-2">
                <Label for="timezone">Timezone</Label>
                <Input
                  id="timezone"
                  v-model="form.timezone"
                  placeholder="UTC"
                />
                <p class="text-xs text-muted-foreground">
                  Default timezone for this website (default: UTC)
                </p>
              </div>
            </CardContent>
          </Card>

          <!-- WooCommerce Credentials -->
          <Card>
            <CardHeader>
              <CardTitle>WooCommerce API Credentials</CardTitle>
              <CardDescription>
                Optional: For future API integrations. Webhooks work without these.
              </CardDescription>
            </CardHeader>
            <CardContent class="space-y-4">
              <div class="space-y-2">
                <Label for="wc_consumer_key">Consumer Key</Label>
                <Input
                  id="wc_consumer_key"
                  v-model="form.wc_consumer_key"
                  type="password"
                  placeholder="ck_..."
                  autocomplete="off"
                />
                <p class="text-xs text-muted-foreground">
                  WooCommerce REST API Consumer Key (optional)
                </p>
              </div>

              <div class="space-y-2">
                <Label for="wc_consumer_secret">Consumer Secret</Label>
                <Input
                  id="wc_consumer_secret"
                  v-model="form.wc_consumer_secret"
                  type="password"
                  placeholder="cs_..."
                  autocomplete="off"
                />
                <p class="text-xs text-muted-foreground">
                  WooCommerce REST API Consumer Secret (optional)
                </p>
              </div>
            </CardContent>
          </Card>

          <!-- Fluent Forms Credentials -->
          <Card>
            <CardHeader>
              <CardTitle>Fluent Forms Credentials</CardTitle>
              <CardDescription>
                Optional: WordPress Application Password for REST API access. Webhooks work without this.
              </CardDescription>
            </CardHeader>
            <CardContent class="space-y-4">
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
            </CardContent>
          </Card>

          <!-- Submit Button -->
          <div class="flex justify-end gap-2">
            <Link href="/websites">
              <Button variant="outline">Cancel</Button>
            </Link>
            <Button @click="submit" :disabled="form.processing">
              {{ form.processing ? 'Creating...' : 'Create Website' }}
            </Button>
          </div>
        </div>

        <!-- Instructions Sidebar -->
        <div class="space-y-6">
          <Card>
            <CardHeader>
              <CardTitle class="flex items-center gap-2">
                <Info class="h-5 w-5" />
                Setup Instructions
              </CardTitle>
            </CardHeader>
            <CardContent class="space-y-4 text-sm">
              <div>
                <h4 class="font-semibold mb-2">After creating the website:</h4>
                <ol class="list-decimal list-inside space-y-1 text-muted-foreground">
                  <li>Copy the WooCommerce webhook URL</li>
                  <li>Go to WooCommerce → Settings → Advanced → Webhooks</li>
                  <li>Create a new webhook with the copied URL</li>
                  <li>Set topic to "Order created" or "Order updated"</li>
                  <li>Save and test the connection</li>
                </ol>
              </div>

              <div class="pt-4 border-t">
                <h4 class="font-semibold mb-2">For Fluent Forms:</h4>
                <ol class="list-decimal list-inside space-y-1 text-muted-foreground">
                  <li>Copy the Fluent Forms webhook URL</li>
                  <li>Go to your form settings</li>
                  <li>Add a webhook integration</li>
                  <li>Paste the URL with the token parameter</li>
                  <li>Test by submitting a form</li>
                </ol>
              </div>
            </CardContent>
          </Card>

          <Card>
            <CardHeader>
              <CardTitle>What Gets Connected?</CardTitle>
            </CardHeader>
            <CardContent class="space-y-3 text-sm text-muted-foreground">
              <div class="flex items-start gap-2">
                <Globe class="h-4 w-4 mt-0.5 flex-shrink-0" />
                <div>
                  <p class="font-medium text-foreground">WooCommerce Orders</p>
                  <p>Real-time order notifications via webhooks</p>
                </div>
              </div>
              <div class="flex items-start gap-2">
                <Globe class="h-4 w-4 mt-0.5 flex-shrink-0" />
                <div>
                  <p class="font-medium text-foreground">Fluent Forms</p>
                  <p>Form submission data via webhooks</p>
                </div>
              </div>
            </CardContent>
          </Card>
        </div>
      </div>
    </div>
  </AppLayout>
</template>
