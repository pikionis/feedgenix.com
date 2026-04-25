import { create } from 'zustand';
import { AxiosError } from 'axios';
import api from '@/api/client';

interface UploadState {
  isUploading: boolean;
  /** 0..100 — UPLOAD progress only (server-side parsing happens after). */
  progress: number;
  error: string | null;
  upload: (projectId: number, file: File) => Promise<void>;
  reset: () => void;
}

/** Error message we surface for any non-axios / unstructured failure. */
const GENERIC_ERROR = 'Upload failed. Please try again.';

export const useUploadStore = create<UploadState>((set) => ({
  isUploading: false,
  progress: 0,
  error: null,

  upload: async (projectId, file) => {
    set({ isUploading: true, progress: 0, error: null });
    const formData = new FormData();
    formData.append('file', file);

    try {
      await api.post(`/api/projects/${projectId}/upload`, formData, {
        headers: { 'Content-Type': 'multipart/form-data' },
        onUploadProgress: (e) => {
          if (e.total) {
            set({ progress: Math.round((e.loaded * 100) / e.total) });
          }
        },
      });
      set({ isUploading: false, progress: 100, error: null });
    } catch (err) {
      const message = extractMessage(err);
      // Reset progress so the UI doesn't lie about partial success.
      set({ isUploading: false, progress: 0, error: message });
      throw err;
    }
  },

  reset: () => set({ isUploading: false, progress: 0, error: null }),
}));

function extractMessage(err: unknown): string {
  if (err instanceof AxiosError) {
    const data = err.response?.data;
    if (data && typeof data === 'object') {
      // Laravel validation surfaces under `errors.<field>[]`.
      const validation = (data as { errors?: Record<string, string[]> }).errors;
      if (validation) {
        const first = Object.values(validation)[0]?.[0];
        if (first) return first;
      }
      const message = (data as { message?: string }).message;
      if (typeof message === 'string' && message.length > 0) {
        return message;
      }
    }
  }
  return GENERIC_ERROR;
}
