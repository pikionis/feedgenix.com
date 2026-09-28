/** €40, €3.40 — euro prefix, no space, European thousands grouping in English copy. */
export function euros(amount: number): string {
  const digits = Number.isInteger(amount) ? 0 : 2

  return `€${amount.toLocaleString('en', { minimumFractionDigits: digits, maximumFractionDigits: digits })}`
}

export function quantity(value: number | null, unit: string): string {
  return value === null ? `Unlimited ${unit}` : `${value.toLocaleString('en')} ${unit}`
}
