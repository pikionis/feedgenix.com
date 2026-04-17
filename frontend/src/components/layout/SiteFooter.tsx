import { Link } from 'react-router-dom';
import Logo from '@/components/brand/Logo';

const columns = [
  {
    title: 'Product',
    links: [
      { label: 'Features', href: '/#features' },
      { label: 'How it works', href: '/#how-it-works' },
      { label: 'Pricing', href: '/pricing' },
      { label: 'FAQ', href: '/#faq' },
    ],
  },
  {
    title: 'Formats',
    links: [
      { label: 'CSV', href: '/#formats' },
      { label: 'XLSX', href: '/#formats' },
      { label: 'XML', href: '/#formats' },
      { label: 'JSON', href: '/#formats' },
    ],
  },
  {
    title: 'Company',
    links: [
      { label: 'About', href: '/about' },
      { label: 'Contact', href: 'mailto:hello@feedgenix.com' },
      { label: 'Privacy', href: '/privacy' },
      { label: 'Terms', href: '/terms' },
    ],
  },
];

export default function SiteFooter() {
  return (
    <footer className="border-t border-border/60 bg-background">
      <div className="mx-auto max-w-7xl px-6 py-16">
        <div className="grid gap-12 md:grid-cols-12">
          <div className="md:col-span-5">
            <Logo />
            <p className="mt-4 max-w-sm text-sm leading-6 text-muted-foreground">
              AI-powered feed intelligence for cross-border e-commerce. Translate, enrich, and
              optimize your product catalog for every market — in minutes, not months.
            </p>
          </div>
          <div className="grid grid-cols-3 gap-8 md:col-span-7">
            {columns.map((col) => (
              <div key={col.title}>
                <h4 className="text-sm font-semibold text-foreground">{col.title}</h4>
                <ul className="mt-4 space-y-3">
                  {col.links.map((l) => (
                    <li key={l.label}>
                      {l.href.startsWith('mailto:') || l.href.startsWith('http') ? (
                        <a
                          href={l.href}
                          className="text-sm text-muted-foreground transition-colors hover:text-foreground"
                        >
                          {l.label}
                        </a>
                      ) : (
                        <Link
                          to={l.href}
                          className="text-sm text-muted-foreground transition-colors hover:text-foreground"
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
        </div>
        <div className="mt-16 flex flex-col items-start justify-between gap-4 border-t border-border/60 pt-8 md:flex-row md:items-center">
          <p className="text-xs text-muted-foreground">
            © {new Date().getFullYear()} FeedGenix. All rights reserved.
          </p>
          <p className="text-xs text-muted-foreground">
            Made for sellers who ship across borders.
          </p>
        </div>
      </div>
    </footer>
  );
}
