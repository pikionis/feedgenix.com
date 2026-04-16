import { create } from 'zustand';
import api from '@/api/client';

interface UploadState {
  isUploading: boolean;
  progress: number;
  upload: (projectId: number, file: File) => Promise<void>;
}

export const useUploadStore = create<UploadState>((set) => ({
  isUploading: false,
  progress: 0,
  upload: async (projectId, file) => {
    set({ isUploading: true, progress: 0 });
    const formData = new FormData();
    formData.append('file', file);
    await api.post(`/api/projects/${projectId}/upload`, formData, {
      headers: { 'Content-Type': 'multipart/form-data' },
      onUploadProgress: (e) => {
        if (e.total) set({ progress: Math.round((e.loaded * 100) / e.total) });
      },
    });
    set({ isUploading: false, progress: 100 });
  },
}));
