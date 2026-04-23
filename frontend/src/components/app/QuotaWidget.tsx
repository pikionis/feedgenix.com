interface QuotaWidgetProps {
  /** Rows used this billing period. */
  used: number;
  /** Row cap for the current plan. Pass Infinity for unlimited tiers. */
  limit: number;
}

/**
 * Monthly quota card pinned to the bottom of the sidebar. Indigo fill on a
 * light indigo wash — same treatment as the design-system app kit.
 *
 * When the user approaches the cap (>= 90%) the bar flips to status-warn
 * so "90% used" stands out from "28% used" at a glance.
 */
export default function QuotaWidget({ used, limit }: QuotaWidgetProps) {
  const unlimited = !Number.isFinite(limit);
  const raw = unlimited || limit <= 0 ? 0 : used / limit;
  const pct = Math.max(0, Math.min(1, raw));
  const pctLabel = unlimited ? '∞' : `${Math.round(pct * 100)}%`;
  const isNearLimit = !unlimited && pct >= 0.9;

  return (
    <div className="rounded-lg bg-indigo-100 p-3.5" data-testid="quota-widget">
      <div className="mb-2 flex items-center justify-between text-[12px] font-medium text-[#3B3490]">
        <span>Rows this month</span>
        <span>{pctLabel}</span>
      </div>
      <div
        className="h-1.5 overflow-hidden rounded-full bg-white/70"
        role="progressbar"
        aria-valuenow={Math.round(pct * 100)}
        aria-valuemin={0}
        aria-valuemax={100}
        aria-label="Monthly quota usage"
      >
        <div
          className="h-full transition-[width] duration-300"
          style={{
            width: unlimited ? '0%' : `${pct * 100}%`,
            background: isNearLimit ? '#C87A1B' : '#4F46E5',
          }}
        />
      </div>
      <div className="mt-1.5 text-[11px] text-[#3B3490]">
        {unlimited ? 'Unlimited rows' : `${formatInt(used)} / ${formatInt(limit)}`}
      </div>
    </div>
  );
}

/** European-style thousands separator (commas) per FeedGenix copy rules. */
function formatInt(n: number): string {
  return n.toLocaleString('en-US');
}
