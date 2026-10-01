import type { Feed, FeedImport, ImportSummary, LimitHit, Plan, SessionUser, Workspace } from '~/lib/api/types'

/** Typed from the OpenAPI contract, so a fixture can never drift from what the API returns. */
export const origin = window.location.origin
export const apiUrl = (path: string): string => `${origin}/api${path}`

export function sessionUser(overrides: Partial<SessionUser> = {}): SessionUser {
  return {
    id: 1,
    name: 'Alice Martin',
    email: 'alice@example.com',
    initials: 'AL',
    avatar_url: null,
    locale: 'en',
    is_staff: false,
    email_verified: true,
    has_password: true,
    workspace: { id: 10, name: "Alice Martin's workspace", slug: 'alice-martin', role: 'owner', plan: 'free' },
    ...overrides,
  }
}

const FEATURES_OFF: Plan['features'] = {
  ai_translation: true,
  ai_agent: false,
  rag_memory: false,
  field_instructions: false,
  model_choice: false,
  priority_queue: false,
  dedicated_infrastructure: false,
  api_mcp: false,
}

export const GIB = 1024 ** 3

export function plan(key: 'free' | 'pro' | 'ultra' | 'custom'): Plan {
  const table = {
    free: { name: 'Free', monthly: 0, feeds: 1, languages: 1, products: 500, gb: 2, rows: 5_000_000 },
    pro: { name: 'Pro', monthly: 40, feeds: 5, languages: 5, products: 50_000, gb: 10, rows: 25_000_000 },
    ultra: { name: 'Ultra', monthly: 100, feeds: 20, languages: 10, products: null, gb: 50, rows: 100_000_000 },
    custom: { name: 'Custom', monthly: 180, feeds: null, languages: null, products: null, gb: 50, rows: 100_000_000 },
  }[key]
  const yearlyPerMonth = table.monthly * 0.75

  return {
    key,
    name: table.name,
    tagline: `${table.name} tagline`,
    price_monthly_eur: table.monthly,
    price_yearly_per_month_eur: yearlyPerMonth,
    price_yearly_eur: yearlyPerMonth * 12,
    yearly_savings_eur: table.monthly * 12 - yearlyPerMonth * 12,
    limits: {
      feeds: table.feeds,
      languages: table.languages,
      products_per_month: table.products,
      products_per_year: table.products === null ? null : table.products * 12,
      worker_slots: 1,
      feed_bytes: table.gb * GIB,
      feed_rows: table.rows,
    },
    overage_credits_per_product: key === 'pro' ? 10 : null,
    features:
      key === 'free' ? FEATURES_OFF : { ...FEATURES_OFF, ai_agent: true, rag_memory: true, field_instructions: true },
  }
}

export const plansResponse = {
  recommended: 'pro',
  annual_discount: 0.25,
  plans: [plan('free'), plan('pro'), plan('ultra'), plan('custom')],
}

export function workspace(overrides: Partial<Workspace> = {}): Workspace {
  return {
    workspace: { id: 10, name: "Alice Martin's workspace", slug: 'alice-martin', role: 'owner' },
    plan: plan('free'),
    recommended_plan: 'pro',
    entitlements: {
      plan: 'free',
      interval: 'month',
      paid_until: null,
      period: { start: '2027-01-01T00:00:00+00:00', end: '2027-02-01T00:00:00+00:00' },
      limits: { feeds: 1, languages: 1, products: 500, worker_slots: 1, feed_bytes: 2 * GIB, feed_rows: 5_000_000 },
      used: { feeds: 0, languages: 0 },
      overage: { available: false, enabled: true, credits_per_product: null },
    },
    languages: [],
    ...overrides,
  }
}

export const languagesResponse = {
  languages: [
    { code: 'de', name: 'German', native_name: 'Deutsch', direction: 'ltr' },
    { code: 'el', name: 'Greek', native_name: 'Ελληνικά', direction: 'ltr' },
    { code: 'fr', name: 'French', native_name: 'Français', direction: 'ltr' },
  ],
}

export const languageLimit: LimitHit = {
  error: 'plan_limit',
  limit: 'languages',
  allowed: 1,
  used: 0,
  requested: 2,
  plan: 'free',
  upgrade_to: 'pro',
  message: 'Your Free plan includes 1 target language. Pro gives you 5 target languages.',
}

const READ_AT = '2027-01-10T10:00:00+00:00'

export function importSummary(overrides: Partial<ImportSummary> = {}): ImportSummary {
  return {
    id: 100,
    feed_id: 7,
    origin: 'fetch',
    status: 'profiled',
    original_name: null,
    format: 'csv',
    row_count: 2,
    column_count: 3,
    reuses_import_id: null,
    failure: null,
    started_at: READ_AT,
    finished_at: READ_AT,
    created_at: READ_AT,
    ...overrides,
  }
}

export function feed(overrides: Partial<Feed> = {}): Feed {
  return {
    id: 7,
    name: 'Main shop',
    source: 'url',
    host: 'shop.example',
    status: 'active',
    parse_options: { encoding: null, delimiter: null, quoted: true, xml_item_path: null },
    latest_import: importSummary(),
    profile_import_id: 100,
    created_at: READ_AT,
    ...overrides,
  }
}

export function feedImport(overrides: Partial<FeedImport> = {}): FeedImport {
  return {
    ...importSummary(),
    can_reread: true,
    received_bytes: 64,
    containers: [],
    inner_name: null,
    encoding: { name: 'UTF-8', reason: 'valid_utf8' },
    delimiter: ';',
    xml_item_path: null,
    parse_options: { encoding: null, delimiter: null, quoted: true, xml_item_path: null },
    sku: { column: 'sku', confidence: 'high', empty: 0, duplicates: 0 },
    columns: [
      { position: 0, name: 'sku', filled: 2, fill_rate: 1, max_length: 3, kind: 'code', examples: ['A-1', 'A-2'] },
      {
        position: 1,
        name: 'title',
        filled: 2,
        fill_rate: 1,
        max_length: 6,
        kind: 'text',
        examples: ['Hammer', 'Nail'],
      },
      { position: 2, name: 'price', filled: 1, fill_rate: 0.5, max_length: 4, kind: 'decimal', examples: ['9.90'] },
    ],
    sample_rows: [
      { sku: 'A-1', title: 'Hammer', price: '9.90' },
      { sku: 'A-2', title: 'Nail', price: '' },
    ],
    warnings: [],
    ...overrides,
  }
}

export const feedLimit: LimitHit = {
  error: 'plan_limit',
  limit: 'feeds',
  allowed: 1,
  used: 1,
  requested: 2,
  plan: 'free',
  upgrade_to: 'pro',
  message: 'Your Free plan includes 1 feed. Pro gives you 5 feeds.',
}
