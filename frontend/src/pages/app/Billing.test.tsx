import { describe, it, expect, beforeEach, vi } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter, Route, Routes } from 'react-router-dom';
import Billing from './Billing';
import { useSubscriptionStore, type SubscriptionInfo } from '@/stores/subscriptionStore';
import { externalRedirect } from '@/pages/public/Pricing';

function fakeInfo(overrides: Partial<SubscriptionInfo> = {}): SubscriptionInfo {
  return {
    plan: 'starter',
    tier: {
      products_per_month: 10_000,
      target_languages: 4,
      team_members: 3,
      features: ['csv_upload', 'smart_mapping', 'csv_export', 'glossary_basic'],
    },
    products_used_this_month: 4_200,
    subscription: {
      stripe_status: 'active',
      ends_at: null,
      trial_ends_at: null,
      on_grace_period: false,
    },
    ...overrides,
  };
}

function renderBilling() {
  return render(
    <MemoryRouter initialEntries={['/billing']}>
      <Routes>
        <Route path="/billing" element={<Billing />} />
        <Route path="/pricing" element={<div>Pricing page</div>} />
      </Routes>
    </MemoryRouter>,
  );
}

describe('Billing', () => {
  beforeEach(() => {
    useSubscriptionStore.setState({
      info: null,
      isLoading: false,
      isStartingCheckout: false,
      isUpdating: false,
      isCanceling: false,
      error: null,
      fetch: vi.fn().mockResolvedValue(undefined),
      checkout: vi.fn(),
      changePlan: vi.fn(),
      cancel: vi.fn().mockResolvedValue(null),
      portal: vi.fn().mockResolvedValue('https://billing.stripe.com/p/session_xyz'),
      reset: vi.fn(),
    });
    externalRedirect.go = vi.fn();
  });

  it('shows the loading placeholder before info has arrived', () => {
    useSubscriptionStore.setState({ isLoading: true, info: null });
    renderBilling();
    expect(screen.getByText('Loading billing details…')).toBeInTheDocument();
  });

  it('shows an error placeholder when fetch fails before any info loads', () => {
    useSubscriptionStore.setState({ isLoading: false, info: null, error: 'No team context.' });
    renderBilling();
    expect(screen.getByRole('alert')).toHaveTextContent('No team context.');
  });

  it('calls fetch on mount', async () => {
    const fetch = vi.fn().mockResolvedValue(undefined);
    useSubscriptionStore.setState({ fetch });
    renderBilling();
    await waitFor(() => expect(fetch).toHaveBeenCalledOnce());
  });

  it('renders plan label, status, row cap, and target languages', () => {
    useSubscriptionStore.setState({ info: fakeInfo() });
    renderBilling();
    expect(screen.getByText('Starter plan')).toBeInTheDocument();
    expect(screen.getByText('Active')).toBeInTheDocument();
    expect(screen.getByText('10,000')).toBeInTheDocument();
    expect(screen.getByText('4')).toBeInTheDocument();
  });

  it('shows the QuotaWidget tied to current usage', () => {
    useSubscriptionStore.setState({
      info: fakeInfo({
        products_used_this_month: 4_200,
        tier: { products_per_month: 10_000, target_languages: 4, team_members: 3, features: [] },
      }),
    });
    renderBilling();
    // 4,200 / 10,000 = 42%
    expect(screen.getByRole('progressbar')).toHaveAttribute('aria-valuenow', '42');
  });

  it('shows "Open billing portal" only for paid non-enterprise plans', () => {
    useSubscriptionStore.setState({ info: fakeInfo({ plan: 'starter' }) });
    const view = renderBilling();
    expect(screen.getByRole('button', { name: /Open billing portal/ })).toBeInTheDocument();
    view.unmount();

    useSubscriptionStore.setState({ info: fakeInfo({ plan: 'free', subscription: null }) });
    renderBilling();
    expect(screen.queryByRole('button', { name: /Open billing portal/ })).not.toBeInTheDocument();
  });

  it('"Open billing portal" calls portal() and redirects to Stripe', async () => {
    const user = userEvent.setup();
    const portal = vi.fn().mockResolvedValue('https://billing.stripe.com/p/x');
    useSubscriptionStore.setState({ info: fakeInfo(), portal });

    renderBilling();
    await user.click(screen.getByRole('button', { name: /Open billing portal/ }));

    await waitFor(() => expect(portal).toHaveBeenCalledOnce());
    await waitFor(() => expect(externalRedirect.go).toHaveBeenCalledWith('https://billing.stripe.com/p/x'));
  });

  it('shows the "Upgrade" copy on the free plan and "Change plan" on paid', () => {
    useSubscriptionStore.setState({ info: fakeInfo({ plan: 'free', subscription: null }) });
    const view = renderBilling();
    expect(screen.getByRole('heading', { name: 'Upgrade' })).toBeInTheDocument();
    view.unmount();

    useSubscriptionStore.setState({ info: fakeInfo() });
    renderBilling();
    expect(screen.getByRole('heading', { name: 'Change plan' })).toBeInTheDocument();
  });

  it('hides the cancel section for the free plan', () => {
    useSubscriptionStore.setState({ info: fakeInfo({ plan: 'free', subscription: null }) });
    renderBilling();
    expect(screen.queryByRole('button', { name: /Cancel subscription/ })).not.toBeInTheDocument();
  });

  it('cancel flow: confirm, cancel, refetch info', async () => {
    const user = userEvent.setup();
    const cancel = vi.fn().mockResolvedValue('2026-05-31');
    const fetch = vi.fn().mockResolvedValue(undefined);
    useSubscriptionStore.setState({ info: fakeInfo(), cancel, fetch });

    renderBilling();
    await user.click(screen.getByRole('button', { name: /Cancel subscription…/ }));

    expect(screen.getByText('Are you sure?')).toBeInTheDocument();

    await user.click(screen.getByRole('button', { name: 'Yes, cancel' }));

    await waitFor(() => expect(cancel).toHaveBeenCalledOnce());
    await waitFor(() => expect(fetch).toHaveBeenCalled());
  });

  it('cancel can be backed out of without firing the API', async () => {
    const user = userEvent.setup();
    const cancel = vi.fn();
    useSubscriptionStore.setState({ info: fakeInfo(), cancel });

    renderBilling();
    await user.click(screen.getByRole('button', { name: /Cancel subscription…/ }));
    await user.click(screen.getByRole('button', { name: 'Keep subscription' }));

    expect(cancel).not.toHaveBeenCalled();
    // Confirmation row gone, primary button back.
    expect(screen.queryByText('Are you sure?')).not.toBeInTheDocument();
    expect(screen.getByRole('button', { name: /Cancel subscription…/ })).toBeInTheDocument();
  });

  it('shows the grace-period banner instead of the cancel section when on_grace_period is true', () => {
    useSubscriptionStore.setState({
      info: fakeInfo({
        subscription: {
          stripe_status: 'canceled',
          ends_at: '2026-05-31T00:00:00Z',
          trial_ends_at: null,
          on_grace_period: true,
        },
      }),
    });

    renderBilling();
    expect(screen.getByLabelText('Grace period notice')).toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /Cancel subscription…/ })).not.toBeInTheDocument();
  });

  it('renders Unlimited copy for Enterprise tier with very large limits', () => {
    useSubscriptionStore.setState({
      info: fakeInfo({
        plan: 'enterprise',
        tier: {
          products_per_month: Number.MAX_SAFE_INTEGER,
          target_languages: Number.MAX_SAFE_INTEGER,
          team_members: Number.MAX_SAFE_INTEGER,
          features: ['*'],
        },
      }),
    });
    renderBilling();

    // Both the "Rows / month" row and the "Target languages" row should say "Unlimited".
    expect(screen.getAllByText('Unlimited').length).toBeGreaterThanOrEqual(2);
  });

  it('does not show "Open billing portal" or cancel section for Enterprise', () => {
    useSubscriptionStore.setState({
      info: fakeInfo({
        plan: 'enterprise',
        tier: {
          products_per_month: Number.MAX_SAFE_INTEGER,
          target_languages: Number.MAX_SAFE_INTEGER,
          team_members: Number.MAX_SAFE_INTEGER,
          features: ['*'],
        },
      }),
    });
    renderBilling();
    expect(screen.queryByRole('button', { name: /Open billing portal/ })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /Cancel subscription…/ })).not.toBeInTheDocument();
  });
});
