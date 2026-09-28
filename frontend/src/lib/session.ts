import { queryOptions, useQuery } from '@tanstack/react-query'
import { api } from '~/lib/api/client'
import type { SessionUser } from '~/lib/api/types'

/** The signed-in person, or null. A 401 is an answer here ("nobody"), not an error. */
export async function fetchSession(): Promise<SessionUser | null> {
  const { data, response } = await api.GET('/v1/auth/user')

  if (response.status === 401) return null
  if (!response.ok || data === undefined) throw new Error(`Could not load the session (${response.status}).`)

  return data.user
}

export const sessionQuery = queryOptions({
  queryKey: ['session'],
  queryFn: fetchSession,
  staleTime: 60_000,
})

export function useSession() {
  return useQuery(sessionQuery)
}

/** Where to go after signing in: a path inside the workspace from ?next=, or the workspace itself. */
export function nextPath(search: URLSearchParams): string {
  const next = search.get('next')
  const inside = next !== null && (next === '/app' || /^\/app[/?#]/.test(next))

  return inside ? next : '/app'
}
