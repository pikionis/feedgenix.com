import type { ReactNode } from 'react';
import Logo from '@/components/brand/Logo';

interface Props {
  /** Right-panel title (h1). */
  title: string;
  /** Optional lead paragraph shown under the title. */
  subtitle?: ReactNode;
  /** The form body. */
  children: ReactNode;
  /** Small text rendered below the form body (e.g. alt-action "New here? Create account"). */
  footer?: ReactNode;
  /** Optional crumbs shown above the title ("Create account" · right-action). */
  crumbs?: { left: ReactNode; right?: ReactNode };
}

/**
 * Split-card auth frame — brand mosaic on the left, form on the right.
 * Mirrors the Composer-style layout from the FeedGenix design system's Auth kit:
 * warm Mist canvas, 0.5px hairline card, playful color blocks tucked in the corners.
 */
export default function AuthLayout({ title, subtitle, children, footer, crumbs }: Props) {
  return (
    <div className="flex min-h-screen items-center justify-center bg-mist px-5 py-10">
      <div className="grid w-full max-w-260 grid-cols-1 overflow-hidden rounded-2xl border-[0.5px] border-ink/10 bg-paper lg:grid-cols-2">
        <BrandPanel />

        <section className="flex min-h-160 flex-col justify-center px-8 py-12 sm:px-14">
          {crumbs && (
            <div className="mb-6 flex justify-between text-[12px] text-ink-400">
              <span>{crumbs.left}</span>
              {crumbs.right && <span>{crumbs.right}</span>}
            </div>
          )}
          <h1 className="text-[28px] font-medium leading-tight tracking-[-0.02em] text-ink">
            {title}
          </h1>
          {subtitle && (
            <p className="mt-2 text-[14px] leading-[1.55] text-ink-500">{subtitle}</p>
          )}
          <div className="mt-7">{children}</div>
          {footer && <div className="mt-6 text-center text-[13px] text-ink-500">{footer}</div>}
        </section>
      </div>
    </div>
  );
}

/**
 * Left brand panel. Features the wordmark, a display headline with highlighted
 * words, trust stats, and scattered color blocks in the corners per the
 * design system's mosaic treatment.
 */
function BrandPanel() {
  return (
    <aside className="relative hidden overflow-hidden bg-mist p-10 lg:flex lg:flex-col lg:gap-6">
      <BackgroundGrid />
      <Mosaic />

      <div className="relative z-10 flex items-center gap-2.5">
        <Logo size="md" asStatic />
      </div>

      <div className="relative z-10 mt-auto max-w-95">
        <h2 className="text-[54px] font-medium leading-none tracking-[-0.03em] text-ink">
          Ship your <Highlight>catalog</Highlight>
          <br />
          in every <Highlight tone="cyan">language</Highlight>
        </h2>
        <p className="mt-4.5 text-[15px] leading-[1.55] text-ink-500">
          Join 2,400+ merchants translating product feeds with hash-cached, AI-routed precision.
        </p>
        <div className="mt-6 grid grid-cols-3 gap-5 border-t-[0.5px] border-ink/12 pt-4.5 text-[12px] text-ink-500">
          <TrustStat primary="4.8 on G2"  body="Merchant reviews" />
          <TrustStat primary="SOC 2"      body="Type II certified" />
          <TrustStat primary="61%"        body="Avg cache hit" />
        </div>
      </div>
    </aside>
  );
}

function Highlight({ children, tone = 'pink' }: { children: ReactNode; tone?: 'pink' | 'cyan' }) {
  const bg = tone === 'pink' ? '#F7B8D2' : '#33C6E8';
  return (
    <span className="rounded" style={{ background: bg, padding: '0 6px', color: '#1F1D2E' }}>
      {children}
    </span>
  );
}

function TrustStat({ primary, body }: { primary: string; body: string }) {
  return (
    <div>
      <b className="mb-0.5 block text-[13px] font-medium text-ink">{primary}</b>
      {body}
    </div>
  );
}

/** Subtle 24px grid on the brand panel so it doesn't read as empty. */
function BackgroundGrid() {
  return (
    <div
      aria-hidden
      className="pointer-events-none absolute inset-0"
      style={{
        backgroundImage:
          'linear-gradient(rgba(31,29,46,.04) .5px, transparent .5px), linear-gradient(90deg, rgba(31,29,46,.04) .5px, transparent .5px)',
        backgroundSize: '24px 24px',
      }}
    />
  );
}

/** Decorative color blocks scattered in the corners, tucked away from text. */
function Mosaic() {
  return (
    <div aria-hidden className="pointer-events-none absolute inset-0">
      <Block color="emerald" style={{ right: -50, top: -20, width: 140, height: 70 }} />
      <Block color="cyan"    style={{ right: 110, top: 20, width: 50, height: 30 }} />
      <Block color="pink"    style={{ right: -30, top: 180, width: 70, height: 50 }} />
      <Block color="amber"   style={{ right: -40, top: 260, width: 40, height: 40 }} />
      <Block color="cyan"    style={{ left: -30, top: 180, width: 50, height: 50 }} />
      <Block color="emerald" style={{ left: -40, top: 260, width: 70, height: 30 }} />
      <Block color="emerald" style={{ right: -30, bottom: -20, width: 120, height: 80 }} />
      <Block color="pink"    style={{ right: 90, bottom: 20, width: 80, height: 40 }} />
      <Block color="amber"   style={{ left: -30, bottom: -10, width: 60, height: 50 }} />
      <Block color="ink"     style={{ left: 40, bottom: -20, width: 28, height: 28 }} />
      <Block color="cyan"    style={{ left: 90, bottom: -10, width: 40, height: 20 }} />
    </div>
  );
}

function Block({
  color,
  style,
}: {
  color: 'emerald' | 'pink' | 'cyan' | 'amber' | 'ink';
  style: React.CSSProperties;
}) {
  const bg = {
    emerald: '#1FB281',
    pink: '#F7B8D2',
    cyan: '#33C6E8',
    amber: '#F0B54A',
    ink: '#1F1D2E',
  }[color];
  return <span className="absolute rounded" style={{ ...style, background: bg }} />;
}
