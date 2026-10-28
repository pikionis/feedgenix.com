import { Sparkles } from 'lucide-react'
import { useState } from 'react'
import { Button } from '~/components/ui/button'
import { Notice } from '~/components/ui/notice'
import type { Mapping } from '~/lib/api/types'
import { cn } from '~/lib/utils'
import { unavailableMessage } from './labels'
import { isSuggesting } from './queries'

/**
 * Ask the AI to read the columns and suggest the rest, optionally with corrections in words. It
 * shows the most it can cost, in credits, before anything is spent; what a person set is never
 * overwritten.
 */
export function AiPanel({
  mapping,
  busy,
  onSuggest,
}: {
  mapping: Mapping
  busy: boolean
  onSuggest: (instructions: string) => void
}) {
  const [instructions, setInstructions] = useState('')
  const { ai } = mapping
  const running = isSuggesting(mapping)

  return (
    <div className="flex flex-col gap-4 rounded-lg border border-dashed border-primary/40 bg-subtle px-5 py-4">
      <div className="flex items-start gap-3">
        <Sparkles size={18} strokeWidth={1.5} className="mt-0.5 shrink-0 text-primary" aria-hidden="true" />
        <div className="flex min-w-0 flex-col gap-1">
          <h2 className="text-[15px] font-medium">Suggest with AI</h2>
          <p className="text-sm text-muted-foreground">
            The AI reads your column names and a few example values (never prices, stock, ids or images) and suggests
            the fields that are still empty. What you set yourself is never changed.
          </p>
        </div>
      </div>

      {running && (
        <p className="flex items-center gap-2 text-sm" aria-live="polite">
          <span className="size-1.5 animate-pulse rounded-full bg-primary" aria-hidden="true" />
          The AI is reading your columns…
        </p>
      )}
      {ai.status === 'failed' && (
        <Notice tone="warn">
          The last suggestion could not finish ({ai.failure ?? 'unknown reason'}). Nothing changed.
        </Notice>
      )}

      {ai.available && !running && (
        <div className="flex flex-col gap-3">
          <label className="flex flex-col gap-1.5 text-sm">
            <span>Anything the AI should know? (optional)</span>
            <textarea
              value={instructions}
              maxLength={2000}
              rows={2}
              placeholder="e.g. “Grösse” is a shoe size; “Text 2” is the short description."
              onChange={(event) => setInstructions(event.target.value)}
              className={cn(
                'w-full rounded-md border-[0.5px] border-hairline bg-background px-3 py-2 text-sm',
                'placeholder:text-faint focus-visible:border-primary focus-visible:ring-1 focus-visible:ring-primary focus-visible:outline-none',
              )}
            />
          </label>
          <div className="flex flex-wrap items-center gap-3">
            <Button disabled={busy} onClick={() => onSuggest(instructions)}>
              {busy ? 'Asking…' : 'Suggest with AI'}
            </Button>
            {ai.estimate_credits !== null && (
              <span className="text-sm text-muted-foreground">
                Up to {ai.estimate_credits.toLocaleString('en')} credits; usually far fewer are used.
              </span>
            )}
          </div>
        </div>
      )}
      {!ai.available && !running && (
        <p className="text-sm text-muted-foreground">{unavailableMessage(ai.unavailable_reason)}</p>
      )}
    </div>
  )
}
