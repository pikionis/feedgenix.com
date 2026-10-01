/** Sizes the way people read them: 1023 B, 1.5 KB, 5 MB, 2 GB (binary units, short names). */
export function formatBytes(bytes: number): string {
  if (bytes < 1024) return `${bytes} B`
  const units = ['KB', 'MB', 'GB']
  let value = bytes / 1024
  let unit = 0
  while (value >= 1024 && unit < units.length - 1) {
    value /= 1024
    unit++
  }

  return `${value.toLocaleString('en', { maximumFractionDigits: value < 10 ? 1 : 0 })} ${units[unit]}`
}
