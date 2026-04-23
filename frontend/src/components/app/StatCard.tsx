import type { ReactNode } from 'react';

interface StatCardProps {
  label: string;
  value: ReactNode;
  /** Optional delta pill — a small tinted chip below the value. */
  delta?: {
    text: string;
    /**
     * - `up`:   emerald (positive)
     * - `down`: emerald (also positive — e.g. cost going down)
     * - `neutral`: slate — used for static informational chips like "DE · FR · IT"
     * - `bad`:  warn amber — negative trend
     */
    tone?: 'up' | 'down' | 'neutral' | 'bad';
  };
}

/**
 * Single Mist-background stat tile. Four of these in a row is the standard
 * workspace landing rhythm (per `ui_kits/app/index.html`).
 */
export default function StatCard({ label, value, delta }: StatCardProps) {
  return (
    <div className="rounded-lg bg-mist p-3.5">
      <div className="text-[12px] text-ink-500">{label}</div>
      <div className="mt-1.5 text-[26px] font-medium leading-none tracking-[-0.01em] text-ink">
        {value}
      </div>
      {delta && (
        <span
          className="mt-1.5 inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[11px] font-medium"
          style={toneStyle(delta.tone ?? 'up')}
        >
          {delta.text}
        </span>
      )}
    </div>
  );
}

function toneStyle(tone: NonNullable<StatCardProps['delta']>['tone']): React.CSSProperties {
  switch (tone) {
    case 'up':
    case 'down':
      return { background: '#D6F1E8', color: '#0B7A63' };
    case 'bad':
      return { background: '#FAEEDA', color: '#A35412' };
    case 'neutral':
    default:
      return { background: '#EDEBE3', color: '#56536A' };
  }
}
