/**
 * Shared Echo notification composable.
 *
 * Ensures there is exactly ONE Echo private channel subscription per user.
 * Other components can register callbacks instead of creating duplicate subscriptions.
 * Waits for Echo to be initialized (getEcho) before subscribing.
 *
 * Usage:
 *   const { onNotification, offNotification } = useEchoNotifications()
 *   onMounted(() => onNotification('orders', (data) => { ... }))
 *   onUnmounted(() => offNotification('orders'))
 */

import { getEcho } from '@/lib/echo'

type Handler = (data: any) => void

// Module-level state — shared across all component instances
let channel: any = null
let echoInstance: any = null
let userId: number | null = null
const handlers = new Map<string, Handler>()

function dispatch(data: any) {
  handlers.forEach((fn) => fn(data))
}

function ensureSubscribed(uid: number) {
  if (channel && userId === uid) return   // already subscribed for this user

  // Clean up stale subscription if user changed
  if (channel && echoInstance) {
    try {
      echoInstance.leave(`App.Models.User.${userId}`)
    } catch {}
    channel = null
  }

  userId = uid
  getEcho().then((instance) => {
    if (!instance) return
    echoInstance = instance
    channel = instance.private(`App.Models.User.${uid}`)
    channel.listen('.notification', (data: any) => {
      dispatch(data)
    })
  }).catch(() => {})
}

export function useEchoNotifications() {
  function onNotification(key: string, handler: Handler, uid: number) {
    ensureSubscribed(uid)
    handlers.set(key, handler)
  }

  function offNotification(key: string) {
    handlers.delete(key)
    // Do NOT leave the channel here — other handlers may still need it.
    // The channel stays alive as long as the page is loaded.
  }

  return { onNotification, offNotification }
}
