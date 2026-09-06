/**
 * Shared Echo notification composable.
 *
 * Ensures there is exactly ONE Echo private channel subscription per user.
 * Other components can register callbacks instead of creating duplicate subscriptions.
 * Waits for Echo to be initialized (getEcho) before subscribing.
 *
 * Usage:
 *   const { onNotification, offNotification } = useEchoNotifications()
 *   onMounted(() => onNotification('orders', (data) => { ... }, user.id))
 *   onUnmounted(() => offNotification('orders'))
 */

import { getEcho } from '@/lib/echo'

type Handler = (data: any) => void
type EchoInstance = NonNullable<Awaited<ReturnType<typeof getEcho>>>

// Module-level state — shared across all component instances
let channel: ReturnType<EchoInstance['private']> | null = null
let echoInstance: EchoInstance | null = null
let userId: number | null = null
let pendingSubscription: Promise<void> | null = null
let subscriptionVersion = 0
const handlers = new Map<string, Handler>()

function dispatch(data: any) {
  handlers.forEach((fn) => fn(data))
}

function resetSubscription() {
  // Invalidate any Echo initialization that completes after cleanup.
  subscriptionVersion += 1
  if (channel && echoInstance) {
    try {
      echoInstance.leave(`App.Models.User.${userId}`)
    } catch {}
  }
  channel = null
  echoInstance = null
  userId = null
  pendingSubscription = null
}

function ensureSubscribed(uid: number) {
  if (channel || pendingSubscription) return
  userId = uid
  const version = ++subscriptionVersion
  pendingSubscription = getEcho().then((instance) => {
    if (!instance || version !== subscriptionVersion || userId !== uid) return
    echoInstance = instance
    channel = instance.private(`App.Models.User.${uid}`)
    channel.listen('.notification', (data: any) => {
      dispatch(data)
    })
  }).catch((error) => {
    console.error('Echo notification setup failed:', error)
  }).finally(() => {
    if (version === subscriptionVersion) pendingSubscription = null
  })
}

export function useEchoNotifications() {
  function onNotification(key: string, handler: Handler, uid: number) {
    if (userId !== null && userId !== uid) {
      resetSubscription()
      handlers.clear()
    }
    handlers.set(key, handler)
    ensureSubscribed(uid)
  }

  function offNotification(key: string) {
    handlers.delete(key)
    // Only the shared owner leaves, after the last consumer has unmounted.
    if (handlers.size === 0) resetSubscription()
  }

  return { onNotification, offNotification }
}
