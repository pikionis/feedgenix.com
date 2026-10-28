import type { ReactNode } from 'react'
import { cn } from '~/lib/utils'
import { BlockDot, type BlockTone } from './mosaic'

/** A number that matters (the app kit's stat card): Mist, 10px corners, no border. */
export function StatCard({
  label,
  value,
  detail,
  tone,
  className,
}: {
  label: string
  value: ReactNode
  detail?: ReactNode
  /** A block in the corner (the mosaic): each card of a row in its own colour. */
  tone?: BlockTone
  className?: string
}) {
  return (
    <div className={cn('relative flex min-w-0 flex-col rounded-lg bg-surface px-4 py-3.5', className)}>
      {tone !== undefined && <BlockDot tone={tone} className="absolute top-3.5 right-4 h-2.5 w-5" />}
      <span className="pr-7 text-xs text-muted-foreground">{label}</span>
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
