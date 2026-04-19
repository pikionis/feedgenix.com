import { Link } from 'react-router-dom';
import Logo from '@/components/brand/Logo';

/**
 * Marketing footer — Ink background with Paper-muted text.
 * 4 columns: brand + product + company + legal. Copy matches the
 * voice rules in the design system (calm, Swiss, no exclamations).
 */

const columns: Array<{ title: string; links: Array<{ label: string; href: string }> }> = [
  {
    title: 'Product',
    links: [
      { label: 'Features',  href: '/#features' },
      { label: 'Pricing',   href: '/pricing' },
      { label: 'Docs',      href: '/#docs' },
      { label: 'Changelog', href: '/#changelog' },
    ],
  },
  {
    title: 'Company',
    links: [
      { label: 'About',    href: '/about' },
      { label: 'Careers',  href: '/careers' },
      { label: 'Contact',  href: 'mailto:hello@feedgenix.com' },
      { label: 'Press',    href: '/press' },
    ],
  },
  {
    title: 'Legal',
    links: [
      { label: 'Terms',    href: '/terms' },
      { label: 'Privacy',  href: '/privacy' },
      { label: 'DPA',      href: '/dpa' },
      { label: 'Security', href: '/security' },
    ],
  },
];

export default function SiteFooter() {
  return (
    <footer className="mt-10 bg-ink text-ink-300">
      <div className="mx-auto max-w-300 px-10 pb-7 pt-16">
        <div className="grid grid-cols-1 gap-10 md:grid-cols-[1.5fr_1fr_1fr_1fr]">
          <div>
            <Logo tone="inverse" asStatic />
            <p className="mt-3 max-w-85 text-[13px] leading-[1.6]">
              Product feed intelligence for cross-border merchants. Made with care in Zürich.
            </p>
          </div>

          {columns.map((col) => (
            <div key={col.title}>
              <h4 className="mb-3 text-[12px] font-medium tracking-[0.04em] text-white">
                {col.title.toUpperCase()}
              </h4>
              <ul className="space-y-1">
                {col.links.map((l) => (
                  <li key={l.label}>
                    {l.href.startsWith('mailto:') || l.href.startsWith('http') ? (
                      <a
                        href={l.href}
                        className="block py-1 text-[13px] text-ink-300 transition-colors duration-200 hover:text-white"
                      >
                        {l.label}
                      </a>
                    ) : (
                      <Link
                        to={l.href}
                        className="block py-1 text-[13px] text-ink-300 transition-colors duration-200 hover:text-white"
                      >
                        {l.label}
                      </Link>
                    )}
                  </li>
                ))}
              </ul>
            </div>
          ))}
        </div>

        <div className="mt-11 flex flex-col items-start justify-between gap-2 border-t border-white/10 pt-5 text-[12px] text-ink-400 md:flex-row md:items-center">
          <span>© {new Date().getFullYear()} FeedGenix AG · Zürich, Switzerland</span>
          <span className="font-mono">CH-270.3.012.345-6</span>
        </div>
      </div>
    </footer>
  );
}
