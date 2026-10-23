import { queryOptions } from '@tanstack/react-query'
import { api } from '~/lib/api/client'
import type { Feed, ImportStatus } from '~/lib/api/types'

/** How often a feed whose import is still running is checked again. */
export const POLL_MS = 1500

/** Still being fetched or read: the answer will change. */
export function isRunning(status: ImportStatus | undefined): boolean {
  return status === 'queued' || status === 'fetching' || status === 'reading'
}

/** The catalogue is being read, or texts are being translated: the feed's stages will move. */
export function isRunWorking(run: Pick<NonNullable<Feed['run']>, 'stage' | 'status'> | null | undefined): boolean {
  if (run === null || run === undefined) return false
  if (run.stage !== 'sync' && run.stage !== 'translate') return false

  return run.status === 'queued' || run.status === 'running'
}

/** Something about the feed is still moving: check it again soon. */
function isBusy(feed: Feed | undefined): boolean {
  return isRunning(feed?.latest_import?.status) || isRunWorking(feed?.run)
}

/** The feed or import does not exist in this workspace (or never did). */
export class NotFound extends Error {}

export const feedsQuery = queryOptions({
  queryKey: ['feeds'],
  queryFn: async () => {
    const { data, response } = await api.GET('/v1/feeds')
    if (data === undefined) throw new Error(`Could not load the feeds (${response.status}).`)

    return data.data
  },
  refetchInterval: (query) => (query.state.data?.some(isBusy) === true ? POLL_MS : false),
})

export function feedQuery(feedId: number) {
  return queryOptions({
    queryKey: ['feeds', feedId],
    queryFn: async () => {
      const { data, response } = await api.GET('/v1/feeds/{feed}', { params: { path: { feed: feedId } } })
      if (response.status === 404) throw new NotFound('This feed does not exist in your workspace.')
      if (data === undefined) throw new Error(`Could not load the feed (${response.status}).`)

      return data.feed
    },
    retry: (count, error) => !(error instanceof NotFound) && count < 1,
    refetchInterval: (query) => (isBusy(query.state.data) ? POLL_MS : false),
  })
}

export function feedImportsQuery(feedId: number) {
  return queryOptions({
    queryKey: ['feeds', feedId, 'imports'],
    queryFn: async () => {
      const { data, response } = await api.GET('/v1/feeds/{feed}/imports', { params: { path: { feed: feedId } } })
      if (data === undefined) throw new Error(`Could not load the imports (${response.status}).`)

      return data.data
    },
  })
}

/** A finished import never changes, so it is loaded once. Only ask for finished ones. */
export function importQuery(importId: number) {
  return queryOptions({
    queryKey: ['imports', importId],
    queryFn: async () => {
      const { data, response } = await api.GET('/v1/imports/{import}', { params: { path: { import: importId } } })
      if (response.status === 404) throw new NotFound('This import does not exist in your workspace.')
      if (data === undefined) throw new Error(`Could not load the import (${response.status}).`)

      return data.import
    },
    staleTime: Infinity,
  })
}
