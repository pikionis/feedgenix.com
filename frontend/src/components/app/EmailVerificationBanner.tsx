import { useEffect } from 'react';
import { useEmailVerificationStore } from '@/stores/emailVerificationStore';

/**
 * Sticky warning banner shown across the authenticated app while the user
 * hasn't verified their email yet. Translation/upload routes are server-side
 * blocked anyway — this banner is the user-facing "why" + the action button
 * to resend the link.
 *
 * Auto-fetches status on mount so the banner appears immediately on first
 * load without a separate dispatcher call from the layout.
 */
export default function EmailVerificationBanner() {
  const {
    verified,
    email,
    isSending,
    error,
    resentAt,
    fetchStatus,
    resend,
  } = useEmailVerificationStore();

  useEffect(() => {
    void fetchStatus();
  }, [fetchStatus]);

  // Hide while verified or while we still don't know (avoids flash on guest pages).
  if (verified !== false) {
    return null;
  }

  const recentlyResent = resentAt !== null && Date.now() - resentAt < 10_000;

  return (
    <div
      role="status"
      data-testid="email-verification-banner"
      className="flex flex-wrap items-center gap-3 px-6 py-2.5 text-[13px]"
      style={{ background: '#FAEEDA', color: '#A35412', borderBottom: '0.5px solid rgba(31,29,46,0.10)' }}
    >
      <WarningIcon />
      <div className="flex-1 min-w-0">
        <span className="font-medium">Verify your email</span> to unlock translation runs.
        We sent a link to{' '}
        <span className="font-medium">{email ?? 'your inbox'}</span>.
        {error && <span className="ml-2 font-medium" style={{ color: '#C6392A' }}>· {error}</span>}
      </div>
      <button
        type="button"
        onClick={() => void resend().catch(() => {})}
        disabled={isSending || recentlyResent}
        className="rounded-md border-[0.5px] px-3 py-1 text-[12px] font-medium transition-colors duration-200 disabled:opacity-60"
        style={{
          background: '#FFFFFF',
          color: '#A35412',
          borderColor: 'rgba(163, 84, 18, 0.3)',
        }}
      >
        {isSending
          ? 'Sending…'
          : recentlyResent
            ? 'Sent — check your inbox'
            : 'Resend email'}
      </button>
    </div>
  );
}

function WarningIcon() {
  return (
    <svg
      width="16"
      height="16"
      viewBox="0 0 24 24"
      fill="none"
      stroke="currentColor"
      strokeWidth="1.75"
      strokeLinecap="round"
      strokeLinejoin="round"
      aria-hidden="true"
    >
      <path d="M10.29 3.86 1.82 18a2 2 0 0 0 1.71 3h16.94a2 2 0 0 0 1.71-3L13.71 3.86a2 2 0 0 0-3.42 0z" />
      <line x1="12" y1="9" x2="12" y2="13" />
      <line x1="12" y1="17" x2="12.01" y2="17" />
    </svg>
  );
}
