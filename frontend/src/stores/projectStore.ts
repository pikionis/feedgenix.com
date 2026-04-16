import { create } from 'zustand';
import api from '@/api/client';

interface Project {
  id: number;
  name: string;
  description: string | null;
  source_language: string | null;
  target_languages: string[];
  status: string;
  products_count: number;
  created_at: string;
}

interface ProjectState {
  projects: Project[];
  currentProject: Project | null;
  isLoading: boolean;
  fetchProjects: () => Promise<void>;
  fetchProject: (id: number) => Promise<void>;
  createProject: (data: Partial<Project>) => Promise<Project>;
}

export const useProjectStore = create<ProjectState>((set) => ({
  projects: [],
  currentProject: null,
  isLoading: false,
  fetchProjects: async () => {
    set({ isLoading: true });
    const { data } = await api.get('/api/projects');
    set({ projects: data.data, isLoading: false });
  },
  fetchProject: async (id) => {
    set({ isLoading: true });
    const { data } = await api.get(`/api/projects/${id}`);
    set({ currentProject: data.data, isLoading: false });
  },
  createProject: async (projectData) => {
    const { data } = await api.post('/api/projects', projectData);
    return data.data;
  },
}));
