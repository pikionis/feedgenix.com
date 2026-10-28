import type { ReactNode } from 'react'
import { cn } from '~/lib/utils'

/**
 * The next thing to do (the app kit's delta callout): a dashed indigo frame on the subtle surface,
 * one sentence and one action.
 */
export function Callout({
  children,
  action,
  className,
}: {
  children: ReactNode
  action?: ReactNode
  className?: string
}) {
  return (
    <div
      className={cn(
        'flex flex-wrap items-center gap-x-4 gap-y-3 rounded-lg border border-dashed border-primary/40 bg-subtle px-4 py-3.5',
        className,
      )}
    >
      <div className="min-w-0 flex-1 text-sm">{children}</div>
      {action}
    </div>
  )
}
