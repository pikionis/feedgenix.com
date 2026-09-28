import { reactRouter } from '@react-router/dev/vite'
import tailwindcss from '@tailwindcss/vite'
import { defineConfig } from 'vite'

// One origin for the SPA and the API: the dev server proxies API calls to Laravel exactly like
// nginx does in production, so the Sanctum session cookie needs no CORS.
const backend = process.env.FEEDGENIX_BACKEND_URL ?? 'http://127.0.0.1:8000'

export default defineConfig({
  plugins: [tailwindcss(), reactRouter()],
  resolve: { tsconfigPaths: true },
  server: {
    port: 5173,
    strictPort: true,
    proxy: {
      '/api': backend,
      '/sanctum': backend,
      '/up': backend,
      // Google sign-in: server-side redirects with a session.
      '/auth': backend,
    },
  },
})
