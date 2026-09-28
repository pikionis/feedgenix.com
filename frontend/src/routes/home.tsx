import { Link } from 'react-router'
import { Logo } from '~/components/brand/logo'
import { Button } from '~/components/ui/button'
import type { Route } from './+types/home'

export const meta: Route.MetaFunction = () => [
  { title: 'FeedGenix — your catalog in every language your buyers shop in' },
  {
    name: 'description',
    content:
      'Connect a product feed, review the AI field mapping, translate into the languages you choose and publish a feed URL. Prices, stock and barcodes pass through untouched.',
  },
]

export default function Home() {
  return (
    <main className="mx-auto flex min-h-screen max-w-[1200px] flex-col px-6 py-16">
      <Logo />
      <h1 className="mt-20 max-w-3xl text-4xl leading-[1.1] font-medium tracking-tight">
        Ship your catalog in every language your buyers shop in.
      </h1>
      <p className="mt-6 max-w-2xl text-lg text-muted-foreground">
        Connect a product feed, review the AI field mapping, translate into the languages you choose and publish a feed
        URL your channels can pull.
      </p>
      <p className="mt-4 max-w-2xl text-muted-foreground">
        Prices, stock and barcodes pass through untouched. That&rsquo;s the Golden Rule.
      </p>
      <div className="mt-10">
        <Button asChild>
          <Link to="/app">Open your workspace</Link>
        </Button>
      </div>
    </main>
  )
}
