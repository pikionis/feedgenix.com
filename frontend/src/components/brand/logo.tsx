import { cn } from '~/lib/utils'

/** The FeedGenix mark (three indigo bars and the emerald spark) with the wordmark. */
export function Logo({ className, inverse = false }: { className?: string; inverse?: boolean }) {
  const bars = inverse ? '#ffffff' : 'var(--fg-indigo)'

  return (
    <span className={cn('inline-flex items-center gap-2', className)}>
      <svg viewBox="0 0 48 48" width="28" height="28" aria-hidden="true">
        <rect x="6" y="14" width="22" height="3.2" rx="1.6" fill={bars} />
        <rect x="6" y="21.4" width="28" height="3.2" rx="1.6" fill={bars} />
        <rect x="6" y="28.8" width="15" height="3.2" rx="1.6" fill={bars} />
        <circle cx="36.5" cy="15.6" r="3.4" fill="var(--fg-emerald)" />
      </svg>
      <span className={cn('text-lg font-medium tracking-tight', inverse ? 'text-on-ink' : 'text-foreground')}>
        FeedGenix
      </span>
    </span>
  )
}
