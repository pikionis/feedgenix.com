import type { ReactNode } from 'react'
import { cn } from '~/lib/utils'

const tones = {
  info: 'bg-surface text-foreground',
  success: 'bg-success-bg text-foreground',
  warn: 'bg-warn-bg text-foreground',
  error: 'bg-error-bg text-foreground',
} as const

/** An inline message. Errors are announced to screen readers. */
export function Notice({
  tone = 'info',
  children,
  className,
}: {
  tone?: keyof typeof tones
  children: ReactNode
  className?: string
}) {
  return (
    <div
      role={tone === 'error' ? 'alert' : 'status'}
      className={cn('rounded-md px-3 py-2 text-sm', tones[tone], className)}
    >
      {children}
    </div>
  )
}
