import { http, HttpResponse } from 'msw'
import { describe, expect, it, vi } from 'vitest'
import { limitHit, unauthenticated } from '~/lib/events'
import { apiUrl, languageLimit, sessionUser } from '~/test/fixtures'
import { csrfCookie } from '~/test/handlers'
import { server } from '~/test/server'
import { api } from './client'

describe('CSRF', () => {
  it('sends the token on state-changing calls, fetching the cookie only once', async () => {
    let cookieFetches = 0
    const tokens: (string | null)[] = []
    server.use(
      csrfCookie('abc=', () => cookieFetches++),
      http.post(apiUrl('/auth/login'), ({ request }) => {
        tokens.push(request.headers.get('X-XSRF-TOKEN'))
        return HttpResponse.json({ user: sessionUser() })
      }),
    )

    await api.POST('/auth/login', { body: { email: 'a@example.com', password: 'x' } })
    await api.POST('/auth/login', { body: { email: 'a@example.com', password: 'x' } })

    expect(cookieFetches).toBe(1)
    expect(tokens).toEqual(['abc=', 'abc='])
  })

  it('never asks for a CSRF cookie to read', async () => {
    server.use(http.get(apiUrl('/v1/auth/user'), () => HttpResponse.json({ user: sessionUser() })))

    const { response } = await api.GET('/v1/auth/user')

    // No csrf handler is registered: a cookie request would have failed the test.
    expect(response.status).toBe(200)
  })
})

describe('an expired CSRF token (419)', () => {
  it('refreshes the cookie and retries once with the same body', async () => {
    const bodies: unknown[] = []
    let refreshes = 0
    server.use(
      csrfCookie('fresh', () => refreshes++),
      http.put(apiUrl('/v1/profile'), async ({ request }) => {
        bodies.push(await request.json())
        return bodies.length === 1
          ? new HttpResponse(null, { status: 419 })
          : HttpResponse.json({ user: sessionUser({ name: 'Renamed' }) })
      }),
    )

    const { data, response } = await api.PUT('/v1/profile', { body: { name: 'Renamed' } })

    expect(response.status).toBe(200)
    expect(data?.user.name).toBe('Renamed')
    expect(bodies).toEqual([{ name: 'Renamed' }, { name: 'Renamed' }])
    expect(refreshes).toBe(2) // the first token, then the refresh
  })

  it('gives up after one retry instead of looping', async () => {
    let attempts = 0
    server.use(
      csrfCookie(),
      http.put(apiUrl('/v1/profile'), () => {
        attempts++
        return new HttpResponse(null, { status: 419 })
      }),
    )

    const { response } = await api.PUT('/v1/profile', { body: { name: 'x' } })

    expect(response.status).toBe(419)
    expect(attempts).toBe(2)
  })
})

describe('what the app is told', () => {
  it('announces a lost session on 401', async () => {
    const listener = vi.fn()
    const off = unauthenticated.on(listener)
    server.use(
      http.get(apiUrl('/v1/auth/user'), () => HttpResponse.json({ message: 'Unauthenticated.' }, { status: 401 })),
    )

    await api.GET('/v1/auth/user')
    off()

    expect(listener).toHaveBeenCalledOnce()
  })

  it('hands a 402 plan limit to the upgrade dialog, with the server’s numbers', async () => {
    const listener = vi.fn()
    const off = limitHit.on(listener)
    server.use(
      csrfCookie(),
      http.put(apiUrl('/v1/workspace/languages'), () => HttpResponse.json(languageLimit, { status: 402 })),
    )

    await api.PUT('/v1/workspace/languages', { body: { languages: ['de', 'fr'] } })
    off()

    expect(listener).toHaveBeenCalledWith(languageLimit)
  })

  it('ignores a 402 whose body is not the plan-limit contract', async () => {
    const listener = vi.fn()
    const off = limitHit.on(listener)
    server.use(
      csrfCookie(),
      http.put(apiUrl('/v1/workspace/languages'), () => HttpResponse.text('nope', { status: 402 })),
    )

    await api.PUT('/v1/workspace/languages', { body: { languages: [] } })
    off()

    expect(listener).not.toHaveBeenCalled()
  })
})
