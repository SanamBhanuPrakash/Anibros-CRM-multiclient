import { api, unwrap } from './client';

/** Lead sources (Meta, Google, website, generic) and their delivery log — v1 API. */
export type Provider = 'meta' | 'google' | 'website' | 'generic';
export type DeliveryStatus = 'received' | 'created' | 'duplicate' | 'needs_review' | 'ignored' | 'failed';

export interface SourceConfig {
  source?: string;
  campaign?: string;
  tags?: string[];
  pipelineId?: string;
  stageId?: string;
  ownerId?: string | null;
  allowedOrigins?: string[];
  fieldMap?: Record<string, string>;
}
export interface LeadSourceEndpoint {
  id: string;
  provider: Provider;
  name: string;
  status: 'active' | 'paused';
  secretHint: string | null;
  hasAccessToken: boolean;
  verifyToken: string | null;
  url: string;
  config: SourceConfig;
  lastReceivedAt: string | null;
  receivedCount: number;
  stats: Record<DeliveryStatus, number>;
  createdAt: string;
}
export interface Delivery {
  id: string;
  endpointId: string;
  endpointName: string;
  provider: Provider;
  status: DeliveryStatus;
  isTest: boolean;
  leadId: string | null;
  who: string;
  reason: string | null;
  attempts: number;
  receivedAt: string;
  processedAt: string | null;
}
export interface CreateSourceInput {
  provider: Provider;
  name: string;
  appSecret?: string;
  pageAccessToken?: string;
  config?: SourceConfig;
}
export interface UpdateSourceInput {
  name?: string;
  status?: 'active' | 'paused';
  config?: SourceConfig;
  appSecret?: string;
  pageAccessToken?: string | null;
}

export const leadSourcesApi = {
  list: () => unwrap(api.get<LeadSourceEndpoint[]>('/api/v1/lead-sources')),
  create: (input: CreateSourceInput) => unwrap(api.post<{ endpoint: LeadSourceEndpoint; secret: string | null }>('/api/v1/lead-sources', input)),
  update: (id: string, patch: UpdateSourceInput) => unwrap(api.patch<LeadSourceEndpoint>(`/api/v1/lead-sources/${id}`, patch)),
  rotate: (id: string) => unwrap(api.post<{ endpoint: LeadSourceEndpoint; secret: string }>(`/api/v1/lead-sources/${id}/rotate-secret`)),
  remove: (id: string) => unwrap(api.delete(`/api/v1/lead-sources/${id}`)),
  test: (id: string) => unwrap(api.post<{ eventId: string }>(`/api/v1/lead-sources/${id}/test`)),
  events: (f: { endpointId?: string; status?: DeliveryStatus }, cursor?: string) => api.get<Delivery[]>('/api/v1/lead-sources/events', { query: { limit: 25, ...f, cursor } }),
  retry: (id: string) => unwrap(api.post(`/api/v1/lead-sources/events/${id}/retry`)),
  ignore: (id: string) => unwrap(api.post(`/api/v1/lead-sources/events/${id}/ignore`)),
};
