import { useQuery, useQueryClient } from '@tanstack/react-query'
import { Bell, CreditCard, LayoutGrid, type LucideIcon, Rss, Settings, ShieldCheck } from 'lucide-react'
import { useEffect, useState } from 'react'
import { Link, NavLink, Outlet, redirect, useNavigate } from 'react-router'
import { Logo } from '~/components/brand/logo'
import { Button } from '~/components/ui/button'
import { Initials } from '~/components/ui/initials'
import { Notice } from '~/components/ui/notice'
import { UpgradeDialog } from '~/features/billing/UpgradeDialog'
import { noticesQuery } from '~/features/notices/queries'
import { workspaceQuery } from '~/features/workspace/queries'
import { api } from '~/lib/api/client'
import { unauthenticated } from '~/lib/events'
import { queryClient } from '~/lib/query'
import { sessionQuery, useSession } from '~/lib/session'
import { cn } from '~/lib/utils'
import type { Route } from './+types/layout'

/** Every /app page needs a session: without one, go to sign-in and come back afterwards. */
export async function clientLoader({ request }: Route.ClientLoaderArgs) {
  const user = await queryClient.ensureQueryData(sessionQuery)
  if (user === null) {
    const url = new URL(request.url)
    throw redirect(`/login?next=${encodeURIComponent(url.pathname + url.search)}`)
  }

  return null
}

export function HydrateFallback() {
  return (
    <div>
      <div className="h-0.5 w-full animate-pulse bg-primary" aria-hidden="true" />
      <p className="sr-only" aria-live="polite">
        Loading…
      </p>
    </div>
  )
}

export default function AppLayout() {
  const session = useSession()
  const navigate = useNavigate()
  const client = useQueryClient()

  // The API said the session is gone (expired, or signed out elsewhere).
  useEffect(
    () =>
      unauthenticated.on(() => {
        client.clear()
        void navigate('/login')
      }),
    [client, navigate],
  )

  const user = session.data
  if (user === null || user === undefined) return <HydrateFallback />

  const signOut = async () => {
    await api.POST('/v1/auth/logout')
    client.clear()
    await navigate('/login')
  }

  // One bar, two shapes: a 180px Mist sidebar from lg (the app kit), a sticky top bar below it. The
  // same elements reorder rather than render twice, so there is one navigation and one sign-out.
  return (
    <div className="min-h-screen bg-background lg:flex">
      <aside className="sticky top-0 z-10 border-b-[0.5px] border-hairline bg-surface lg:static lg:w-[180px] lg:shrink-0 lg:border-r-[0.5px] lg:border-b-0">
        <div className="flex flex-wrap items-center gap-x-6 gap-y-2 px-4 py-3 sm:px-6 lg:sticky lg:top-0 lg:h-screen lg:flex-col lg:flex-nowrap lg:items-stretch lg:gap-4 lg:px-3 lg:py-[18px]">
          <div className="lg:px-1.5">
            <Logo />
          </div>
          {user.workspace !== null && <WorkspaceCard name={user.workspace.name} plan={user.workspace.plan} />}
          <nav aria-label="Workspace" className="order-1 flex gap-0.5 lg:order-2 lg:flex-col">
            <NavItem to="/app" end icon={LayoutGrid}>
              Overview
            </NavItem>
            <NavItem to="/app/feeds" icon={Rss}>
              Feeds
            </NavItem>
            <NoticesItem />
            <NavItem to="/app/billing" icon={CreditCard}>
              Billing
            </NavItem>
            <NavItem to="/app/settings" icon={Settings}>
              Settings
            </NavItem>
            {user.is_staff && (
              <NavItem to="/admin" icon={ShieldCheck}>
                Staff
              </NavItem>
            )}
          </nav>
          <Quota />
          <Button
            variant="ghost"
            size="sm"
            className="order-3 ml-auto lg:order-4 lg:ml-0 lg:justify-start"
            onClick={() => void signOut()}
          >
            Sign out
          </Button>
        </div>
      </aside>
      <main className="mx-auto w-full max-w-[1200px] min-w-0 px-4 py-7 sm:px-6 lg:px-8">
        {!user.email_verified && <VerifyEmailNotice email={user.email} />}
        <Outlet />
      </main>
      <UpgradeDialog />
    </div>
  )
}

const PLAN_NAMES: Record<string, string> = { free: 'Free', pro: 'Pro', ultra: 'Ultra', custom: 'Custom' }

