import { api, unwrap } from './client';

/**
 * Per-client custom lead fields. Each client has its own set; calls act on the workspace the request is scoped to
 * (pass the client id to scope a call to that client without switching workspace, so several client tabs can be open at once).
 */
export type FieldType = 'text' | 'number' | 'date' | 'boolean' | 'select' | 'multi_select' | 'url' | 'email';
export interface FieldOption {
  value: string;
  label: string;
}
export interface CustomField {
  id: string;
  /** The key under `customFields` on a lead. Never changes. */
  key: string;
  label: string;
  type: FieldType;
  required: boolean;
  visible: boolean;
  options: FieldOption[];
  defaultValue: string | null;
  helpText: string | null;
  showInList: boolean;
  order: number;
}
export interface NewCustomField {
  label: string;
  type: FieldType;
  key?: string;
  required?: boolean;
  options?: Array<string | { value?: string; label: string }>;
  defaultValue?: string | null;
  helpText?: string | null;
  showInList?: boolean;
}
export type CustomFieldPatch = Partial<Pick<NewCustomField, 'label' | 'required' | 'options' | 'defaultValue' | 'helpText' | 'showInList'>> & { visible?: boolean };

const scope = (clientId?: string) => (clientId ? { headers: { 'X-Tenant-Id': clientId } } : undefined);

export const customFieldsApi = {
  list: (clientId?: string, includeHidden = false) => unwrap(api.get<CustomField[]>('/api/v1/custom-fields', { query: { includeHidden }, ...scope(clientId) })),
  create: (input: NewCustomField, clientId?: string) => unwrap(api.post<CustomField>('/api/v1/custom-fields', input, scope(clientId))),
  update: (id: string, patch: CustomFieldPatch, clientId?: string) => unwrap(api.patch<CustomField>(`/api/v1/custom-fields/${id}`, patch, scope(clientId))),
  reorder: (ids: string[], clientId?: string) => unwrap(api.put<CustomField[]>('/api/v1/custom-fields/order', { ids }, scope(clientId))),
  /** Agency admins: copy another client's field definitions into this client (never any lead data). */
  copyFrom: (clientId: string, fromClientId: string) => unwrap(api.post<{ copied: string[]; skipped: string[] }>(`/api/v1/agency/clients/${clientId}/custom-fields/copy`, { fromClientId })),
};
