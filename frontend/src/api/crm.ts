import { api, unwrap } from './client';

/** Pipelines, leads, saved views and imports (v1 API). Contacts and team live in `endpoints.ts`. */
export type LeadStatus = 'open' | 'contacted' | 'qualified' | 'viewing_scheduled' | 'negotiation' | 'won' | 'lost' | 'disqualified';
export type LeadSource = 'website' | 'referral' | 'portal' | 'direct' | 'meta_ads' | 'google_ads' | 'walk_in' | 'other';
export type SlaState = 'none' | 'met' | 'ok' | 'at_risk' | 'breached';
export type GateField = 'ownerId' | 'estimatedValue' | 'budgetMax' | 'phone' | 'email' | 'nextFollowupAt';

export interface Stage {
  id: string;
  name: string;
  stageKey: string;
  orderIndex: number;
  color: string;
  mapsToStatus: LeadStatus;
  probability: number;
  isClosed: boolean;
  leadCount: number;
}
export interface Pipeline {
  id: string;
  name: string;
  description: string | null;
  isDefault: boolean;
  /** Lost and Not a fit need a reason code (the recommended pipeline); otherwise a written reason is enough. */
  reasonCodesRequired: boolean;
  /** Won needs a recorded deal value and the unit sold (decision D3). On in the recommended pipeline. */
  dealValueRequired: boolean;
  /** 'beginner_v1' when created from the recommended pipeline. */
  template: string | null;
  stages: Stage[];
}
export type ReasonKind = 'lost' | 'not_a_fit';
export interface ReasonCode {
  code: string;
  label: string;
}
export type ReasonCodes = Record<ReasonKind, ReasonCode[]>;
export interface Transition {
  fromStageId: string;
  toStageId: string;
  requiredFields: GateField[];
}

export interface Lead {
  id: string;
  contactId: string | null;
  contactName: string | null;
  contactEmail: string | null;
  contactPhone: string | null;
  pipelineId: string;
  stageId: string;
  stageName: string;
  stageKey: string | null;
  stageColor: string;
  stageProbability: number;
  status: LeadStatus;
  ownerId: string | null;
  source: LeadSource;
  leadScore: number;
  scoreBreakdown: Array<{ factor: string; points: number; note: string }>;
  estimatedValue: number | null;
  currency: string;
  budgetMin: number | null;
  budgetMax: number | null;
  bedroomsMin: number | null;
  locations: string[];
  propertyTypes: string[];
  listingType: string | null;
  tags: string[];
  campaign: string | null;
  customFields: Record<string, unknown>;
  lossReason: string | null;
  lossReasonCode: string | null;
  closedAt: string | null;
  assignedAt: string | null;
  nextFollowupAt: string | null;
  lastActivityAt: string | null;
  sla: { state: SlaState; dueAt: string | null };
  version: number;
  createdAt: string;
  updatedAt: string;
}
export interface NextStep {
  kind: 'reply' | 'reply_overdue' | 'record_visit' | 'visit' | 'task' | 'plan';
  title: string;
  detail: string;
  dueAt: string | null;
  urgent: boolean;
  viewingId?: string;
  taskId?: string;
}
export interface LeadDetail extends Lead {
  /** The one thing to do next, worked out by the server from the lead's own data; null once the lead is closed. */
  nextStep: NextStep | null;
  history: Array<{ id: string; at: string; reason: string | null; reasonCode: string | null; fromStage: string | null; toStage: string; changedBy: string | null }>;
  transitions: Array<{ stageId: string; name: string; color: string; status: LeadStatus; probability: number; requiredFields: GateField[]; requiresReason: boolean; reasonKind: ReasonKind | null; reasonCodeRequired: boolean; closes: boolean }>;
  /** People working on the lead besides its owner. */
  handlers: LeadHandler[];
}
export interface LeadHandler {
  userId: string;
  name: string;
  workChannels: string[] | null;
  addedAt: string;
}
export interface LeadFilters {
  q?: string;
  pipelineId?: string;
  stageId?: string;
  status?: string;
  state?: 'open' | 'closed';
  ownerId?: string;
  source?: LeadSource;
  tag?: string;
  scoreMin?: number;
  sla?: 'breached' | 'at_risk';
  followup?: 'overdue' | 'today';
  /** Whole days in the workspace time zone (YYYY-MM-DD, `createdTo` inclusive). The Dashboard links here. */
  createdFrom?: string;
  createdTo?: string;
  /** `exclude` leaves out leads tagged test (what the Dashboard and reports do by default). */
  test?: 'exclude';
  contactId?: string;
  /** 'true': only open leads nobody owns yet (the pool a caller can claim from). */
  pool?: 'true';
  sort?: 'created' | 'updated' | 'score' | 'value' | 'followup';
  order?: 'asc' | 'desc';
}
export interface LeadInput {
  contactId?: string;
  contact?: { firstName: string; lastName?: string | null; email?: string | null; phone?: string | null };
  pipelineId?: string;
  stageId?: string;
  ownerId?: string | null;
  source?: LeadSource;
  estimatedValue?: number | null;
  budgetMin?: number | null;
  budgetMax?: number | null;
  bedroomsMin?: number | null;
  locations?: string[];
  tags?: string[];
  campaign?: string | null;
  nextFollowupAt?: string | null;
  allowDuplicate?: boolean;
  /** Answers to the client's own lead fields, by field key. */
  customFields?: Record<string, unknown>;
}
export type LeadPatch = Partial<Omit<LeadInput, 'contactId' | 'contact' | 'pipelineId' | 'stageId' | 'ownerId' | 'allowDuplicate'>>;
export type ActivityType = 'note' | 'call' | 'whatsapp' | 'sms' | 'email' | 'instagram' | 'meeting' | 'viewing' | 'status_change' | 'stage_change' | 'task_created' | 'task_completed';
export interface Activity {
  id: string;
  type: ActivityType;
  description: string;
  metadata: Record<string, unknown>;
  /** Calls and messages: who reached out, how it went, how long. */
  direction?: 'outbound' | 'inbound' | null;
  outcome?: string | null;
  durationMinutes?: number | null;
  createdAt: string;
  userName: string | null;
  userId: string | null;
}
export interface BoardStage {
  id: string;
  name: string;
  color: string;
  orderIndex: number;
  mapsToStatus: LeadStatus;
  probability: number;
  isClosed: boolean;
  count: number;
  value: number;
  leads: Lead[];
}
export interface Forecast {
  stages: Array<{ stageId: string; name: string; status: LeadStatus; probability: number; count: number; value: number; weighted: number; isClosed: boolean }>;
  open: { count: number; value: number; weighted: number };
  /** `value` is the estimate typed on won leads; `dealValue` is the sum of recorded sales (the figure to report). */
  won: { count: number; value: number; dealValue: number; withoutDealValue: number };
}
export type BulkRequest =
  | { ids: string[]; action: 'assign'; ownerId: string | null }
  | { ids: string[]; action: 'move_stage'; stageId: string; lossReason?: string; reasonCode?: string }
  | { ids: string[]; action: 'add_tags' | 'remove_tags'; tags: string[] }
  | { ids: string[]; action: 'archive' };
