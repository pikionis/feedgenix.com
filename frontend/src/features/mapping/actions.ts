import { useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { feedQuery, feedsQuery } from '~/features/feeds/queries'
import { api } from '~/lib/api/client'
import { isValidationError, type Mapping } from '~/lib/api/types'
import { describeFailure } from '~/lib/forms'
import { mappingQuery } from './queries'

/** One sentence for a failed mapping call, in the server's words when it has them. */
export function mappingErrorMessage(status: number, body: unknown): string {
  if (isValidationError(body)) return Object.values(body.errors)[0]?.[0] ?? body.message
  if (typeof body === 'object' && body !== null && 'error' in body) {
    const error = body as { error: string; message?: string; needed_credits?: number; available_credits?: number }
    if (error.error === 'insufficient_credits') {
      return `A suggestion needs up to ${(error.needed_credits ?? 0).toLocaleString('en')} credits; your workspace has ${(error.available_credits ?? 0).toLocaleString('en')}. During the beta we add credits for you: write to us.`
    }
    if (error.error === 'mapping_changed')
      return 'Someone else changed this mapping meanwhile. It was reloaded: try again.'
    if (typeof error.message === 'string') return error.message
  }

  return describeFailure(status)
}

export interface FieldChange {
  target: string
  locale: string | null
  column: string | null
}

/** Edits, AI suggestions and approval, each refreshing the mapping (and the feed's stage) after. */
export function useMappingActions(feedId: number, mapping: Mapping) {
  const client = useQueryClient()
  const [busy, setBusy] = useState<string | null>(null)
  const [error, setError] = useState<string>()

  const refresh = async (data?: Mapping) => {
    if (data !== undefined) client.setQueryData(mappingQuery(feedId).queryKey, data)
    else await client.invalidateQueries({ queryKey: mappingQuery(feedId).queryKey, exact: true })
    await Promise.all([
      client.invalidateQueries({ queryKey: feedQuery(feedId).queryKey, exact: true }),
      client.invalidateQueries({ queryKey: feedsQuery.queryKey, exact: true }),
    ])
  }

  const run = async (
    key: string,
    call: () => Promise<{ data?: { mapping: Mapping }; error?: unknown; response: Response }>,
  ) => {
    setBusy(key)
    setError(undefined)
    const { data, error: body, response } = await call()
    setBusy(null)
    if (data === undefined) {
      setError(mappingErrorMessage(response.status, body))
      if (response.status === 409) await refresh()

      return false
    }
    await refresh(data.mapping)

    return true
  }

  return {
    busy,
    error,
    setField: (change: FieldChange) =>
      run(`field:${change.target}@${change.locale ?? ''}`, () =>
        api.PUT('/v1/feeds/{feed}/mapping/fields', {
          params: { path: { feed: feedId } },
          body: { version: mapping.version, target: change.target, locale: change.locale, column: change.column },
        }),
      ),
    setLanguage: (locale: string) =>
      run('language', () =>
        api.PATCH('/v1/feeds/{feed}/mapping', {
          params: { path: { feed: feedId } },
          body: { version: mapping.version, source_locale: locale },
        }),
      ),
    suggest: (instructions: string) =>
      run('suggest', () =>
        api.POST('/v1/feeds/{feed}/mapping/suggest', {
          params: { path: { feed: feedId } },
          body: { instructions: instructions.trim() === '' ? null : instructions.trim() },
        }),
      ),
    approve: () =>
      run('approve', () =>
        api.POST('/v1/feeds/{feed}/mapping/approve', {
          params: { path: { feed: feedId } },
          body: { version: mapping.version },
        }),
      ),
  }
}
