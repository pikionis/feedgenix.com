import type { ReactNode } from 'react'
import { Link } from 'react-router'
import { Logo } from '~/components/brand/logo'
import { Card } from '~/components/ui/card'

export function AuthLayout({
  title,
  subtitle,
  children,
  footer,
}: {
  title: string
  subtitle?: string
  children: ReactNode
  footer?: ReactNode
}) {
  return (
    <main className="flex min-h-screen items-center justify-center bg-surface px-4 py-12">
      <div className="w-full max-w-[400px]">
        <Link to="/" className="mb-8 inline-flex" aria-label="FeedGenix home">
          <Logo />
        </Link>
        <Card>
          <h1 className="text-xl font-medium tracking-tight">{title}</h1>
          {subtitle !== undefined && <p className="mt-1 text-sm text-muted-foreground">{subtitle}</p>}
          <div className="mt-6">{children}</div>
        </Card>
        {footer !== undefined && <div className="mt-4 text-center text-sm text-muted-foreground">{footer}</div>}
      </div>
    </main>
  )
}
