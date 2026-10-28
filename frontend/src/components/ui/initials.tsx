import { cn } from '~/lib/utils'

/** "Tools export" → "TE". Words that start with a letter, at most two. */
export function initialsOf(name: string): string {
  return (
    name
      .split(/\s+/)
      .filter((word) => /^\p{L}/u.test(word))
      .slice(0, 2)
      .map((word) => word[0]?.toUpperCase())
      .join('') || '·'
  )
}

/** An initials avatar (the design system has no photos): a Mist square, or Ink for the workspace. */
export function Initials({
  name,
  tone = 'mist',
  className,
}: {
  name: string
  tone?: 'mist' | 'ink'
  className?: string
}) {
  return (
    <span
      aria-hidden="true"
      className={cn(
        'flex size-8 shrink-0 items-center justify-center rounded-md text-xs font-medium',
        tone === 'ink' ? 'bg-ink text-on-ink' : 'bg-surface text-foreground',
        className,
      )}
    >
      {initialsOf(name)}
    </span>
  )
}
