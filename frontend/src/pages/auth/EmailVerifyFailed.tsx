import { Link, useSearchParams } from 'react-router-dom';
import { useEmailVerificationStore } from '@/stores/emailVerificationStore';
import AuthLayout from '@/components/layout/AuthLayout';

/**
 * Landing page when the backend rejects a verification link (expired, hash
 * tampered, or unknown user). Tells the user what happened and offers a
 * resend (auth required) or a login redirect.
 */
const REASON_COPY: Record<string, { title: string; subtitle: string }> = {
  'invalid-hash': {
    title: 'Verification link is invalid',
    subtitle:
      "This link doesn't match any account we know about. It may have been edited in transit, or you may have changed your email since it was issued.",
  },
  'unknown-user': {
    title: 'Account not found',
    subtitle:
      "The user this link points to no longer exists. If you've recently deleted your account, you'll need to register again.",
  },
  expired: {
    title: 'Verification link expired',
    subtitle:
      "Verification links are good for 60 minutes. Sign in and ask for a new one — we'll send a fresh link to your inbox.",
  },
};

export default function EmailVerifyFailed() {
  const [params] = useSearchParams();
  const reason = params.get('reason') ?? 'expired';
  const copy = REASON_COPY[reason] ?? REASON_COPY.expired;
  const { isSending, error, resentAt, resend } = useEmailVerificationStore();
  const recentlyResent = resentAt !== null && Date.now() - resentAt < 10_000;

  return (
    <AuthLayout
      title={copy.title}
      subtitle={copy.subtitle}
      crumbs={{ left: 'Verify your email' }}
      footer={
        <>
          Need help?{' '}
          <a
            href="mailto:support@feedgenix.com"
            className="font-medium text-indigo hover:text-indigo-600"
          >
            support@feedgenix.com
          </a>
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
          onClick={() => void resend().catch(() => {})}
          disabled={isSending || recentlyResent}
          className="w-full rounded-lg bg-indigo py-3 text-[14px] font-medium text-white transition-colors duration-200 hover:bg-indigo-600 disabled:cursor-not-allowed disabled:opacity-60"
        >
          {isSending
            ? 'Sending…'
            : recentlyResent
              ? 'Sent — check your inbox'
              : 'Send a new verification link'}
        </button>

        <p className="text-center text-[12.5px] text-ink-500">
          Resend requires you to be signed in. Not signed in?{' '}
          <Link to="/login" className="font-medium text-indigo hover:text-indigo-600">
            Sign in first
          </Link>
          .
        </p>
      </div>
    </AuthLayout>
  );
}
