<script setup lang="ts">
import { Button } from '@/components/ui/button'
import { Badge } from '@/components/ui/badge'
import {
  DropdownMenu, DropdownMenuContent, DropdownMenuTrigger,
  DropdownMenuItem, DropdownMenuSeparator,
} from '@/components/ui/dropdown-menu'
import OrderNotificationSound from '@/components/OrderNotificationSound.vue'
import { Bell } from 'lucide-vue-next'
import { computed, ref, onMounted, onUnmounted, useId } from 'vue'
import { router, usePage } from '@inertiajs/vue3'
import axios from 'axios'
import { useEchoNotifications } from '@/composables/useEchoNotifications'
import { createAutoRefresh } from '@/lib/liveOrders'
import { createNotificationFeed, type AppNotification } from '@/lib/notifications'

const page = usePage()
const initialCount = Number((page.props.notifications as { unread_count?: number } | undefined)?.unread_count) || 0
const notifications = ref<AppNotification[]>([])
const unreadCount = ref(initialCount)
const loading = ref(false)
const marking = ref(false)
const errorMessage = ref('')
const refreshFailed = ref(false)
const displayError = computed(() => errorMessage.value || (refreshFailed.value ? 'Could not update notifications. Open again to retry.' : ''))
const feed = createNotificationFeed(initialCount, (state) => {
  notifications.value = state.notifications
  unreadCount.value = state.unreadCount
})
const consumerKey = `notification-bell-${useId()}`
const { onNotification, offNotification } = useEchoNotifications()
let mounted = false
let userId: number | null = null
let mutationController: AbortController | null = null
const cleanups: Array<() => void> = []
const currentUser = () => mounted && page.props.auth?.user?.id === userId
const available = () => currentUser() && navigator.onLine && document.visibilityState === 'visible' && !marking.value

const refresh = createAutoRefresh({
  isAvailable: available,
  onState: (state) => {
    loading.value = state.refreshing
    refreshFailed.value = state.hasError
  },
  refresh: ({ isCurrent, complete }) => {
    const controller = new AbortController()
    const revision = feed.revision
    void axios.get('/notifications', {
      headers: { Accept: 'application/json', 'Cache-Control': 'no-cache' },
      signal: controller.signal,
      timeout: 20_000,
    }).then(({ data }) => {
      if (!isCurrent() || !currentUser()) return
      feed.applySnapshot(data, revision)
      complete('success')
    }).catch(() => {
      if (isCurrent()) complete('error')
    })
    return () => controller.abort()
  },
})

const requestRefresh = () => refresh.request()
function onOpenChange(open: boolean) {
  if (open) requestRefresh()
}

function formatTimeAgo(value: string): string {
  const date = new Date(value)
  if (!Number.isFinite(date.getTime())) return ''
  const seconds = Math.max(0, Math.floor((Date.now() - date.getTime()) / 1000))
  if (seconds < 60) return 'just now'
  if (seconds < 3600) return `${Math.floor(seconds / 60)}m ago`
  if (seconds < 86400) return `${Math.floor(seconds / 3600)}h ago`
  if (seconds < 604800) return `${Math.floor(seconds / 86400)}d ago`
  return date.toLocaleDateString()
}

function notificationUrl(notification: AppNotification): string | null {
  const { type, order_id, submission_id } = notification.data
  if (type === 'order' && Number.isInteger(order_id) && order_id! > 0) return `/orders/${order_id}`
  if (type === 'form_submission' && Number.isInteger(submission_id) && submission_id! > 0) {
    return `/submissions/entries/${submission_id}`
  }
  return null
}

