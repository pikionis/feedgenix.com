import '@testing-library/jest-dom/vitest'
import { cleanup } from '@testing-library/react'
import { afterAll, afterEach, beforeAll } from 'vitest'
import { queryClient } from '~/lib/query'
import { clearCookies } from './handlers'
import { server } from './server'

// Like Http::preventStrayRequests() on the backend: an un-mocked request fails the test.
beforeAll(() => server.listen({ onUnhandledFrame: 'error' }))

afterEach(() => {
  server.resetHandlers()
  cleanup()
  // The shared client is used by route loaders: nothing of one test may reach the next.
  queryClient.clear()
  clearCookies()
})

afterAll(() => server.close())
