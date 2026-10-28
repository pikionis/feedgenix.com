import { screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { http, HttpResponse } from 'msw'
import { expect, it } from 'vitest'
import { UpgradeDialog } from '~/features/billing/UpgradeDialog'
import type { Workspace } from '~/lib/api/types'
import { apiUrl, languageLimit, languagesResponse, plansResponse, workspace } from '~/test/fixtures'
import { csrfCookie } from '~/test/handlers'
import { renderRoutes } from '~/test/render'
import { server } from '~/test/server'
import { LanguageSettings } from './LanguageSettings'

function renderSettings(current: Workspace) {
  server.use(
    csrfCookie(),
    http.get(apiUrl('/languages'), () => HttpResponse.json(languagesResponse)),
    http.get(apiUrl('/plans'), () => HttpResponse.json(plansResponse)),
  )

  return renderRoutes(
    [
      {
        path: '/app/settings',
        Component: () => (
          <>
            <LanguageSettings workspace={current} />
            <UpgradeDialog />
          </>
        ),
      },
    ],
    '/app/settings',
  )
}

async function add(name: string) {
  await userEvent
    .setup()
    .selectOptions(
      await screen.findByLabelText('Add a language'),
      await screen.findByRole('option', { name: new RegExp(name) }),
    )
}

it('saves the chosen languages', async () => {
  let sent: unknown = null
  server.use(
    http.put(apiUrl('/v1/workspace/languages'), async ({ request }) => {
      sent = await request.json()
      return HttpResponse.json(workspace({ languages: [languagesResponse.languages[0]!] }))
    }),
  )
  renderSettings(workspace())

  await add('German')
  await userEvent.setup().click(screen.getByRole('button', { name: 'Save languages' }))

  expect(await screen.findByText('Saved')).toBeInTheDocument()
  expect(sent).toEqual({ languages: ['de'] })
})

it('opens the upgrade dialog, and saves nothing, when the list is over the plan', async () => {
  server.use(http.put(apiUrl('/v1/workspace/languages'), () => HttpResponse.json(languageLimit, { status: 402 })))
  renderSettings(workspace())

  await add('German')
  await add('French')
  await userEvent.setup().click(screen.getByRole('button', { name: 'Save languages' }))

  expect(await screen.findByRole('dialog')).toHaveTextContent(languageLimit.message)
  expect(screen.queryByText('Saved')).not.toBeInTheDocument()
})

it('is read-only for members', async () => {
  renderSettings(workspace({ workspace: { id: 10, name: 'Shop', slug: 'shop', role: 'member' } }))

  expect(await screen.findByText('Only the owner or an admin can change the languages.')).toBeInTheDocument()
  expect(screen.queryByRole('button', { name: 'Save languages' })).not.toBeInTheDocument()
})

it('marks the languages the plan paused', async () => {
  const languages = languagesResponse.languages.filter((language) => language.code !== 'el')
  renderSettings(workspace({ languages, paused_languages: ['fr'] }))

  const selected = await screen.findByRole('list', { name: 'Selected target languages' })
  // Once the names are in.
  await expect.poll(() => selected.textContent).toContain('Frenchpaused by your plan')
  expect(selected).not.toHaveTextContent('Germanpaused')
})
