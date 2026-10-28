import { screen, within } from '@testing-library/react'
import userEvent from '@testing-library/user-event'
import { http, HttpResponse } from 'msw'
import { expect, it, vi } from 'vitest'
import type { AdminAuditLine, AdminWorkspace, AdminWorkspaceRow } from '~/lib/api/types'
import { apiUrl, feed, plansResponse, sessionUser } from '~/test/fixtures'
import { csrfCookie } from '~/test/handlers'
import { renderRoutes } from '~/test/render'
import { server } from '~/test/server'
import AdminAudit from './audit'
import AdminLayout, { clientLoader } from './layout'
import AdminWorkspacePage from './workspace'
import AdminWorkspaces from './workspaces'

function row(overrides: Partial<AdminWorkspaceRow> = {}): AdminWorkspaceRow {
  return {
    id: 7,
    name: 'Alpine Outfitters',
    slug: 'alpine',
    plan: 'pro',
    plan_name: 'Pro',
    interval: 'month',
    internal: false,
    frozen: false,
    ai_paused: false,
    owner: { name: 'Ada Alp', email: 'ada@alpine.example', verified: true },
    feeds: 3,
    feeds_paused: 0,
    languages: 2,
    products_this_period: 1234,
    credits_available: 2500,
    ai_credits_30d: 41,
    last_import_at: null,
    created_at: '2026-10-01T10:00:00+00:00',
    ...overrides,
  }
}

function detail(overrides: Partial<AdminWorkspace> = {}): AdminWorkspace {
  return {
    workspace: row(),
    plan: {
      started_at: null,
      ends_at: null,
      status: 'active',
      overrides: null,
      period_start: '2026-10-01T00:00:00+00:00',
      period_end: '2026-11-01T00:00:00+00:00',
      limits: { feeds: 5, languages: 5, products: 50000 },
    },
    members: [{ id: 1, name: 'Ada Alp', email: 'ada@alpine.example', role: 'owner', verified: true, staff: false }],
    languages: [
      { code: 'de', paused: false },
      { code: 'fr', paused: true },
    ],
    feeds: [feed({ id: 11, name: 'Main shop', plan_paused: true })],
    held: [{ reason: 'language_paused', units: 4, feeds: 1 }],
    credits: {
      available: 2500,
      reserved: 0,
      transactions: [
        {
          id: 1,
          kind: 'grant',
          credits: 2500,
          balance_after: 2500,
          note: 'beta budget',
          created_at: '2026-10-02T10:00:00+00:00',
        },
      ],
    },
    ai_usage_30d: [
      {
        purpose: 'translate',
        calls: 12,
        failed: 1,
        input_tokens: 24000,
        output_tokens: 6000,
        thinking_tokens: 500,
        credits: 41,
      },
    ],
    notices: [],
    audit: [],
    ...overrides,
  }
}

function auditLine(overrides: Partial<AdminAuditLine> = {}): AdminAuditLine {
  return {
    id: 1,
    user_email: 'staff@feedgenix.example',
    action: 'view',
    method: 'GET',
    path: '/api/v1/admin/workspaces/7',
    status: 200,
    subject_tenant_id: 7,
    details: null,
    created_at: '2026-10-28T12:00:00+00:00',
    ...overrides,
  }
}

function staff({ passed = true } = {}) {
  server.use(
    csrfCookie(),
    http.get(apiUrl('/v1/auth/user'), () => HttpResponse.json({ user: sessionUser({ is_staff: true }) })),
    http.get(apiUrl('/v1/security/two-factor'), () =>
      HttpResponse.json({ enabled: true, pending: false, recovery_codes_left: 8, passed }),
    ),
  )
}

function renderStaff(entry: string) {
  return renderRoutes(
    [
      {
        path: '/admin',
        loader: ({ request }) => clientLoader({ request } as Parameters<typeof clientLoader>[0]),
        Component: AdminLayout,
        children: [
          { path: 'workspaces', Component: AdminWorkspaces },
          { path: 'workspaces/:workspaceId', Component: AdminWorkspacePage },
          { path: 'audit', Component: AdminAudit },
        ],
      },
    ],
    entry,
  )
}

it('asks for the code before any staff page loads its data', async () => {
  staff({ passed: false })
  let asked = 0
  server.use(
    http.get(apiUrl('/v1/admin/workspaces'), () => {
      asked++
      return HttpResponse.json({ workspaces: [], meta: { page: 1, pages: 1, total: 0 } })
    }),
  )

  renderStaff('/admin/workspaces')

  expect(await screen.findByRole('heading', { name: 'Confirm it is you' })).toBeInTheDocument()
  expect(asked).toBe(0)
})

