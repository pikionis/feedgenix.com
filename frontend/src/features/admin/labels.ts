/**
 * Why work waits, in staff words: the reasons the translation engine records
 * (Domain/Translation/RunProgress.php and ops:reconcile). Anything else shows its code.
 */
export const HOLDS: Record<string, string> = {
  credits: 'Not enough credits',
  reservation_exhausted: 'Its reservation ran out',
  allowance: 'Over the period’s products',
  overage_credits: 'Overage fee not covered',
  ai_disabled: 'AI switched off',
  unpriced_model: 'No priced model',
  provider_unavailable: 'Provider unavailable',
  ai_paused: 'AI paused for the workspace',
  owner_unverified: 'Owner not verified',
  workspace_frozen: 'Workspace frozen',
  feed_paused: 'Feed paused by the plan',
  language_paused: 'Language paused by the plan',
}

export const holdLabel = (reason: string): string => HOLDS[reason] ?? reason
