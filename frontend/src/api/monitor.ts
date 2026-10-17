import { api, unwrap } from './client';

/** Monitor panel: health overview, activity log, audit trail (v1 API). */
export type CheckStatus = 'ok' | 'warn' | 'fail';
export interface Check {
  id: 'database' | 'worker' | 'queue' | 'intake' | 'errors';
  label: string;
  status: CheckStatus;
  detail: string;
}
export interface Overview {
  generatedAt: string;
  status: 'healthy' | 'attention' | 'problem';
  summary: string;
  checks: Check[];
  queue: { queued: number; running: number; deadLast7d: number; oldestWaitingSeconds: number };
  intake: { activeSources: number; pausedSources: number; deliveries24h: Record<string, number>; quietSources: Array<{ id: string; name: string; lastReceivedAt: string }> };
  leads: { created24h: number; created7d: number; bySource7d: Array<{ source: string; n: number }>; firstReplyOverdue: number; tasksOverdue: number } | null;
  events: { errors24h: number; security24h: number; warnings24h: number };
  audit: { actions24h: number };
  recent: Array<{ id: string; at: string; severity: string; component: string; message: string }>;
}
export interface LogEvent {
  id: string;
  at: string;
  severity: 'info' | 'warn' | 'error' | 'fatal';
  component: string;
  message: string;
  requestId: string | null;
  userName: string | null;
  context: Record<string, unknown>;
}
export interface AuditEntry {
  id: string;
  at: string;
  action: string;
  entityType: string;
  entityId: string;
  actorId: string | null;
  actorName: string | null;
  ip: string | null;
  requestId: string | null;
}
export interface AuditFilters {
  action?: string;
  entityType?: string;
  actorId?: string;
  from?: string;
  to?: string;
}
export interface LogFilters {
  severity?: 'warn' | 'error';
  component?: string;
  q?: string;
}

const clean = <T extends object>(o: T) => Object.fromEntries(Object.entries(o).filter(([, v]) => v !== undefined && v !== '')) as T;

export const monitorApi = {
  overview: () => unwrap(api.get<Overview>('/api/v1/monitor/overview')),
  events: (f: LogFilters, cursor?: string) => api.get<LogEvent[]>('/api/v1/monitor/events', { query: { limit: 30, ...clean(f), cursor } }),
  audit: (f: AuditFilters, cursor?: string) => api.get<AuditEntry[]>('/api/v1/monitor/audit', { query: { limit: 30, ...clean(f), cursor } }),
  auditDetail: (id: string) => unwrap(api.get<AuditEntry & { before: unknown; after: unknown }>(`/api/v1/monitor/audit/${id}`)),
  facets: () => unwrap(api.get<{ actions: string[]; entityTypes: string[]; people: Array<{ id: string; name: string }> }>('/api/v1/monitor/audit/facets')),
  exportUrl: (f: AuditFilters) => `/api/v1/monitor/audit/export?${new URLSearchParams(clean(f) as Record<string, string>).toString()}`,
};
