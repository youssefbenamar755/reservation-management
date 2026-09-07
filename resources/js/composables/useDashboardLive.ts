import { useEchoNotifications } from '@/composables/useEchoNotifications'
import { refreshDashboardSnapshot } from '@/lib/dashboardSnapshot'
import { getEcho } from '@/lib/echo'
import { createAutoRefresh, type AutoRefreshState } from '@/lib/liveOrders'
import { subscribeToOrders, type OrdersConnectionState } from '@/lib/ordersPush'
import { router, usePage } from '@inertiajs/vue3'
import { computed, onMounted, onUnmounted, ref, watch } from 'vue'

type WebsiteSelection = string | number | Array<string | number> | null | undefined

/** One page-owned order subscription plus the existing shared submission notifications. */
export function useDashboardLive(options: {
  getUserId: () => number | null | undefined
  getWebsiteId: () => WebsiteSelection
}) {
  const page = usePage()
  const online = ref(true)
  const visible = ref(true)
  const channelState = ref<OrdersConnectionState>('connecting')
  const refreshState = ref<AutoRefreshState>({ refreshing: false, lastCheckedAt: null, hasError: false })
  const connectionState = computed<OrdersConnectionState>(() => online.value ? channelState.value : 'disconnected')
  const isRefreshing = computed(() => refreshState.value.refreshing)
  const { onNotification, offNotification } = useEchoNotifications()
  const navigationVisits = new Set<object>()
  const cleanupListeners: Array<() => void> = []
  let mounted = false
  let dashboardPath = ''
  let activeUserId: number | null = null
  let stopOrdersSubscription: (() => void) | null = null

  const isAvailable = () => mounted && activeUserId !== null && options.getUserId() === activeUserId &&
    online.value && visible.value && window.location.pathname === dashboardPath
  const coordinator = createAutoRefresh({
    isAvailable,
    onState: (state) => { refreshState.value = state },
    refresh: ({ isCurrent, complete }) => {
      const controller = new AbortController()
      const owner = activeUserId
      void refreshDashboardSnapshot({
        getUrl: () => window.location.href,
        isCurrent: () => isCurrent() && activeUserId === owner && options.getUserId() === owner,
        signal: controller.signal,
        apply: (snapshot, stillCurrent) => new Promise<boolean>((resolve) => {
          let applied = false
          router.replace({
            preserveScroll: true,
            preserveState: true,
            props: (currentProps) => {
              // Inertia queues client updates: recheck after any intervening navigation.
              if (!stillCurrent()) return currentProps
              applied = true
              return {
                ...currentProps,
                ...snapshot,
                filters: JSON.stringify(snapshot.filters) === JSON.stringify(currentProps.filters) ? currentProps.filters : snapshot.filters,
              }
            },
            onFinish: () => resolve(applied),
          })
        }),
      }).then(complete)
      return () => controller.abort()
    },
  })

  function requestForWebsite(value: unknown) {
    const payload = value && typeof value === 'object' ? value as Record<string, unknown> : {}
    const data = payload.data && typeof payload.data === 'object' ? payload.data as Record<string, unknown> : {}
    const websiteId = payload.website_id ?? data.website_id
    const selected = options.getWebsiteId()
    const ids = (Array.isArray(selected) ? selected : [selected]).filter((id) => id !== null && id !== undefined && id !== '').map(String)
    if (ids.length && websiteId !== null && websiteId !== undefined && !ids.includes(String(websiteId))) return
    coordinator.request()
  }

  function bindUser() {
    coordinator.suspend()
    stopOrdersSubscription?.()
    stopOrdersSubscription = null
    offNotification('dashboard-live')
    activeUserId = null
    channelState.value = 'disconnected'
    const userId = options.getUserId()
    if (!Number.isSafeInteger(userId) || !userId || userId < 1) return
    activeUserId = userId
    const current = () => mounted && activeUserId === userId
    stopOrdersSubscription = subscribeToOrders({
      userId,
      getEcho,
      onOrder: (data) => { if (current()) requestForWebsite(data) },
      onSubscribed: () => { if (current()) coordinator.request() },
      onState: (state) => { if (current()) channelState.value = state },
    })
    onNotification('dashboard-live', (notification) => {
      if (current() && notification.type === 'form_submission') requestForWebsite(notification)
    }, userId, () => { if (current()) coordinator.request() })
    coordinator.resume()
  }

  function updateAvailability() {
    online.value = navigator.onLine
    visible.value = !document.hidden
    coordinator.availabilityChanged()
  }

  function suspendForHistory() {
    coordinator.suspend()
  }

  watch(options.getUserId, () => { if (mounted) bindUser() }, { flush: 'sync' })
  watch(() => page.url, () => {
    if (!mounted) return
    coordinator.suspend()
    if (!navigationVisits.size) coordinator.resume()
  }, { flush: 'sync' })

  onMounted(() => {
    mounted = true
    dashboardPath = window.location.pathname
    online.value = navigator.onLine
    visible.value = !document.hidden
    coordinator.start()
    cleanupListeners.push(
      router.on('before', ({ detail: { visit } }) => {
        if (visit.async) return
        coordinator.suspend()
        // Cancelled visits have no start/finish event. Resume only if none started.
        queueMicrotask(() => {
          if (mounted && !navigationVisits.size) coordinator.resume()
        })
      }),
      router.on('start', ({ detail: { visit } }) => {
        if (visit.async) return
        navigationVisits.add(visit)
        coordinator.suspend()
      }),
      router.on('finish', ({ detail: { visit } }) => {
        if (visit.async) return
        navigationVisits.delete(visit)
        if (mounted && !navigationVisits.size) coordinator.resume()
      }),
      router.on('navigate', () => {
        if (mounted && !navigationVisits.size) coordinator.resume()
      }),
    )
    document.addEventListener('visibilitychange', updateAvailability)
    window.addEventListener('online', updateAvailability)
    window.addEventListener('offline', updateAvailability)
    window.addEventListener('focus', updateAvailability)
    window.addEventListener('popstate', suspendForHistory)
    bindUser()
  })

  onUnmounted(() => {
    mounted = false
    coordinator.stop()
    stopOrdersSubscription?.()
    offNotification('dashboard-live')
    cleanupListeners.forEach((remove) => remove())
    navigationVisits.clear()
    document.removeEventListener('visibilitychange', updateAvailability)
    window.removeEventListener('online', updateAvailability)
    window.removeEventListener('offline', updateAvailability)
    window.removeEventListener('focus', updateAvailability)
    window.removeEventListener('popstate', suspendForHistory)
  })

  return { connectionState, refreshState, isRefreshing, refresh: () => coordinator.request(0) }
}
