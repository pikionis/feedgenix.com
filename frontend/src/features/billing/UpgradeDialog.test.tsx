import { act, screen, waitFor } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { http, HttpResponse } from 'msw'
import { expect, it } from 'vitest'
import { limitHit } from '~/lib/events'
import { apiUrl, languageLimit, plansResponse } from '~/test/fixtures'
import { renderRoutes } from '~/test/render'
import { server } from '~/test/server'
import { UpgradeDialog } from './UpgradeDialog'

function renderDialog() {
  server.use(http.get(apiUrl('/plans'), () => HttpResponse.json(plansResponse)))

  return renderRoutes([{ path: '/app', Component: UpgradeDialog }], '/app')
}

it('opens on a plan limit with the server’s wording, the numbers and the plan that fits', async () => {
  renderDialog()

  act(() => limitHit.emit(languageLimit))

  const dialog = await screen.findByRole('dialog')
  expect(dialog).toHaveTextContent('You have reached your plan’s limit')
  expect(dialog).toHaveTextContent(languageLimit.message)
  expect(dialog).toHaveTextContent('2 / 1')
  expect(await screen.findByRole('link', { name: 'See Pro' })).toHaveAttribute('href', '/app/settings#plan')
})

it('offers a conversation when no plan fits', async () => {
  renderDialog()

  act(() => limitHit.emit({ ...languageLimit, upgrade_to: null }))

  expect(await screen.findByText(/custom setup/)).toBeInTheDocument()
})

it('closes with “Not now”', async () => {
  renderDialog()
  act(() => limitHit.emit(languageLimit))

  await userEvent.setup().click(await screen.findByRole('button', { name: 'Not now' }))

  await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument())
})

it('says a feed’s size in words, without a bar of bytes', async () => {
  renderDialog()
  const message = 'This file is 3 GB; your Free plan reads feeds up to 2 GB. Pro reads feeds up to 10 GB.'

  act(() =>
    limitHit.emit({
      error: 'plan_limit',
      limit: 'feed_bytes',
      allowed: 2 * 1024 ** 3,
      used: null,
      requested: 3 * 1024 ** 3,
      plan: 'free',
      upgrade_to: 'pro',
      message,
    }),
  )

  const dialog = await screen.findByRole('dialog')
  expect(dialog).toHaveTextContent(message)
  expect(dialog).not.toHaveTextContent('Asked for')
  expect(await screen.findByText('Feeds up to 10 GB each')).toBeInTheDocument()
})
