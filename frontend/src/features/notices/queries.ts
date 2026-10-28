import { queryOptions } from '@tanstack/react-query'
import { api } from '~/lib/api/client'
import type { NoticesResponse } from '~/lib/api/types'

/** How often the unread count is checked while the app is open. */
export const NOTICES_POLL_MS = 60_000

export const noticesQuery = queryOptions({
  queryKey: ['notices'],
  queryFn: async (): Promise<NoticesResponse> => {
    const { data, response } = await api.GET('/v1/notices')
    if (data === undefined) throw new Error(`Could not load the notices (${response.status}).`)

    return data
  },
  refetchInterval: NOTICES_POLL_MS,
})
