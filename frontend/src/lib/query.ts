import { QueryClient } from '@tanstack/react-query'

export function createQueryClient(): QueryClient {
  return new QueryClient({
    defaultOptions: {
      queries: { staleTime: 30_000, retry: 1, refetchOnWindowFocus: false },
    },
  })
}

/**
 * The browser's single QueryClient, shared by React and the route loaders (which run outside
 * React). Cleared on sign-out so nothing of one session survives into the next.
 */
export const queryClient = createQueryClient()
