import type { Delimiter, Encoding, EncodingReason, FeedFormat, ImportStatus, ValueKind } from '~/lib/api/types'

// Every map is keyed by a type generated from the API contract: a value the backend adds fails the
// type check here until it has wording.

export type StatusTone = 'queued' | 'running' | 'success' | 'error'

export const STATUS: Record<ImportStatus, { label: string; tone: StatusTone }> = {
  queued: { label: 'Waiting', tone: 'queued' },
  fetching: { label: 'Downloading', tone: 'running' },
  reading: { label: 'Reading', tone: 'running' },
  profiled: { label: 'Read', tone: 'success' },
  unchanged: { label: 'Unchanged', tone: 'queued' },
  failed: { label: 'Failed', tone: 'error' },
}

export const FORMAT: Record<FeedFormat, string> = {
  csv: 'CSV',
  xml: 'XML',
  json: 'JSON',
  ndjson: 'JSON lines',
  xlsx: 'Excel',
}

export const KIND: Record<ValueKind, string> = {
  empty: 'Empty',
  gtin: 'GTIN',
  integer: 'Whole number',
  decimal: 'Decimal',
  boolean: 'Yes / no',
  date: 'Date',
  image_url: 'Image URL',
  url: 'Link',
  html: 'HTML',
  code: 'Code',
  text: 'Text',
}

export const ENCODING_REASON: Record<EncodingReason, string> = {
  bom: 'marked in the file',
  utf16_pattern: 'recognised as UTF-16',
  valid_utf8: 'every character is valid UTF-8',
  ascii: 'plain ASCII',
  fallback: 'not UTF-8, so Windows-1252 was assumed',
  xml_declaration: 'declared in the XML',
  override: 'your choice',
}

export const ENCODINGS: Record<Encoding, string> = {
  'UTF-8': 'UTF-8',
  'UTF-16LE': 'UTF-16 (little-endian)',
  'UTF-16BE': 'UTF-16 (big-endian)',
  'UTF-32LE': 'UTF-32 (little-endian)',
  'UTF-32BE': 'UTF-32 (big-endian)',
  'Windows-1252': 'Windows-1252 (Western European)',
  'ISO-8859-1': 'ISO-8859-1 (Latin-1)',
  'ISO-8859-15': 'ISO-8859-15 (Latin-9, with €)',
  'Windows-1250': 'Windows-1250 (Central European)',
  'ISO-8859-2': 'ISO-8859-2 (Latin-2)',
  'Windows-1251': 'Windows-1251 (Cyrillic)',
  'Windows-1253': 'Windows-1253 (Greek)',
  'ISO-8859-7': 'ISO-8859-7 (Greek)',
  'Windows-1254': 'Windows-1254 (Turkish)',
  'ISO-8859-9': 'ISO-8859-9 (Turkish)',
}

export const DELIMITERS: Record<Delimiter, string> = {
  ',': 'Comma',
  ';': 'Semicolon',
  '\t': 'Tab',
  '|': 'Vertical bar',
}

export function isEncoding(value: string | null): value is Encoding {
  return value !== null && Object.hasOwn(ENCODINGS, value)
}

export function isDelimiter(value: string | null): value is Delimiter {
  return value !== null && Object.hasOwn(DELIMITERS, value)
}

/** "3 minutes ago", "yesterday", for timestamps from the API. */
export function formatWhen(iso: string | null, now: Date = new Date()): string {
  if (iso === null) return ''
  const seconds = Math.round((new Date(iso).getTime() - now.getTime()) / 1000)
  const format = new Intl.RelativeTimeFormat('en', { numeric: 'auto' })
  const steps: [Intl.RelativeTimeFormatUnit, number][] = [
    ['second', 60],
    ['minute', 60],
    ['hour', 24],
    ['day', 30],
    ['month', 12],
  ]
  let value = seconds
  for (const [unit, size] of steps) {
    if (Math.abs(value) < size) return format.format(value, unit)
    value = Math.round(value / size)
  }

  return format.format(value, 'year')
}

/** "try again in 10 minutes": how long a rate-limited source asked us to wait. */
export function formatWait(seconds: number): string {
  if (seconds < 90) return `${seconds} seconds`
  if (seconds < 90 * 60) return `${Math.round(seconds / 60)} minutes`

  return `${Math.round(seconds / 3600)} hours`
}
