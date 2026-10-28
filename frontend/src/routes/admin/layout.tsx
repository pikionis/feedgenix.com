import { Link, NavLink, Outlet, redirect } from 'react-router'
import { Logo } from '~/components/brand/logo'
import { StaffGate } from '~/features/admin/StaffGate'
import { cn } from '~/lib/utils'
import { queryClient } from '~/lib/query'
import { sessionQuery } from '~/lib/session'
import type { Route } from './+types/layout'

/** The staff area: a session and staff access; everyone else goes where they belong. */
export async function clientLoader({ request }: Route.ClientLoaderArgs) {
  const user = await queryClient.ensureQueryData(sessionQuery)
  if (user === null) {
    const url = new URL(request.url)
    throw redirect(`/login?next=${encodeURIComponent(url.pathname + url.search)}`)
  }
  if (!user.is_staff) throw redirect('/app')

  return null
}

export function HydrateFallback() {
  return <div className="h-0.5 w-full animate-pulse bg-primary" aria-hidden="true" />
}

export default function AdminLayout() {
  return (
    <div className="min-h-screen bg-background">
      <header className="sticky top-0 z-10 border-b-[0.5px] border-hairline bg-background">
        <div className="mx-auto flex max-w-[1200px] flex-wrap items-center gap-x-4 gap-y-2 px-4 py-3 sm:px-6">
          <Logo />
          <span className="rounded-full bg-ink px-2.5 py-0.5 text-xs text-on-ink">Staff</span>
          <nav aria-label="Staff" className="flex flex-wrap gap-1">
            <StaffLink to="/admin" end>
              Platform
            </StaffLink>
            <StaffLink to="/admin/workspaces">Workspaces</StaffLink>
            <StaffLink to="/admin/audit">Staff trail</StaffLink>
          </nav>
          <Link to="/app" className="ml-auto text-sm text-muted-foreground hover:text-foreground">
            Back to my workspace
          </Link>
        </div>
      </header>
      <main className="mx-auto max-w-[1200px] px-4 py-8 sm:px-6">
        <StaffGate>
          <Outlet />
        </StaffGate>
      </main>
    </div>
  )
}

function StaffLink({ to, end, children }: { to: string; end?: boolean; children: string }) {
  return (
    <NavLink
      to={to}
      end={end}
      className={({ isActive }) =>
        cn(
          'rounded-md border-[0.5px] px-3 py-1.5 text-[13px] transition-colors duration-200',
          isActive
            ? 'border-hairline bg-surface font-medium text-foreground'
            : 'border-transparent text-muted-foreground hover:bg-ink/[0.04]',
        )
      }
    >
      {children}
    </NavLink>
  )
}
