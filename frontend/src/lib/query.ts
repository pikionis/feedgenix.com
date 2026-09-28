import { QueryClient } from '@tanstack/react-query'

/** One QueryClient per browser session (created in root.tsx, cleared on logout from M1). */
export function createQueryClient(): QueryClient {
  return new QueryClient({
    defaultOptions: {
      queries: { staleTime: 30_000, retry: 1, refetchOnWindowFocus: false },
    },
  })
}
