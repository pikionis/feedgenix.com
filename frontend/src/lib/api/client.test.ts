import { expect, it, vi } from 'vitest'
import { server } from '~/test/server'
import { api } from './client'

it('sends every call through the mock network, so a forgotten mock can never reach a real API', async () => {
  vi.spyOn(console, 'error').mockImplementation(() => {})
  const unhandled: string[] = []
  const listener = ({ request }: { request: Request }) => {
    unhandled.push(request.url)
  }
  server.events.on('request:unhandled', listener)

  try {
    await expect(api.GET('/health')).rejects.toThrow()
  } finally {
    server.events.removeListener('request:unhandled', listener)
  }

  // MSW saw the call. A client holding a stale fetch would bypass it and hit the network instead.
  expect(unhandled).toEqual([`${window.location.origin}/api/health`])
})
