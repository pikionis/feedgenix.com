import { delay, http, HttpResponse } from 'msw'
import { beforeEach, expect, it } from 'vitest'
import { apiUrl, importSummary } from '~/test/fixtures'
import { csrfCookie } from '~/test/handlers'
import { server } from '~/test/server'
import { uploadFile, UploadFailed, type UploadProgress } from './upload'

const UPLOAD_ID = '0190c5a4-0000-7000-8000-000000000001'
const CHUNKS = apiUrl(`/v1/feeds/7/uploads/${UPLOAD_ID}/chunks/:index`)

/** A 10-byte file in chunks of 4: 4 + 4 + 2 bytes. */
const file = () => new File(['0123456789'], 'feed.csv', { type: 'text/csv' })

let started: unknown
let completions: number
let received: Map<number, string>

beforeEach(() => {
  started = null
  completions = 0
  received = new Map()
  server.use(
    csrfCookie(),
    http.post(apiUrl('/v1/feeds/7/uploads'), async ({ request }) => {
      started = await request.json()
      return HttpResponse.json(
        {
          upload: {
            id: UPLOAD_ID,
            filename: 'feed.csv',
            size: 10,
            chunk_size: 4,
            total_chunks: 3,
            expires_at: '2027-01-11T10:00:00+00:00',
          },
        },
        { status: 201 },
      )
    }),
    http.post(apiUrl(`/v1/feeds/7/uploads/${UPLOAD_ID}/complete`), () => {
      completions++
      return HttpResponse.json({ import: importSummary({ status: 'queued', origin: 'upload' }) }, { status: 202 })
    }),
  )
})

function acceptChunks(before?: (index: number, attempt: number) => Response | undefined) {
  const attempts = new Map<number, number>()
  server.use(
    http.put(CHUNKS, async ({ request, params }) => {
      const index = Number(params.index)
      const attempt = (attempts.get(index) ?? 0) + 1
      attempts.set(index, attempt)
      const early = before?.(index, attempt)
      if (early !== undefined) return early
      expect(request.headers.get('Content-Type')).toBe('application/octet-stream')
      expect(request.headers.get('X-XSRF-TOKEN')).toBe('test-token')
      received.set(index, await request.text())
      return new HttpResponse(null, { status: 204 })
    }),
  )

  return attempts
}

it('sends every chunk as raw bytes, then completes once, reporting progress up to the whole file', async () => {
  acceptChunks()
  const progress: UploadProgress[] = []

  const result = await uploadFile(7, file(), (step) => progress.push(step))

  expect(started).toEqual({ filename: 'feed.csv', size: 10 })
  expect(received.size).toBe(3)
  expect([0, 1, 2].map((index) => received.get(index))).toEqual(['0123', '4567', '89'])
  expect(completions).toBe(1)
  expect(result.status).toBe('queued')
  expect(progress[0]).toEqual({ sent: 0, total: 10 })
  expect(progress.at(-1)).toEqual({ sent: 10, total: 10 })
  const sent = progress.map((step) => step.sent)
  expect(sent.every((value, index) => value >= (sent[index - 1] ?? 0))).toBe(true)
})

it('sends a chunk again after a server error', async () => {
  const attempts = acceptChunks((index, attempt) =>
    index === 1 && attempt === 1 ? new HttpResponse(null, { status: 503 }) : undefined,
  )

  await uploadFile(7, file(), () => {})

  expect(attempts.get(1)).toBe(2)
  expect(received.get(1)).toBe('4567')
  expect(completions).toBe(1)
})

it('sends a chunk again after the connection dropped', async () => {
  const attempts = acceptChunks((index, attempt) => (index === 2 && attempt === 1 ? HttpResponse.error() : undefined))

  await uploadFile(7, file(), () => {})

  expect(attempts.get(2)).toBe(2)
  expect(completions).toBe(1)
})

