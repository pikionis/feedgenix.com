import type {
  ExportBuild,
  ExportProfile,
  ExportsResponse,
  Feed,
  FeedImport,
  ImportSummary,
  LimitHit,
  Mapping,
  MappingField,
  PipelineRun,
  Plan,
  ReviewPage,
  ReviewProduct,
  ReviewText,
  SessionUser,
  TranslationPlan,
  Workspace,
} from '~/lib/api/types'

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
    paused_languages: [],
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
    mapping_status: null,
    run: null,
    plan_paused: false,
    exports: { count: 0, published: 0 },
    created_at: READ_AT,
    ...overrides,
  }
}

export function pipelineRun(overrides: Partial<PipelineRun> = {}): PipelineRun {
  return {
    id: 40,
    stage: 'plan',
    status: 'awaiting_decision',
    counts: { created: 5 },
    warnings: [],
    failure_message: null,
    ...overrides,
  }
}

/** A German feed with titles and sizes, English and French in the workspace, English chosen. */
export function translationPlan(overrides: Partial<TranslationPlan> = {}): TranslationPlan {
  return {
    version: 2,
    saved: true,
    fields: [
      { field: 'title', label: 'Title', mode: 'translate', sources: [{ locale: 'de', products: 5 }] },
      { field: 'size', label: 'Size', mode: 'keep', sources: [{ locale: 'de', products: 5 }] },
    ],
    target_locales: ['en'],
    auto_translate: false,
    workspace_locales: [
      { code: 'en', name: 'English', paused: false },
      { code: 'fr', name: 'French', paused: false },
    ],
    work: [
      { field: 'title', target_locale: 'en', mode: 'translate', texts: 5, memory: 2 },
      { field: 'size', target_locale: 'en', mode: 'keep', texts: 5, memory: 0 },
    ],
    estimate: { available: true, unavailable_reason: null, about_credits: 1200, at_most_credits: 4800 },
    allowance: { held: false, new: 3, over: 0, allowance: 500, used: 10, resets_at: '2027-02-01T00:00:00+00:00' },
    run: pipelineRun(),
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

export function mappingField(overrides: Partial<MappingField> = {}): MappingField {
  return {
    key: 'title@de',
    target: 'title',
    label: 'Title',
    locale: 'de',
    strategy: 'direct',
    source_column: 'Titel',
    recipe: null,
    candidate_column: null,
    origin: 'heuristic',
    confidence: 0.85,
    reason: 'The column name matches Title.',
    status: 'validated',
    issues: [],
    samples: ['Wanderschuh aus Leder', 'Regenjacke'],
    ...overrides,
  }
}

/** A drafted mapping of a small German shop feed: SKU, price and title mapped, colour refused. */
export function mapping(overrides: Partial<Mapping> = {}): Mapping {
  return {
    id: 3,
    feed_id: 7,
    status: 'draft',
    version: 4,
    source_locale: 'de',
    approved_at: null,
    ai: { status: 'none', failure: null, available: true, unavailable_reason: null, estimate_credits: 1200 },
    columns: [
      { position: 0, name: 'Artikelnummer', kind: 'code', fill_rate: 1, protected: true, examples: ['AO-1'] },
      { position: 1, name: 'Titel', kind: 'text', fill_rate: 1, protected: false, examples: ['Wanderschuh'] },
      { position: 2, name: 'Preis CHF', kind: 'decimal', fill_rate: 1, protected: true, examples: ['189.90'] },
      { position: 3, name: 'Grösse', kind: 'text', fill_rate: 1, protected: false, examples: ['M', 'L'] },
      { position: 4, name: 'Beschreibung', kind: 'text', fill_rate: 1, protected: false, examples: ['Robust'] },
    ],
    fields: [
      mappingField({
        key: 'sku',
        target: 'sku',
        label: 'SKU',
        locale: null,
        source_column: 'Artikelnummer',
        samples: ['AO-1'],
      }),
      mappingField({
        key: 'price',
        target: 'price',
        label: 'Price',
        locale: null,
        source_column: 'Preis CHF',
        samples: ['189.90'],
      }),
      mappingField(),
      mappingField({
        key: 'color@de',
        target: 'color',
        label: 'Color',
        strategy: 'none',
        source_column: null,
        candidate_column: 'Grösse',
        origin: 'ai',
        status: 'rejected',
        issues: [
          {
            type: 'values_do_not_fit',
            severity: 'error',
            count: 1,
            message: '4 of 4 values look like sizes (M, XL, 42): they belong in Size. Left empty.',
          },
        ],
        samples: [],
      }),
    ],
    available_fields: [
      {
        key: 'sku',
        label: 'SKU',
        class: 'golden',
        kind: 'code',
        description: "The seller's own product id.",
        localized: false,
        composable: false,
      },
      {
        key: 'price',
        label: 'Price',
        class: 'golden',
        kind: 'money',
        description: 'The regular selling price.',
        localized: false,
        composable: false,
      },
      {
        key: 'title',
        label: 'Title',
        class: 'translatable',
        kind: 'text',
        description: "The product's name.",
        localized: true,
        composable: false,
      },
      {
        key: 'color',
        label: 'Color',
        class: 'translatable',
        kind: 'text',
        description: 'The colour name.',
        localized: true,
        composable: false,
      },
      {
        key: 'warranty',
        label: 'Warranty',
        class: 'translatable',
        kind: 'text',
        description: 'The warranty terms.',
        localized: true,
        composable: false,
      },
    ],
    ...overrides,
  }
}

export const SOURCE_HASH = 'a'.repeat(64)

export function reviewText(overrides: Partial<ReviewText> = {}): ReviewText {
  return {
    field: 'title',
    label: 'Title',
    provided: false,
    source: { locale: 'de', value: 'Wanderschuh aus Leder', hash: SOURCE_HASH },
    target: {
      value: 'Leather hiking boot',
      status: 'ready',
      origin: 'ai',
      stale: false,
      failures: [],
      hard: false,
    },
    ...overrides,
  }
}

export function reviewProduct(overrides: Partial<ReviewProduct> = {}): ReviewProduct {
  return { id: 11, sku: 'AO-1001', texts: [reviewText()], ...overrides }
}

export function reviewPage(
  products: ReviewProduct[] = [reviewProduct()],
  meta: Partial<ReviewPage['meta']> = {},
): ReviewPage {
  return {
    data: products,
    meta: {
      page: 1,
      pages: 1,
      total: products.length,
      counts: { all: products.length, review: 0, missing: 0, manual: 0 },
      ...meta,
    },
  }
}

const TOKEN = 'T'.repeat(40)

export function exportBuild(overrides: Partial<ExportBuild> = {}): ExportBuild {
  return {
    id: 90,
    status: 'published',
    rows: 5,
    files: [
      { name: 'products-de.tsv', locale: 'de', rows: 5, bytes: 2048, gz_bytes: 512 },
      { name: 'products-en.tsv', locale: 'en', rows: 5, bytes: 2048, gz_bytes: 512 },
    ],
    report: { left_out: 0, missing: {}, examples: [] },
    failure_reason: null,
    failure_message: null,
    created_at: READ_AT,
    published_at: READ_AT,
    ...overrides,
  }
}

/** A live Google Merchant export of a German feed with English. */
export function exportProfile(overrides: Partial<ExportProfile> = {}): ExportProfile {
  return {
    id: 30,
    name: 'Google',
    preset: 'google_merchant',
    preset_label: 'Google Merchant Center',
    dialect: 'tsv',
    layout: 'per_language',
    locales: ['de', 'en'],
    status: 'active',
    version: 1,
    urls: ['de', 'en'].map((locale) => ({
      file: `products-${locale}.tsv`,
      locale,
      url: `${origin}/f/${TOKEN}/products-${locale}.tsv`,
      gz_url: `${origin}/f/${TOKEN}/products-${locale}.tsv.gz`,
    })),
    previous_urls_until: null,
    published: exportBuild(),
    latest: exportBuild(),
    earlier: [],
    ...overrides,
  }
}

export function exportsResponse(exports: ExportProfile[] = [exportProfile()]): ExportsResponse {
  return {
    exports,
    presets: [
      { key: 'standard', label: 'Every mapped field', dialects: ['csv', 'tsv'], layouts: ['wide', 'per_language'] },
      { key: 'google_merchant', label: 'Google Merchant Center', dialects: ['tsv'], layouts: ['per_language'] },
    ],
    locales: [
      { code: 'de', name: 'German' },
      { code: 'en', name: 'English' },
    ],
  }
}
