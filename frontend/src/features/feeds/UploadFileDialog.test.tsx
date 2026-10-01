import { screen } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { http, HttpResponse } from 'msw'
import { expect, it } from 'vitest'
import { UpgradeDialog } from '~/features/billing/UpgradeDialog'
import { apiUrl, feed, plansResponse, workspace } from '~/test/fixtures'
import { csrfCookie } from '~/test/handlers'
import { renderRoutes } from '~/test/render'
import { server } from '~/test/server'
import { UploadFileDialog } from './UploadFileDialog'

it('steps aside for the upgrade dialog when the file is larger than the plan reads', async () => {
  const message = 'This file is 3 GB; your Free plan reads feeds up to 2 GB. Pro reads feeds up to 10 GB.'
  server.use(
    csrfCookie(),
    http.get(apiUrl('/v1/workspace'), () => HttpResponse.json(workspace())),
    http.get(apiUrl('/plans'), () => HttpResponse.json(plansResponse)),
    http.post(apiUrl('/v1/feeds/7/uploads'), () =>
      HttpResponse.json(
        {
          error: 'plan_limit',
          limit: 'feed_bytes',
          allowed: 2,
          used: null,
          requested: 3,
          plan: 'free',
          upgrade_to: 'pro',
          message,
        },
        { status: 402 },
      ),
    ),
  )
  let closed = false
  renderRoutes(
    [
      {
        path: '/app/feeds/7',
        Component: () => (
          <>
            <UploadFileDialog feed={feed({ source: 'upload', host: null })} onClose={() => (closed = true)} />
            <UpgradeDialog />
          </>
        ),
      },
    ],
    '/app/feeds/7',
  )

  const user = userEvent.setup()
  await user.upload(await screen.findByLabelText('File'), new File(['sku\nA-1\n'], 'huge.csv'))
  await user.click(screen.getByRole('button', { name: 'Upload' }))

  expect(await screen.findByText(message)).toBeInTheDocument()
  expect(closed).toBe(true)
})
