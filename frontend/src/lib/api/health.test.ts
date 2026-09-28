import { http, HttpResponse } from 'msw'
import { expect, it } from 'vitest'
import { server } from '~/test/server'
import { fetchHealth } from './health'

const url = `${window.location.origin}/api/health`

it('reports ok when the API and its database answer', async () => {
  server.use(http.get(url, () => HttpResponse.json({ status: 'ok', database: 'ok' })))

  await expect(fetchHealth()).resolves.toBe('ok')
})

it('reports degraded when the API answers 503', async () => {
  server.use(http.get(url, () => HttpResponse.json({ status: 'degraded', database: 'down' }, { status: 503 })))

  await expect(fetchHealth()).resolves.toBe('degraded')
})

it('never trusts a 200 whose body does not say ok', async () => {
  server.use(http.get(url, () => HttpResponse.json({ status: 'maintenance' })))

  await expect(fetchHealth()).resolves.toBe('degraded')
})

it('reports unreachable instead of throwing when the network fails', async () => {
  server.use(http.get(url, () => HttpResponse.error()))

  await expect(fetchHealth()).resolves.toBe('unreachable')
})
