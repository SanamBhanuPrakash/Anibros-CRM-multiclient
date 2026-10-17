import { createContext, useContext, useEffect, useRef, useState, type ReactNode } from 'react';
import { useQueryClient } from '@tanstack/react-query';
import { useAuth } from '@/features/auth/AuthProvider';
import { realtimeBus } from './bus';
import { api } from '@/api/client';

/**
 * One WebSocket per tab. A push is only a hint: it invalidates query caches and the normal, authorized API is
 * re-queried. Consequences: duplicates are harmless, missed pushes self-heal on reconnect (`hello`/`resync` refetch
 * everything), and authorization stays in exactly one place (the API).
 */
export type RealtimeStatus = 'connecting' | 'live' | 'reconnecting' | 'offline';
const Ctx = createContext<{ status: RealtimeStatus }>({ status: 'offline' });
export const useRealtimeStatus = () => useContext(Ctx).status;

/** Event type prefix → query-key roots to invalidate. Keep in sync with server EVENT_CATALOG. */
export const INVALIDATIONS: Record<string, string[]> = {
  lead: ['leads', 'dashboard', 'reports', 'pipeline', 'home'],
  contact: ['contacts'],
  pipeline: ['pipelines', 'leads', 'pipeline'],
  import: ['imports', 'leads'],
  activity: ['activities', 'leads'],
  task: ['tasks', 'dashboard', 'home'],
  viewing: ['viewings', 'calendar', 'tasks', 'leads', 'home', 'dashboard'],
  automation: ['automations', 'automation-runs'],
  property: ['properties', 'matching', 'projects', 'deals', 'dashboard'],
  reservation: ['deals', 'properties', 'leads', 'home', 'dashboard', 'pipelines', 'shortlist'],
  shortlist: ['shortlist', 'leads'],
  project: ['projects', 'properties'],
  notification: ['notifications'],
  integration: ['integrations'],
  lead_source: ['lead-sources'],
  inbound: ['lead-sources', 'leads'],
  webhook: ['operations'],
  member: ['members', 'me'],
};

export function RealtimeProvider({ children }: { children: ReactNode }) {
  const qc = useQueryClient();
  const { status: auth, me } = useAuth();
  const [status, setStatus] = useState<RealtimeStatus>('offline');
  const tenantId = me?.activeTenantId;
  const attempts = useRef(0);

  useEffect(() => {
    if (auth !== 'authenticated' || !tenantId) {
      setStatus('offline');
      return;
    }
    let timer: ReturnType<typeof setTimeout> | undefined;
    let stopped = false;
    let cursor: number | undefined;
    let needsResync = true;
    let pending = new Set<string>();
    let flushTimer: ReturnType<typeof setTimeout> | undefined;

    // Coalesce bursts (e.g. an import creating 500 leads) into one invalidation per key per 150 ms.
    const queueInvalidate = (roots: string[]) => {
      roots.forEach((r) => pending.add(r));
      flushTimer ??= setTimeout(() => {
        const keys = [...pending];
        pending = new Set();
        flushTimer = undefined;
        keys.forEach((k) => void qc.invalidateQueries({ queryKey: [k] }));
      }, 150);
    };

    const refetchAll = () => void qc.invalidateQueries({ predicate: (q) => q.queryKey[0] !== 'me' });

    const poll = async () => {
      setStatus(attempts.current === 0 ? 'connecting' : 'reconnecting');
      try {
        const response = await api.get<{ events: Array<{ type: 'event'; event: string; aggregate: { type: string; id: string }; cursor: number; at: string }>; cursor: number }>('/api/v1/realtime/events', { query: cursor === undefined ? {} : { after: cursor } });
        if (stopped) return;
        cursor = response.data.cursor;
        attempts.current = 0;
        setStatus('live');
        if (needsResync) {
          needsResync = false;
          refetchAll();
        }
        for (const event of response.data.events) {
          realtimeBus.emit(event);
          queueInvalidate(INVALIDATIONS[event.event.split('.')[0]] ?? []);
        }
        timer = setTimeout(poll, 5_000);
      } catch (error: any) {
        if (stopped || error?.status === 401 || error?.status === 403) return setStatus('offline');
        setStatus('reconnecting');
        const delay = Math.min(30_000, 1_000 * 2 ** attempts.current++) * (0.7 + Math.random() * 0.6);
        timer = setTimeout(poll, delay);
      }
    };

    // Deferred a tick so React StrictMode's mount→unmount→mount in dev doesn't open (and abort) a throw-away socket.
    timer = setTimeout(poll, 40);
    const online = () => { needsResync = true; clearTimeout(timer); void poll(); };
    window.addEventListener('online', online);
    return () => {
      stopped = true;
      clearTimeout(timer);
      clearTimeout(flushTimer);
      window.removeEventListener('online', online);
    };
  }, [auth, tenantId, qc]);

  return <Ctx.Provider value={{ status }}>{children}</Ctx.Provider>;
}
