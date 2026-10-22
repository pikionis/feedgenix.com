import { Link } from 'react-router'
import { Logo } from '~/components/brand/logo'
import { SECTIONS } from './MarketingNav'

const linkClass = 'block py-1 text-sm text-(--fg-ink-300) transition-colors duration-200 hover:text-on-ink'
// Once per load, outside render: the pre-rendered page and the hydrated one agree on it.
const YEAR = new Date().getFullYear()

/** The Ink footer. Company and legal details arrive with the public launch (M11), never invented. */
export function MarketingFooter() {
  return (
    <footer className="mt-10 bg-ink pt-16 pb-7 text-(--fg-ink-300)">
      <div className="mx-auto max-w-[1200px] px-6 sm:px-10">
        <div className="grid gap-10 sm:grid-cols-[1.5fr_1fr_1fr]">
          <div>
            <Logo inverse className="mb-3" />
            <p className="max-w-[340px] text-sm leading-relaxed">
              Product feeds for cross-border merchants, in every language your buyers shop in.
            </p>
          </div>
          <nav aria-label="Product">
            <h2 className="mb-3 text-xs font-medium tracking-[0.04em] text-on-ink">Product</h2>
            {SECTIONS.map((section) => (
              <a key={section.href} href={section.href} className={linkClass}>
                {section.label}
              </a>
            ))}
          </nav>
          <nav aria-label="Account">
            <h2 className="mb-3 text-xs font-medium tracking-[0.04em] text-on-ink">Account</h2>
            <Link to="/login" className={linkClass}>
              Sign in
            </Link>
            <Link to="/register" className={linkClass}>
              Create an account
            </Link>
          </nav>
        </div>
        <div className="mt-11 flex flex-wrap justify-between gap-2 border-t-[0.5px] border-white/10 pt-5 text-xs text-faint">
          <span>© {YEAR} FeedGenix</span>
          <span>Private beta</span>
        </div>
      </div>
    </footer>
  )
}
