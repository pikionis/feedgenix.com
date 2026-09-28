import { expect, it } from 'vitest'
import { nextPath } from './session'

it.each([
  ['/app/settings', '/app/settings'],
  ['/app/settings#plan', '/app/settings#plan'],
  [null, '/app'],
  ['//evil.example/app', '/app'],
  ['https://evil.example/app', '/app'],
  ['/login', '/app'],
  ['/appevil', '/app'],
  ['/app?tab=1', '/app?tab=1'],
  ['/app\\evil.example', '/app'],
  ['/admin', '/admin'],
  ['/admin?x=1', '/admin?x=1'],
  ['/adminx', '/app'],
])('sends %s to %s after sign-in, never outside the app', (next, expected) => {
  const search = new URLSearchParams(next === null ? '' : `next=${encodeURIComponent(next)}`)

  expect(nextPath(search)).toBe(expected)
})
