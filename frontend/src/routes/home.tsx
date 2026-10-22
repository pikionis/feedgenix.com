import { Features } from '~/features/marketing/Features'
import { Hero } from '~/features/marketing/Hero'
import { HowItWorks } from '~/features/marketing/HowItWorks'
import { MarketingFooter } from '~/features/marketing/MarketingFooter'
import { MarketingNav } from '~/features/marketing/MarketingNav'
import { Pricing } from '~/features/marketing/Pricing'
import type { Route } from './+types/home'

export const meta: Route.MetaFunction = () => [
  { title: 'FeedGenix — your catalog in every language your buyers shop in' },
  {
    name: 'description',
    content:
      'Connect a product feed, review the AI field mapping, translate into the languages you choose and publish a feed URL. Prices, stock and barcodes pass through untouched.',
  },
]

/**
 * The landing page (the marketing UI kit of the April 2026 design system), pre-rendered at build time.
 * Layout widths stay in px (1200, 1120, 520, 340 as in the design): the root font is 15px, so the rem
 * classes an editor suggests instead (max-w-300 …) would render about 6% narrower.
 */
export default function Home() {
  return (
    <div className="min-h-screen bg-background text-foreground">
      <div className="mx-auto max-w-[1200px] px-6 sm:px-10">
        <MarketingNav />
        <main>
          <Hero />
        </main>
      </div>
      <Features />
      <div className="mx-auto max-w-[1200px] px-6 sm:px-10">
        <HowItWorks />
        <Pricing />
      </div>
      <MarketingFooter />
    </div>
  )
}
