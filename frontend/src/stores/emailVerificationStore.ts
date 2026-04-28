import { create } from 'zustand';
import { AxiosError } from 'axios';
import api from '@/api/client';

interface EmailVerificationState {
  /** null = unknown (not yet fetched) / true = verified / false = pending */
  verified: boolean | null;
  email: string | null;
  isLoading: boolean;
  isSending: boolean;
  /** Last error from a resend or status fetch. */
  error: string | null;
  /**
   * Set after a successful resend. The frontend uses it to disable the resend
   * button for ~10 seconds (matches the server's 6/min throttle politely).
   */
  resentAt: number | null;

  fetchStatus: () => Promise<void>;
  resend: () => Promise<void>;
  /** Marks verified locally (used by /email/verified after the click handler). */
  markVerified: () => void;
  reset: () => void;
}

const GENERIC_ERROR = "We couldn't reach the server. Please try again.";

export const useEmailVerificationStore = create<EmailVerificationState>((set) => ({
  verified: null,
  email: null,
  isLoading: false,
  isSending: false,
  error: null,
  resentAt: null,

  fetchStatus: async () => {
    set({ isLoading: true, error: null });
    try {
      const { data } = await api.get<{ verified: boolean; email: string }>('/api/email/status');
      set({ verified: !!data.verified, email: data.email, isLoading: false });
    } catch (err) {
      // 401 is the expected "not authenticated" case — treat as unknown,
      // not an error, so the banner doesn't flash on guest pages.
      if (err instanceof AxiosError && err.response?.status === 401) {
        set({ verified: null, email: null, isLoading: false, error: null });
        return;
      }
      set({ isLoading: false, error: extractMessage(err) });
    }
  },

  resend: async () => {
    set({ isSending: true, error: null });
    try {
      await api.post('/api/email/verification-notification');
      set({ isSending: false, resentAt: Date.now() });
    } catch (err) {
      // 429 throttle returns a friendly message; surface verbatim.
      if (err instanceof AxiosError && err.response?.status === 429) {
        set({ isSending: false, error: 'Slow down — wait a minute before requesting another email.' });
        return;
      }
      set({ isSending: false, error: extractMessage(err) });
      throw err;
    }
  },

  markVerified: () => set({ verified: true, error: null }),

  reset: () => set({
    verified: null,
    email: null,
    isLoading: false,
    isSending: false,
    error: null,
    resentAt: null,
  }),
}));

function extractMessage(err: unknown): string {
  if (err instanceof AxiosError) {
    const data = err.response?.data;
    if (data && typeof data === 'object') {
      const message = (data as { message?: string }).message;
      if (typeof message === 'string' && message.length > 0) return message;
    }
  }
  return GENERIC_ERROR;
}
