import { formatBytes } from '~/lib/format'
import type { ExportBuild, ExportProfile } from '~/lib/api/types'

export const LAYOUTS: Record<ExportProfile['layout'], string> = {
  per_language: 'One file per language',
  wide: 'One file, a column per language',
}

export const DIALECTS: Record<ExportProfile['dialect'], string> = {
  csv: 'CSV',
  tsv: 'TSV (tab-separated)',
}

/** "5 products · 12 KB" for a build's files together. */
export function buildSize(build: ExportBuild): string {
  const bytes = build.files.reduce((sum, file) => sum + file.bytes, 0)

  return `${build.rows.toLocaleString('en')} ${build.rows === 1 ? 'product' : 'products'} · ${formatBytes(bytes)}`
}

/** The columns a channel requires that some products leave empty, per file. */
export function gaps(build: ExportBuild): { file: string; column: string; count: number }[] {
  return Object.entries(build.report.missing).flatMap(([file, columns]) =>
    Object.entries(columns).map(([column, count]) => ({ file, column, count })),
  )
}