it('lists the workspaces and searches them on the server', async () => {
  staff()
  const searches: (string | null)[] = []
  server.use(
    http.get(apiUrl('/v1/admin/workspaces'), ({ request }) => {
      const search = new URL(request.url).searchParams.get('search')
      searches.push(search)
      const rows =
        search === 'harbour'
          ? [row({ id: 9, name: 'Harbour Tools', slug: 'harbour', owner: null, plan: 'free', plan_name: 'Free' })]
          : [
              row(),
              row({
                id: 8,
                name: 'Coast Supply',
                slug: 'coast',
                feeds_paused: 2,
                owner: { name: 'C', email: 'c@coast.example', verified: false },
              }),
            ]
      return HttpResponse.json({ workspaces: rows, meta: { page: 1, pages: 1, total: rows.length } })
    }),
  )
  const user = userEvent.setup()

  renderStaff('/admin/workspaces')

  const table = await screen.findByRole('table', { name: 'Workspaces' })
  expect(within(table).getByRole('link', { name: /Alpine Outfitters/ })).toHaveAttribute('href', '/admin/workspaces/7')
  const coast = within(table).getByRole('row', { name: /Coast Supply/ })
  expect(within(coast).getByText('not verified')).toBeInTheDocument()
  expect(within(coast).getByText('2 paused')).toBeInTheDocument()
  expect(screen.getByText('2 workspaces, newest first. AI spend in credits.')).toBeInTheDocument()

  await user.type(screen.getByLabelText('Name, slug, owner email or id'), 'harbour')
  await user.click(screen.getByRole('button', { name: 'Search' }))

  expect(await screen.findByRole('link', { name: /Harbour Tools/ })).toBeInTheDocument()
  expect(screen.queryByText('Alpine Outfitters')).not.toBeInTheDocument()
  expect(searches).toEqual([null, 'harbour'])
})

it('shows one workspace: usage, members, feeds, held work in words, AI in credits and tokens', async () => {
  staff()
  server.use(http.get(apiUrl('/v1/admin/workspaces/7'), () => HttpResponse.json(detail())))

  renderStaff('/admin/workspaces/7')

  expect(await screen.findByRole('heading', { name: 'Alpine Outfitters' })).toBeInTheDocument()
  expect(screen.getByText('Into de, fr (paused).')).toBeInTheDocument()
  expect(within(screen.getByRole('list', { name: 'Feeds' })).getByText('Paused by the plan')).toBeInTheDocument()
  expect(
    within(screen.getByRole('table', { name: 'Work that waits' })).getByText('Language paused by the plan'),
  ).toBeInTheDocument()
  const ai = within(screen.getByRole('table', { name: 'AI, last 30 days' })).getByRole('row', { name: /translate/i })
  expect(
    within(ai)
      .getAllByRole('cell')
      .map((cell) => cell.textContent),
  ).toEqual(['translate', '12', '1', '24,000', '6,000', '500', '41'])
  expect(within(screen.getByRole('table', { name: 'Credits' })).getByText(/beta budget/)).toBeInTheDocument()
})

it('says when a workspace does not exist', async () => {
  staff()
  server.use(http.get(apiUrl('/v1/admin/workspaces/404'), () => HttpResponse.json({ message: 'x' }, { status: 404 })))

  renderStaff('/admin/workspaces/404')

  expect(await screen.findByRole('heading', { name: 'This workspace does not exist' })).toBeInTheDocument()
})

it('shows the staff trail with links to the workspaces', async () => {
  staff()
  server.use(
    http.get(apiUrl('/v1/admin/audit'), () =>
      HttpResponse.json({
        audit: [
          auditLine(),
          auditLine({ id: 2, path: '/api/v1/admin/workspaces', subject_tenant_id: null, status: 404 }),
        ],
      }),
    ),
  )

  renderStaff('/admin/audit')

  const table = await screen.findByRole('table', { name: 'Staff trail' })
  expect(within(table).getByRole('link', { name: '#7' })).toHaveAttribute('href', '/admin/workspaces/7')
  expect(within(table).getAllByRole('row')).toHaveLength(3)
})

it('grants credits with why, and shows what the wallet holds now', async () => {
  staff()
  const bodies: unknown[] = []
  let loads = 0
  server.use(
    http.get(apiUrl('/plans'), () => HttpResponse.json(plansResponse)),
    http.get(apiUrl('/v1/admin/workspaces/7'), () => {
      loads++
      return HttpResponse.json(detail())
    }),
    http.post(apiUrl('/v1/admin/workspaces/7/credits'), async ({ request }) => {
      bodies.push(await request.json())
      return HttpResponse.json({ granted: 100000, available: 102500 })
    }),
  )
  const user = userEvent.setup()
  renderStaff('/admin/workspaces/7')
  const form = within(await screen.findByRole('form', { name: 'Grant credits' }))

  // Checked here first: an exponent is not an amount, and nothing is sent.
  await user.type(form.getByLabelText('Worth, in euros'), '1e3')
  await user.type(form.getByLabelText('Why'), 'beta budget')
  await user.click(form.getByRole('button', { name: 'Grant credits' }))
  expect(await form.findByText('A positive amount in euros, like 10 or 2.50.')).toBeInTheDocument()
  expect(bodies).toEqual([])

  await user.clear(form.getByLabelText('Worth, in euros'))
  await user.type(form.getByLabelText('Worth, in euros'), '10')
  await user.click(form.getByRole('button', { name: 'Grant credits' }))

  expect(await form.findByText('Granted 100,000 credits. Available now: 102,500.')).toBeInTheDocument()
  expect(bodies).toEqual([{ euros: '10', note: 'beta budget' }])
  await vi.waitFor(() => expect(loads).toBe(2))
})

