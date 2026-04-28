import { useEffect } from 'react';
import { Link, useNavigate } from 'react-router-dom';
import AuthLayout from '@/components/layout/AuthLayout';
import { useAuthStore } from '@/stores/authStore';
import { useEmailVerificationStore } from '@/stores/emailVerificationStore';

/**
 * Landing page after the backend redirect on a successful verification.
 * The /api/email/verify route consumed the link, marked the user verified,
 * then redirected here. We:
 *   - flip the local store so banners disappear instantly
 *   - refresh the auth user (so email_verified_at is fresh in memory)
 *   - bounce the user to /dashboard after a short pause so they can see the
 *     confirmation message
 */
export default function EmailVerified() {
  const navigate = useNavigate();
  const fetchUser = useAuthStore((s) => s.fetchUser);
  const isAuthenticated = useAuthStore((s) => s.isAuthenticated);
  const markVerified = useEmailVerificationStore((s) => s.markVerified);

  useEffect(() => {
    markVerified();
    void fetchUser();
  }, [markVerified, fetchUser]);

  // Auto-redirect after 2.5s — gives the user time to read the success.
  useEffect(() => {
    const handle = window.setTimeout(() => {
      navigate(isAuthenticated ? '/dashboard' : '/login', { replace: true });
    }, 2_500);
    return () => window.clearTimeout(handle);
  }, [navigate, isAuthenticated]);

  return (
    <AuthLayout
      title="Email verified"
      subtitle={
        <>
          Your 7-day FeedGenix trial just started. Translation runs are unlocked —{' '}
          <strong className="font-medium text-ink">€0 charged</strong> until day 8.
        </>
      }
      crumbs={{ left: 'All set' }}
      footer={
        <>
          You'll be redirected automatically.{' '}
          <Link
            to={isAuthenticated ? '/dashboard' : '/login'}
            className="font-medium text-indigo hover:text-indigo-600"
          >
            Go now
          </Link>
        </>
      }
    >
      <div className="flex items-center justify-center py-2">
        <div
          className="flex h-14 w-14 items-center justify-center rounded-full"
          style={{ background: '#D6F1E8' }}
          aria-hidden
        >
          <svg width="28" height="28" viewBox="0 0 24 24" fill="none" stroke="#0F9B7E" strokeWidth="2.5" strokeLinecap="round">
            <polyline points="20 6 9 17 4 12" />
          </svg>
        </div>
      </div>
    </AuthLayout>
  );
}
