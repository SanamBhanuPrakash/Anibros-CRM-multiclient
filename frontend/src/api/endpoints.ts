import { api, unwrap } from './client';

/** Mirrors the v1 API (see docs/openapi.json). Types are written against the generated contract in `schema.d.ts`. */
export type Role =
  | 'tenant_owner'
  | 'tenant_admin'
  | 'sales_manager'
  | 'sales_agent'
  | 'marketing_manager'
  | 'marketing_analyst'
  | 'executive_readonly'
  | 'auditor';

export const ROLE_LABEL: Record<Role, string> = {
  tenant_owner: 'Owner',
  tenant_admin: 'Admin',
  sales_manager: 'Sales manager',
  sales_agent: 'Sales agent',
  marketing_manager: 'Marketing manager',
  marketing_analyst: 'Marketing analyst',
  executive_readonly: 'Executive (read-only)',
  auditor: 'Auditor',
};

export interface TenantInfo {
  id: string;
  name: string;
  slug: string;
  role: Role;
  timezone: string;
  currency: string;
  /** agency_hq = the agency's own workspace; client = one client's isolated workspace */
  kind: 'agency_hq' | 'client';
  industry: string | null;
  /** What kinds of work this person may log in this workspace (call, whatsapp, sms, email, instagram, meeting); null = everything. */
  workChannels: string[] | null;
}
export interface Me {
  user: { id: string; email: string; name: string; phone: string | null; avatarUrl: string | null; timezone: string; locale: string; mfaEnabled: boolean };
  tenants: TenantInfo[];
  activeTenantId: string;
  capabilities: Record<string, 'all' | 'own'>;
  sessionId: string;
}

export type LoginResult = ({ status: 'authenticated'; csrfToken: string } & Me) | { status: 'mfa_required'; challenge: string };

export const authApi = {
  login: (email: string, password: string) => unwrap(api.post<LoginResult>('/api/v1/auth/login', { email, password }, { expect401: true })),
  loginMfa: (challenge: string, input: { code?: string; recoveryCode?: string }) =>
    unwrap(api.post<{ status: 'authenticated' } & Me>('/api/v1/auth/login/mfa', { challenge, ...input }, { expect401: true })),
  signup: (input: { email: string; password: string; name: string; organisationName?: string }) => unwrap(api.post<{ status: 'verification_sent' }>('/api/v1/auth/signup', input)),
  verifyEmail: (token: string) => unwrap(api.post<{ status: 'authenticated' } & Me>('/api/v1/auth/verify-email', { token })),
  resendVerification: (email: string) => unwrap(api.post<{ status: 'accepted' }>('/api/v1/auth/resend-verification', { email })),
  requestReset: (email: string) => unwrap(api.post<{ status: 'accepted' }>('/api/v1/auth/password-reset/request', { email })),
  completeReset: (token: string, password: string) => unwrap(api.post<{ status: 'password_updated' }>('/api/v1/auth/password-reset/complete', { token, password })),
  logout: () => unwrap(api.post<void>('/api/v1/auth/logout', {}, { expect401: true })),
  previewInvitation: (token: string) =>
    unwrap(api.post<{ email: string; tenantName: string; role: Role; accountExists: boolean }>('/api/v1/invitations/preview', { token })),
  acceptInvitation: (input: { token: string; name?: string; password?: string }) =>
    unwrap(api.post<{ status: 'joined'; tenantId: string } | { status: 'account_created'; activeTenantId: string }>('/api/v1/invitations/accept', input)),
};

export interface SessionItem {
  id: string;
  deviceLabel: string | null;
  ip: string | null;
  createdAt: string;
  lastSeenAt: string;
  current: boolean;
}

export const meApi = {
  get: () => unwrap(api.get<Me>('/api/v1/me')),
  update: (input: { name?: string; phone?: string | null; timezone?: string; locale?: string }) => unwrap(api.patch('/api/v1/me', input)),
  changePassword: (currentPassword: string, newPassword: string) => unwrap(api.post<void>('/api/v1/me/password', { currentPassword, newPassword })),
  switchTenant: (tenantId: string) => unwrap(api.post<Me>('/api/v1/me/switch-tenant', { tenantId })),
  sessions: () => unwrap(api.get<SessionItem[]>('/api/v1/me/sessions')),
  revokeSession: (id: string) => unwrap(api.delete(`/api/v1/me/sessions/${id}`)),
  revokeOtherSessions: () => unwrap(api.delete<{ revoked: number }>('/api/v1/me/sessions')),
  mfaSetup: () => unwrap(api.post<{ secret: string; otpauthUri: string }>('/api/v1/me/mfa/setup')),
  mfaConfirm: (code: string) => unwrap(api.post<{ recoveryCodes: string[] }>('/api/v1/me/mfa/confirm', { code })),
  mfaDisable: (password: string, code: string) => unwrap(api.post<void>('/api/v1/me/mfa/disable', { password, code })),
  mfaRecoveryCodes: (code: string) => unwrap(api.post<{ recoveryCodes: string[] }>('/api/v1/me/mfa/recovery-codes', { code })),
};

