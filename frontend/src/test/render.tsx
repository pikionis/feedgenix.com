import { QueryClientProvider } from '@tanstack/react-query'
import { render } from '@testing-library/react'
import { createRoutesStub } from 'react-router'
import { createQueryClient } from '~/lib/query'

type Routes = Parameters<typeof createRoutesStub>[0]

/** Render route components the way the app does: a router and a fresh query client. */
export function renderRoutes(routes: Routes, entry: string) {
  const Stub = createRoutesStub(routes)
  const client = createQueryClient()
  client.setDefaultOptions({ queries: { retry: false } })

  const result = render(
    <QueryClientProvider client={client}>
      <Stub initialEntries={[entry]} />
    </QueryClientProvider>,
  )

  return { ...result, client }
}
