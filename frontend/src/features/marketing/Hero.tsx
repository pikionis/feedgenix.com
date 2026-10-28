import { Check } from 'lucide-react'
import { Link } from 'react-router'
import { Button } from '~/components/ui/button'
import { FeedPreview } from './FeedPreview'

const FACTS = ['Free plan, no card', 'Any BCP-47 language', 'Feeds up to 50 GB'] as const

export function Hero() {
  return (
    <section className="grid items-center gap-12 py-16 lg:grid-cols-2 lg:gap-16 lg:py-20">
      <div>
        <span className="inline-flex items-center rounded-full bg-primary-soft px-3 py-1.5 text-xs font-medium tracking-wide text-primary-hover">
          ✦ Private beta · invite only
        </span>
        <h1 className="mt-6 mb-5 text-3xl leading-[1.05] font-medium tracking-[-0.022em] sm:text-4xl">
          Ship your catalog in every language your buyers shop in.
        </h1>
        <p className="mb-3 max-w-[520px] text-md leading-relaxed text-muted-foreground">
          Connect a product feed, review the AI field mapping, translate into the languages you choose and publish a
          feed URL your channels can pull.
        </p>
        <p className="mb-8 max-w-[520px] text-md leading-relaxed text-muted-foreground">
          Prices, stock and barcodes pass through untouched. That&rsquo;s the Golden Rule.
        </p>
        <div className="mb-6 flex flex-wrap gap-2.5">
          <Button asChild>
            <Link to="/register">Start free</Link>
          </Button>
          <Button variant="secondary" asChild>
            <a href="#how-it-works">See how it works</a>
          </Button>
        </div>
        <ul className="flex flex-wrap gap-x-5 gap-y-2 text-sm text-faint">
          {FACTS.map((fact) => (
            <li key={fact} className="inline-flex items-center gap-1.5">
              <Check size={14} strokeWidth={2.25} className="text-accent" aria-hidden="true" />
              {fact}
            </li>
          ))}
        </ul>
      </div>
      <FeedPreview />
    </section>
  )
}