it("shows the server's reason when a grant is refused", async () => {
  staff()
  server.use(
    http.get(apiUrl('/plans'), () => HttpResponse.json(plansResponse)),
    http.get(apiUrl('/v1/admin/workspaces/7'), () => HttpResponse.json(detail())),
    http.post(apiUrl('/v1/admin/workspaces/7/credits'), () =>
      HttpResponse.json(
        { message: 'x', errors: { euros: ['The amount must be a positive number of euros up to 10000.'] } },
        { status: 422 },
      ),
    ),
  )
  const user = userEvent.setup()
  renderStaff('/admin/workspaces/7')
  const form = within(await screen.findByRole('form', { name: 'Grant credits' }))

  await user.type(form.getByLabelText('Worth, in euros'), '10000.01')
  await user.type(form.getByLabelText('Why'), 'too much')
  await user.click(form.getByRole('button', { name: 'Grant credits' }))

  expect(await form.findByText('The amount must be a positive number of euros up to 10000.')).toBeInTheDocument()
})

it('changes the plan only after it says what pauses, and reports what did', async () => {
  staff()
  const bodies: unknown[] = []
  server.use(
    http.get(apiUrl('/plans'), () => HttpResponse.json(plansResponse)),
    http.get(apiUrl('/v1/admin/workspaces/7'), () => HttpResponse.json(detail())),
    http.post(apiUrl('/v1/admin/workspaces/7/plan'), async ({ request }) => {
      bodies.push(await request.json())
      return HttpResponse.json({
        plan: 'free',
        plan_name: 'Free',
        ends_at: null,
        feeds_paused: 2,
        feeds_resumed: 0,
        languages_paused: 1,
        languages_resumed: 0,
      })
    }),
  )
  const user = userEvent.setup()
  renderStaff('/admin/workspaces/7')
  const form = within(await screen.findByRole('form', { name: 'Change the plan' }))

  await form.findByRole('option', { name: 'Free' })
  await user.selectOptions(form.getByLabelText('Plan'), 'free')
  await user.click(form.getByRole('button', { name: 'Change the plan' }))
  const dialog = await screen.findByRole('dialog', { name: 'Put Alpine Outfitters on Free?' })
  expect(dialog).toHaveTextContent('Nothing is deleted')
  await user.click(within(dialog).getByRole('button', { name: 'Cancel' }))
  expect(bodies).toEqual([])

  await user.click(form.getByRole('button', { name: 'Change the plan' }))
  await user.click(within(await screen.findByRole('dialog')).getByRole('button', { name: 'Put on Free' }))

  expect(await form.findByText('On Free now. Paused 2 feeds, 1 language.')).toBeInTheDocument()
  expect(bodies).toEqual([{ plan: 'free', interval: 'month' }])
})

it('sends the paid-until date with a paid plan', async () => {
  staff()
  const bodies: unknown[] = []
  server.use(
    http.get(apiUrl('/plans'), () => HttpResponse.json(plansResponse)),
    http.get(apiUrl('/v1/admin/workspaces/7'), () =>
      HttpResponse.json(detail({ workspace: row({ plan: 'free', plan_name: 'Free' }) })),
    ),
    http.post(apiUrl('/v1/admin/workspaces/7/plan'), async ({ request }) => {
      bodies.push(await request.json())
      return HttpResponse.json({
        plan: 'ultra',
        plan_name: 'Ultra',
        ends_at: '2027-03-31T23:59:59+00:00',
        feeds_paused: 0,
        feeds_resumed: 0,
        languages_paused: 0,
        languages_resumed: 0,
      })
    }),
  )
  const user = userEvent.setup()
  renderStaff('/admin/workspaces/7')
  const form = within(await screen.findByRole('form', { name: 'Change the plan' }))

  await form.findByRole('option', { name: 'Free' })
  await user.selectOptions(form.getByLabelText('Plan'), 'ultra')
  await user.selectOptions(form.getByLabelText('Billed'), 'year')
  await user.type(form.getByLabelText('Paid until'), '2027-03-31')
  await user.click(form.getByRole('button', { name: 'Change the plan' }))
  await user.click(within(await screen.findByRole('dialog')).getByRole('button', { name: 'Put on Ultra' }))

  expect(await form.findByText('On Ultra now.')).toBeInTheDocument()
  expect(bodies).toEqual([{ plan: 'ultra', interval: 'year', until: '2027-03-31' }])
})
