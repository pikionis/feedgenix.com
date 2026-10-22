import { Languages, ShieldCheck, TableProperties, type LucideIcon } from 'lucide-react'

const FEATURES: { title: string; body: string; Icon: LucideIcon }[] = [
  {
    title: 'AI mapping you review',
    body: 'FeedGenix reads your columns and proposes where each one belongs. When it is unsure, the field stays empty rather than wrong.',
    Icon: TableProperties,
  },
  {
    title: 'Any language you sell in',
    body: 'Choose the languages per workspace, from German and Greek to Japanese or Swiss French. Text is translated; codes and numbers are not.',
    Icon: Languages,
  },
  {
    title: 'The Golden Rule',
    body: 'Prices, stock, barcodes, SKUs and dimensions never reach a model. They pass through byte-identical to your source.',
    Icon: ShieldCheck,
  },
]

/** Three feature cards on the warm band (full width, like the design). */
export function Features() {
  return (
    <section id="features" aria-label="Features" className="bg-warm px-6 py-16 sm:px-10 lg:py-20">
      <div className="mx-auto grid max-w-[1120px] gap-5 md:grid-cols-3">
        {FEATURES.map(({ title, body, Icon }) => (
          <article key={title} className="rounded-xl border-[0.5px] border-hairline bg-background p-7">
            <div className="mb-4 flex size-10 items-center justify-center rounded-lg bg-primary-soft text-primary-hover">
              <Icon size={18} strokeWidth={1.5} aria-hidden="true" />
            </div>
            <h3 className="mb-2 text-md font-medium tracking-[-0.01em]">{title}</h3>
            <p className="text-sm leading-relaxed text-muted-foreground">{body}</p>
          </article>
        ))}
      </div>
    </section>
  )
}
