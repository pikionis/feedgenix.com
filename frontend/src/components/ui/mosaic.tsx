import type { CSSProperties, ReactNode } from 'react'
import { cn } from '~/lib/utils'

/*
 * The mosaic of the design system's brand panel, used across the site: flat coloured blocks at a
 * frame's edges, away from text, and a word set on a block ("Ship your catalog in every language").
 * Decorative only: blocks are hidden from screen readers and never carry meaning.
 */

export type BlockTone = 'emerald' | 'pink' | 'cyan' | 'amber' | 'indigo' | 'ink'

const BG: Record<BlockTone, string> = {
  emerald: 'bg-block-emerald',
  pink: 'bg-block-pink',
  cyan: 'bg-block-cyan',
  amber: 'bg-block-amber',
  indigo: 'bg-block-indigo',
  ink: 'bg-block-ink',
}

/** Each part of the workspace has its block colour: its title and its menu item share it. */
export const SECTION_TONES = {
  overview: 'cyan',
  feeds: 'emerald',
  notices: 'amber',
  billing: 'pink',
  settings: 'indigo',
} as const satisfies Record<string, BlockTone>

export type Block = {
  tone: BlockTone
  width: number
  height: number
} & Pick<CSSProperties, 'top' | 'right' | 'bottom' | 'left'>

/** Blocks placed in a `relative` frame and clipped to it; place them where no text runs. */
export function Mosaic({ blocks, className }: { blocks: readonly Block[]; className?: string }) {
  return (
    <div aria-hidden="true" className={cn('pointer-events-none absolute inset-0 overflow-hidden', className)}>
      {blocks.map(({ tone, ...position }) => (
        <span
          key={`${tone}:${String(position.top ?? position.bottom)}:${String(position.left ?? position.right)}`}
          className={cn('absolute rounded-[4px]', BG[tone])}
          style={position}
        />
      ))}
    </div>
  )
}

/** One word on a block. The text stays Ink on the light blocks and white on Indigo or Ink, in both themes. */
export function Highlight({ tone, children }: { tone: BlockTone; children: ReactNode }) {
  return (
    <mark
      className={cn(
        'rounded-[4px] px-1.5 [-webkit-box-decoration-break:clone] [box-decoration-break:clone]',
        BG[tone],
        tone === 'indigo' || tone === 'ink' ? 'text-white' : 'text-block-ink',
      )}
    >
      {children}
    </mark>
  )
}

/** A small solid square in a block colour: a marker, as on the active menu item. */
export function BlockDot({ tone, className }: { tone: BlockTone; className?: string }) {
  return <span aria-hidden="true" className={cn('size-2.5 shrink-0 rounded-[3px]', BG[tone], className)} />
}
