import { screen, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { http, HttpResponse } from 'msw'
import { expect, it } from 'vitest'
import { apiUrl, plan, plansResponse, workspace } from '~/test/fixtures'
import { renderRoutes } from '~/test/render'
import { server } from '~/test/server'
import Billing from './billing'
import Checkout from './checkout'
import Plans from './plans'

function useApi(overrides: Parameters<typeof workspace>[0] = {}) {
  server.use(
    http.get(apiUrl('/v1/workspace'), () => HttpResponse.json(workspace(overrides))),
    http.get(apiUrl('/plans'), () => HttpResponse.json(plansResponse)),
  )
}

const routes = [
  { path: '/app/billing', Component: Billing },
  { path: '/app/billing/plans', Component: Plans },
  { path: '/app/billing/checkout', Component: Checkout },
]

/** The owner's pricing rule: no page shows a cost, a provider price or the markup. */
function expectNoCostLeak() {
  const text = document.body.textContent ?? ''
  for (const leak of [/¢/, /markup/i, /provider (cost|price)/i, /per row/i, /×\s*1\.1/]) expect(text).not.toMatch(leak)
}

it('shows the plan, its period and usage, and promises no payment it cannot take', async () => {
  useApi({
    plan: plan('pro'),
    entitlements: {
      ...workspace().entitlements,
      plan: 'pro',
      interval: 'year',
      paid_until: '2027-10-28T00:00:00+00:00',
      limits: { ...workspace().entitlements.limits, feeds: 5, languages: 5, products: 600_000 },
      used: { feeds: 3, languages: 4 },
      overage: { available: true, enabled: true, credits_per_product: 10 },
    },
  })
  renderRoutes(routes, '/app/billing')

  expect(await screen.findByText('Pro · yearly')).toBeInTheDocument()
  expect(screen.getByText(/paid until 28 October 2027/)).toBeInTheDocument()
  expect(screen.getByText(/10 credits per extra product/)).toBeInTheDocument()
  expect(screen.getByRole('link', { name: 'Change plan' })).toHaveAttribute('href', '/app/billing/plans')

  // Buying credits explains the pack, but the payment button stays off until checkout exists.
  await userEvent.click(screen.getByRole('button', { name: 'Buy credits' }))
  const dialog = await screen.findByRole('dialog', { name: 'Buy credits' })
  expect(within(dialog).getByText('500,000 credits', { selector: 'dd' })).toBeInTheDocument()
  expect(within(dialog).getByRole('button', { name: 'Checkout opens soon' })).toBeDisabled()
  expectNoCostLeak()
})

it('refuses a credit amount under €10', async () => {
  useApi()
  renderRoutes(routes, '/app/billing')

  await userEvent.click(await screen.findByRole('button', { name: 'Buy credits' }))
  const dialog = await screen.findByRole('dialog', { name: 'Buy credits' })
  await userEvent.click(within(dialog).getByRole('button', { name: /Other/ }))
  const amount = within(dialog).getByLabelText('Amount in euro')
  await userEvent.clear(amount)
  await userEvent.type(amount, '9.99')

  expect(amount).toHaveAttribute('aria-invalid', 'true')
  expect(within(dialog).getAllByText('—', { selector: 'dd' })).toHaveLength(2)
})

it('lists every plan with the current one marked, and leads the others to the confirmation step', async () => {
  useApi()
  renderRoutes(routes, '/app/billing/plans')

  const free = await screen.findByRole('article', { name: 'Free plan' })
  expect(within(free).getByText('Your plan')).toBeInTheDocument()
  const pro = screen.getByRole('article', { name: 'Pro plan' })
  expect(pro).toHaveTextContent('€30')
  expect(within(pro).getByRole('link', { name: 'Choose Pro' })).toHaveAttribute(
    'href',
    '/app/billing/checkout?plan=pro&period=yearly',
  )

  await userEvent.click(screen.getByRole('button', { name: 'Monthly' }))
  expect(pro).toHaveTextContent('€40')
  expect(within(pro).getByRole('link', { name: 'Choose Pro' })).toHaveAttribute(
    'href',
    '/app/billing/checkout?plan=pro&period=monthly',
  )
  expect(await screen.findByRole('cell', { name: 'Waits for next month' })).toBeInTheDocument()
  expectNoCostLeak()
})

it('confirms the price of the chosen plan and period before payment', async () => {
  useApi()
  renderRoutes(routes, '/app/billing/checkout?plan=ultra&period=monthly')

  expect(await screen.findByRole('heading', { name: 'Upgrade to Ultra' })).toBeInTheDocument()
  expect(screen.getByText('€100 + VAT')).toBeInTheDocument()
  await userEvent.click(screen.getByRole('button', { name: /Yearly/ }))
  expect(await screen.findByText('€900 + VAT')).toBeInTheDocument()
  expect(screen.getByRole('button', { name: 'Checkout opens soon' })).toBeDisabled()
  expectNoCostLeak()
})

it('never offers checkout for an unknown or free plan', async () => {
  useApi()
  renderRoutes(routes, '/app/billing/checkout?plan=free')

  expect(await screen.findByRole('heading', { name: 'Choose a paid plan first' })).toBeInTheDocument()
  expect(screen.queryByRole('button', { name: /Checkout/ })).not.toBeInTheDocument()
})
