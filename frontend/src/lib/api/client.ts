import createClient from 'openapi-fetch'
import { sessionMiddleware } from './middleware'
import type { paths } from './schema'

/**
 * The one typed client for the FeedGenix API.
 *
 * It runs on the same origin as the SPA (cookie session, no CORS). The types are generated from the
 * backend's OpenAPI document (`npm run api:types`) and CI fails when they drift. The base URL is
 * absolute so it also resolves in tests and during build-time pre-rendering (which makes no calls).
 */
const origin = typeof window === 'undefined' ? 'http://localhost' : window.location.origin

export const api = createClient<paths>({
  baseUrl: `${origin}/api`,
  credentials: 'same-origin',
  // Look fetch up on every call. openapi-fetch otherwise keeps the fetch that existed when this
  // module loaded, which bypasses anything installed later (MSW in tests would be skipped and the
  // request would go to the real network).
  fetch: (request) => globalThis.fetch(request),
})

api.use(sessionMiddleware(origin))
