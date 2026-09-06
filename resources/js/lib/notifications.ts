export interface NotificationData {
  type: string
  website_id?: number
  website_name?: string
  order_id?: number
  wp_order_id?: number
  submission_id?: number
  [key: string]: unknown
}

export interface AppNotification {
  id: string
  type: string
  message: string
  read_at: string | null
  created_at: string
  redirect_url?: string
  data: NotificationData
}

const record = (value: unknown): Record<string, unknown> =>
  value !== null && typeof value === 'object' && !Array.isArray(value) ? value as Record<string, unknown> : {}

function canonicalType(value: unknown): string {
  if (typeof value !== 'string') return ''
  if (value === 'App\\Notifications\\NewOrderNotification') return 'order'
  if (value === 'App\\Notifications\\NewFormSubmissionNotification') return 'form_submission'
  return value
}

/** Laravel may override the outer type with its notification class name. */
export function normalizeNotification(value: unknown): AppNotification | null {
  const source = record(value)
  if (typeof source.id !== 'string' || !source.id.trim()) return null
  const data = record(source.data)
  const type = canonicalType(data.type) || canonicalType(source.type) || 'unknown'
  return {
    id: source.id,
    type,
    message: typeof source.message === 'string' ? source.message : typeof data.message === 'string' ? data.message : '',
    read_at: typeof source.read_at === 'string' ? source.read_at : null,
    created_at: typeof source.created_at === 'string' ? source.created_at : '',
    ...(typeof source.redirect_url === 'string' ? { redirect_url: source.redirect_url } : {}),
    data: { ...data, type },
  }
}

export interface NotificationFeedState {
  notifications: AppNotification[]
  unreadCount: number
}

export interface NotificationReadCheckpoint {
  revision: number
  ids: Set<string>
  unreadCount: number
}

/** A fetched snapshot can replace local state only if no push/read changed it. */
export function createNotificationFeed(initialUnreadCount: number, onChange: (state: NotificationFeedState) => void) {
  let state: NotificationFeedState = { notifications: [], unreadCount: Math.max(0, initialUnreadCount || 0) }
  let revision = 0
  const knownIds = new Set<string>()
  const emit = () => onChange({ notifications: [...state.notifications], unreadCount: state.unreadCount })
  const remember = (id: string) => {
    knownIds.add(id)
    if (knownIds.size > 2_000) knownIds.delete(knownIds.values().next().value!)
  }
  const validCount = (value: unknown): value is number => Number.isInteger(value) && (value as number) >= 0

  return {
    get revision() { return revision },
    receive(notification: AppNotification) {
      if (knownIds.has(notification.id)) return false
      remember(notification.id)
      revision++
      state.notifications = [notification, ...state.notifications].slice(0, 50)
      if (!notification.read_at) state.unreadCount++
      emit()
      return true
    },
    applySnapshot(value: unknown, expectedRevision: number) {
      if (revision !== expectedRevision) return false
      const snapshot = record(value)
      if (!Array.isArray(snapshot.notifications) || !validCount(snapshot.unread_count)) {
        throw new Error('Invalid notifications response')
      }
      const unique = new Map<string, AppNotification>()
      for (const raw of snapshot.notifications) {
        const notification = normalizeNotification(raw)
        if (!notification) throw new Error('Invalid notification identity')
        if (!unique.has(notification.id)) unique.set(notification.id, notification)
        remember(notification.id)
      }
      state = { notifications: [...unique.values()].slice(0, 50), unreadCount: snapshot.unread_count }
      emit()
      return true
    },
    beginRead(): NotificationReadCheckpoint {
      revision++
      return { revision, ids: new Set(state.notifications.filter((item) => !item.read_at).map((item) => item.id)), unreadCount: state.unreadCount }
    },
    finishRead(checkpoint: NotificationReadCheckpoint, id: string | null, unreadCount: unknown) {
      const changedDuringRequest = revision !== checkpoint.revision
      const wasUnread = state.notifications.some((item) => item.id === id && !item.read_at)
      const readAt = new Date().toISOString()
      state.notifications = state.notifications.map((item) =>
        !item.read_at && (id === null ? checkpoint.ids.has(item.id) : item.id === id)
          ? { ...item, read_at: readAt } : item)
      if (!changedDuringRequest && validCount(unreadCount)) {
        state.unreadCount = unreadCount
      } else {
        // A push arriving during mark-all must remain unread until the next snapshot.
        state.unreadCount = Math.max(0, state.unreadCount - (id === null ? checkpoint.unreadCount : wasUnread ? 1 : 0))
      }
      revision++
      emit()
    },
  }
}
