import { Link } from 'react-router-dom';
import type { ReactNode } from 'react';
import Logo from '@/components/brand/Logo';

interface Props {
  children: ReactNode;
  title: string;
  subtitle?: ReactNode;
  footer?: ReactNode;
}

export default function AuthLayout({ children, title, subtitle, footer }: Props) {
  return (
    <div className="grid min-h-screen lg:grid-cols-2">
      {/* Left: form */}
      <div className="flex flex-col justify-between p-8 sm:p-12">
        <div className="flex items-center justify-between">
          <Logo />
          <Link to="/" className="text-sm text-muted-foreground transition-colors hover:text-foreground">
            ← Back to home
          </Link>
        </div>

        <div className="mx-auto my-12 w-full max-w-sm">
          <h1 className="font-heading text-3xl font-semibold tracking-tight">{title}</h1>
          {subtitle && <p className="mt-2 text-sm text-muted-foreground">{subtitle}</p>}
          <div className="mt-8">{children}</div>
        </div>

        <div className="text-xs text-muted-foreground">
          {footer ?? (
            <p>
              By continuing you agree to our{' '}
              <Link to="/terms" className="underline hover:text-foreground">
                Terms
              </Link>{' '}
              and{' '}
              <Link to="/privacy" className="underline hover:text-foreground">
                Privacy Policy
              </Link>
              .
            </p>
          )}
        </div>
      </div>

      {/* Right: brand panel */}
      <div className="relative hidden overflow-hidden bg-linear-to-br from-secondary via-secondary to-[hsl(222_47%_10%)] lg:block">
        <div
          className="pointer-events-none absolute inset-0"
          style={{
            background:
              'radial-gradient(ellipse 80% 60% at 20% 0%, hsl(var(--primary) / 0.18), transparent 60%), radial-gradient(ellipse 60% 50% at 80% 100%, hsl(var(--primary) / 0.12), transparent 60%)',
          }}
        />
        <div className="relative flex h-full flex-col justify-between p-12 text-secondary-foreground">
          <div />
          <div className="max-w-md">
            <div className="inline-flex items-center gap-2 rounded-full border border-white/10 bg-white/5 px-3 py-1 text-xs font-medium text-primary backdrop-blur">
              <span className="h-1.5 w-1.5 rounded-full bg-primary" /> Feed intelligence, ready to ship
            </div>
            <blockquote className="mt-8 font-heading text-3xl font-medium leading-snug tracking-tight text-white">
              "We went from 3 weeks of translator back-and-forth to a 12-minute
              pipeline. Prices and SKUs survived every batch."
            </blockquote>
            <div className="mt-6 flex items-center gap-3">
              <div className="flex h-10 w-10 items-center justify-center rounded-full bg-primary/20 font-heading text-sm font-semibold text-primary">
                MK
              </div>
              <div>
                <div className="text-sm font-semibold text-white">Maria K.</div>
                <div className="text-xs text-white/60">Head of Ops, cross-border Shopify brand</div>
              </div>
            </div>
          </div>
          <div className="flex items-center gap-6 text-xs text-white/50">
            <span className="inline-flex items-center gap-2">
              <span className="h-1.5 w-1.5 rounded-full bg-green-400" /> GDPR compliant
            </span>
            <span className="inline-flex items-center gap-2">
              <span className="h-1.5 w-1.5 rounded-full bg-green-400" /> EU hosted
            </span>
            <span className="inline-flex items-center gap-2">
              <span className="h-1.5 w-1.5 rounded-full bg-green-400" /> SOC 2 in progress
            </span>
          </div>
        </div>
      </div>
    </div>
  );
}