export interface SavedView {
  id: string;
  entity: 'lead' | 'contact';
  name: string;
  filters: Record<string, unknown>;
  sort: string | null;
  isShared: boolean;
  isMine: boolean;
  ownerName: string | null;
  updatedAt: string;
}
export interface ImportJob {
  id: string;
  status: 'queued' | 'running' | 'completed' | 'failed';
  fileName: string | null;
  totalRows: number;
  processedRows: number;
  createdCount: number;
  matchedCount: number;
  skippedCount: number;
  errorCount: number;
  errors: Array<{ row: number; field?: string; message: string }>;
  mapping: Record<string, string>;
  createdAt: string;
  finishedAt: string | null;
}

const clean = <T extends object>(o: T) => Object.fromEntries(Object.entries(o).filter(([, v]) => v !== undefined && v !== '')) as T;

export const pipelinesApi = {
  list: () => unwrap(api.get<Pipeline[]>('/api/v1/pipelines')),
  create: (input: { name: string; description?: string | null; copyFromId?: string; empty?: boolean }) => unwrap(api.post<Pipeline>('/api/v1/pipelines', input)),
  update: (id: string, patch: { name?: string; description?: string | null; isDefault?: boolean; reasonCodesRequired?: boolean; dealValueRequired?: boolean }) => unwrap(api.patch<Pipeline>(`/api/v1/pipelines/${id}`, patch)),
  reasonCodes: () => unwrap(api.get<ReasonCodes>('/api/v1/pipelines/reason-codes')),
  /** Adds the recommended pipeline as a NEW pipeline; never edits an existing one. */
  applyRecommended: (makeDefault: boolean) => unwrap(api.post<{ pipeline: Pipeline; created: boolean }>('/api/v1/pipelines/apply-recommended', { makeDefault })),
  archive: (id: string) => unwrap(api.delete(`/api/v1/pipelines/${id}`)),
  addStage: (id: string, input: { name: string; color?: string; mapsToStatus?: LeadStatus; probability?: number; afterStageId?: string | null }) => unwrap(api.post<Pipeline>(`/api/v1/pipelines/${id}/stages`, input)),
  updateStage: (id: string, stageId: string, patch: { name?: string; color?: string; mapsToStatus?: LeadStatus; probability?: number }) => unwrap(api.patch<Pipeline>(`/api/v1/pipelines/${id}/stages/${stageId}`, patch)),
  removeStage: (id: string, stageId: string, moveLeadsTo?: string) => unwrap(api.delete<Pipeline>(`/api/v1/pipelines/${id}/stages/${stageId}`, { query: { moveLeadsTo } })),
  reorder: (id: string, stageIds: string[]) => unwrap(api.put<Pipeline>(`/api/v1/pipelines/${id}/stage-order`, { stageIds })),
  transitions: (id: string) => unwrap(api.get<{ restricted: boolean; transitions: Transition[] }>(`/api/v1/pipelines/${id}/transitions`)),
  setTransitions: (id: string, body: { restricted: boolean; transitions: Transition[] }) => unwrap(api.put<{ restricted: boolean; transitions: Transition[] }>(`/api/v1/pipelines/${id}/transitions`, body)),
  board: (id: string, q: { ownerId?: string; q?: string; perStage?: number } = {}) => unwrap(api.get<{ stages: BoardStage[] }>(`/api/v1/pipelines/${id}/board`, { query: clean(q) })),
  forecast: (id: string) => unwrap(api.get<Forecast>(`/api/v1/pipelines/${id}/forecast`)),
};

