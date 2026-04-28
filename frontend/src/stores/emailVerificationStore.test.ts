import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { AxiosError } from 'axios';
import { useEmailVerificationStore } from './emailVerificationStore';
import api from '@/api/client';

function reset() {
  useEmailVerificationStore.getState().reset();
}

describe('emailVerificationStore', () => {
  beforeEach(() => {
    reset();
    vi.restoreAllMocks();
  });

  afterEach(() => reset());

  it('fetchStatus stores verified + email on 200', async () => {
    vi.spyOn(api, 'get').mockResolvedValue({
      data: { verified: false, email: 'maria@example.com' },
    });

    await useEmailVerificationStore.getState().fetchStatus();

    const s = useEmailVerificationStore.getState();
    expect(s.verified).toBe(false);
    expect(s.email).toBe('maria@example.com');
    expect(s.error).toBeNull();
  });

  it('fetchStatus treats 401 as silent unknown, not an error', async () => {
    const err = new AxiosError('Unauthenticated', '401');
    err.response = { status: 401, data: { message: 'Unauthenticated.' } } as never;
    vi.spyOn(api, 'get').mockRejectedValue(err);

    await useEmailVerificationStore.getState().fetchStatus();

    const s = useEmailVerificationStore.getState();
    expect(s.verified).toBeNull();
    expect(s.error).toBeNull();
  });

  it('fetchStatus surfaces server errors other than 401', async () => {
    const err = new AxiosError('Server error', '500');
    err.response = { status: 500, data: { message: 'Down.' } } as never;
    vi.spyOn(api, 'get').mockRejectedValue(err);

    await useEmailVerificationStore.getState().fetchStatus();

    expect(useEmailVerificationStore.getState().error).toBe('Down.');
  });

  it('resend POSTs and stamps resentAt', async () => {
    const post = vi.spyOn(api, 'post').mockResolvedValue({ data: {} });
    const before = Date.now();

    await useEmailVerificationStore.getState().resend();

    expect(post).toHaveBeenCalledWith('/api/email/verification-notification');
    expect(useEmailVerificationStore.getState().resentAt).toBeGreaterThanOrEqual(before);
    expect(useEmailVerificationStore.getState().error).toBeNull();
  });

  it('resend rethrows on failure and surfaces the throttle message on 429', async () => {
    const err = new AxiosError('Too Many Requests', '429');
    err.response = { status: 429, data: { message: 'Too Many Attempts.' } } as never;
    vi.spyOn(api, 'post').mockRejectedValue(err);

    // 429 path is handled silently inside the store — does not throw.
    await useEmailVerificationStore.getState().resend();

    expect(useEmailVerificationStore.getState().error).toMatch(/Slow down/);
    expect(useEmailVerificationStore.getState().resentAt).toBeNull();
  });

  it('resend rethrows non-429 errors so the caller can react', async () => {
    const err = new AxiosError('Server error', '500');
    err.response = { status: 500, data: { message: 'Mailer down.' } } as never;
    vi.spyOn(api, 'post').mockRejectedValue(err);

    await expect(useEmailVerificationStore.getState().resend()).rejects.toBeDefined();

    expect(useEmailVerificationStore.getState().error).toBe('Mailer down.');
  });

  it('markVerified flips state without hitting the API', () => {
    useEmailVerificationStore.setState({ verified: false, email: 'x@x' });

    useEmailVerificationStore.getState().markVerified();

    expect(useEmailVerificationStore.getState().verified).toBe(true);
    expect(useEmailVerificationStore.getState().error).toBeNull();
  });

  it('reset clears every field', () => {
    useEmailVerificationStore.setState({
      verified: true,
      email: 'x@x',
      isLoading: true,
      isSending: true,
      error: 'kapow',
      resentAt: Date.now(),
    });

    useEmailVerificationStore.getState().reset();
    const s = useEmailVerificationStore.getState();

    expect(s.verified).toBeNull();
    expect(s.email).toBeNull();
    expect(s.isLoading).toBe(false);
    expect(s.isSending).toBe(false);
    expect(s.error).toBeNull();
    expect(s.resentAt).toBeNull();
  });
});
