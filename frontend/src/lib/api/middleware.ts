import type { Middleware } from 'openapi-fetch'
import { limitHit, unauthenticated } from '~/lib/events'
import { csrfToken, refreshCsrfCookie } from './csrf'
import type { LimitHit } from './types'

const SAFE_METHODS = new Set(['GET', 'HEAD', 'OPTIONS'])

/**
 * Every call the SPA makes goes through here:
 * - state-changing requests carry the CSRF token (fetching the cookie first when needed);
 * - a 419 (expired CSRF token) refreshes the cookie and retries once, never more;
 * - a 401 tells the app the session is gone;
 * - a 402 opens the upgrade dialog with the server's own numbers and wording.
 */
export function sessionMiddleware(origin: string): Middleware {
  // A copy of each state-changing request, taken before its body is sent, so a 419 can retry it.
  const pending = new Map<string, Request>()

  return {
    async onRequest({ request, id }) {
      request.headers.set('Accept', 'application/json')
      request.headers.set('X-Requested-With', 'XMLHttpRequest')

      if (!SAFE_METHODS.has(request.method)) {
        const token = await csrfToken(origin)
        if (token !== null) request.headers.set('X-XSRF-TOKEN', token)
        pending.set(id, request.clone())
      }

      return request
    },

    async onResponse({ response, id }) {
      const original = pending.get(id)
      pending.delete(id)

      if (response.status === 419 && original !== undefined) {
        await refreshCsrfCookie(origin)
        const retry = new Request(original)
        const token = await csrfToken(origin)
        if (token !== null) retry.headers.set('X-XSRF-TOKEN', token)

        return globalThis.fetch(retry)
      }

      if (response.status === 401) unauthenticated.emit()

      if (response.status === 402) {
        const body: unknown = await response
          .clone()
          .json()
          .catch(() => null)
        if (isLimitHit(body)) limitHit.emit(body)
      }

      return response
    },

    onError({ id }) {
      pending.delete(id)
    },
  }
}

function isLimitHit(body: unknown): body is LimitHit {
  return typeof body === 'object' && body !== null && 'error' in body && 'limit' in body && 'message' in body
}
