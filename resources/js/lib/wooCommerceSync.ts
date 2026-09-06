type SyncResponse = Pick<Response, 'ok' | 'status' | 'json'> & {
  headers?: Pick<Headers, 'get'>
}

export interface WooCommerceSyncCounts {
  newOrders: number
  updatedOrders: number
}

interface WooCommerceSyncPage extends WooCommerceSyncCounts {
  status?: 'partial' | 'success'
}

export async function readWooCommerceSyncResult(response: SyncResponse): Promise<WooCommerceSyncPage | null> {
  if (!response.ok) throw new Error(`HTTP ${response.status}`)

  let payload
  try {
    payload = await response.json()
  } catch {
    throw new Error('The server did not return a valid sync result.')
  }

  if (payload?.status !== undefined) {
    if (payload.status === 'error') {
      throw new Error(typeof payload.message === 'string' ? payload.message : 'Sync failed.')
    }
    if (!['partial', 'success'].includes(payload.status) ||
        !Number.isSafeInteger(payload.synced) || payload.synced < 0 ||
        !Number.isSafeInteger(payload.updated) || payload.updated < 0) {
      throw new Error('The server did not return a valid sync result.')
    }
    return { status: payload.status, newOrders: payload.synced, updatedOrders: payload.updated }
  }

  // Laravel redirects on both success and failure, so HTTP 200 is insufficient.
  const flash = payload?.props?.flash
  const error = flash?.error || flash?.warning
  if (error) throw new Error(String(error))

  const message = flash?.success
  if (typeof message !== 'string' || !message.trim()) {
    throw new Error('The server did not confirm that sync completed.')
  }

  const newMatch = message.match(/(\d+) new\b/i)
  const updatedMatch = message.match(/updated (\d+) existing\b/i)
  if (newMatch && updatedMatch) {
    return { newOrders: Number(newMatch[1]), updatedOrders: Number(updatedMatch[1]) }
  }
  if (/already up to date/i.test(message)) return { newOrders: 0, updatedOrders: 0 }

  // Missing update counts (including older responses) cannot confirm no changes.
  return null
}

function waitForRetry(milliseconds: number, signal?: AbortSignal): Promise<void> {
  signal?.throwIfAborted()
  return new Promise((resolve, reject) => {
    const onAbort = () => {
      clearTimeout(timer)
      reject(signal?.reason)
    }
    const timer = setTimeout(() => {
      signal?.removeEventListener('abort', onAbort)
      resolve()
    }, milliseconds)
    signal?.addEventListener('abort', onAbort, { once: true })
  })
}

function retryDelay(response: SyncResponse): number {
  const retryAfter = response.headers?.get('Retry-After')
  const seconds = retryAfter ? Number(retryAfter) : Number.NaN
  const date = retryAfter ? Date.parse(retryAfter) : Number.NaN
  const milliseconds = Number.isFinite(seconds)
    ? seconds * 1000
    : Number.isFinite(date) ? date - Date.now() : 60_000
  return Math.min(60_000, Math.max(0, milliseconds))
}

export async function runWooCommerceSync(
  requestPage: () => Promise<SyncResponse>,
  onProgress?: (counts: WooCommerceSyncCounts) => void,
  options: {
    signal?: AbortSignal
    sleep?: (milliseconds: number, signal?: AbortSignal) => Promise<void>
  } = {},
): Promise<WooCommerceSyncCounts | null> {
  const totals: WooCommerceSyncCounts = { newOrders: 0, updatedOrders: 0 }
  let rateLimitRetries = 0
  while (true) {
    options.signal?.throwIfAborted()
    const response = await requestPage()
    options.signal?.throwIfAborted()
    if (response.status === 429 && rateLimitRetries < 3) {
      rateLimitRetries++
      await (options.sleep ?? waitForRetry)(retryDelay(response), options.signal)
      continue
    }
    const result = await readWooCommerceSyncResult(response)
    options.signal?.throwIfAborted()
    if (!result) return null
    totals.newOrders += result.newOrders
    totals.updatedOrders += result.updatedOrders
    onProgress?.({ ...totals })
    if (result.status !== 'partial') return totals
  }
}

export function summarizeWooCommerceSyncResults(results: Array<WooCommerceSyncCounts | null>, subject = 'All websites'): string {
  let newOrders = 0
  let updatedOrders = 0
  for (const result of results) {
    if (!result) return `${subject} synced successfully.`
    newOrders += result.newOrders
    updatedOrders += result.updatedOrders
  }

  if (newOrders === 0 && updatedOrders === 0) return `${subject} synced — already up to date.`
  return `${subject} synced — ${newOrders} new order(s), ${updatedOrders} existing order(s) updated.`
}