export const leadsApi = {
  list: (f: LeadFilters, cursor?: string) => api.get<Lead[]>('/api/v1/leads', { query: { limit: 30, ...clean(f), cursor } }),
  get: (id: string) => unwrap(api.get<LeadDetail>(`/api/v1/leads/${id}`)),
  create: (input: LeadInput) => unwrap(api.post<Lead>('/api/v1/leads', input)),
  update: (id: string, version: number, patch: LeadPatch) => unwrap(api.patch<Lead>(`/api/v1/leads/${id}`, patch, { headers: { 'If-Match': `"${version}"` } })),
  move: (id: string, input: { stageId: string; reason?: string; lossReason?: string; reasonCode?: string; deal?: { propertyId?: string; reservationId?: string; dealValue: number } }) => unwrap(api.post<Lead>(`/api/v1/leads/${id}/stage`, input)),
  /** clientId works the lead inside that client when called from the agency-wide lists. */
  assign: (id: string, ownerId: string | null, clientId?: string) => unwrap(api.post<Lead>(`/api/v1/leads/${id}/assign`, { ownerId }, clientId ? { headers: { 'X-Tenant-Id': clientId } } : undefined)),
  claim: (id: string, clientId?: string) => unwrap(api.post<Lead>(`/api/v1/leads/${id}/claim`, undefined, clientId ? { headers: { 'X-Tenant-Id': clientId } } : undefined)),
  addHandler: (id: string, userId: string) => unwrap(api.put<LeadHandler[]>(`/api/v1/leads/${id}/handlers/${userId}`)),
  removeHandler: (id: string, userId: string) => unwrap(api.delete<LeadHandler[]>(`/api/v1/leads/${id}/handlers/${userId}`)),
  directory: (clientId: string) => unwrap(api.get<Array<{ userId: string; name: string; role: string }>>('/api/v1/members/directory', { headers: { 'X-Tenant-Id': clientId } })),
  archive: (id: string) => unwrap(api.delete(`/api/v1/leads/${id}`)),
  restore: (id: string) => unwrap(api.post<Lead>(`/api/v1/leads/${id}/restore`)),
  bulk: (req: BulkRequest) => unwrap(api.post<{ updated: number; skipped: Array<{ id: string; reason: string }> }>('/api/v1/leads/bulk', req)),
  activities: (id: string, cursor?: string) => api.get<Activity[]>(`/api/v1/leads/${id}/activities`, { query: { limit: 20, cursor } }),
  logActivity: (id: string, input: { type: 'note' | 'call' | 'email' | 'meeting'; description: string; outcome?: string; durationMinutes?: number }) => unwrap(api.post<Activity>(`/api/v1/leads/${id}/activities`, input)),
  /** Same-origin URL: the session cookie authorises the download. */
  exportUrl: (f: LeadFilters) => {
    const params = new URLSearchParams(Object.entries(clean({ ...f, sort: undefined, order: undefined })).map(([k, v]) => [k, String(v)]));
    const qs = params.toString();
    return `/api/v1/leads/export${qs ? `?${qs}` : ''}`;
  },
  imports: () => unwrap(api.get<ImportJob[]>('/api/v1/leads/imports')),
  startImport: (input: { fileName?: string; csv: string; mapping?: Record<string, string>; onDuplicate?: 'skip' | 'create'; defaultSource?: LeadSource }) =>
    unwrap(api.post<ImportJob & { detectedMapping: Record<string, string> }>('/api/v1/leads/imports', input)),
};

export const viewsApi = {
  list: (entity: 'lead' | 'contact') => unwrap(api.get<SavedView[]>('/api/v1/saved-views', { query: { entity } })),
  create: (input: { entity: 'lead' | 'contact'; name: string; filters: Record<string, unknown>; sort?: string | null; isShared?: boolean }) => unwrap(api.post<SavedView>('/api/v1/saved-views', input)),
  update: (id: string, patch: { name?: string; filters?: Record<string, unknown>; isShared?: boolean }) => unwrap(api.patch<SavedView>(`/api/v1/saved-views/${id}`, patch)),
  remove: (id: string) => unwrap(api.delete(`/api/v1/saved-views/${id}`)),
};
