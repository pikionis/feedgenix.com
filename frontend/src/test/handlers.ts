import { http, HttpResponse } from 'msw'
import { CSRF_COOKIE } from '~/lib/api/csrf'
import type { AuthOptions } from '~/lib/api/types'
import { origin } from './fixtures'

/** Sanctum's CSRF endpoint: sets the cookie like the real one would. `onHit` counts calls. */
export function csrfCookie(token = 'test-token', onHit?: () => void) {
  return http.get(`${origin}/sanctum/csrf-cookie`, () => {
    onHit?.()
    document.cookie = `${CSRF_COOKIE}=${encodeURIComponent(token)}; path=/`

    return new HttpResponse(null, { status: 204 })
  })
}

/** What the sign-in pages are offered. Defaults (server.ts): no Google, invite-only sign-up. */
export function authOptions({ google = false, registration = 'invite' }: Partial<AuthOptions> = {}) {
  return http.get(`${origin}/api/auth/options`, () => HttpResponse.json({ google, registration }))
}

export function clearCookies(): void {
  for (const part of document.cookie.split(';')) {
    const name = part.split('=')[0]?.trim()
    if (name) document.cookie = `${name}=; expires=Thu, 01 Jan 1970 00:00:00 GMT; path=/`
  }
}
