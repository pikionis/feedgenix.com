import { Link, NavLink } from 'react-router-dom';
import { useState } from 'react';
import Logo from '@/components/brand/Logo';
import { useAuthStore } from '@/stores/authStore';

/**
 * Marketing top navigation. Non-sticky by design — it scrolls off,
 * consistent with the FeedGenix "calm, flat" marketing system. No blur,
 * no shadow, just a hairline bottom border.
 */

const navLinks: Array<{ to: string; label: string; external?: boolean }> = [
  { to: '/#features', label: 'Product', external: true },
  { to: '/pricing', label: 'Pricing' },
  { to: '/#how', label: 'Docs', external: true },
  { to: '/#faq', label: 'Blog', external: true },
];

export default function SiteHeader() {
  const [open, setOpen] = useState(false);
  const { isAuthenticated } = useAuthStore();

  return (
    <header className="w-full fg-hairline-b bg-paper">
      <div className="mx-auto flex max-w-300 items-center gap-7 px-10 py-5.5">
        <Logo size="md" />

        <nav className="hidden flex-1 items-center gap-7 pl-5 md:flex">
          {navLinks.map((l) =>
            l.external ? (
              <a
                key={l.to}
                href={l.to.startsWith('/#') ? l.to.slice(1) : l.to}
                className="text-[14px] text-ink-500 transition-colors duration-200 hover:text-ink"
              >
                {l.label}
              </a>
            ) : (
              <NavLink
                key={l.to}
                to={l.to}
                className={({ isActive }) =>
                  `text-[14px] transition-colors duration-200 ${
                    isActive ? 'text-ink' : 'text-ink-500 hover:text-ink'
                  }`
                }
              >
                {l.label}
              </NavLink>
            )
          )}
        </nav>

        <div className="hidden items-center gap-2.5 md:flex ml-auto">
          {isAuthenticated ? (
            <Link
              to="/dashboard"
              className="rounded-lg bg-indigo px-4 py-2.25 text-[14px] font-medium text-white transition-colors duration-200 hover:bg-indigo-600"
            >
              Go to dashboard →
            </Link>
          ) : (
            <>
              <Link
                to="/login"
                className="rounded-lg px-4 py-2.25 text-[14px] font-medium text-ink transition-colors duration-200 hover:bg-mist"
              >
                Sign in
              </Link>
              <Link
                to="/register"
                className="rounded-lg bg-indigo px-4 py-2.25 text-[14px] font-medium text-white transition-colors duration-200 hover:bg-indigo-600"
              >
                Start free
              </Link>
            </>
          )}
        </div>

        <button
          onClick={() => setOpen(!open)}
          className="ml-auto md:hidden"
          aria-label="Toggle menu"
        >
          <svg width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round">
            {open ? <path d="M6 6l12 12M6 18L18 6" /> : <path d="M4 7h16M4 12h16M4 17h16" />}
          </svg>
        </button>
      </div>

      {open && (
        <div className="fg-hairline-t bg-paper md:hidden">
          <nav className="flex flex-col gap-1 px-10 py-4">
            {navLinks.map((l) => (
              <a
                key={l.to}
                href={l.external && l.to.startsWith('/#') ? l.to.slice(1) : l.to}
                className="rounded-lg px-3 py-2 text-[14px] text-ink-500 hover:bg-mist hover:text-ink"
                onClick={() => setOpen(false)}
              >
                {l.label}
              </a>
            ))}
            <div className="mt-2 flex flex-col gap-2 fg-hairline-t pt-4">
              {isAuthenticated ? (
                <Link
                  to="/dashboard"
                  className="rounded-lg bg-indigo px-4 py-2.5 text-center text-[14px] font-medium text-white"
                >
                  Go to dashboard
                </Link>
              ) : (
                <>
                  <Link
                    to="/login"
                    className="rounded-lg fg-hairline px-4 py-2.5 text-center text-[14px] font-medium text-ink"
                  >
                    Sign in
                  </Link>
                  <Link
                    to="/register"
                    className="rounded-lg bg-indigo px-4 py-2.5 text-center text-[14px] font-medium text-white"
                  >
                    Start free
                  </Link>
                </>
              )}
            </div>
          </nav>
        </div>
      )}
    </header>
  );
}
