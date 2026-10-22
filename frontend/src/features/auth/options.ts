import { queryOptions, useQuery } from '@tanstack/react-query'
import { api } from '~/lib/api/client'
import type { RegistrationMode } from '~/lib/api/types'

/** What the sign-in and sign-up pages offer: Google, and who may sign up. */
export const authOptionsQuery = queryOptions({
  queryKey: ['auth-options'],
  queryFn: async () => {
    const { data, response } = await api.GET('/auth/options')
    if (data === undefined) throw new Error(`Could not load the sign-in options (${response.status}).`)

    return data
  },
  staleTime: 10 * 60_000,
})

/** True only once the server says it has Google credentials: no button that ends on Google's error page. */
export function useGoogleSignIn(): boolean {
  return useQuery(authOptionsQuery).data?.google === true
}

/**
 * Who may sign up, once the server has said so. If it cannot be asked, assume the server's own
 * default (invite): an invite field nobody needs is harmless, a missing one would lock invitees out.
 */
export function useRegistrationMode(): RegistrationMode | undefined {
  const options = useQuery(authOptionsQuery)
  if (options.isError) return 'invite'

  return options.data?.registration
}
