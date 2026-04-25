import { create } from 'zustand';
import { AxiosError } from 'axios';
import api from '@/api/client';

export type JobStatus = 'queued' | 'processing' | 'completed' | 'failed' | 'cancelled';

export interface TranslationJob {
  id: number;
  project_id: number;
  team_id: number;
  status: JobStatus;
  total_products: number;
  processed_products: number;
  total_translations: number;
  completed_translations: number;
  cached_translations: number;
  failed_translations: number;
  estimated_cost: number;
  actual_cost: number;
  started_at: string | null;
  completed_at: string | null;
  error_message: string | null;
}

interface TranslationJobState {
  job: TranslationJob | null;
  /** 0..100, derived from the job. Held separately for animation smoothness. */
  progressPct: number;
  isStarting: boolean;
  isPolling: boolean;
  error: string | null;
  /** Kick off translation — returns the freshly-created job. */
  start: (projectId: number) => Promise<TranslationJob | null>;
  /** Manual one-shot fetch of the latest job state. */
  refresh: (projectId: number) => Promise<void>;
  /** Begin polling until the job reaches a terminal state, then stop. */
  startPolling: (projectId: number, intervalMs?: number) => void;
  stopPolling: () => void;
  reset: () => void;
}

const TERMINAL_STATUSES: JobStatus[] = ['completed', 'failed', 'cancelled'];
const DEFAULT_INTERVAL = 2000;
const GENERIC_ERROR = 'Could not reach the translation service. Please retry.';

let pollHandle: ReturnType<typeof setInterval> | null = null;

export const useTranslationJobStore = create<TranslationJobState>((set, get) => ({
  job: null,
  progressPct: 0,
  isStarting: false,
  isPolling: false,
  error: null,

  start: async (projectId) => {
    set({ isStarting: true, error: null });
    try {
      const { data } = await api.post<TranslationJob>(`/api/projects/${projectId}/translate`);
      set({
        job: data,
        isStarting: false,
        progressPct: pctOf(data),
        error: null,
      });
      return data;
    } catch (err) {
      set({ isStarting: false, error: extractMessage(err) ?? GENERIC_ERROR });
      throw err;
    }
  },

  refresh: async (projectId) => {
    try {
      const { data } = await api.get<{ job: TranslationJob; progress_percentage: number }>(
        `/api/projects/${projectId}/status`,
      );
      set({
        job: data.job,
        // Server rounds the percentage; trust it for parity with status pages.
        progressPct: typeof data.progress_percentage === 'number' ? data.progress_percentage : pctOf(data.job),
        error: null,
      });
    } catch (err) {
      // 404 → no job yet for this project. Silently leave job as-is so a
      // freshly-loaded page doesn't show an error before the user has hit
      // "Start translation".
      if (err instanceof AxiosError && err.response?.status === 404) {
        return;
      }
      set({ error: extractMessage(err) ?? GENERIC_ERROR });
    }
  },

  startPolling: (projectId, intervalMs = DEFAULT_INTERVAL) => {
    // Always clear any prior poll before starting a new one — switching
    // projects must not leave a stale interval querying the old id.
    if (pollHandle) {
      clearInterval(pollHandle);
      pollHandle = null;
    }
    set({ isPolling: true });

    const tick = async () => {
      await get().refresh(projectId);
      const status = get().job?.status;
      if (status && TERMINAL_STATUSES.includes(status)) {
        get().stopPolling();
      }
    };

    // Poll once immediately so the UI updates before the first interval.
    void tick();
    pollHandle = setInterval(() => void tick(), intervalMs);
  },

  stopPolling: () => {
    if (pollHandle) {
      clearInterval(pollHandle);
      pollHandle = null;
    }
    set({ isPolling: false });
  },

  reset: () => {
    if (pollHandle) {
      clearInterval(pollHandle);
      pollHandle = null;
    }
    set({ job: null, progressPct: 0, isStarting: false, isPolling: false, error: null });
  },
}));

function pctOf(job: TranslationJob | null): number {
  if (!job || job.total_translations <= 0) return 0;
  return Math.max(0, Math.min(100, Math.round((job.completed_translations / job.total_translations) * 100)));
}

function extractMessage(err: unknown): string | null {
  if (err instanceof AxiosError) {
    const data = err.response?.data;
    if (data && typeof data === 'object') {
      const message = (data as { message?: string }).message;
      if (typeof message === 'string' && message.length > 0) return message;
    }
  }
  return null;
}
