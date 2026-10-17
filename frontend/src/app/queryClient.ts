import { QueryClient } from '@tanstack/react-query';
import { ApiProblem } from '@/api/client';

/**
 * Retry policy: never retry client errors (4xx are answers, not glitches) or unsafe operations; retry transient
 * network/5xx failures a couple of times with the library's exponential backoff.
 */
export function createQueryClient() {
  return new QueryClient({
    defaultOptions: {
      queries: {
        staleTime: 30_000,
        gcTime: 5 * 60_000,
        refetchOnWindowFocus: true,
        retry: (count, error) => {
          if (error instanceof ApiProblem && error.status >= 400 && error.status < 500) return false;
          return count < 2;
        },
      },
      mutations: { retry: false },
    },
  });
}
