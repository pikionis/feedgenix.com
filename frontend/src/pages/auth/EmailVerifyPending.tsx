import { useEffect } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import { useAuthStore } from '@/stores/authStore';
import { useEmailVerificationStore } from '@/stores/emailVerificationStore';
import AuthLayout from '@/components/layout/AuthLayout';

/**
 * Shown right after registration, while the user's email_verified_at is still
 * null. Tells them to check their inbox, lets them resend, and bounces them
 * to /dashboard once the backend reports verified=true.
 *
 * The page polls /api/email/status every 4s — a typical user clicks the link
 * in their inbox in the SAME browser, the click handler redirects to
 * /email/verified which calls markVerified() in the store; for users who
 * click the link in a different browser/device, the poll catches the change
 * and lets them through.
 */
export default function EmailVerifyPending() {
  const navigate = useNavigate();
  const user = useAuthStore((s) => s.user);
  const {
    verified,
    email,
    isSending,
    error,
    resentAt,
    fetchStatus,
    resend,
  } = useEmailVerificationStore();

  // Initial fetch + poll every 4s for state changes.
  useEffect(() => {
    void fetchStatus();
    const handle = window.setInterval(() => void fetchStatus(), 4_000);
    return () => window.clearInterval(handle);
  }, [fetchStatus]);

  // Auto-redirect once verified.
  useEffect(() => {
    if (verified === true) {
      navigate('/dashboard', { replace: true });
    }
  }, [verified, navigate]);

  const recentlyResent = resentAt !== null && Date.now() - resentAt < 10_000;

  return (
    <AuthLayout
      title="Check your inbox"
      subtitle={
        <>
          We sent a verification link to{' '}
          <span className="font-medium text-ink">{email ?? user?.email ?? 'your email'}</span>.
          Click it to start your 7-day trial.
        </>
      }
      crumbs={{
        left: 'Verify your email',
        right: (
          <Link to="/login" className="font-medium text-indigo hover:text-indigo-600">
            Sign in instead
          </Link>
        ),
      }}
      footer={
        <>
          The link expires in 60 minutes. If you can't find it, check your spam folder
          before resending.
        </>
      }
    >
      <div className="space-y-3.5">
        {error && (
          <div
            role="alert"
            className="rounded-lg border-[0.5px] border-[#C6392A]/30 bg-[#FCE5E1] px-3 py-2.5 text-[13px] text-[#C6392A]"
          >
            {error}
          </div>
        )}

        <button
          type="button"
          onClick={() => void resend()}
          disabled={isSending || recentlyResent}
          className="w-full rounded-lg bg-indigo py-3 text-[14px] font-medium text-white transition-colors duration-200 hover:bg-indigo-600 disabled:cursor-not-allowed disabled:opacity-60"
        >
          {isSending
            ? 'Sending…'
            : recentlyResent
              ? 'Sent — check your inbox'
              : 'Resend verification email'}
        </button>

        <p className="text-center text-[12.5px] text-ink-500">
          Wrong email?{' '}
          <Link to="/login" className="font-medium text-indigo hover:text-indigo-600">
            Sign in with the correct one
          </Link>
          .
        </p>
      </div>
    </AuthLayout>
  );
}
