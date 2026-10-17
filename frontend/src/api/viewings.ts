import { api, unwrap } from './client';

/** Site visits (v1 API): booking, rescheduling, outcomes, calendar file. */
export type ViewingStatus = 'scheduled' | 'confirmed' | 'completed' | 'cancelled' | 'no_show';
export interface Viewing {
  id: string;
  leadId: string;
  propertyId: string;
  agentId: string | null;
  scheduledAt: string;
  durationMinutes: number;
  status: ViewingStatus;
  notes: string | null;
  version: number;
  outcome: { code: string; label: string } | null;
  outcomeNote: string | null;
  closedAt: string | null;
  cancelReason: string | null;
  reminderTaskId: string | null;
  createdAt: string;
  property: { id: string; title: string; price: number; location: string | null };
  leadContact: { firstName: string | null; lastName: string | null; email: string | null; phone: string | null };
  agent: { id: string; name: string } | null;
}
export interface ViewingOutcome {
  code: string;
  label: string;
}
export interface BookInput {
  leadId: string;
  propertyId: string;
  scheduledAt: string;
  durationMinutes?: number;
  notes?: string | null;
  agentId?: string | null;
}
export interface CompleteResult {
  viewing: Viewing;
  followUp: { id: string; title: string; dueDate: string | null } | null;
  suggestedStage: { stageId: string; name: string } | null;
}

export const viewingsApi = {
  list: (q: { leadId?: string; propertyId?: string; status?: ViewingStatus; from?: string; to?: string; limit?: number } = {}) =>
    api.get<Viewing[]>('/api/v1/viewings', { query: { limit: 200, ...q } }),
  get: (id: string) => unwrap(api.get<Viewing>(`/api/v1/viewings/${id}`)),
  outcomes: () => unwrap(api.get<ViewingOutcome[]>('/api/v1/viewings/outcomes')),
  book: (input: BookInput) => unwrap(api.post<Viewing>('/api/v1/viewings', input)),
  reschedule: (id: string, version: number, patch: { scheduledAt?: string; durationMinutes?: number; notes?: string | null; agentId?: string | null }) =>
    unwrap(api.patch<Viewing>(`/api/v1/viewings/${id}`, patch, { headers: { 'If-Match': `"${version}"` } })),
  cancel: (id: string, reason?: string) => unwrap(api.post<Viewing>(`/api/v1/viewings/${id}/cancel`, { reason: reason || undefined })),
  noShow: (id: string, note?: string) => unwrap(api.post<Viewing>(`/api/v1/viewings/${id}/no-show`, { note: note || undefined })),
  complete: (id: string, input: { outcome: string; note?: string; followUp?: { dueAt: string; title?: string } }) => unwrap(api.post<CompleteResult>(`/api/v1/viewings/${id}/complete`, input)),
  /** Same-origin URL: the session cookie authorises the download. */
  icsUrl: (id: string) => `/api/v1/viewings/${id}/ics`,
};