async function markRead(notification: AppNotification | null) {
  if (marking.value || !currentUser()) return
  marking.value = true
  errorMessage.value = ''
  refresh.suspend()
  const checkpoint = feed.beginRead()
  const controller = new AbortController()
  mutationController = controller
  try {
    // Axios reads Laravel's current XSRF cookie; a cached meta token can expire.
    const { data } = await axios.post(
      notification ? `/notifications/${encodeURIComponent(notification.id)}/read` : '/notifications/read-all',
      {},
      { headers: { Accept: 'application/json' }, signal: controller.signal, timeout: 20_000 },
    )
    if (!currentUser() || controller.signal.aborted) return
    if (data.success !== true) throw new Error('Read was not confirmed')
    feed.finishRead(checkpoint, notification?.id ?? null, data.unread_count)
    window.dispatchEvent(new Event('notifications:changed'))
    if (notification) {
      const target = typeof data.redirect_url === 'string' ? data.redirect_url : notificationUrl(notification)
      if (target) router.visit(target)
    }
  } catch {
    if (currentUser() && !controller.signal.aborted) {
      errorMessage.value = 'Could not mark notifications as read. Please try again.'
    }
  } finally {
    if (mutationController === controller) mutationController = null
    marking.value = false
    if (currentUser()) refresh.resume()
  }
}

onMounted(() => {
  const user = page.props.auth?.user
  if (!user) return
  mounted = true
  userId = user.id
  refresh.start()
  refresh.request()
  onNotification(consumerKey, (notification) => {
    feed.receive(notification)
    requestRefresh()
  }, user.id, requestRefresh)

  const availabilityChanged = () => refresh.availabilityChanged()
  for (const event of ['focus', 'online', 'offline']) {
    window.addEventListener(event, availabilityChanged)
    cleanups.push(() => window.removeEventListener(event, availabilityChanged))
  }
  document.addEventListener('visibilitychange', availabilityChanged)
  cleanups.push(() => document.removeEventListener('visibilitychange', availabilityChanged))
  window.addEventListener('notifications:changed', requestRefresh)
  cleanups.push(() => window.removeEventListener('notifications:changed', requestRefresh))
})

onUnmounted(() => {
  mounted = false
  refresh.stop()
  mutationController?.abort()
  offNotification(consumerKey)
  cleanups.forEach((cleanup) => cleanup())
})
</script>

<template>
  <DropdownMenu @update:open="onOpenChange">
    <DropdownMenuTrigger :as-child="true">
      <Button variant="ghost" size="icon" class="relative h-9 w-9" :aria-label="unreadCount ? `Notifications, ${unreadCount} unread` : 'Notifications'">
        <Bell class="h-5 w-5" />
        <Badge v-if="unreadCount > 0" class="absolute -right-1 -top-1 flex h-5 w-5 items-center justify-center rounded-full p-0 text-xs" variant="destructive">
          {{ unreadCount > 99 ? '99+' : unreadCount }}
        </Badge>
      </Button>
    </DropdownMenuTrigger>
    <DropdownMenuContent align="end" class="w-80">
      <div class="flex items-center justify-between p-2">
        <h3 class="text-sm font-semibold">Notifications</h3>
        <Button v-if="unreadCount > 0" variant="ghost" size="sm" class="h-7 text-xs" :disabled="marking" @click="markRead(null)">
          Mark all as read
        </Button>
      </div>
      <div class="px-2 pb-2"><OrderNotificationSound /></div>
      <DropdownMenuSeparator />
      <p v-if="displayError" role="status" class="px-3 py-2 text-xs text-destructive">{{ displayError }}</p>
      <div class="max-h-[400px] overflow-y-auto">
        <div v-if="loading && notifications.length === 0" class="p-4 text-center text-sm text-muted-foreground">
          Loading...
        </div>
        <div v-else-if="notifications.length === 0 && !displayError" class="p-4 text-center text-sm text-muted-foreground">
          No notifications
        </div>
        <DropdownMenuItem
          v-for="notification in notifications"
          :key="notification.id"
          :disabled="marking"
          :class="['flex cursor-pointer flex-col items-start gap-1 p-3', !notification.read_at ? 'bg-accent' : '']"
          @click="markRead(notification)"
        >
          <p class="text-sm font-medium leading-none">{{ notification.message }}</p>
          <p class="text-xs text-muted-foreground">{{ formatTimeAgo(notification.created_at) }}</p>
        </DropdownMenuItem>
      </div>
    </DropdownMenuContent>
  </DropdownMenu>
</template>
