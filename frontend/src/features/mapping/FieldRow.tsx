import type { CanonicalField, MappingColumn, MappingField } from '~/lib/api/types'
import { cn } from '~/lib/utils'
import { FIELD_STATUS, ORIGIN, languageName, type FieldTone } from './labels'

const TONES: Record<FieldTone, { badge: string; dot: string }> = {
  success: { badge: 'bg-success-bg', dot: 'bg-success' },
  neutral: { badge: 'bg-surface', dot: 'bg-faint' },
  warn: { badge: 'bg-warn-bg', dot: 'bg-warn' },
  info: { badge: 'bg-primary/10', dot: 'bg-primary' },
  error: { badge: 'bg-error-bg', dot: 'bg-error' },
}

const EMPTY = ''

/**
 * One canonical field (in one language): where its value comes from, a column picker, the status
 * with the reason, and sample values. A protected column (prices, stock, ids, images, weights) is
 * offered only to the fields that may hold it.
 */
export function FieldRow({
  field,
  decision,
  locale,
  columns,
  canEdit,
  busy,
  onChange,
}: {
  field: CanonicalField
  decision: MappingField | undefined
  locale: string | null
  columns: MappingColumn[]
  canEdit: boolean
  busy: boolean
  onChange: (column: string | null) => void
}) {
  const status = FIELD_STATUS[decision?.status ?? 'unmapped']
  const takesProtected = field.class === 'golden' || field.class === 'pass_through'
  const label = locale === null ? field.label : `${field.label} (${languageName(locale)})`
  const selectId = `field-${field.key}-${locale ?? 'all'}`
  const composed = decision?.strategy === 'compose' ? decision.recipe : null
  const problems = (decision?.issues ?? []).filter((issue) => issue.severity !== 'info')

  return (
    <li className="grid gap-x-6 gap-y-2 py-4 sm:grid-cols-[minmax(0,14rem)_minmax(0,1fr)_auto]">
      <div className="min-w-0">
        <label htmlFor={selectId} className="text-sm font-medium">
          {label}
        </label>
        <p className="text-xs text-muted-foreground">{field.description}</p>
      </div>

      <div className="flex min-w-0 flex-col gap-2">
        {composed !== null ? (
          <div className="flex flex-wrap items-center gap-2">
            <span className="text-sm">Composed from {composed.sources.map((source) => source.column).join(', ')}</span>
            {canEdit && (
              <button
                type="button"
                className="text-sm text-primary hover:text-primary-hover disabled:opacity-50"
                disabled={busy}
                onClick={() => onChange(null)}
              >
                Clear
              </button>
            )}
          </div>
        ) : (
          <select
            id={selectId}
            className={cn(
              'h-9 w-full max-w-sm rounded-md border-[0.5px] border-hairline bg-background px-2 text-sm',
              'focus-visible:border-primary focus-visible:ring-1 focus-visible:ring-primary focus-visible:outline-none',
              'disabled:cursor-not-allowed disabled:opacity-60',
            )}
            value={decision?.strategy === 'direct' ? (decision.source_column ?? EMPTY) : EMPTY}
            disabled={!canEdit || busy}
            onChange={(event) => onChange(event.target.value === EMPTY ? null : event.target.value)}
          >
            <option value={EMPTY}>Leave empty</option>
            {columns.map((column) => (
              <option key={column.position} value={column.name} disabled={column.protected && !takesProtected}>
                {column.name}
                {column.protected && !takesProtected ? ' (prices, ids or images: not for text)' : ''}
              </option>
            ))}
          </select>
        )}
        {decision?.strategy === 'none' && decision.candidate_column !== null && (
          <p className="text-xs text-muted-foreground">Suggested: {decision.candidate_column}</p>
        )}
        {problems.map((issue) => (
          <p key={issue.type} className={cn('text-xs', issue.severity === 'error' ? 'text-error' : 'text-warn')}>
            {issue.message}
          </p>
        ))}
        {decision?.samples !== undefined && decision.samples.length > 0 && (
          <p className="truncate text-xs text-faint" title={decision.samples.join(' · ')}>
            e.g. {decision.samples.slice(0, 3).join(' · ')}
          </p>
        )}
      </div>

      <div className="flex flex-col items-start gap-1 sm:items-end">
        <span
          className={cn(
            'inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-medium text-foreground',
            TONES[status.tone].badge,
          )}
        >
          <span className={cn('size-1.5 rounded-full', TONES[status.tone].dot)} aria-hidden="true" />
          {status.label}
        </span>
        {decision !== undefined && <span className="text-xs text-faint">{ORIGIN[decision.origin]}</span>}
      </div>
    </li>
  )
}
