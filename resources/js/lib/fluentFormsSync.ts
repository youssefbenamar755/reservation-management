import axios from 'axios'

interface SyncResponse {
  status: number
  data: unknown
  headers?: Record<string, unknown>
}

export interface FluentFormsSyncProgress {
  synced: number
  updated: number
  pages: number
}

export function requestFluentFormsPage(url: string, data: Record<string, number>, signal: AbortSignal): Promise<SyncResponse> {
  return axios.post(url, data, {
    signal,
    timeout: 25_000,
    headers: { Accept: 'application/json' },
    validateStatus: () => true,
  })
}

function waitForRetry(milliseconds: number, signal?: AbortSignal): Promise<void> {
  signal?.throwIfAborted()
  return new Promise((resolve, reject) => {
    const onAbort = () => { clearTimeout(timer); reject(signal?.reason) }
    const timer = setTimeout(() => {
      signal?.removeEventListener('abort', onAbort)
      resolve()
    }, milliseconds)
    signal?.addEventListener('abort', onAbort, { once: true })
  })
}

function retryDelay(response: SyncResponse): number {
  const value = response.headers?.['retry-after']
  const seconds = value === undefined ? NaN : Number(value)
  const date = typeof value === 'string' ? Date.parse(value) : NaN
  const milliseconds = Number.isFinite(seconds) ? seconds * 1000 : Number.isFinite(date) ? date - Date.now() : 60_000
  return Math.min(60_000, Math.max(0, milliseconds))
}

export async function runFluentFormsSync(
  requestPage: () => Promise<SyncResponse>,
  onProgress?: (progress: FluentFormsSyncProgress) => void,
  options: { signal?: AbortSignal; sleep?: (milliseconds: number, signal?: AbortSignal) => Promise<void> } = {},
): Promise<FluentFormsSyncProgress> {
  const totals: FluentFormsSyncProgress = { synced: 0, updated: 0, pages: 0 }
  let retries = 0
  while (true) {
    options.signal?.throwIfAborted()
    const response = await requestPage()
    options.signal?.throwIfAborted()
    if (response.status === 429 && retries < 3) {
      retries++
      await (options.sleep ?? waitForRetry)(retryDelay(response), options.signal)
      continue
    }
    const payload = response.data as Record<string, unknown> | null
    if (response.status < 200 || response.status >= 300 || payload?.status === 'error') {
      const errors = payload?.errors && typeof payload.errors === 'object' ? Object.values(payload.errors).flat().filter((value) => typeof value === 'string').join(' ') : ''
      throw new Error(errors || (typeof payload?.message === 'string' ? payload.message : `Sync failed (HTTP ${response.status}).`))
    }
    if (!payload || !['partial', 'success'].includes(String(payload.status)) ||
        !Number.isSafeInteger(payload.synced) || Number(payload.synced) < 0 ||
        !Number.isSafeInteger(payload.updated) || Number(payload.updated) < 0) {
      throw new Error('The server did not return a valid sync result. Please sign in again if your session expired, then retry.')
    }
    totals.synced += Number(payload.synced)
    totals.updated += Number(payload.updated)
    totals.pages++
    onProgress?.({ ...totals })
    if (payload.status === 'success') return totals
  }
}
