/** Sanctum's CSRF protection for the same-origin SPA: the XSRF-TOKEN cookie, echoed as a header. */

export const CSRF_COOKIE = 'XSRF-TOKEN'

export function readCookie(name: string): string | null {
  if (typeof document === 'undefined') return null

  for (const part of document.cookie.split(';')) {
    const [key, ...value] = part.trim().split('=')
    if (key === name) return decodeURIComponent(value.join('='))
  }

  return null
}

/** Ask the API for a fresh CSRF cookie (Sanctum sets it on this response). */
export async function refreshCsrfCookie(origin: string): Promise<void> {
  await globalThis.fetch(`${origin}/sanctum/csrf-cookie`, { credentials: 'same-origin' })
}

export async function csrfToken(origin: string): Promise<string | null> {
  if (readCookie(CSRF_COOKIE) === null) await refreshCsrfCookie(origin)

  return readCookie(CSRF_COOKIE)
}
