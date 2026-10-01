import { api } from '~/lib/api/client'
import type { ImportSummary } from '~/lib/api/types'
import { feedErrorMessage } from './errors'

/** Chunks in flight at once, attempts per chunk before giving up, and the first wait between them. */
const PARALLEL = 3
const ATTEMPTS = 3
const RETRY_MS = 1000

/** An upload that stopped, with the sentence to show. */
export class UploadFailed extends Error {}

export interface UploadProgress {
  sent: number
  total: number
}

/**
 * Upload a file into a feed in chunks, then queue its import. The server fixes the chunk size;
 * chunks go a few at a time, in any order, and each is retried after a network error or a server
 * error, so a flaky connection does not restart a 2 GB upload.
 */
export async function uploadFile(
  feedId: number,
  file: File,
  onProgress: (progress: UploadProgress) => void,
  signal?: AbortSignal,
): Promise<ImportSummary> {
  const started = await api.POST('/v1/feeds/{feed}/uploads', {
    params: { path: { feed: feedId } },
    body: { filename: file.name, size: file.size },
    signal,
  })
  if (started.data === undefined) throw new UploadFailed(feedErrorMessage(started.response.status, started.error))
  const upload = started.data.upload

  const pending = Array.from({ length: upload.total_chunks }, (_, index) => index)
  let sent = 0
  onProgress({ sent, total: file.size })

  // The first chunk that fails stops the others: nothing keeps sending (or retrying) after the
  // upload has been reported as failed. Cancelling stops them too.
  const stop = new AbortController()
  const cancel = () => stop.abort(signal?.reason)
  signal?.addEventListener('abort', cancel, { once: true })

  // Each worker takes the next chunk when its last one has arrived.
  const worker = async (): Promise<void> => {
    const index = pending.shift()
    if (index === undefined) return
    const start = index * upload.chunk_size
    const bytes = await file.slice(start, Math.min(file.size, start + upload.chunk_size)).arrayBuffer()
    stop.signal.throwIfAborted()
    await sendChunk(feedId, upload.id, index, bytes, stop.signal)
    sent += bytes.byteLength
    onProgress({ sent, total: file.size })

    return worker()
  }
  try {
    await Promise.all(Array.from({ length: Math.min(PARALLEL, upload.total_chunks) }, worker))
  } catch (error) {
    stop.abort()
    throw error
  } finally {
    signal?.removeEventListener('abort', cancel)
  }

  const completed = await api.POST('/v1/feeds/{feed}/uploads/{upload}/complete', {
    params: { path: { feed: feedId, upload: upload.id } },
    body: {},
    signal,
  })
  if (completed.data === undefined) {
    throw new UploadFailed(feedErrorMessage(completed.response.status, completed.error))
  }

  return completed.data.import
}

async function sendChunk(
  feed: number,
  upload: string,
  index: number,
  bytes: ArrayBuffer,
  signal: AbortSignal,
  attempt = 1,
): Promise<void> {
  let status: number | null = null
  let body: unknown = null
  try {
    const result = await api.PUT('/v1/feeds/{feed}/uploads/{upload}/chunks/{index}', {
      params: { path: { feed, upload, index } },
      // The chunk is the raw request body, which the API contract cannot describe: hence the cast.
      body: bytes as never,
      bodySerializer: (raw: unknown) => raw as ArrayBuffer,
      headers: { 'Content-Type': 'application/octet-stream' },
      signal,
    })
    if (result.response.ok) return
    status = result.response.status
    body = result.error
  } catch (error) {
    // Cancelled by the person: stop. A network error: try again below.
    if (signal.aborted) throw error
  }

  // A refusal (wrong length, expired upload, no access) will not change on a retry.
  if (status !== null && status < 500) throw new UploadFailed(feedErrorMessage(status, body))
  if (attempt >= ATTEMPTS) {
    throw new UploadFailed(
      status === null
        ? 'The connection dropped during the upload. Check it and try again.'
        : feedErrorMessage(status, body),
    )
  }
  await pause(attempt * RETRY_MS, signal)

  return sendChunk(feed, upload, index, bytes, signal, attempt + 1)
}

function pause(ms: number, signal: AbortSignal): Promise<void> {
  return new Promise((resolve, reject) => {
    const timer = setTimeout(resolve, ms)
    signal.addEventListener(
      'abort',
      () => {
        clearTimeout(timer)
        reject(signal.reason as Error)
      },
      { once: true },
    )
  })
}
