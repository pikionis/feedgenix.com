import { useQuery } from '@tanstack/react-query'
import { useEffect, type ReactNode } from 'react'
import { useLocation } from 'react-router'
import { Card } from '~/components/ui/card'
import { PlanCards } from '~/features/billing/PlanCards'
import { PasswordForm, ProfileForm } from '~/features/workspace/AccountForms'
import { LanguageSettings } from '~/features/workspace/LanguageSettings'
import { workspaceQuery } from '~/features/workspace/queries'
import { WorkspaceNameForm } from '~/features/workspace/WorkspaceNameForm'
import { useSession } from '~/lib/session'
import type { Route } from './+types/settings'

export const meta: Route.MetaFunction = () => [
  { title: 'Settings — FeedGenix' },
  { name: 'robots', content: 'noindex' },
]

function Section({
  id,
  title,
  description,
  wide,
  children,
}: {
  id?: string
  title: string
  description: string
  wide?: boolean
  children: ReactNode
}) {
  return (
    <section
      id={id}
      aria-labelledby={id !== undefined ? `${id}-title` : undefined}
      className={wide === true ? 'flex flex-col gap-4 py-8' : 'grid gap-6 py-8 md:grid-cols-[240px_1fr]'}
      style={{ borderTop: '0.5px solid var(--border-hair)' }}
    >
      <div>
        <h2 id={id !== undefined ? `${id}-title` : undefined} className="font-medium">
          {title}
        </h2>
        <p className="mt-1 text-sm text-muted-foreground">{description}</p>
      </div>
      <div>{children}</div>
    </section>
  )
}

export default function Settings() {
  const session = useSession()
  const workspace = useQuery(workspaceQuery)
  const { hash } = useLocation()

  // Links like /app/settings#plan scroll once the sections exist.
  useEffect(() => {
    if (workspace.data !== undefined && hash !== '') {
      document.getElementById(hash.slice(1))?.scrollIntoView({ block: 'start' })
    }
  }, [workspace.data, hash])

  if (workspace.data === undefined || session.data === undefined || session.data === null) {
    return <div className="h-0.5 w-full animate-pulse bg-primary" aria-hidden="true" />
  }

  return (
    <div>
      <h1 className="mb-6 text-2xl font-medium tracking-tight">Settings</h1>
      <Section
        id="workspace"
        title="Workspace"
        description="Everything in it — feeds, memory, exports — is private to it."
      >
        <Card>
          <WorkspaceNameForm workspace={workspace.data.workspace} />
        </Card>
      </Section>
      <Section id="languages" title="Target languages" description="The languages your feeds are translated into.">
        <Card>
          <LanguageSettings
            key={workspace.data.languages.map((language) => language.code).join('|')}
            workspace={workspace.data}
          />
        </Card>
      </Section>
      <Section id="profile" title="Profile" description="How you appear in your workspace.">
        <Card>
          <ProfileForm user={session.data} />
        </Card>
      </Section>
      <Section id="security" title="Security" description="The password you sign in with.">
        <Card>
          <PasswordForm hasPassword={session.data.has_password} />
        </Card>
      </Section>
      <Section id="plan" title="Plan" description="Upgrade any time. Yearly is paid once and saves 25%." wide>
        <PlanCards current={workspace.data.plan.key} />
      </Section>
    </div>
  )
}
