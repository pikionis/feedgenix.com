import { cn } from '~/lib/utils'

// A tint per language (styles/tokens.css); any other language gets the neutral pair. Never a flag.
const TINTED = new Set(['de', 'fr', 'it', 'el', 'en'])

/** "de-CH" → a "DE-CH" monogram in the German tint, with the full name for screen readers. */
export function LanguagePill({ code, name, className }: { code: string; name?: string; className?: string }) {
  const base = code.split('-')[0]?.toLowerCase() ?? ''
  const tint = TINTED.has(base) ? base : 'neutral'

  return (
    <span
      className={cn('inline-flex rounded-full px-2 py-0.5 text-xs font-medium', className)}
      style={{ background: `var(--lang-${tint}-bg)`, color: `var(--lang-${tint}-fg)` }}
      title={name}
    >
      <span aria-hidden={name !== undefined}>{code.toUpperCase()}</span>
      {name !== undefined && <span className="sr-only">{name}</span>}
    </span>
  )
}
