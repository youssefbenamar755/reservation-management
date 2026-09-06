export type RefreshOutcome = 'success' | 'error' | 'cancelled'

export interface AutoRefreshState {
  refreshing: boolean
  lastCheckedAt: number | null
  hasError: boolean
}

interface RefreshRequest {
  isCurrent: () => boolean
  complete: (outcome: RefreshOutcome) => void
}

type Timer = ReturnType<typeof setTimeout>

/** One request at a time, with push bursts coalesced and polling after completion. */
export function createAutoRefresh(options: {
  refresh: (request: RefreshRequest) => (() => void)
  isAvailable: () => boolean
  onState: (state: AutoRefreshState) => void
  interval?: number
  coalesceDelay?: number
  now?: () => number
  setTimer?: (callback: () => void, delay: number) => Timer
  clearTimer?: (timer: Timer) => void
}) {
  const interval = options.interval ?? 10_000
  const coalesceDelay = options.coalesceDelay ?? 250
  const now = options.now ?? Date.now
  const setTimer = options.setTimer ?? setTimeout
  const clearTimer = options.clearTimer ?? clearTimeout
  const state: AutoRefreshState = { refreshing: false, lastCheckedAt: null, hasError: false }
  let started = false
  let suspended = false
  let pending = false
  let failures = 0
  let timer: Timer | null = null
  let timerKind: 'poll' | 'event' | null = null
  let active: { cancel?: () => void } | null = null

  const emit = () => options.onState({ ...state })
  const available = () => started && !suspended && options.isAvailable()

  function clearScheduled() {
    if (timer !== null) clearTimer(timer)
    timer = null
    timerKind = null
  }

  function cancelActive() {
    const previous = active
    active = null // Invalidate callbacks before cancellation can call complete().
    state.refreshing = false
    previous?.cancel?.()
    emit()
  }

  function schedule(delay: number, kind: 'poll' | 'event') {
    clearScheduled()
    if (!available()) return
    timerKind = kind
    timer = setTimer(run, delay)
  }

  function run() {
    timer = null
    timerKind = null
    if (!available() || active) return
    pending = false
    const request: { cancel?: () => void } = {}
    active = request
    state.refreshing = true
    emit()

    const isCurrent = () => active === request && available()
    const complete = (outcome: RefreshOutcome) => {
      if (active !== request) return
      active = null
      state.refreshing = false
      if (outcome === 'success') {
        failures = 0
        state.hasError = false
        state.lastCheckedAt = now()
      } else if (outcome === 'error') {
        failures++
        state.hasError = true
      }
      emit()
      const retryDelay = Math.min(interval * (2 ** failures), 60_000)
      schedule(pending && outcome !== 'error' ? coalesceDelay : retryDelay, pending ? 'event' : 'poll')
    }

    try {
      const cancel = options.refresh({ isCurrent, complete })
      if (active === request) request.cancel = cancel
    } catch {
      complete('error')
    }
  }

  function request(delay = coalesceDelay) {
    pending = true
    if (!available() || active || timerKind === 'event') return
    schedule(delay, 'event')
  }

  return {
    start() {
      if (started) return
      started = true
      emit()
      schedule(interval, 'poll')
    },
    request,
    suspend() {
      suspended = true
      pending = true
      clearScheduled()
      cancelActive()
    },
    resume() {
      suspended = false
      request(0)
    },
    availabilityChanged() {
      if (!options.isAvailable()) {
        pending = true
        clearScheduled()
        cancelActive()
      } else {
        request(0)
      }
    },
    stop() {
      started = false
      pending = false
      clearScheduled()
      cancelActive()
    },
  }
}

export interface OrdersSnapshot {
  data: unknown[]
  current_page: number
  total: number
  [key: string]: unknown
}

/** Guard both the HTTP response and the deferred Inertia prop update. */
export async function refreshOrdersSnapshot(options: {
  getUrl: () => string
  isCurrent: () => boolean
  signal: AbortSignal
  apply: (orders: OrdersSnapshot, isCurrent: () => boolean) => Promise<boolean>
  fetch?: typeof fetch
  setTimer?: (callback: () => void, delay: number) => Timer
  clearTimer?: (timer: Timer) => void
}): Promise<RefreshOutcome> {
  const url = options.getUrl()
  const isCurrent = () => !options.signal.aborted && options.isCurrent() && options.getUrl() === url
  if (!isCurrent()) return 'cancelled'
  const controller = new AbortController()
  const abortFromParent = () => controller.abort()
  options.signal.addEventListener('abort', abortFromParent, { once: true })
  const clearTimer = options.clearTimer ?? clearTimeout
  // A stalled connection/body must not prevent all future automatic checks.
  const deadline = (options.setTimer ?? setTimeout)(() => controller.abort(), 20_000)
  try {
    const response = await (options.fetch ?? fetch)(url, {
      headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
      credentials: 'same-origin',
      cache: 'no-store',
      signal: controller.signal,
    })
    if (!response.ok) throw new Error(`HTTP ${response.status}`)
    const { orders } = await response.json()
    clearTimer(deadline)
    if (!Array.isArray(orders?.data) || !Number.isInteger(orders.current_page) ||
        orders.current_page < 1 || !Number.isInteger(orders.total) || orders.total < 0) {
      throw new Error('Invalid orders response')
    }
    if (!isCurrent()) return 'cancelled'
    const applied = await options.apply(orders, isCurrent)
    return applied && isCurrent() ? 'success' : 'cancelled'
  } catch {
    return isCurrent() ? 'error' : 'cancelled'
  } finally {
    clearTimer(deadline)
    options.signal.removeEventListener('abort', abortFromParent)
  }
}
