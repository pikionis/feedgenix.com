import { languageName } from '~/features/mapping/labels'
import type { PipelineRun, TranslationMode, TranslationPlan } from '~/lib/api/types'

/** What each mode does to a field in the other languages. */
export const MODES: Record<TranslationMode, { label: string; note: string }> = {
  translate: { label: 'Translate', note: 'Translated with AI, with quality checks' },
  keep: { label: 'Keep as is', note: 'The same text in every language, at no cost' },
  skip: { label: 'Leave out', note: 'Not filled in the other languages' },
}

const UNAVAILABLE: Record<string, string> = {
  ai_disabled:
    'AI is not switched on yet, so there is no estimate. You can start: the texts wait and are translated once it is.',
  unpriced_model:
    'There is no estimate right now. You can start: the texts wait and are translated once AI is available.',
  provider_unavailable:
    'There is no estimate right now. You can start: the texts wait and are translated once AI is available.',
}

export function unavailableMessage(reason: string | null): string {
  return (reason !== null ? UNAVAILABLE[reason] : undefined) ?? 'There is no estimate right now.'
}

/** A language's name as the workspace knows it, or the browser's name for the code. */
export function localeName(plan: TranslationPlan, code: string): string {
  return plan.workspace_locales.find((locale) => locale.code === code)?.name ?? languageName(code)
}

/** Where the run stands, in a word or two. */
export function runLabel(run: PipelineRun | null): string {
  if (run === null) return 'Not started'
  if (run.status === 'failed') return 'Failed'
  if (run.stage === 'sync') return 'Reading the products'
  if (run.stage === 'plan') return 'Ready to start'
  if (run.status === 'held') return 'Waiting'
  if (run.stage === 'translate') return 'Translating'

  return 'Done'
}

/** "1 text" / "1,200 texts". */
export function texts(count: number): string {
  return `${count.toLocaleString('en')} ${count === 1 ? 'text' : 'texts'}`
}
