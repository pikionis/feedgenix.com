import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { AxiosError } from 'axios';
import { useSubscriptionStore, type SubscriptionInfo } from './subscriptionStore';
import api from '@/api/client';

function fakeInfo(overrides: Partial<SubscriptionInfo> = {}): SubscriptionInfo {
  return {
    plan: 'starter',
    tier: {
      products_per_month: 1000,
      target_languages: 3,
      team_members: 2,
      features: ['csv_upload', 'smart_mapping', 'csv_export'],
      price_monthly: 2900,
    },
    products_used_this_month: 423,
    subscription: {
      stripe_status: 'active',
      ends_at: null,
      trial_ends_at: null,
      on_grace_period: false,
    },
    ...overrides,
  };
}

function reset() {
  useSubscriptionStore.getState().reset();
}

describe('subscriptionStore', () => {
  beforeEach(() => {
    reset();
    vi.restoreAllMocks();
  });

  afterEach(() => {
    reset();
  });

  it('fetch() loads info and clears error on success', async () => {
    const info = fakeInfo();
    vi.spyOn(api, 'get').mockResolvedValue({ data: info });

    await useSubscriptionStore.getState().fetch();

    expect(useSubscriptionStore.getState().info).toEqual(info);
    expect(useSubscriptionStore.getState().isLoading).toBe(false);
    expect(useSubscriptionStore.getState().error).toBeNull();
  });

  it('fetch() surfaces server message on failure and leaves info null', async () => {
    const err = new AxiosError('Forbidden', '403');
    err.response = { status: 403, data: { message: 'No team context.' } } as never;
    vi.spyOn(api, 'get').mockRejectedValue(err);

    await useSubscriptionStore.getState().fetch();

    expect(useSubscriptionStore.getState().info).toBeNull();
    expect(useSubscriptionStore.getState().error).toBe('No team context.');
  });

  it('checkout() POSTs the plan + cycle and returns the Stripe URL', async () => {
    const post = vi.spyOn(api, 'post').mockResolvedValue({
      data: { checkout_url: 'https://checkout.stripe.com/c/pay/cs_test_123' },
    });

    const url = await useSubscriptionStore.getState().checkout('pro', 'yearly');

    expect(post).toHaveBeenCalledWith('/api/subscription', { plan: 'pro', billing_cycle: 'yearly' });
    expect(url).toBe('https://checkout.stripe.com/c/pay/cs_test_123');
    expect(useSubscriptionStore.getState().isStartingCheckout).toBe(false);
  });

  it('checkout() rethrows and surfaces server message on validation failure', async () => {
    const err = new AxiosError('Unprocessable', '422');
    err.response = { status: 422, data: { message: 'Invalid plan or billing cycle configuration.' } } as never;
    vi.spyOn(api, 'post').mockRejectedValue(err);

    await expect(useSubscriptionStore.getState().checkout('starter', 'monthly')).rejects.toBeDefined();

    expect(useSubscriptionStore.getState().error).toBe('Invalid plan or billing cycle configuration.');
    expect(useSubscriptionStore.getState().isStartingCheckout).toBe(false);
  });

  it('checkout() falls back to generic error for non-axios throws', async () => {
    vi.spyOn(api, 'post').mockRejectedValue(new Error('boom'));

    await expect(useSubscriptionStore.getState().checkout('pro', 'monthly')).rejects.toBeDefined();

    expect(useSubscriptionStore.getState().error).toBe('The billing service is unavailable. Please try again.');
  });

  it('changePlan() PUTs and refetches info', async () => {
    const put = vi.spyOn(api, 'put').mockResolvedValue({ data: { plan: 'pro' } });
    const get = vi.spyOn(api, 'get').mockResolvedValue({ data: fakeInfo({ plan: 'pro' }) });

    await useSubscriptionStore.getState().changePlan('pro', 'monthly');

    expect(put).toHaveBeenCalledWith('/api/subscription', { plan: 'pro', billing_cycle: 'monthly' });
    expect(get).toHaveBeenCalledWith('/api/subscription');
    expect(useSubscriptionStore.getState().info?.plan).toBe('pro');
  });

  it('changePlan() rethrows on PUT failure and does NOT call GET', async () => {
    const err = new AxiosError('Not Found', '404');
    err.response = { status: 404, data: { message: 'No active subscription found. Please create one first.' } } as never;
    vi.spyOn(api, 'put').mockRejectedValue(err);
    const get = vi.spyOn(api, 'get');

    await expect(useSubscriptionStore.getState().changePlan('pro', 'monthly')).rejects.toBeDefined();

    expect(useSubscriptionStore.getState().error).toBe('No active subscription found. Please create one first.');
    expect(get).not.toHaveBeenCalled();
  });

  it('cancel() returns the ends_at date and surfaces the server message on failure', async () => {
    vi.spyOn(api, 'delete').mockResolvedValue({ data: { ends_at: '2026-05-31T00:00:00Z' } });

    const endsAt = await useSubscriptionStore.getState().cancel();
    expect(endsAt).toBe('2026-05-31T00:00:00Z');

    const err = new AxiosError('Not Found', '404');
    err.response = { status: 404, data: { message: 'No active subscription found.' } } as never;
    vi.spyOn(api, 'delete').mockRejectedValue(err);

    await expect(useSubscriptionStore.getState().cancel()).rejects.toBeDefined();
    expect(useSubscriptionStore.getState().error).toBe('No active subscription found.');
  });

  it('portal() returns the URL', async () => {
    vi.spyOn(api, 'get').mockResolvedValue({ data: { portal_url: 'https://billing.stripe.com/p/session_xyz' } });

    const url = await useSubscriptionStore.getState().portal();

    expect(url).toBe('https://billing.stripe.com/p/session_xyz');
  });
});
