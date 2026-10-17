/**
 * In-tab event bus fed by the single WebSocket owned by <RealtimeProvider>.
 * Components never open sockets themselves: one connection per tab keeps server connection caps meaningful and
 * makes reconnect/backoff behaviour consistent.
 */
export interface RealtimeMessage {
  type: 'event';
  event: string;
  aggregate: { type: string; id: string };
  cursor?: number;
  at: string;
  /** @deprecated legacy shape (topic/action/entityId) kept for pages that have not been rebuilt yet */
  topic?: string;
  action?: 'INSERT' | 'UPDATE' | 'DELETE' | 'TRANSITION';
  entityId?: string;
}

type Listener = (m: RealtimeMessage) => void;
const listeners = new Set<Listener>();

export const realtimeBus = {
  subscribe(l: Listener): () => void {
    listeners.add(l);
    return () => void listeners.delete(l);
  },
  emit(m: RealtimeMessage) {
    for (const l of [...listeners]) {
      try {
        l(m);
      } catch (e) {
        console.error('realtime listener failed', e);
      }
    }
  },
};
