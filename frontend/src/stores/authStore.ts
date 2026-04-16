import { create } from 'zustand';
import api, { getCsrfCookie } from '@/api/client';

interface User {
  id: number;
  name: string;
  email: string;
  role: string;
  team?: { id: number; name: string; slug: string; plan: string };
}

interface AuthState {
  user: User | null;
  isLoading: boolean;
  isAuthenticated: boolean;
  login: (email: string, password: string) => Promise<void>;
  register: (name: string, email: string, password: string, password_confirmation: string) => Promise<void>;
  logout: () => Promise<void>;
  fetchUser: () => Promise<void>;
}

export const useAuthStore = create<AuthState>((set) => ({
  user: null,
  isLoading: true,
  isAuthenticated: false,
  login: async (email, password) => {
    await getCsrfCookie();
    await api.post('/api/auth/login', { email, password });
    const { data } = await api.get('/api/auth/user');
    set({ user: data.data, isAuthenticated: true });
  },
  register: async (name, email, password, password_confirmation) => {
    await getCsrfCookie();
    await api.post('/api/auth/register', { name, email, password, password_confirmation });
    const { data } = await api.get('/api/auth/user');
    set({ user: data.data, isAuthenticated: true });
  },
  logout: async () => {
    await api.post('/api/auth/logout');
    set({ user: null, isAuthenticated: false });
  },
  fetchUser: async () => {
    try {
      const { data } = await api.get('/api/auth/user');
      set({ user: data.data, isAuthenticated: true, isLoading: false });
    } catch {
      set({ user: null, isAuthenticated: false, isLoading: false });
    }
  },
}));
