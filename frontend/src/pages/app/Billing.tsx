import { useEffect, useState } from 'react';
import { Link } from 'react-router-dom';
import { useSubscriptionStore } from '@/stores/subscriptionStore';
import { externalRedirect } from '@/pages/public/Pricing';
import PageHeader from '@/components/app/PageHeader';
import QuotaWidget from '@/components/app/QuotaWidget';

/**
 * Billing settings — the workspace's view of what plan it's on, where it
 * stands on the monthly quota, and the controls to change/cancel/manage.
 *
 * Source of truth is GET /api/subscription. Plan changes go through Stripe's
 * billing portal (one click → Stripe-hosted UX); same-tier toggles between
 * monthly/yearly use the changePlan PUT.
 */
export default function Billing() {
  const {
    info,
    isLoading,
    isCanceling,
    error,
    fetch,
    cancel,
    portal,
  } = useSubscriptionStore();
  const [confirmCancel, setConfirmCancel] = useState(false);

  useEffect(() => {
    void fetch();
  }, [fetch]);

  if (isLoading || !info) {
    return (
      <div className="mx-auto max-w-3xl text-[14px] text-ink-500">
        {error ? (
          <p role="alert" className="rounded-lg border-[0.5px] border-[#C6392A]/30 bg-[#FCE5E1] px-3 py-2.5 text-[#C6392A]">
            {error}
          </p>
        ) : (
          'Loading billing details…'
        )}
      </div>
    );
  }

  const plan = info.plan;
  const sub = info.subscription;
  const limit = info.tier.products_per_month ?? 0;
  const isFree = plan === 'free';
  const isEnterprise = plan === 'enterprise';

  async function openPortal() {
    try {
      const url = await portal();
      externalRedirect.go(url);
    } catch {
      // surfaced via store error
    }
  }

  async function handleCancel() {
    try {
      await cancel();
      await fetch();
      setConfirmCancel(false);
    } catch {
      // store error displayed
    }
  }

  return (
    <div className="mx-auto max-w-3xl">
      <PageHeader
        title="Billing"
        subtitle="Your plan, your usage, and the controls that change them."
        actions={
          !isFree && !isEnterprise && (
            <button
              type="button"
              onClick={openPortal}
              className="rounded-lg border-[0.5px] border-ink/14 bg-paper px-3.5 py-2 text-[13px] font-medium text-ink transition-colors duration-200 hover:bg-mist"
            >
              Open billing portal →
            </button>
          )
        }
      />

      {error && (
        <div role="alert" className="mb-4 rounded-lg border-[0.5px] border-[#C6392A]/30 bg-[#FCE5E1] px-3 py-2.5 text-[13px] text-[#C6392A]">
          {error}
        </div>
      )}

      <section className="mb-4 rounded-lg border-[0.5px] border-ink/10 bg-paper p-5">
        <h2 className="mb-3 text-[17px] font-medium tracking-[-0.01em] text-ink">Current plan</h2>
        <dl className="space-y-2.5 text-[13.5px]">
          <Row label="Plan" value={planLabel(plan)} capitalize />
          <Row label="Status" value={statusLabel(sub?.stripe_status, sub?.on_grace_period)} />
          {sub?.ends_at && (
            <Row
              label={sub.on_grace_period ? 'Ends at' : 'Renews at'}
              value={new Date(sub.ends_at).toLocaleDateString()}
            />
          )}
          {sub?.trial_ends_at && (
            <Row label="Trial ends" value={new Date(sub.trial_ends_at).toLocaleDateString()} />
          )}
          <Row
            label="Rows / month"
            value={limit === Number.MAX_SAFE_INTEGER || limit > 1_000_000 ? 'Unlimited' : limit.toLocaleString('en-US')}
          />
          <Row label="Target languages" value={info.tier.target_languages === Number.MAX_SAFE_INTEGER || info.tier.target_languages > 100 ? 'Unlimited' : String(info.tier.target_languages)} />
        </dl>
      </section>

      <section className="mb-4 rounded-lg border-[0.5px] border-ink/10 bg-paper p-5">
        <h2 className="mb-3 text-[17px] font-medium tracking-[-0.01em] text-ink">This month</h2>
        <QuotaWidget
          used={info.products_used_this_month}
          limit={limit > 1_000_000 ? Infinity : limit}
        />
      </section>

      <section className="mb-4 rounded-lg border-[0.5px] border-ink/10 bg-paper p-5">
        <h2 className="mb-1.5 text-[17px] font-medium tracking-[-0.01em] text-ink">
          {isFree ? 'Upgrade' : 'Change plan'}
        </h2>
        <p className="mb-3.5 text-[13.5px] leading-[1.55] text-ink-500">
          {isFree
            ? 'Pick a paid plan to lift the row cap and unlock more target languages.'
            : 'Comparing plans? The pricing page lays them out side-by-side.'}
        </p>
        <Link
          to="/pricing"
          className="inline-flex rounded-lg bg-indigo px-3.5 py-2 text-[13px] font-medium text-white transition-colors duration-200 hover:bg-indigo-600"
        >
          See pricing
        </Link>
      </section>

      {sub && !isEnterprise && !sub.on_grace_period && (
        <section className="rounded-lg p-5" style={{ border: '0.5px solid rgba(198, 57, 42, 0.30)', background: '#FCE5E1' }}>
          <h2 className="mb-1.5 text-[17px] font-medium" style={{ color: '#8B1F16' }}>
            Cancel subscription
          </h2>
          <p className="mb-3.5 text-[13.5px] leading-[1.55]" style={{ color: '#8B1F16' }}>
            You'll keep paid features until the end of the current billing period, then drop back
            to the free tier.
          </p>
          {!confirmCancel ? (
            <button
              type="button"
              onClick={() => setConfirmCancel(true)}
              className="rounded-lg px-3.5 py-2 text-[13px] font-medium text-white transition-opacity duration-200 hover:opacity-90"
              style={{ background: '#C6392A' }}
            >
              Cancel subscription…
            </button>
          ) : (
            <div className="flex flex-wrap items-center gap-2">
              <span className="text-[13px] font-medium" style={{ color: '#8B1F16' }}>
                Are you sure?
              </span>
              <button
                type="button"
                onClick={handleCancel}
                disabled={isCanceling}
                className="rounded-lg px-3.5 py-2 text-[13px] font-medium text-white transition-opacity duration-200 hover:opacity-90 disabled:opacity-60"
                style={{ background: '#C6392A' }}
              >
                {isCanceling ? 'Cancelling…' : 'Yes, cancel'}
              </button>
              <button
                type="button"
                onClick={() => setConfirmCancel(false)}
                className="rounded-lg border-[0.5px] border-ink/14 bg-paper px-3.5 py-2 text-[13px] font-medium text-ink transition-colors duration-200 hover:bg-mist"
              >
                Keep subscription
              </button>
            </div>
          )}
        </section>
      )}

      {sub?.on_grace_period && (
        <section
          className="rounded-lg p-5"
          style={{ border: '0.5px solid rgba(200, 122, 27, 0.30)', background: '#FAEEDA' }}
          aria-label="Grace period notice"
        >
          <h2 className="mb-1.5 text-[17px] font-medium" style={{ color: '#A35412' }}>
            Subscription cancelled — grace period active
          </h2>
          <p className="text-[13.5px] leading-[1.55]" style={{ color: '#A35412' }}>
            Your paid features are active until {sub.ends_at ? new Date(sub.ends_at).toLocaleDateString() : 'the end of the period'}.
            After that you'll move to the free tier.
          </p>
        </section>
      )}
    </div>
  );
}

function planLabel(plan: string): string {
  return plan === 'free' ? 'Free plan' : `${plan[0].toUpperCase()}${plan.slice(1)} plan`;
}

function statusLabel(stripeStatus: string | undefined, onGrace: boolean | undefined): string {
  if (!stripeStatus) return 'No active subscription';
  if (onGrace) return 'Cancelled — grace period';
  switch (stripeStatus) {
    case 'active': return 'Active';
    case 'trialing': return 'Trial';
    case 'past_due': return 'Past due';
    case 'incomplete': return 'Incomplete';
    case 'canceled': return 'Cancelled';
    default: return stripeStatus;
  }
}

function Row({ label, value, capitalize }: { label: string; value: string; capitalize?: boolean }) {
  return (
    <div className="grid grid-cols-[160px_1fr] items-baseline gap-3">
      <dt className="text-ink-500">{label}</dt>
      <dd className={`font-medium text-ink ${capitalize ? 'capitalize' : ''}`}>{value}</dd>
    </div>
  );
}
