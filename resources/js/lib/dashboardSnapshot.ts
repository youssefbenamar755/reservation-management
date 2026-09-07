import type { RefreshOutcome } from '@/lib/liveOrders'

const objectKeys = ['filters', 'period', 'summary', 'operations'] as const
const arrayKeys = ['websites', 'revenue', 'trend', 'statusBreakdown', 'websitePerformance', 'recentOrders', 'recentSubmissions', 'websiteHealth'] as const
export const dashboardSnapshotKeys = [...objectKeys, ...arrayKeys, 'generated_at'] as const
export type DashboardSnapshot = Record<typeof dashboardSnapshotKeys[number], unknown>

function readSnapshot(value: unknown): DashboardSnapshot {
  if (!value || typeof value !== 'object' || Array.isArray(value)) throw new Error('Invalid dashboard response')
  const record = value as Record<string, unknown>
  for (const key of objectKeys) {
    if (!record[key] || typeof record[key] !== 'object' || Array.isArray(record[key])) throw new Error('Invalid dashboard response')
  }
  for (const key of arrayKeys) {
    if (!Array.isArray(record[key])) throw new Error('Invalid dashboard response')
  }
  if (typeof record.generated_at !== 'string' || !Number.isFinite(Date.parse(record.generated_at))) throw new Error('Invalid dashboard response')
  // Never replace shared auth, flash, errors, or other page context from this endpoint.
  return Object.fromEntries(dashboardSnapshotKeys.map((key) => [key, record[key]])) as DashboardSnapshot
}

/** Validate and apply a complete dashboard snapshot only while its URL and owner are current. */
export async function refreshDashboardSnapshot(options: {
  getUrl: () => string
  isCurrent: () => boolean
  signal: AbortSignal
  apply: (snapshot: DashboardSnapshot, isCurrent: () => boolean) => Promise<boolean>
  fetch?: typeof fetch
  setTimer?: typeof setTimeout
  clearTimer?: typeof clearTimeout
}): Promise<RefreshOutcome> {
  const url = options.getUrl()
  const parentIsCurrent = () => !options.signal.aborted && options.isCurrent() && options.getUrl() === url
  if (!parentIsCurrent()) return 'cancelled'
  const controller = new AbortController()
  const isCurrent = () => !controller.signal.aborted && parentIsCurrent()
  const abortFromParent = () => controller.abort()
  options.signal.addEventListener('abort', abortFromParent, { once: true })
  const deadline = (options.setTimer ?? setTimeout)(() => controller.abort(), 20_000)
  let onAbort: () => void = () => {}
  const interrupted = new Promise<RefreshOutcome>((resolve) => {
    onAbort = () => resolve(parentIsCurrent() ? 'error' : 'cancelled')
    controller.signal.addEventListener('abort', onAbort, { once: true })
  })
  const operation = async (): Promise<RefreshOutcome> => {
    try {
      const response = await (options.fetch ?? fetch)(url, {
        signal: controller.signal,
        credentials: 'same-origin',
        cache: 'no-store',
        headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
      })
      if (!response.ok) throw new Error(`HTTP ${response.status}`)
      const snapshot = readSnapshot(await response.json())
      if (!isCurrent()) return 'cancelled'
      const applied = await options.apply(snapshot, isCurrent)
      return applied && isCurrent() ? 'success' : 'cancelled'
    } catch {
      return parentIsCurrent() ? 'error' : 'cancelled'
    }
  }
  try {
    // Also bounds a stalled body or deferred Inertia updater, not only the fetch.
    return await Promise.race([operation(), interrupted])
  } finally {
    (options.clearTimer ?? clearTimeout)(deadline)
    controller.signal.removeEventListener('abort', onAbort)
    options.signal.removeEventListener('abort', abortFromParent)
  }
}
