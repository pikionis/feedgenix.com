import react from '@vitejs/plugin-react'
import { defineConfig } from 'vitest/config'

// Unit/component tests run without the React Router Vite plugin; routes are rendered through
// createRoutesStub and the API is mocked with MSW, so no test ever reaches the network.
export default defineConfig({
  plugins: [react()],
  resolve: { tsconfigPaths: true },
  test: {
    environment: 'jsdom',
    setupFiles: ['./src/test/setup.ts'],
    include: ['src/**/*.test.{ts,tsx}'],
    css: false,
    restoreMocks: true,
  },
})
