import { queryOptions } from '@tanstack/react-query'
import { isRunWorking, POLL_MS } from '~/features/feeds/queries'
import { api } from '~/lib/api/client'
import type { Feed, TranslationPlan } from '~/lib/api/types'

/** How often work that waits (for credits, the allowance or AI) is checked again. */
export const HELD_POLL_MS = 15_000

type Run = Pick<NonNullable<Feed['run']>, 'stage' | 'status'> | null | undefined

/** The plan can be started: nothing is being read or translated right now. */
export function canStart(run: Run): boolean {
  if (run === null || run === undefined) return false

  return (
    (run.stage === 'plan' && run.status === 'awaiting_decision') || run.status === 'done' || run.status === 'failed'
  )
}

export function pollInterval(run: Run): number | false {
  if (isRunWorking(run)) return POLL_MS
  if (run?.status === 'held') return HELD_POLL_MS

  return false
}

export function translationPlanQuery(feedId: number) {
  return queryOptions({
    queryKey: ['feeds', feedId, 'translation-plan'],
    queryFn: async (): Promise<TranslationPlan> => {
      const { data, response } = await api.GET('/v1/feeds/{feed}/translation-plan', {
        params: { path: { feed: feedId } },
      })
      if (data === undefined) throw new Error(`Could not load the translation plan (${response.status}).`)

      return data.translation_plan
    },
    refetchInterval: (query) => pollInterval(query.state.data?.run),
  })
}
