import type { ReactNode } from 'react';

interface PageHeaderProps {
  /** Main heading — sentence case (design system rule). */
  title: string;
  /** Optional single-line subtitle with metadata separated by middle-dot. */
  subtitle?: ReactNode;
  /** Right-aligned CTAs (usually a secondary + primary button). */
  actions?: ReactNode;
}

/**
 * The standard top-of-page header used across every app route.
 * Keeps a consistent rhythm: 26px title, 12.5px muted subtitle, actions right.
 */
export default function PageHeader({ title, subtitle, actions }: PageHeaderProps) {
  return (
    <header className="mb-5.5 flex items-center gap-3.5">
      <div className="min-w-0">
        <h1 className="m-0 truncate text-[26px] font-medium tracking-[-0.02em] text-ink">
          {title}
        </h1>
        {subtitle && (
          <p className="m-0 mt-0.5 truncate text-[12.5px] text-ink-500">{subtitle}</p>
        )}
      </div>
      {actions && <div className="ml-auto flex items-center gap-2">{actions}</div>}
    </header>
  );
}
