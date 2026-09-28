import { useQueryClient } from '@tanstack/react-query'
import { useEffect, useState } from 'react'
import { NavLink, Outlet, redirect, useNavigate } from 'react-router'
import { Logo } from '~/components/brand/logo'
import { Button } from '~/components/ui/button'
import { Notice } from '~/components/ui/notice'
import { UpgradeDialog } from '~/features/billing/UpgradeDialog'
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

  return (
    <div className="min-h-screen bg-surface">
      <header className="sticky top-0 z-10 border-b-[0.5px] border-hairline bg-background">
        <div className="mx-auto flex max-w-[1200px] items-center gap-6 px-6 py-3">
          <Logo />
          <nav aria-label="Workspace" className="flex gap-1 text-sm">
            <NavItem to="/app" end>
              Overview
            </NavItem>
            <NavItem to="/app/settings">Settings</NavItem>
            {user.is_staff && <NavItem to="/admin">Staff</NavItem>}
          </nav>
          <div className="ml-auto flex items-center gap-3 text-sm">
            <span className="text-muted-foreground">{user.workspace?.name}</span>
            <Button variant="ghost" size="sm" onClick={() => void signOut()}>
              Sign out
            </Button>
          </div>
        </div>
      </header>
      <main className="mx-auto max-w-[1200px] px-6 py-8">
        {!user.email_verified && <VerifyEmailNotice email={user.email} />}
        <Outlet />
      </main>
      <UpgradeDialog />
    </div>
  )
}

function NavItem({ to, end, children }: { to: string; end?: boolean; children: string }) {
  return (
    <NavLink
      to={to}
      end={end ?? false}
      className={({ isActive }) =>
        cn(
          'rounded-lg px-3 py-1.5',
          isActive ? 'bg-surface text-foreground' : 'text-muted-foreground hover:text-foreground',
        )
      }
    >
      {children}
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
