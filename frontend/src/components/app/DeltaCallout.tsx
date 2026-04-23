import type { ReactNode } from 'react';

interface DeltaCalloutProps {
  message: ReactNode;
  /** Right-hand action — typically a primary button that triggers the delta run. */
  action: ReactNode;
}

/**
 * The dashed-border callout that appears below the feeds table whenever
 * there's changed data ready to be re-translated. Indigo dashed border on
 * subtle background — visually a "nudge", not a full card.
 */
export default function DeltaCallout({ message, action }: DeltaCalloutProps) {
  return (
    <div
      className="mt-5 flex items-center gap-3.5 rounded-lg px-4.5 py-4 text-[13px]"
      style={{ border: '1px dashed rgba(79,70,229,0.4)', background: '#FAFAF7' }}
      role="status"
    >
      <div className="flex-1 text-ink-500">{message}</div>
      <div>{action}</div>
    </div>
  );
}
