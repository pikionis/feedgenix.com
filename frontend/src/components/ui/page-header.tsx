import { ArrowLeft } from 'lucide-react'
import type { ReactNode } from 'react'
import { Link } from 'react-router'

/**
 * The top of every workspace page (the app kit's page header): an optional way back, the title,
 * one line about the page, and its actions on the right.
 */
export function PageHeader({
  title,
  subtitle,
  back,
  actions,
}: {
  title: ReactNode
  subtitle?: ReactNode
  back?: { to: string; label: string }
  actions?: ReactNode
}) {
  return (
    <div className="flex flex-col gap-3">
      {back !== undefined && (
        <Link
          to={back.to}
          className="inline-flex items-center gap-1 self-start text-sm text-muted-foreground transition-colors duration-200 hover:text-foreground"
        >
          <ArrowLeft size={14} strokeWidth={1.5} aria-hidden="true" /> {back.label}
        </Link>
      )}
      <div className="flex flex-wrap items-center justify-between gap-x-4 gap-y-3">
        <div className="min-w-0">
          <h1 className="truncate text-[26px] leading-tight font-medium tracking-[-0.02em]">{title}</h1>
          {subtitle !== undefined && <p className="mt-1 max-w-2xl text-sm text-muted-foreground">{subtitle}</p>}
        </div>
        {actions !== undefined && <div className="flex flex-wrap items-center gap-2">{actions}</div>}
      </div>
    </div>
  )
}
