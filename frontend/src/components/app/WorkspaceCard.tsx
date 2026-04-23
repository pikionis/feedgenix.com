interface WorkspaceCardProps {
  /** Full team name, e.g. "Deindeal AG". */
  name: string;
  /** Plan tier label, e.g. "Pro plan" or "Free plan". */
  plan: string;
  /** Optional override for the 2-letter avatar. Defaults to initials derived from name. */
  initials?: string;
}

/**
 * The little "team" card at the top of the app sidebar — Paper on Mist,
 * hairline border, 10px radius. Shows which workspace you're in.
 */
export default function WorkspaceCard({ name, plan, initials }: WorkspaceCardProps) {
  const monogram = (initials ?? deriveInitials(name)).slice(0, 2).toUpperCase();
  return (
    <div className="flex items-center gap-2.5 rounded-lg border-[0.5px] border-ink/10 bg-paper p-3">
      <div
        aria-hidden
        className="flex h-7 w-7 shrink-0 items-center justify-center rounded-md bg-ink text-[11px] font-medium text-white"
      >
        {monogram}
      </div>
      <div className="min-w-0 leading-tight">
        <div className="truncate text-[13px] font-medium text-ink">{name}</div>
        <div className="text-[11px] text-ink-500">{plan}</div>
      </div>
    </div>
  );
}

function deriveInitials(name: string): string {
  const parts = name.trim().split(/\s+/).filter(Boolean);
  if (parts.length === 0) return '??';
  if (parts.length === 1) return parts[0].slice(0, 2);
  return parts[0][0] + parts[1][0];
}
