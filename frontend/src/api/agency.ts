import { api, unwrap } from './client';
import type { Role } from './endpoints';

/**
 * Agency layer: many isolated client workspaces, one shared team. See docs/AGENCY_DESIGN.md.
 * Calls here work from any workspace the caller belongs to; the server decides what each role may see.
 */
export type ClientRole = 'sales_agent' | 'sales_manager' | 'marketing_manager' | 'marketing_analyst' | 'executive_readonly';

export interface ClientStats {
  totalLeads: number;
  openLeads: number;
  newLast7Days: number;
  unassigned: number;
  slaBreached: number;
  won: number;
  lastLeadAt: string | null;
  spendThisMonth: number | null;
  currency: string;
  leadSources: { active: number; lastReceivedAt: string | null } | null;
  apiKeys: number | null;
}
export interface Client {
  id: string;
  name: string;
  slug: string;
  industry: string | null;
  website: string | null;
  contactName: string | null;
  contactEmail: string | null;
  contactPhone: string | null;
  notes: string | null;
  timezone: string;
  currency: string;
  status: 'active' | 'archived';
  createdAt: string;
  archivedAt: string | null;
  myRole: Role | null;
  assignedStaff: number;
  stats: ClientStats | null;
}
export interface ClientInput {
  name: string;
  industry?: string;
  website?: string | null;
  contactName?: string | null;
  contactEmail?: string | null;
  contactPhone?: string | null;
  notes?: string | null;
  timezone?: string;
  currency?: string;
  staff?: Array<{ userId: string; role: ClientRole }>;
}
export interface AgencyOverview {
  agency: { name: string; hqTenantId: string; isAdmin: boolean; isOwner: boolean };
  clients: { active: number; archived: number };
  staff: number | null;
  totals: { totalLeads: number; openLeads: number; newLast7Days: number; unassigned: number; slaBreached: number; won: number };
}
export interface AgencyOptions {
  industries: Array<{ code: string; label: string; appointment: string; realEstateModules: boolean }>;
  clientRoles: ClientRole[];
}
/** The kinds of work an employee may log in a client. null on a membership = everything. */
export const WORK_CHANNELS = ['call', 'whatsapp', 'sms', 'email', 'instagram', 'meeting'] as const;
export type WorkChannel = (typeof WORK_CHANNELS)[number];

export interface StaffMember {
  userId: string;
  name: string;
  email: string;
  agencyRole: 'owner' | 'admin' | 'staff';
  status: string;
  joinedAt: string;
  clients: Array<{ clientId: string; clientName: string; role: Role; workChannels: string[] | null }>;
}

/** One client in the side-by-side report. `scope: 'own'` means the figures cover only this person's leads in it. */
export interface CompareRow {
  client: { id: string; name: string; industry: string | null };
  scope: 'all' | 'own';
  currency: string;
  timezone: string;
  leads: { created: number; previousCreated: number; open: number; unassigned: number; waitingForReply: number; followupsOverdue: number; won: number; winRate: number | null };
  deals: { count: number; value: number } | null;
  firstReply: { medianMinutes: number | null; inTimeRate: number | null } | null;
  money: { spend: number; costPerLead: number | null; costPerSale: number | null } | null;
  trend: Array<{ label: string; leads: number }>;
}
export interface AgencyCompare {
  range: { from: string; to: string };
  includesTestLeads: boolean;
  bucket: 'day' | 'week' | 'month' | null;
  partial: boolean;
  excluded: Array<{ clientId: string; clientName: string; reason: string }>;
  rows: CompareRow[];
  totals: {
    clients: number;
    leads: { created: number; previousCreated: number; open: number; unassigned: number; waitingForReply: number; followupsOverdue: number; won: number; winRate: number | null };
    dealCount: number;
    byCurrency: Array<{ currency: string; dealValue: number; spend: number; clientsWithSpend: number }>;
  };
  trend: Array<{ label: string; leads: number }>;
}
export interface AgencyLead {
  id: string;
  client: { id: string; name: string; industry: string | null };
  contactName: string | null;
  contactPhone: string | null;
  contactEmail: string | null;
  stageName: string;
  stageColor: string;
  status: string;
  ownerId: string | null;
  ownerName: string | null;
  source: string;
  campaign: string | null;
  leadScore: number;
  estimatedValue: number | null;
  currency: string;
  tags: string[];
  createdAt: string;
  updatedAt: string;
  lastActivityAt: string | null;
  nextFollowupAt: string | null;
  sla: { state: 'none' | 'met' | 'ok' | 'at_risk' | 'breached'; dueAt: string | null };
  touches: { calls: number; connected: number; total: number; lastTouchAt: string | null };
}
export interface AgencyLeadFilters {
  clientId?: string;
  q?: string;
  status?: string;
  state?: 'open' | 'closed';
  /** a user id, 'unassigned' or 'me' */
  ownerId?: string;
  source?: string;
  tag?: string;
  scoreMin?: number;
  createdFrom?: string;
  createdTo?: string;
  test?: 'exclude';
  sla?: 'breached' | 'at_risk';
  followup?: 'overdue' | 'today';
  /** A custom field of the chosen client (its key) and the value to match. */
  field?: string;
  fieldValue?: string;
  /** 'true': only open leads nobody owns yet (what a caller can claim from). */
  pool?: 'true';
}
export interface AgencyLeadList {
  items: AgencyLead[];
  page: { page: number; pageSize: number; total: number; totalPages: number };
  byClient: Array<{ clientId: string; clientName: string; total: number }>;
}
/** One of my own open tasks that is overdue or due today, with the client it belongs to. */
export interface AgencyTask {
  id: string;
  client: { id: string; name: string };
  title: string;
  kind: string;
  priority: string;
  dueDate: string;
  overdue: boolean;
  leadId: string | null;
  leadName: string | null;
}

