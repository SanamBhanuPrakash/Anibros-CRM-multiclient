import { useEffect, useRef, type ReactNode } from 'react';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { createQueryClient } from './queryClient';
import { useScopeState } from './scope';

const AGENCY = '__agency__';

/**
 * One data cache per open client (and one for the agency home).
 *
 * Existing screens key their queries by what they show ("leads", "tasks"...), not by client. Giving every client its own cache means
 * client A's leads can never be served from client B's cache, and switching tabs shows each client exactly as you left it, instantly.
 * Closing a client tab discards its cache.
 */
export function ScopedQueryProvider({ children }: { children: ReactNode }) {
  const { scope, tabs } = useScopeState();
  const caches = useRef(new Map<string, QueryClient>());
  const key = scope ?? AGENCY;
  let qc = caches.current.get(key);
  if (!qc) {
    qc = createQueryClient();
    caches.current.set(key, qc);
  }

  useEffect(() => {
    const keep = new Set([AGENCY, ...tabs]);
    for (const [k, c] of caches.current) {
      if (!keep.has(k)) {
        c.clear();
        caches.current.delete(k);
      }
    }
  }, [tabs]);

  return <QueryClientProvider client={qc}>{children}</QueryClientProvider>;
}
