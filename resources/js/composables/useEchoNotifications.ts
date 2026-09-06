import { getEcho } from '@/lib/echo'
import { normalizeNotification, type AppNotification } from '@/lib/notifications'

type Handler = (data: AppNotification) => void
type EchoInstance = NonNullable<Awaited<ReturnType<typeof getEcho>>>
type Consumer = { handler: Handler; onReconnect?: () => void }
const events = ['.notification', '.Illuminate\\Notifications\\Events\\BroadcastNotificationCreated']

// One owner for the private channel; all page and app consumers share it.
let channel: ReturnType<EchoInstance['private']> | null = null
let userId: number | null = null
let seenUserId: number | null = null
let pendingSubscription: Promise<void> | null = null
let subscriptionVersion = 0
let cleanup = () => {}
const handlers = new Map<string, Consumer>()
const seenIds = new Set<string>()

function safely(callback: () => void) {
  try { callback() } catch (error) { console.error('Notification callback failed:', error) }
}

function dispatch(value: unknown) {
  const notification = normalizeNotification(value)
  if (!notification || seenIds.has(notification.id)) return
  seenIds.add(notification.id)
  if (seenIds.size > 2_000) seenIds.delete(seenIds.values().next().value!)
  for (const [key, consumer] of [...handlers]) {
    if (handlers.get(key) === consumer) safely(() => consumer.handler(notification))
  }
}

function resetSubscription() {
  subscriptionVersion++ // Invalidate delayed initialization and captured callbacks.
  cleanup()
  cleanup = () => {}
  channel = null
  userId = null
  pendingSubscription = null
}

function ensureSubscribed(uid: number) {
  if (channel || pendingSubscription) return
  userId = uid
  const version = ++subscriptionVersion
  pendingSubscription = getEcho().then((instance) => {
    if (!instance || version !== subscriptionVersion || userId !== uid) return
    const name = `App.Models.User.${uid}`
    const subscribedChannel = instance.private(name)
    channel = subscribedChannel
    const subscription = subscribedChannel.subscription
    const current = () => version === subscriptionVersion && userId === uid
    const onEvent = (data: unknown) => { if (current()) dispatch(data) }
    const onSubscribed = () => {
      if (!current()) return
      for (const [key, consumer] of [...handlers]) {
        if (handlers.get(key) === consumer && consumer.onReconnect) safely(consumer.onReconnect)
      }
    }
    cleanup = () => {
      for (const event of events) subscribedChannel.stopListening(event, onEvent)
      subscription.unbind('pusher:subscription_succeeded', onSubscribed)
      instance.leave(name)
    }
    for (const event of events) subscribedChannel.listen(event, onEvent)
    subscription.bind('pusher:subscription_succeeded', onSubscribed)
    if (subscription.subscribed) onSubscribed()
  }).catch((error) => {
    if (version === subscriptionVersion) resetSubscription()
    console.error('Echo notification setup failed:', error)
  }).finally(() => {
    if (version === subscriptionVersion) pendingSubscription = null
  })
}

export function useEchoNotifications() {
  function onNotification(key: string, handler: Handler, uid: number, onReconnect?: () => void) {
    if (userId !== null && userId !== uid) {
      resetSubscription()
      handlers.clear()
    }
    if (seenUserId !== uid) {
      seenUserId = uid
      seenIds.clear()
    }
    handlers.set(key, { handler, onReconnect })
    ensureSubscribed(uid)
  }

  function offNotification(key: string) {
    handlers.delete(key)
    if (handlers.size === 0) resetSubscription()
  }

  return { onNotification, offNotification }
}