export interface ActivitySummary {
  from: string;
  to: string;
  timeZone: string;
  rows: Array<{ clientId: string; clientName: string; userId: string; userName: string; calls: number; connected: number; whatsapp: number; sms: number; emails: number; instagram: number; meetings: number; leadsTouched: number }>;
  totals: { calls: number; connected: number; whatsapp: number; sms: number; emails: number; instagram: number; meetings: number };
}
export interface ActivityOptions {
  types: string[];
  directions: Array<'outbound' | 'inbound'>;
  outcomes: Array<{ code: string; label: string; forTypes: string[] }>;
}
export interface LogActivityInput {
  type: 'call' | 'whatsapp' | 'sms' | 'email' | 'instagram' | 'meeting' | 'note';
  description: string;
  direction?: 'outbound' | 'inbound';
  outcome?: string;
  occurredAt?: string;
  durationMinutes?: number;
  nextFollowupAt?: string | null;
}

const A = '/api/v1/agency';
/** Build the download URL for the current filters (open it in the browser: the cookie session authenticates it). */
export const exportUrl = (f: AgencyLeadFilters, format: 'xlsx' | 'csv', dataset: 'leads' | 'activities' = 'leads') => {
  const p = new URLSearchParams();
  for (const [k, v] of Object.entries({ ...f, format, dataset })) if (v !== undefined && v !== '') p.set(k, String(v));
  return `${A}/leads/export?${p.toString()}`;
};

export const agencyApi = {
  overview: () => unwrap(api.get<AgencyOverview>(`${A}/overview`)),
  options: () => unwrap(api.get<AgencyOptions>(`${A}/options`)),
  clients: (status: 'active' | 'archived' | 'all' = 'active') => unwrap(api.get<Client[]>(`${A}/clients`, { query: { status } })),
  client: (id: string) => unwrap(api.get<Client>(`${A}/clients/${id}`)),
  createClient: (input: ClientInput) => unwrap(api.post<Client>(`${A}/clients`, input)),
  updateClient: (id: string, patch: Partial<Omit<ClientInput, 'staff'>>) => unwrap(api.patch<Client>(`${A}/clients/${id}`, patch)),
  archiveClient: (id: string) => unwrap(api.post<Client>(`${A}/clients/${id}/archive`)),
  restoreClient: (id: string) => unwrap(api.post<Client>(`${A}/clients/${id}/restore`)),
  team: (id: string) => unwrap(api.get<Array<{ userId: string; name: string; email: string; clientRole: Role; workChannels: string[] | null; isAgencyAdmin: boolean; assignedAt: string }>>(`${A}/clients/${id}/team`)),
  /** `channels`: leave out to keep what the person handles, null for everything, or a list to limit them. */
  assign: (clientId: string, userId: string, role: ClientRole, channels?: string[] | null) =>
    unwrap(api.put<{ userId: string; clientId: string; role: ClientRole }>(`${A}/clients/${clientId}/team/${userId}`, channels === undefined ? { role } : { role, channels })),
  unassign: (clientId: string, userId: string) => unwrap(api.delete<{ leadsUnassigned: number }>(`${A}/clients/${clientId}/team/${userId}`)),
  staff: () => unwrap(api.get<{ staff: StaffMember[]; invitations: Array<{ id: string; email: string; createdAt: string; expiresAt: string }> }>(`${A}/staff`)),
  inviteStaff: (email: string) => unwrap(api.post<{ id: string; email: string }>(`${A}/staff/invitations`, { email })),
  setStaffRole: (userId: string, agencyRole: 'admin' | 'staff') => unwrap(api.patch<{ userId: string; agencyRole: string }>(`${A}/staff/${userId}`, { agencyRole })),
  removeStaff: (userId: string) => unwrap(api.delete<{ leadsUnassigned: number; clientsLeft: number }>(`${A}/staff/${userId}`)),
  leads: (f: AgencyLeadFilters & { page?: number; pageSize?: number; sort?: 'created' | 'updated' | 'score' | 'value'; order?: 'asc' | 'desc' }) => unwrap(api.get<AgencyLeadList>(`${A}/leads`, { query: { ...f } })),
  report: (q: { from?: string; to?: string; clientId?: string; includeTest?: boolean } = {}) =>
    unwrap(api.get<AgencyCompare>(`${A}/report`, { query: { from: q.from, to: q.to, clientId: q.clientId, includeTest: q.includeTest ? 'true' : 'false' } })),
  unreadByClient: () => unwrap(api.get<Array<{ clientId: string; unread: number }>>(`${A}/unread`)),
  myTasks: () => unwrap(api.get<AgencyTask[]>(`${A}/tasks`)),
  activity: (q: { clientId?: string; from?: string; to?: string } = {}) => unwrap(api.get<ActivitySummary>(`${A}/activity`, { query: q })),
};

/** Logging work on a lead happens INSIDE that lead's client: pass the client id so the request is scoped to it. */
export const clientWorkApi = {
  options: (clientId: string) => unwrap(api.get<ActivityOptions>('/api/v1/leads/activity-options', { headers: { 'X-Tenant-Id': clientId } })),
  log: (clientId: string, leadId: string, input: LogActivityInput) => unwrap(api.post(`/api/v1/leads/${leadId}/activities`, input, { headers: { 'X-Tenant-Id': clientId } })),
};
