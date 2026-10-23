import { queryOptions } from '@tanstack/react-query'
import { POLL_MS } from '~/features/feeds/queries'
import { api } from '~/lib/api/client'
import type { ExportProfile, ExportsResponse } from '~/lib/api/types'

/** A build is queued or running: the export will change soon. */
export function isBuilding(profile: ExportProfile): boolean {
  if (profile.latest?.status === 'building') return true

  // Created, its first build not started yet.
  return profile.status === 'active' && profile.latest === null
}

export function exportsQuery(feedId: number) {
  return queryOptions({
    queryKey: ['feeds', feedId, 'exports'],
    queryFn: async (): Promise<ExportsResponse> => {
      const { data, response } = await api.GET('/v1/feeds/{feed}/exports', { params: { path: { feed: feedId } } })
      if (data === undefined) throw new Error(`Could not load the exports (${response.status}).`)

      return data
    },
    refetchInterval: (query) => (query.state.data?.exports.some(isBuilding) === true ? POLL_MS : false),
  })
}
