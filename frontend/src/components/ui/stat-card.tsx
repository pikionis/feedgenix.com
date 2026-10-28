import type { ReactNode } from 'react'
import { cn } from '~/lib/utils'

/** A number that matters (the app kit's stat card): Mist, 10px corners, no border. */
export function StatCard({
  label,
  value,
  detail,
  className,
}: {
  label: string
  value: ReactNode
  detail?: ReactNode
  className?: string
}) {
  return (
    <div className={cn('flex min-w-0 flex-col rounded-lg bg-surface px-4 py-3.5', className)}>
      <span className="text-xs text-muted-foreground">{label}</span>
      <span className="my-1.5 text-[26px] leading-none font-medium tracking-[-0.01em]">{value}</span>
      {detail !== undefined && <span className="text-xs text-muted-foreground">{detail}</span>}
    </div>
  )
}

/** A small pill under a stat: emerald for good news, neutral otherwise. */
export function Delta({ children, tone = 'neutral' }: { children: ReactNode; tone?: 'good' | 'neutral' }) {
  return (
    <span
      className={cn(
        'inline-flex items-center gap-1 self-start rounded-full px-2 py-0.5 text-[11px] font-medium',
        tone === 'good' ? 'bg-success-bg text-accent-hover' : 'bg-queued-bg text-muted-foreground',
      )}
    >
      {children}
    </span>
  )
}
