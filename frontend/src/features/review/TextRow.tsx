import { useState } from 'react'
import { Button } from '~/components/ui/button'
import { Notice } from '~/components/ui/notice'
import { languageName } from '~/features/mapping/labels'
import type { ReviewText } from '~/lib/api/types'
import { cn } from '~/lib/utils'
import { canApprove, FAILURES, textState, type TextTone } from './labels'

// The mapping's badges: a tinted pill with a dot, the words in the body colour.
const TONES: Record<TextTone, { badge: string; dot: string }> = {
  success: { badge: 'bg-success-bg', dot: 'bg-success' },
  neutral: { badge: 'bg-surface', dot: 'bg-faint' },
  warn: { badge: 'bg-warn-bg', dot: 'bg-warn' },
  error: { badge: 'bg-error-bg', dot: 'bg-error' },
}

/**
 * One text of a product: the source it is translated from, the translation, what the checks
 * found, and (for owners and admins) writing it yourself, approving the AI's or handing it back.
 */
export function TextRow({
  text,
  canEdit,
  busy,
  error,
  onWrite,
  onApprove,
  onReset,
}: {
  text: ReviewText
  canEdit: boolean
  busy: boolean
  error: string | undefined
  onWrite: (value: string) => Promise<boolean>
  onApprove: () => void
  onReset: () => void
}) {
  const [draft, setDraft] = useState<string | null>(null)
  const state = textState(text)
  const target = text.target
  const editable = canEdit && !text.provided && text.source !== null

  return (
    <li className="flex flex-col gap-2 py-3">
      <div className="flex flex-wrap items-center justify-between gap-2">
        <p className="text-sm font-medium">{text.label}</p>
        <span
          className={cn(
            'inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-medium text-foreground',
            TONES[state.tone].badge,
          )}
        >
          <span className={cn('size-1.5 rounded-full', TONES[state.tone].dot)} aria-hidden="true" />
          {state.label}
        </span>
      </div>
      {text.source !== null && !text.provided && (
        <p className="max-h-24 overflow-y-auto text-xs whitespace-pre-wrap text-muted-foreground">
          <span className="text-faint">{languageName(text.source.locale)}: </span>
          {text.source.value}
        </p>
      )}

      {draft === null ? (
        <p className={cn('max-h-40 overflow-y-auto text-sm whitespace-pre-wrap', target === null && 'text-faint')}>
          {target?.value ?? (text.provided ? '' : 'Translated at the next run.')}
        </p>
      ) : (
        <textarea
          aria-label={`${text.label}: your translation`}
          value={draft}
          rows={Math.min(10, Math.max(2, Math.ceil(draft.length / 80)))}
          maxLength={20000}
          onChange={(event) => setDraft(event.target.value)}
          className="w-full rounded-md border-[0.5px] border-hairline bg-background px-3 py-2 text-sm"
        />
      )}

      {target !== null && target.failures.length > 0 && !target.stale && (
        <ul className="flex flex-col gap-0.5 text-xs text-muted-foreground">
          {target.failures.map((failure) => (
            <li key={failure}>· {FAILURES[failure]}</li>
          ))}
        </ul>
      )}
      {error !== undefined && <Notice tone="error">{error}</Notice>}

      {editable && (
        <div className="flex flex-wrap gap-2">
          {draft === null ? (
            <>
              <Button size="sm" variant="secondary" disabled={busy} onClick={() => setDraft(target?.value ?? '')}>
                {target === null ? 'Translate it yourself' : 'Edit'}
              </Button>
              {canApprove(text) && (
                <Button size="sm" variant="secondary" disabled={busy} onClick={onApprove}>
                  Approve as it is
                </Button>
              )}
              {target !== null && target.origin !== 'source' && (
                <Button size="sm" variant="ghost" disabled={busy} onClick={onReset}>
                  Let the AI translate it again
                </Button>
              )}
            </>
          ) : (
            <>
              <Button
                size="sm"
                disabled={busy || draft.trim() === ''}
                onClick={() => void onWrite(draft).then((saved) => saved && setDraft(null))}
              >
                {busy ? 'Saving…' : 'Save'}
              </Button>
              <Button size="sm" variant="ghost" disabled={busy} onClick={() => setDraft(null)}>
                Cancel
              </Button>
            </>
          )}
        </div>
      )}
    </li>
  )
}
