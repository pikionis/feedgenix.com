import { useQueryClient } from '@tanstack/react-query'
import { useState } from 'react'
import { feedQuery, feedsQuery } from '~/features/feeds/queries'
import { api } from '~/lib/api/client'
import { isValidationError, type TranslationMode, type TranslationPlan } from '~/lib/api/types'
import { describeFailure } from '~/lib/forms'
import { translationPlanQuery } from './queries'

/** One sentence for a failed plan call, in the server's words when it has them. */
export function planErrorMessage(status: number, body: unknown): string {
  if (isValidationError(body)) return Object.values(body.errors)[0]?.[0] ?? body.message
  if (typeof body === 'object' && body !== null && 'error' in body) {
    const error = body as { error: string; message?: string }
    if (error.error === 'plan_changed') return 'Someone else changed this plan meanwhile. It was reloaded: try again.'
    if (typeof error.message === 'string') return error.message
  }

  return describeFailure(status)
}

/** Saving the choices and starting, each refreshing the plan (and the feed's stages) after. */
export function useTranslationActions(feedId: number, plan: TranslationPlan) {
  const client = useQueryClient()
  const [busy, setBusy] = useState<string | null>(null)
  const [error, setError] = useState<string>()

  const refresh = async (data?: TranslationPlan) => {
    if (data !== undefined) client.setQueryData(translationPlanQuery(feedId).queryKey, data)
    else await client.invalidateQueries({ queryKey: translationPlanQuery(feedId).queryKey, exact: true })
    await Promise.all([
      client.invalidateQueries({ queryKey: feedQuery(feedId).queryKey, exact: true }),
      client.invalidateQueries({ queryKey: feedsQuery.queryKey, exact: true }),
    ])
  }

  const run = async (
    key: string,
    call: () => Promise<{ data?: { translation_plan: TranslationPlan }; error?: unknown; response: Response }>,
  ) => {
    setBusy(key)
    setError(undefined)
    const { data, error: body, response } = await call()
    setBusy(null)
    if (data === undefined) {
      setError(planErrorMessage(response.status, body))
      if (response.status === 409) await refresh()

      return false
    }
    await refresh(data.translation_plan)

    return true
  }

  const save = (key: string, fields: Record<string, TranslationMode>, targets: string[], auto?: boolean) =>
    run(key, () =>
      api.PUT('/v1/feeds/{feed}/translation-plan', {
        params: { path: { feed: feedId } },
        body: {
          version: plan.version,
          fields,
          target_locales: targets,
          ...(auto === undefined ? {} : { auto_translate: auto }),
        },
      }),
    )
  const modes = Object.fromEntries(plan.fields.map((field) => [field.field, field.mode]))

  return {
    busy,
    error,
    setMode: (field: string, mode: TranslationMode) =>
      save(`field:${field}`, { ...modes, [field]: mode }, plan.target_locales),
    setTarget: (code: string, on: boolean) =>
      save(
        `locale:${code}`,
        modes,
        on ? [...plan.target_locales, code] : plan.target_locales.filter((locale) => locale !== code),
      ),
    setAuto: (on: boolean) => save('auto', modes, plan.target_locales, on),
    start: () =>
      run('start', () =>
        api.POST('/v1/feeds/{feed}/translation-plan/start', {
          params: { path: { feed: feedId } },
          body: { version: plan.version },
        }),
      ),
    readAgain: () =>
      run('read', () => api.POST('/v1/feeds/{feed}/catalog/sync', { params: { path: { feed: feedId } } })),
  }
}
