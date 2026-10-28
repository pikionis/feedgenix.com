import { Fragment } from 'react'

const STEPS = [
  {
    title: 'Connect a feed',
    body: 'A URL or a file: CSV, TSV, XML or Google Merchant RSS, JSON or Excel, zipped or not.',
  },
  {
    title: 'Review the mapping',
    body: 'AI proposes where each column goes. You approve or edit, and an unsure field stays empty.',
  },
  {
    title: 'Translate and check',
    body: 'Into the languages you choose, with quality checks per language and an estimate before anything runs.',
  },
  {
    title: 'Publish a feed URL',
    body: 'One URL per language for your channels to pull, with prices, stock and barcodes exactly as in your source.',
  },
] as const

const STEP_BLOCKS = ['bg-block-pink', 'bg-block-cyan', 'bg-block-emerald', 'bg-block-amber'] as const

export function HowItWorks() {
  return (
    <section
      id="how-it-works"
      aria-labelledby="how-it-works-title"
      className="border-b-[0.5px] border-hairline py-16 lg:py-20"
    >
      <h2 id="how-it-works-title" className="mb-10 text-2xl font-medium tracking-[-0.02em]">
        How it works
      </h2>
      <ol className="grid gap-8 lg:grid-cols-[1fr_24px_1fr_24px_1fr_24px_1fr] lg:items-center lg:gap-0">
        {STEPS.map((step, index) => (
          <Fragment key={step.title}>
            {index > 0 && (
              <li aria-hidden="true" className="hidden text-center text-faint lg:block">
                →
              </li>
            )}
            <li>
              <p
                className={`mb-3 flex size-8 items-center justify-center rounded-[4px] font-mono text-xs text-block-ink ${STEP_BLOCKS[index]}`}
              >
                <span className="sr-only">Step </span>
                {String(index + 1).padStart(2, '0')}
              </p>
              <h3 className="text-md font-medium tracking-[-0.01em]">{step.title}</h3>
              <p className="mt-2 text-sm leading-relaxed text-muted-foreground">{step.body}</p>
            </li>
          </Fragment>
        ))}
      </ol>
    </section>
  )
}
