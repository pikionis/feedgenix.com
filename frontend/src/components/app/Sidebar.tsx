import { NavLink } from 'react-router-dom';
import type { ReactNode } from 'react';
import Logo from '@/components/brand/Logo';
import WorkspaceCard from './WorkspaceCard';
import QuotaWidget from './QuotaWidget';

interface NavItem {
  to: string;
  label: string;
  icon: ReactNode;
  /**
   * If true, match exactly (used for the Dashboard/root entry so it doesn't
   * stay highlighted on every sub-route).
   */
  end?: boolean;
}

interface SidebarProps {
  workspaceName: string;
  plan: string;
  /** Used rows for the current billing period. */
  rowsUsed: number;
  /** Cap for the current plan (Infinity = Enterprise). */
  rowsLimit: number;
  /** Optional bottom-left "Sign out" action. */
  onSignOut?: () => void;
}

/**
 * 180px sidebar rendered on every authenticated page.
 * Structure top-to-bottom: logo • workspace card • nav • (spacer) • quota widget • sign out.
 */
export default function Sidebar({
  workspaceName,
  plan,
  rowsUsed,
  rowsLimit,
  onSignOut,
}: SidebarProps) {
  const items: NavItem[] = [
    { to: '/dashboard', label: 'Dashboard',     icon: <DashboardIcon />, end: true },
    { to: '/projects',  label: 'Feeds',         icon: <FeedsIcon /> },
    { to: '/jobs',      label: 'Jobs',          icon: <JobsIcon /> },
    { to: '/usage',     label: 'Usage',         icon: <UsageIcon /> },
    { to: '/settings',  label: 'Settings',      icon: <SettingsIcon /> },
  ];

  return (
    <aside
      className="flex h-screen w-[180px] shrink-0 flex-col gap-4 border-r-[0.5px] border-ink/10 bg-mist p-3.5"
      data-testid="app-sidebar"
    >
      <div className="flex items-center gap-2 px-1.5">
        <Logo size="sm" />
      </div>

      <WorkspaceCard name={workspaceName} plan={plan} />

      <nav aria-label="Primary" className="flex flex-col gap-0.5">
        {items.map((item) => (
          <NavLink
            key={item.to}
            to={item.to}
            end={item.end}
            className={({ isActive }) =>
              [
                'flex items-center gap-2.5 rounded-lg px-3 py-2.25 text-[13px] transition-colors duration-200',
                isActive
                  ? 'border-[0.5px] border-ink/8 bg-paper font-medium text-ink'
                  : 'text-ink-500 hover:bg-ink/5 hover:text-ink',
              ].join(' ')
            }
          >
            <span aria-hidden className="text-ink-400 [.active_&]:text-ink">
              {item.icon}
            </span>
            <span>{item.label}</span>
          </NavLink>
        ))}
      </nav>

      <div className="mt-auto flex flex-col gap-3">
        <QuotaWidget used={rowsUsed} limit={rowsLimit} />
        {onSignOut && (
          <button
            type="button"
            onClick={onSignOut}
            className="rounded-lg px-3 py-2 text-left text-[13px] text-ink-500 transition-colors duration-200 hover:bg-ink/5 hover:text-ink"
          >
            Sign out
          </button>
        )}
      </div>
    </aside>
  );
}

/* ─────── icons — Lucide, stroke 1.5 ─────── */

function DashboardIcon() {
  return (
    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round" strokeLinejoin="round">
      <rect x="3" y="3"  width="7" height="9"  rx="1.5" />
      <rect x="14" y="3" width="7" height="5"  rx="1.5" />
      <rect x="14" y="12" width="7" height="9" rx="1.5" />
      <rect x="3" y="16" width="7" height="5"  rx="1.5" />
    </svg>
  );
}
function FeedsIcon() {
  return (
    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round" strokeLinejoin="round">
      <path d="M3 5v14h18V5z" /><path d="M3 10h18" /><path d="M9 5v14" />
    </svg>
  );
}
function JobsIcon() {
  return (
    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round" strokeLinejoin="round">
      <circle cx="12" cy="12" r="9" /><path d="M12 7v5l3 2" />
    </svg>
  );
}
function UsageIcon() {
  return (
    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round" strokeLinejoin="round">
      <path d="M3 3v18h18" /><path d="M7 15l4-4 3 3 5-6" />
    </svg>
  );
}
function SettingsIcon() {
  return (
    <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="1.5" strokeLinecap="round" strokeLinejoin="round">
      <circle cx="12" cy="12" r="3" />
      <path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 1 1-4 0v-.09a1.65 1.65 0 0 0-1-1.51 1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 1 1 0-4h.09a1.65 1.65 0 0 0 1.51-1 1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06a1.65 1.65 0 0 0 1.82.33h0a1.65 1.65 0 0 0 1-1.51V3a2 2 0 1 1 4 0v.09a1.65 1.65 0 0 0 1 1.51h0a1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82v0a1.65 1.65 0 0 0 1.51 1H21a2 2 0 1 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z" />
    </svg>
  );
}
