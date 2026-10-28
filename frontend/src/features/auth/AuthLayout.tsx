import type { ReactNode } from 'react'
import { Link } from 'react-router'
import { Logo } from '~/components/brand/logo'

// The decorative block mosaic of the design system's auth kit: kept to the panel's edges, away from text.
const BLOCKS: { id: string; color: string; style: React.CSSProperties }[] = [
  { id: 'b1', color: 'var(--block-emerald)', style: { right: -50, top: -20, width: 140, height: 70 } },
  { id: 'b2', color: 'var(--block-cyan)', style: { right: 110, top: 20, width: 50, height: 30 } },
  { id: 'b3', color: 'var(--block-pink)', style: { right: -30, top: 200, width: 70, height: 50 } },
  { id: 'b4', color: 'var(--block-amber)', style: { right: -20, top: 270, width: 40, height: 40 } },
  { id: 'b5', color: 'var(--block-emerald)', style: { right: -30, bottom: -20, width: 120, height: 80 } },
  { id: 'b6', color: 'var(--block-pink)', style: { right: 100, bottom: 16, width: 70, height: 36 } },
  { id: 'b7', color: 'var(--block-amber)', style: { left: -30, bottom: -10, width: 60, height: 44 } },
  { id: 'b8', color: 'var(--block-ink)', style: { left: 44, bottom: -14, width: 28, height: 28 } },
]

const FACTS = [
  ['Free plan', 'No card needed'],
  ['Any language', 'BCP-47 codes'],
  ['Up to 50 GB', 'Per feed'],
] as const

/** Sign-in and sign-up: the brand panel with the mosaic on the left (from lg), the form on the right. */
export function AuthLayout({
  title,
  subtitle,
  children,
  footer,
}: {
  title: string
  subtitle?: string
  children: ReactNode
  footer?: ReactNode
}) {
  return (
    <main className="flex min-h-screen items-center justify-center bg-surface px-4 py-10">
      <div className="grid w-full max-w-[1040px] overflow-hidden rounded-2xl border-[0.5px] border-hairline bg-background lg:min-h-[640px] lg:grid-cols-2">
        <aside className="relative hidden flex-col overflow-hidden bg-surface p-10 lg:flex" aria-hidden="true">
          {BLOCKS.map((block) => (
            <span
              key={block.id}
              className="absolute rounded-[4px]"
              style={{ ...block.style, background: block.color }}
            />
          ))}
          <Logo className="relative" />
          <div className="relative mt-auto mb-16">
            <p className="mb-5 text-[50px] leading-[1.12] font-medium tracking-[-0.03em]">
              Ship your <span className="rounded-[4px] bg-(--block-pink) px-1.5">catalog</span> in every{' '}
              <span className="rounded-[4px] bg-(--block-cyan) px-1.5">language</span>
            </p>
            <p className="mb-6 max-w-[380px] leading-relaxed text-muted-foreground">
              Prices, stock and barcodes pass through untouched. That&rsquo;s the Golden Rule.
            </p>
            <dl className="flex max-w-[400px] flex-wrap gap-6 border-t-[0.5px] border-hairline pt-4 text-xs text-muted-foreground">
              {FACTS.map(([term, detail]) => (
                <div key={term}>
                  <dt className="text-sm font-medium text-foreground">{term}</dt>
                  <dd>{detail}</dd>
                </div>
              ))}
            </dl>
          </div>
        </aside>

        <div className="flex flex-col justify-center px-6 py-10 sm:px-14 sm:py-12">
          <Link to="/" className="mb-8 inline-flex self-start lg:hidden" aria-label="FeedGenix home">
            <Logo />
          </Link>
          <h1 className="text-[28px] leading-tight font-medium tracking-[-0.02em]">{title}</h1>
          {subtitle !== undefined && <p className="mt-2 text-sm text-muted-foreground">{subtitle}</p>}
          <div className="mt-7">{children}</div>
          {footer !== undefined && <div className="mt-6 text-center text-sm text-muted-foreground">{footer}</div>}
        </div>
      </div>
    </main>
  )
}
