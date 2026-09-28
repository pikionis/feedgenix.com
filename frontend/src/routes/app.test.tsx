import { QueryClientProvider } from '@tanstack/react-query'
import { render, screen } from '@testing-library/react'
import { http, HttpResponse } from 'msw'
import { createRoutesStub } from 'react-router'
import { expect, it } from 'vitest'
import { createQueryClient } from '~/lib/query'
import { server } from '~/test/server'
import AppShell from './app'

const url = `${window.location.origin}/api/health`

function renderShell() {
  const Stub = createRoutesStub([{ path: '/app', Component: AppShell }])
  render(
    <QueryClientProvider client={createQueryClient()}>
      <Stub initialEntries={['/app']} />
    </QueryClientProvider>,
  )
}

it('shows that the API is reachable', async () => {
  server.use(http.get(url, () => HttpResponse.json({ status: 'ok', database: 'ok' })))
  renderShell()

  expect(await screen.findByText('API ok')).toBeInTheDocument()
})

it('says so when the API is degraded', async () => {
  server.use(http.get(url, () => HttpResponse.json({ status: 'degraded', database: 'down' }, { status: 503 })))
  renderShell()

  expect(await screen.findByText('API unavailable')).toBeInTheDocument()
})