/** The workspace (the app kit's switcher card): a Paper card with an Ink initials square. */
function WorkspaceCard({ name, plan }: { name: string; plan: string }) {
  return (
    <div className="order-2 hidden min-w-0 items-center gap-2.5 md:flex lg:order-1 lg:rounded-lg lg:border-[0.5px] lg:border-hairline lg:bg-background lg:p-2.5">
      <Initials name={name} tone="ink" className="hidden size-7 lg:flex" />
      <div className="min-w-0">
        <p className="max-w-[260px] truncate text-sm text-muted-foreground lg:max-w-none lg:text-[13px] lg:leading-tight lg:font-medium lg:text-foreground">
          {name}
        </p>
        <p className="hidden text-[11px] text-muted-foreground lg:block">{PLAN_NAMES[plan] ?? plan} plan</p>
      </div>
    </div>
  )
}

/**
 * What the plan allows and what is in use (the app kit's quota widget): this period's products on
 * an indigo tint, then feeds and languages.
 */
function Quota() {
  const workspace = useQuery(workspaceQuery)
  const entitlements = workspace.data?.entitlements
  if (entitlements === undefined) return null

  const products = { used: entitlements.used.products, limit: entitlements.limits.products }
  const percent =
    products.limit === null || products.limit === 0
      ? null
      : Math.min(100, Math.round((products.used / products.limit) * 100))
  const rows = [
    { label: 'Feeds', used: entitlements.used.feeds, limit: entitlements.limits.feeds },
    { label: 'Languages', used: entitlements.used.languages, limit: entitlements.limits.languages },
  ]

  return (
    <div className="hidden flex-col gap-3 rounded-lg bg-primary-soft p-3.5 text-xs text-primary-hover lg:order-3 lg:mt-auto lg:flex">
      <div>
        <div className="flex justify-between font-medium">
          <span>Products this period</span>
          {percent !== null && <span>{percent}%</span>}
        </div>
        {percent !== null && (
          <div className="mt-2 h-[5px] overflow-hidden rounded-full bg-background/70" aria-hidden="true">
            <div className="h-full rounded-full bg-primary" style={{ width: `${percent}%` }} />
          </div>
        )}
        <p className="mt-1.5 font-mono text-[11px]">
          {products.used.toLocaleString('en')} /{' '}
          {products.limit === null ? 'unlimited' : products.limit.toLocaleString('en')}
        </p>
      </div>
      <dl aria-label="Plan usage" className="flex flex-col gap-1">
        {rows.map((row) => (
          <div key={row.label} className="flex justify-between">
            <dt>{row.label}</dt>
            <dd>{row.limit === null ? `${row.used} · unlimited` : `${row.used} of ${row.limit}`}</dd>
          </div>
        ))}
      </dl>
      <Link to="/app/billing/plans" className="font-medium text-primary hover:text-primary-hover">
        See plans
      </Link>
    </div>
  )
}

/** Notices, with how many are unread (checked every minute). */
function NoticesItem() {
  const notices = useQuery(noticesQuery)
  const unread = notices.data?.unread ?? 0

  return (
    <NavItem to="/app/notices" icon={Bell} badge={unread > 0 ? unread : undefined}>
      Notices
    </NavItem>
  )
}

function NavItem({
  to,
  end,
  icon: Icon,
  badge,
  children,
}: {
  to: string
  end?: boolean
  icon: LucideIcon
  badge?: number
  children: string
}) {
  return (
    <NavLink
      to={to}
      end={end ?? false}
      className={({ isActive }) =>
        cn(
          'flex items-center gap-2.5 rounded-md border-[0.5px] px-3 py-2 text-[13px] transition-colors duration-200',
          isActive
            ? 'border-hairline bg-background font-medium text-foreground'
            : 'border-transparent text-muted-foreground hover:bg-ink/[0.04] hover:text-foreground',
        )
      }
    >
      <Icon size={16} strokeWidth={1.5} className="hidden lg:block" aria-hidden="true" />
      {children}
      {badge !== undefined && ' '}
      {badge !== undefined && (
        <span className="ml-auto rounded-full bg-primary px-1.5 text-xs text-primary-foreground">
          {badge > 99 ? '99+' : badge}
          <span className="sr-only"> unread</span>
        </span>
      )}
    </NavLink>
  )
}

function VerifyEmailNotice({ email }: { email: string }) {
  const [state, setState] = useState<'idle' | 'sending' | 'sent' | 'failed'>('idle')

  const resend = async () => {
    setState('sending')
    const { response } = await api.POST('/v1/auth/email/verification-notification')
    setState(response.ok ? 'sent' : 'failed')
  }

  return (
    <Notice tone="warn" className="mb-6 flex flex-wrap items-center justify-between gap-2">
      <span>
        Verify your address: we sent a link to <span className="font-medium">{email}</span>. AI work starts once it is
        verified.
      </span>
      {state === 'sent' ? (
        <span>Sent. Check your inbox.</span>
      ) : (
        <Button variant="secondary" size="sm" disabled={state === 'sending'} onClick={() => void resend()}>
          {state === 'failed' ? 'Try again' : 'Send it again'}
        </Button>
      )}
    </Notice>
  )
}
