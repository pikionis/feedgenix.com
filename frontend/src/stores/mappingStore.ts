import { create } from 'zustand';
import { AxiosError } from 'axios';
import api from '@/api/client';

export interface FieldMapping {
  id: number;
  project_id: number;
  upload_id: number | null;
  mapping: Record<string, { kind: string; confidence: number; source_column: string; notes?: string; ai_verified?: boolean }>;
  translatable_fields: string[];
  protected_fields: string[];
  skipped_fields: string[];
  ambiguous_fields: string[];
  detected_source_language: string | null;
  ai_confidence: number | null;
  ai_model: string | null;
  user_approved: boolean;
  approved_at: string | null;
}

interface MappingState {
  mapping: FieldMapping | null;
  isLoading: boolean;
  isSaving: boolean;
  error: string | null;
  fetch: (projectId: number) => Promise<void>;
  /** Persist bucket changes server-side. Doesn't approve. */
  save: (projectId: number, buckets: Pick<FieldMapping, 'translatable_fields' | 'protected_fields' | 'skipped_fields'>) => Promise<void>;
  /** Approves the mapping (sets user_approved = true, project.status = review). */
  approve: (projectId: number) => Promise<void>;
  reset: () => void;
}

const GENERIC_ERROR = 'Could not save the mapping. Please try again.';

export const useMappingStore = create<MappingState>((set, get) => ({
  mapping: null,
  isLoading: false,
  isSaving: false,
  error: null,

  fetch: async (projectId) => {
    set({ isLoading: true, error: null });
    try {
      const { data } = await api.get<FieldMapping>(`/api/projects/${projectId}/mapping`);
      set({ mapping: data, isLoading: false });
    } catch (err) {
      set({
        mapping: null,
        isLoading: false,
        error: extractMessage(err) ?? 'Mapping not ready yet.',
      });
    }
  },

  save: async (projectId, buckets) => {
    set({ isSaving: true, error: null });
    try {
      const { data } = await api.put<FieldMapping>(`/api/projects/${projectId}/mapping`, buckets);
      set({ mapping: data, isSaving: false });
    } catch (err) {
      set({ isSaving: false, error: extractMessage(err) ?? GENERIC_ERROR });
      throw err;
    }
  },

  approve: async (projectId) => {
    const current = get().mapping;
    if (!current) return;
    set({ isSaving: true, error: null });
    try {
      const { data } = await api.put<FieldMapping>(`/api/projects/${projectId}/mapping`, {
        translatable_fields: current.translatable_fields,
        protected_fields: current.protected_fields,
        skipped_fields: current.skipped_fields,
        user_approved: true,
      });
      set({ mapping: data, isSaving: false });
    } catch (err) {
      set({ isSaving: false, error: extractMessage(err) ?? GENERIC_ERROR });
      throw err;
    }
  },

  reset: () => set({ mapping: null, isLoading: false, isSaving: false, error: null }),
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
