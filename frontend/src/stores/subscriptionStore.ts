import { create } from 'zustand';
import { AxiosError } from 'axios';
import api from '@/api/client';

export type Plan = 'free' | 'starter' | 'pro' | 'enterprise';
export type BillingCycle = 'monthly' | 'yearly';

export interface SubscriptionInfo {
  plan: Plan;
  tier: {
    products_per_month: number;
    target_languages: number;
    team_members: number;
    features: string[];
    price_monthly?: number;
    price_yearly?: number;
  };
  products_used_this_month: number;
  subscription: {
    stripe_status: string;
    ends_at: string | null;
    trial_ends_at: string | null;
    on_grace_period: boolean;
  } | null;
}

interface SubscriptionState {
  info: SubscriptionInfo | null;
  isLoading: boolean;
  isStartingCheckout: boolean;
  isUpdating: boolean;
  isCanceling: boolean;
  error: string | null;
  fetch: () => Promise<void>;
  /**
   * Start a new subscription. Returns the Stripe Checkout URL — the caller
   * is responsible for navigating (window.location.assign) so this stays
   * unit-testable without redirecting jsdom away.
   */
  checkout: (plan: Exclude<Plan, 'free'>, cycle: BillingCycle) => Promise<string>;
  /** Swap the existing subscription to a different plan. */
  changePlan: (plan: Exclude<Plan, 'free'>, cycle: BillingCycle) => Promise<void>;
  /** Cancel at period end. Returns the date the subscription will end. */
  cancel: () => Promise<string | null>;
  /** Returns the Stripe billing portal URL — caller redirects. */
  portal: () => Promise<string>;
  reset: () => void;
}

const GENERIC_ERROR = 'The billing service is unavailable. Please try again.';

export const useSubscriptionStore = create<SubscriptionState>((set) => ({
  info: null,
  isLoading: false,
  isStartingCheckout: false,
  isUpdating: false,
  isCanceling: false,
  error: null,

  fetch: async () => {
    set({ isLoading: true, error: null });
    try {
      const { data } = await api.get<SubscriptionInfo>('/api/subscription');
      set({ info: data, isLoading: false });
    } catch (err) {
      set({ isLoading: false, error: extractMessage(err) ?? GENERIC_ERROR });
    }
  },

  checkout: async (plan, cycle) => {
    set({ isStartingCheckout: true, error: null });
    try {
      const { data } = await api.post<{ checkout_url: string }>('/api/subscription', {
        plan,
        billing_cycle: cycle,
      });
      set({ isStartingCheckout: false });
      return data.checkout_url;
    } catch (err) {
      set({ isStartingCheckout: false, error: extractMessage(err) ?? GENERIC_ERROR });
      throw err;
    }
  },

  changePlan: async (plan, cycle) => {
    set({ isUpdating: true, error: null });
    try {
      await api.put('/api/subscription', { plan, billing_cycle: cycle });
      // Re-fetch so the UI reflects the new plan + tier without staleness.
      const { data } = await api.get<SubscriptionInfo>('/api/subscription');
      set({ info: data, isUpdating: false });
    } catch (err) {
      set({ isUpdating: false, error: extractMessage(err) ?? GENERIC_ERROR });
      throw err;
    }
  },

  cancel: async () => {
    set({ isCanceling: true, error: null });
    try {
      const { data } = await api.delete<{ ends_at: string | null }>('/api/subscription');
      set({ isCanceling: false });
      return data.ends_at;
    } catch (err) {
      set({ isCanceling: false, error: extractMessage(err) ?? GENERIC_ERROR });
      throw err;
    }
  },

  portal: async () => {
    set({ error: null });
    const { data } = await api.get<{ portal_url: string }>('/api/subscription/portal');
    return data.portal_url;
  },

  reset: () => set({
    info: null,
    isLoading: false,
    isStartingCheckout: false,
    isUpdating: false,
    isCanceling: false,
    error: null,
  }),
}));

function extractMessage(err: unknown): string | null {
  if (err instanceof AxiosError) {
    const data = err.response?.data;
    if (data && typeof data === 'object') {
      const validation = (data as { errors?: Record<string, string[]> }).errors;
      if (validation) {
        const first = Object.values(validation)[0]?.[0];
        if (first) return first;
      }
      const message = (data as { message?: string }).message;
      if (typeof message === 'string' && message.length > 0) return message;
    }
  }
  return null;
}
