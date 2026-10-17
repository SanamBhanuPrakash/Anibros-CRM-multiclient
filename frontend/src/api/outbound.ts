import { api, unwrap } from './client';
import type { Role } from './endpoints';

/** Outgoing webhooks, ready-made API key templates and e-mail / WhatsApp sending (see docs/INTEGRATION_GUIDE.md). */

export type WebhookEvent = 'lead.created' | 'lead.assigned' | 'lead.stage_changed' | 'lead.updated' | 'lead.deleted' | 'activity.created' | 'task.created' | 'task.completed' | 'contact.created';
export interface Webhook {
  id: string;
  name: string;
  url: string;
  events: WebhookEvent[];
  status: 'active' | 'paused' | 'disabled';
  disabledReason: string | null;
  secretHint: string | null;
  lastDeliveryAt: string | null;
  lastStatus: number | null;
  lastError: string | null;
  stats: { delivered: number; failed: number; pending: number };
  createdAt: string;
}
export type WebhookDeliveryStatus = 'pending' | 'delivered' | 'failed' | 'dead' | 'skipped';
export interface WebhookDelivery {
  id: string;
  endpointId: string;
  endpointName: string;
  event: string;
  isTest: boolean;
  status: WebhookDeliveryStatus;
  attempts: number;
  responseStatus: number | null;
  responseMs: number | null;
  error: string | null;
  createdAt: string;
  deliveredAt: string | null;
}

export const webhooksApi = {
  events: () => unwrap(api.get<Array<{ event: WebhookEvent; description: string }>>('/api/v1/webhooks/events')),
  list: () => unwrap(api.get<Webhook[]>('/api/v1/webhooks')),
  create: (input: { name: string; url: string; events: WebhookEvent[] }) => unwrap(api.post<{ webhook: Webhook; secret: string }>('/api/v1/webhooks', input)),
  update: (id: string, patch: { name?: string; url?: string; events?: WebhookEvent[]; status?: 'active' | 'paused' }) => unwrap(api.patch<Webhook>(`/api/v1/webhooks/${id}`, patch)),
  rotate: (id: string) => unwrap(api.post<{ webhook: Webhook; secret: string }>(`/api/v1/webhooks/${id}/rotate-secret`)),
  test: (id: string) => unwrap(api.post<{ ok: boolean; status: number | null; ms: number | null; error: string | null }>(`/api/v1/webhooks/${id}/test`)),
  remove: (id: string) => unwrap(api.delete(`/api/v1/webhooks/${id}`)),
  deliveries: (f: { endpointId?: string; status?: WebhookDeliveryStatus }, cursor?: string) => api.get<WebhookDelivery[]>('/api/v1/webhooks/deliveries', { query: { limit: 25, ...f, cursor } }),
  retry: (id: string) => unwrap(api.post(`/api/v1/webhooks/deliveries/${id}/retry`)),
};

export interface KeyTemplate {
  id: 'send-leads-in' | 'read-data-out';
  direction: 'in' | 'out';
  title: string;
  description: string;
  role: Role;
  scopes: string[];
  expiresInDays: number | null;
  suggestedName: string;
}
export const keyTemplatesApi = { list: () => unwrap(api.get<KeyTemplate[]>('/api/v1/api-keys/templates')) };

export interface WhatsAppAccount {
  connected: boolean;
  phoneNumberId: string | null;
  displayPhone: string | null;
  tokenHint: string | null;
  status: 'active' | 'paused' | null;
  lastError: string | null;
  lastSentAt: string | null;
}
export interface SendMessage {
  leadId: string;
  channel: 'email' | 'whatsapp';
  subject?: string;
  body: string;
  template?: { name: string; language: string; params?: string[] };
}
export const messagingApi = {
  whatsapp: (clientId?: string) => unwrap(api.get<WhatsAppAccount>('/api/v1/messaging/whatsapp', clientId ? { headers: { 'X-Tenant-Id': clientId } } : undefined)),
  connectWhatsapp: (input: { phoneNumberId: string; accessToken: string }) => unwrap(api.put<WhatsAppAccount>('/api/v1/messaging/whatsapp', input)),
  disconnectWhatsapp: () => unwrap(api.delete('/api/v1/messaging/whatsapp')),
  /** `clientId` names the client the lead belongs to (needed when sending from a cross-client list). */
  send: (m: SendMessage, clientId?: string) => unwrap(api.post<{ activityId: string; delivery: 'queued' }>('/api/v1/messaging/send', m, clientId ? { headers: { 'X-Tenant-Id': clientId } } : undefined)),
};
