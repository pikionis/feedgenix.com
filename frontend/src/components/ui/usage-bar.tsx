/** Used against allowed: the numbers as text (for everyone) and a thin decorative bar. */
export function UsageBar({ used, allowed, label }: { used: number; allowed: number | null; label: string }) {
  const percent = allowed === null || allowed === 0 ? (allowed === 0 ? 100 : 0) : Math.min(100, (used / allowed) * 100)

  return (
    <div className="flex flex-col gap-1">
      <div className="flex justify-between text-sm text-muted-foreground">
        <span>{label}</span>
        <span className="font-mono text-xs">
          {used.toLocaleString('en')} / {allowed === null ? 'unlimited' : allowed.toLocaleString('en')}
        </span>
      </div>
      <div className="h-1 overflow-hidden rounded-full bg-surface" aria-hidden="true">
        <div className={percent >= 100 ? 'h-full bg-warn' : 'h-full bg-primary'} style={{ width: `${percent}%` }} />
      </div>
    </div>
  )
}
