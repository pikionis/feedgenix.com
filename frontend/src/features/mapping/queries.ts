import { queryOptions } from '@tanstack/react-query'
import { POLL_MS } from '~/features/feeds/queries'
import { api } from '~/lib/api/client'
import type { Mapping } from '~/lib/api/types'

/** The feed has no profiled import yet: there are no columns to map. */
export class NoProfile extends Error {}

/** An AI suggestion is queued or running: the answer will change. */
export function isSuggesting(mapping: Mapping | undefined): boolean {
  return mapping?.ai.status === 'queued' || mapping?.ai.status === 'running'
}

export function mappingQuery(feedId: number) {
  return queryOptions({
    queryKey: ['feeds', feedId, 'mapping'],
    queryFn: async () => {
      const { data, response } = await api.GET('/v1/feeds/{feed}/mapping', { params: { path: { feed: feedId } } })
      if (response.status === 404) throw new NoProfile('Import the feed first: the mapping is built from its columns.')
      if (data === undefined) throw new Error(`Could not load the mapping (${response.status}).`)

      return data.mapping
    },
    retry: (count, error) => !(error instanceof NoProfile) && count < 1,
    refetchInterval: (query) => (isSuggesting(query.state.data) ? POLL_MS : false),
  })
}
