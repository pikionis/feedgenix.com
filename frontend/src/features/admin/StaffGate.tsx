import { useQuery, useQueryClient } from '@tanstack/react-query'
import type { ReactNode } from 'react'
import { Card } from '~/components/ui/card'
import { api } from '~/lib/api/client'
import { twoFactorStatusQuery } from './queries'
import { TwoFactorCodeForm } from './TwoFactorCodeForm'
import { TwoFactorSetup } from './TwoFactorSetup'

/**
 * Every staff page sits behind the second factor: set it up once, prove it once per session. The
 * API enforces the same; this only shows the way through instead of an error.
 */
export function StaffGate({ children }: { children: ReactNode }) {
  const client = useQueryClient()
  const status = useQuery(twoFactorStatusQuery)
  const refresh = () => client.invalidateQueries({ queryKey: twoFactorStatusQuery.queryKey })

  if (status.data === undefined) return <div className="h-0.5 w-full animate-pulse bg-primary" aria-hidden="true" />

  if (!status.data.enabled) {
    return (
      <Card className="max-w-lg">
        <h1 className="mb-4 text-xl font-medium tracking-tight">Two-factor sign-in</h1>
        <TwoFactorSetup onDone={() => void refresh()} />
      </Card>
    )
  }

  if (!status.data.passed) {
    return (
      <Card className="max-w-lg">
        <h1 className="mb-1 text-xl font-medium tracking-tight">Confirm it is you</h1>
        <p className="mb-5 text-sm text-muted-foreground">The staff area asks for a code once per session.</p>
        <TwoFactorCodeForm
          label="Code"
          hint="From your authenticator app, or one of your recovery codes."
          submitLabel="Continue"
          onSubmit={async (code) => {
            const { error, response } = await api.POST('/v1/security/two-factor/challenge', { body: { code } })
            if (response.ok) await refresh()

            return { ok: response.ok, status: response.status, error }
          }}
        />
      </Card>
    )
  }

  return children
}
