import { Link } from 'react-router'
import { Logo } from '~/components/brand/logo'
import { Button } from '~/components/ui/button'

export const SECTIONS = [
  { href: '#features', label: 'Features' },
  { href: '#how-it-works', label: 'How it works' },
  { href: '#pricing', label: 'Pricing' },
] as const

/** The marketing nav: not sticky (design/README.md), section links collapse away on a phone. */
export function MarketingNav() {
  return (
    <nav aria-label="Main" className="flex items-center gap-6 border-b-[0.5px] border-hairline py-5">
      <Link to="/" aria-label="FeedGenix home">
        <Logo />
      </Link>
      <div className="hidden flex-1 gap-7 pl-4 text-sm text-muted-foreground md:flex">
        {SECTIONS.map((section) => (
          <a key={section.href} href={section.href} className="transition-colors duration-200 hover:text-foreground">
            {section.label}
          </a>
        ))}
      </div>
      <div className="ml-auto flex gap-2">
        <Button variant="ghost" asChild>
          <Link to="/login">Sign in</Link>
        </Button>
        <Button asChild>
          <Link to="/register">Start free</Link>
        </Button>
      </div>
    </nav>
  )
}
