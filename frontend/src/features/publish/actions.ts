import { useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { feedQuery, feedsQuery } from '~/features/feeds/queries'
import { api } from '~/lib/api/client'
import { isValidationError, type ExportProfile } from '~/lib/api/types'
import { describeFailure } from '~/lib/forms'
import { exportsQuery } from './queries'

/** One sentence for a failed export call, in the server's words when it has them. */
export function exportErrorMessage(status: number, body: unknown): string {
  if (isValidationError(body)) return Object.values(body.errors)[0]?.[0] ?? body.message
  if (typeof body === 'object' && body !== null && 'error' in body) {
    const error = body as { error: string; message?: string }
    if (error.error === 'export_changed')
      return 'Someone else changed this export meanwhile. It was reloaded: try again.'
    if (typeof error.message === 'string') return error.message
  }

  return describeFailure(status)
}

export interface NewExport {
  name: string
  preset: string
  dialect: 'csv' | 'tsv'
  layout: 'wide' | 'per_language'
  locales: string[]
}

export type ExportChange = Partial<Pick<ExportProfile, 'name' | 'dialect' | 'layout' | 'locales' | 'status'>>

type Answer = Promise<{ data?: unknown; error?: unknown; response: Response }>

const path = (profile: ExportProfile) => ({ path: { export: profile.id } })

/** Creating, changing and building exports; each refreshes the list (and the feed's stages) after. */
export function useExportActions(feedId: number) {
  const client = useQueryClient()
  const [busy, setBusy] = useState<string | null>(null)
  const [errors, setErrors] = useState<Record<string, string>>({})

  const run = async (key: string, call: () => Answer) => {
    setBusy(key)
    setErrors((current) => Object.fromEntries(Object.entries(current).filter(([name]) => name !== key)))
    const { error, response } = await call()
    setBusy(null)
    if (!response.ok) setErrors((current) => ({ ...current, [key]: exportErrorMessage(response.status, error) }))
    await Promise.all([
      client.invalidateQueries({ queryKey: exportsQuery(feedId).queryKey, exact: true }),
      client.invalidateQueries({ queryKey: feedQuery(feedId).queryKey, exact: true }),
      client.invalidateQueries({ queryKey: feedsQuery.queryKey, exact: true }),
    ])

    return response.ok
  }

  return {
    busy,
    errors,
    create: (body: NewExport) =>
      run('new', () => api.POST('/v1/feeds/{feed}/exports', { params: { path: { feed: feedId } }, body })),
    update: (profile: ExportProfile, changes: ExportChange) =>
      run(`change:${profile.id}`, () =>
        api.PATCH('/v1/exports/{export}', { params: path(profile), body: { version: profile.version, ...changes } }),
      ),
    build: (profile: ExportProfile, allowShrink = false) =>
      run(`build:${profile.id}`, () =>
        api.POST('/v1/exports/{export}/build', {
          params: path(profile),
          body: allowShrink ? { allow_shrink: true } : {},
        }),
      ),
    rotate: (profile: ExportProfile) =>
      run(`rotate:${profile.id}`, () => api.POST('/v1/exports/{export}/token', { params: path(profile) })),
    publishEarlier: (profile: ExportProfile, artifactId: number) =>
      run(`earlier:${profile.id}`, () =>
        api.POST('/v1/exports/{export}/builds/{artifact}/publish', {
          params: { path: { export: profile.id, artifact: artifactId } },
        }),
      ),
    remove: (profile: ExportProfile) =>
      run(`remove:${profile.id}`, () => api.DELETE('/v1/exports/{export}', { params: path(profile) })),
  }
}

export type ExportActions = ReturnType<typeof useExportActions>
