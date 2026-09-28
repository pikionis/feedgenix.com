import { useQuery } from '@tanstack/react-query'
import { Logo } from '~/components/brand/logo'
import { fetchHealth } from '~/lib/api/health'
import type { Route } from './+types/app'

export const meta: Route.MetaFunction = () => [
  { title: 'Workspace — FeedGenix' },
  { name: 'robots', content: 'noindex' },
]

/** The workspace shell. Sign-in, tenancy and the real navigation arrive in M1. */
export default function AppShell() {
  const health = useQuery({ queryKey: ['health'], queryFn: fetchHealth })

  const status = health.isPending ? 'Connecting…' : health.data === 'ok' ? 'API ok' : 'API unavailable'

  return (
    <div className="min-h-screen bg-surface">
      <header className="flex items-center justify-between border-b-[0.5px] border-hairline bg-background px-6 py-3">
        <Logo />
        <span data-testid="api-status" className="text-sm text-muted-foreground">
          {status}
        </span>
      </header>
      <main className="px-8 py-7">
        <h1 className="text-2xl font-medium tracking-tight">Workspace</h1>
        <p className="mt-2 text-muted-foreground">Your feeds will appear here.</p>
      </main>
    </div>
  )
}