it('stops at a refusal without retrying, and never completes', async () => {
  const attempts = acceptChunks((index) =>
    index === 0
      ? HttpResponse.json(
          {
            message: 'Chunk 0 must be 4 bytes; 3 arrived.',
            errors: { chunk: ['Chunk 0 must be 4 bytes; 3 arrived.'] },
          },
          { status: 422 },
        )
      : undefined,
  )

  await expect(uploadFile(7, file(), () => {})).rejects.toThrow(
    new UploadFailed('Chunk 0 must be 4 bytes; 3 arrived.', 422),
  )
  expect(attempts.get(0)).toBe(1)
  expect(completions).toBe(0)
})

it('says so when the upload expired', async () => {
  acceptChunks(() =>
    HttpResponse.json({ message: 'This upload is finished or has expired. Start a new one.' }, { status: 410 }),
  )

  await expect(uploadFile(7, file(), () => {})).rejects.toThrow(
    'This upload is finished or has expired. Start a new one.',
  )
})

it('stops the other chunks, retries included, once one is refused', async () => {
  const attempts = new Map<number, number>()
  server.use(
    http.put(CHUNKS, async ({ params }) => {
      const index = Number(params.index)
      attempts.set(index, (attempts.get(index) ?? 0) + 1)
      if (index === 1) return new HttpResponse(null, { status: 503 }) // would be retried after a second
      if (index === 0) {
        await delay(50)
        return HttpResponse.json(
          { message: 'No.', errors: { chunk: ['Chunk 0 must be 4 bytes; 3 arrived.'] } },
          { status: 422 },
        )
      }
      return new HttpResponse(null, { status: 204 })
    }),
  )

  await expect(uploadFile(7, file(), () => {})).rejects.toThrow('Chunk 0 must be 4 bytes; 3 arrived.')
  await new Promise((resolve) => setTimeout(resolve, 1300))

  expect(attempts.get(1)).toBe(1)
  expect(completions).toBe(0)
})

it('gives up after three attempts at a failing chunk', { timeout: 10_000 }, async () => {
  const attempts = acceptChunks((index) => (index === 0 ? new HttpResponse(null, { status: 500 }) : undefined))

  await expect(uploadFile(7, file(), () => {})).rejects.toThrow('Something went wrong on our side')
  expect(attempts.get(0)).toBe(3)
  expect(completions).toBe(0)
})

it('passes on why the upload could not start', async () => {
  server.use(
    http.post(apiUrl('/v1/feeds/7/uploads'), () =>
      HttpResponse.json(
        {
          message: 'The file is larger than 2048 MB, the most one import can hold.',
          errors: { size: ['The file is larger than 2048 MB, the most one import can hold.'] },
        },
        { status: 422 },
      ),
    ),
  )

  await expect(uploadFile(7, file(), () => {})).rejects.toThrow(
    'The file is larger than 2048 MB, the most one import can hold.',
  )
})

it('stops sending when cancelled', async () => {
  const controller = new AbortController()
  const attempts = acceptChunks((index) => {
    if (index === 0) controller.abort()
    return undefined
  })

  await expect(uploadFile(7, file(), () => {}, controller.signal)).rejects.toThrow()
  expect(completions).toBe(0)
  expect(attempts.size).toBeLessThan(3)
})

it('passes on a plan limit at the start, with its status', async () => {
  const message = 'This file is 3 GB; your Free plan reads feeds up to 2 GB. Pro reads feeds up to 10 GB.'
  server.use(
    http.post(apiUrl('/v1/feeds/7/uploads'), () =>
      HttpResponse.json(
        {
          error: 'plan_limit',
          limit: 'feed_bytes',
          allowed: 2,
          used: null,
          requested: 3,
          plan: 'free',
          upgrade_to: 'pro',
          message,
        },
        { status: 402 },
      ),
    ),
  )

  await expect(uploadFile(7, file(), () => {})).rejects.toThrow(new UploadFailed(message, 402))
})
