import type { ImportStatus } from '~/lib/api/types'
import { cn } from '~/lib/utils'
import { STATUS, type StatusTone } from './labels'

// The dot carries the colour; the words stay in the text colour, so they read at any size.
const TONES: Record<StatusTone, { badge: string; dot: string }> = {
  queued: { badge: 'bg-queued-bg', dot: 'bg-queued' },
  running: { badge: 'bg-primary/10', dot: 'bg-primary animate-pulse' },
  success: { badge: 'bg-success-bg', dot: 'bg-success' },
  error: { badge: 'bg-error-bg', dot: 'bg-error' },
}

export function StatusBadge({ status, className }: { status: ImportStatus; className?: string }) {
  const { label, tone } = STATUS[status]

  return (
    <span
      className={cn(
        'inline-flex items-center gap-1.5 rounded-full px-2.5 py-0.5 text-xs font-medium text-foreground',
        TONES[tone].badge,
        className,
      )}
    >
      <span className={cn('size-1.5 rounded-full', TONES[tone].dot)} aria-hidden="true" />
      {label}
    </span>
  )
}
