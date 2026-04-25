import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import { AxiosError } from 'axios';
import { useTranslationJobStore, type TranslationJob, type JobStatus } from './translationJobStore';
import api from '@/api/client';

function fakeJob(overrides: Partial<TranslationJob> = {}): TranslationJob {
  return {
    id: 1,
    project_id: 42,
    team_id: 10,
    status: 'queued',
    total_products: 100,
    processed_products: 0,
    total_translations: 200,
    completed_translations: 0,
    cached_translations: 0,
    failed_translations: 0,
    estimated_cost: 0,
    actual_cost: 0,
    started_at: null,
    completed_at: null,
    error_message: null,
    ...overrides,
  };
}

function reset() {
  useTranslationJobStore.getState().reset();
}

describe('translationJobStore', () => {
  beforeEach(() => {
    reset();
    vi.restoreAllMocks();
  });

  afterEach(() => {
    reset();
    vi.useRealTimers();
  });

  it('start() POSTs to /translate and stores the new job', async () => {
    const job = fakeJob({ status: 'queued' });
    const post = vi.spyOn(api, 'post').mockResolvedValue({ data: job });

    const result = await useTranslationJobStore.getState().start(42);

    expect(post).toHaveBeenCalledWith('/api/projects/42/translate');
    expect(result).toEqual(job);
    expect(useTranslationJobStore.getState().job?.status).toBe('queued');
    expect(useTranslationJobStore.getState().error).toBeNull();
  });

  it('start() surfaces the server message when the project rejects translation', async () => {
    const err = new AxiosError('Unprocessable', '422');
    err.response = { status: 422, data: { message: 'Field mapping must be approved before starting translation.' } } as never;
    vi.spyOn(api, 'post').mockRejectedValue(err);

    await expect(useTranslationJobStore.getState().start(42)).rejects.toBeDefined();

    expect(useTranslationJobStore.getState().error).toBe('Field mapping must be approved before starting translation.');
    expect(useTranslationJobStore.getState().isStarting).toBe(false);
  });

  it('refresh() trusts the server-rounded progress_percentage', async () => {
    vi.spyOn(api, 'get').mockResolvedValue({
      data: { job: fakeJob({ completed_translations: 73, total_translations: 200 }), progress_percentage: 36.5 },
    });

    await useTranslationJobStore.getState().refresh(42);

    expect(useTranslationJobStore.getState().progressPct).toBe(36.5);
  });

  it('refresh() falls back to deriving the percentage when the server omits it', async () => {
    vi.spyOn(api, 'get').mockResolvedValue({
      data: { job: fakeJob({ completed_translations: 50, total_translations: 200 }), progress_percentage: undefined },
    });

    await useTranslationJobStore.getState().refresh(42);

    expect(useTranslationJobStore.getState().progressPct).toBe(25);
  });

  it('refresh() ignores 404 (no job yet) without setting an error', async () => {
    const err = new AxiosError('Not Found', '404');
    err.response = { status: 404, data: { message: 'No translation job found for this project.' } } as never;
    vi.spyOn(api, 'get').mockRejectedValue(err);

    await useTranslationJobStore.getState().refresh(42);

    expect(useTranslationJobStore.getState().error).toBeNull();
  });

  it('refresh() surfaces non-404 errors verbatim', async () => {
    const err = new AxiosError('Server error', '500');
    err.response = { status: 500, data: { message: 'Translation service down.' } } as never;
    vi.spyOn(api, 'get').mockRejectedValue(err);

    await useTranslationJobStore.getState().refresh(42);

    expect(useTranslationJobStore.getState().error).toBe('Translation service down.');
  });

  it('startPolling polls until the job reaches a terminal state, then stops', async () => {
    vi.useFakeTimers();

    let status: JobStatus = 'processing';
    vi.spyOn(api, 'get').mockImplementation(async () => ({
      data: { job: fakeJob({ status }), progress_percentage: 0 },
    }));

    useTranslationJobStore.getState().startPolling(42, 1000);

    // Run several ticks with status=processing → polling stays active.
    for (let i = 0; i < 4; i++) {
      await vi.advanceTimersByTimeAsync(1000);
    }
    expect(useTranslationJobStore.getState().isPolling).toBe(true);
    expect(useTranslationJobStore.getState().job?.status).toBe('processing');

    // Server flips to completed → next tick must terminate the poll.
    status = 'completed';
    await vi.advanceTimersByTimeAsync(1000);

    expect(useTranslationJobStore.getState().job?.status).toBe('completed');
    expect(useTranslationJobStore.getState().isPolling).toBe(false);

    // Subsequent advances must not make any more requests.
    const callsAfterTerminal = (api.get as any).mock.calls.length;
    await vi.advanceTimersByTimeAsync(10_000);
    expect((api.get as any).mock.calls.length).toBe(callsAfterTerminal);
  });

  it('startPolling cancels any previous interval so a project switch does not double up', async () => {
    vi.useFakeTimers();
    vi.spyOn(api, 'get').mockResolvedValue({
      data: { job: fakeJob({ status: 'processing' }), progress_percentage: 0 },
    });

    useTranslationJobStore.getState().startPolling(1, 1000);
    await vi.advanceTimersByTimeAsync(2_000);
    const callsAtSwitch = (api.get as any).mock.calls.length;

    useTranslationJobStore.getState().startPolling(2, 1000);
    await vi.advanceTimersByTimeAsync(2_000);
    const totalCalls = (api.get as any).mock.calls.length;

    // After the switch we should poll once per tick (not twice). With ~2s
    // before and ~2s after, two intervals on a single timer ≈ 4 extra calls
    // total above the pre-switch count plus 1 immediate tick from the new
    // start. If the old interval had survived we'd see at least one more.
    const newCalls = totalCalls - callsAtSwitch;
    // At most: 1 immediate + 2 interval ticks = 3.
    expect(newCalls).toBeLessThanOrEqual(3);

    // And the latest URL is for project 2, not project 1.
    const lastUrl = (api.get as any).mock.calls.at(-1)?.[0] as string;
    expect(lastUrl).toContain('/projects/2/');
  });

  it('stopPolling clears the interval and flips isPolling false', async () => {
    vi.useFakeTimers();
    vi.spyOn(api, 'get').mockResolvedValue({
      data: { job: fakeJob({ status: 'processing' }), progress_percentage: 0 },
    });

    useTranslationJobStore.getState().startPolling(42, 1000);
    await vi.runOnlyPendingTimersAsync();
    useTranslationJobStore.getState().stopPolling();

    const callsAtStop = (api.get as any).mock.calls.length;

    await vi.advanceTimersByTimeAsync(5000);
    expect((api.get as any).mock.calls.length).toBe(callsAtStop);
    expect(useTranslationJobStore.getState().isPolling).toBe(false);
  });

  it('reset() stops polling and clears state', async () => {
    vi.useFakeTimers();
    vi.spyOn(api, 'get').mockResolvedValue({
      data: { job: fakeJob({ status: 'processing' }), progress_percentage: 0 },
    });

    useTranslationJobStore.getState().startPolling(42, 1000);
    await vi.runOnlyPendingTimersAsync();
    useTranslationJobStore.getState().reset();

    const s = useTranslationJobStore.getState();
    expect(s.job).toBeNull();
    expect(s.progressPct).toBe(0);
    expect(s.isPolling).toBe(false);
    expect(s.error).toBeNull();
  });
});