export interface Member {
  membershipId: string;
  userId: string;
  name: string;
  email: string;
  role: Role;
  status: 'active' | 'suspended';
  joinedAt: string;
  mfaEnabled: boolean;
  lastSeenAt: string | null;
}
export interface Invitation {
  id: string;
  email: string;
  role: Role;
  invitedBy: string | null;
  expiresAt: string;
  createdAt: string;
}
export interface DirectoryEntry {
  userId: string;
  name: string;
  role: Role;
}

export const membersApi = {
  directory: () => unwrap(api.get<DirectoryEntry[]>('/api/v1/members/directory')),
  list: () => unwrap(api.get<{ members: Member[]; invitations: Invitation[] }>('/api/v1/members')),
  invite: (email: string, role: Role) => unwrap(api.post<Invitation>('/api/v1/members/invitations', { email, role })),
  revokeInvitation: (id: string) => unwrap(api.delete(`/api/v1/members/invitations/${id}`)),
  update: (membershipId: string, patch: { role?: Role; status?: 'active' | 'suspended' }) => unwrap(api.patch(`/api/v1/members/${membershipId}`, patch)),
  remove: (membershipId: string) => unwrap(api.delete(`/api/v1/members/${membershipId}`)),
};

export interface ApiKey {
  id: string;
  name: string;
  prefix: string;
  role: Role;
  scopes: string[];
  expiresAt: string | null;
  lastUsedAt: string | null;
  lastUsedIp: string | null;
  revokedAt: string | null;
  createdAt: string;
}

export const apiKeysApi = {
  list: () => unwrap(api.get<ApiKey[]>('/api/v1/api-keys')),
  scopes: () => unwrap(api.get<{ scopes: string[]; roles: Role[] }>('/api/v1/api-keys/scopes')),
  create: (input: { name: string; role: Role; scopes: string[]; expiresInDays?: number }) => unwrap(api.post<ApiKey & { key: string }>('/api/v1/api-keys', input)),
  revoke: (id: string) => unwrap(api.delete(`/api/v1/api-keys/${id}`)),
};

export interface Contact {
  id: string;
  firstName: string;
  lastName: string | null;
  displayName: string;
  email: string | null;
  phone: string | null;
  alternateEmails: string[];
  alternatePhones: string[];
  company: string | null;
  jobTitle: string | null;
  city: string | null;
  ownerId: string | null;
  tags: string[];
  customFields: Record<string, unknown>;
  leadCount: number;
  version: number;
  createdAt: string;
  updatedAt: string;
}
export interface ContactInput {
  firstName: string;
  lastName?: string | null;
  email?: string | null;
  phone?: string | null;
  alternateEmails?: string[];
  alternatePhones?: string[];
  company?: string | null;
  jobTitle?: string | null;
  city?: string | null;
  ownerId?: string | null;
  tags?: string[];
}
export type ContactSort = 'created' | 'updated' | 'name';
export interface ContactListParams {
  q?: string;
  sort?: ContactSort;
  order?: 'asc' | 'desc';
  ownerId?: string;
  tag?: string;
}
export type ConsentChannel = 'email' | 'sms' | 'whatsapp' | 'push' | 'phone_call';
export interface ConsentState {
  current: Array<{ channel: ConsentChannel; granted: boolean; since: string | null; source: string | null }>;
  history: Array<{ id: string; channel: ConsentChannel; status: 'granted' | 'revoked' | 'expired'; source: string; createdAt: string; revokedAt: string | null; revokeReason: string | null }>;
}
export interface DuplicateHit {
  id: string;
  displayName: string;
  matchedOn: Array<'email' | 'phone'>;
}

export const contactsApi = {
  list: (params: ContactListParams, cursor?: string) => api.get<Contact[]>('/api/v1/contacts', { query: { limit: 30, ...params, cursor } }),
  get: (id: string) => unwrap(api.get<Contact>(`/api/v1/contacts/${id}`)),
  create: (input: ContactInput) => unwrap(api.post<Contact>('/api/v1/contacts', input)),
  update: (id: string, version: number, patch: Partial<ContactInput>) => unwrap(api.patch<Contact>(`/api/v1/contacts/${id}`, patch, { headers: { 'If-Match': `"${version}"` } })),
  remove: (id: string) => unwrap(api.delete(`/api/v1/contacts/${id}`)),
  restore: (id: string) => unwrap(api.post<Contact>(`/api/v1/contacts/${id}/restore`)),
  merge: (survivorId: string, duplicateIds: string[]) => unwrap(api.post<{ contact: Contact; merges: Array<{ id: string; mergedId: string }> }>(`/api/v1/contacts/${survivorId}/merge`, { duplicateIds })),
  undoMerge: (mergeId: string) => unwrap(api.post<{ survivor: Contact; restored: Contact }>(`/api/v1/contacts/merges/${mergeId}/undo`)),
  lookup: (q: { email?: string; phone?: string }) => unwrap(api.get<DuplicateHit[]>('/api/v1/contacts/lookup', { query: q })),
  consents: (id: string) => unwrap(api.get<ConsentState>(`/api/v1/contacts/${id}/consents`)),
  recordConsent: (id: string, input: { channel: ConsentChannel; action: 'grant' | 'revoke'; source?: string; reason?: string }) =>
    unwrap(api.post<ConsentState>(`/api/v1/contacts/${id}/consents`, { source: 'manual', ...input })),
};
