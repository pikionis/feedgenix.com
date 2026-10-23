import type { FieldClass, MappingField } from '~/lib/api/types'

export type FieldTone = 'success' | 'neutral' | 'warn' | 'info' | 'error'

/** What a field's status means to the person reviewing it. */
export const FIELD_STATUS: Record<MappingField['status'] | 'unmapped', { label: string; tone: FieldTone }> = {
  validated: { label: 'Checked', tone: 'success' },
  mapped: { label: 'Mapped', tone: 'neutral' },
  needs_input: { label: 'Check this', tone: 'warn' },
  composed: { label: 'Composed', tone: 'info' },
  rejected: { label: 'Left empty', tone: 'error' },
  cleared: { label: 'Left empty by you', tone: 'neutral' },
  unmapped: { label: 'Not mapped', tone: 'neutral' },
}

export const ORIGIN: Record<MappingField['origin'], string> = {
  heuristic: 'From the column name',
  ai: 'Suggested by AI',
  manual: 'Set by you',
}

/** The groups of the review screen, in order, with what happens to their values. */
export const GROUPS: { classes: FieldClass[]; title: string; note: string }[] = [
  {
    classes: ['golden'],
    title: 'Identifiers, prices and stock',
    note: 'Copied exactly as they are. They are never sent to the AI and never translated.',
  },
  { classes: ['pass_through'], title: 'Links, brand and labels', note: 'Copied as they are, in every language.' },
  { classes: ['translatable'], title: 'Texts', note: 'Translated into your languages in the next step.' },
  {
    classes: ['composable'],
    title: 'Composed texts',
    note: 'Built from several columns as "Label: value" lines, then translated.',
  },
]

/** Fields shown even when nothing fills them; the rest wait under "More fields". */
export const ALWAYS_SHOWN: ReadonlySet<string> = new Set([
  'sku',
  'gtin',
  'mpn',
  'price',
  'sale_price',
  'currency',
  'stock_quantity',
  'brand',
  'link',
  'image_link',
  'availability',
  'title',
  'description',
  'color',
  'size',
  'category',
  'material',
  'features',
])

const UNAVAILABLE: Record<string, string> = {
  ai_disabled: 'AI suggestions are switched off for now. You can map every field yourself.',
  unpriced_model: 'No AI model is available yet. You can map every field yourself.',
  provider_unavailable: 'No AI model is available yet. You can map every field yourself.',
}

export function unavailableMessage(reason: string | null): string {
  return (reason !== null ? UNAVAILABLE[reason] : undefined) ?? 'AI suggestions are not available right now.'
}

/** "de-CH" → "Swiss German" (the browser's own names), or the code when it has none. */
export function languageName(code: string | null): string {
  if (code === null) return 'unknown'
  try {
    return new Intl.DisplayNames(['en'], { type: 'language' }).of(code) ?? code
  } catch {
    return code
  }
}
