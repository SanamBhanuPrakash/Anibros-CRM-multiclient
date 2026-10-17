import { api, unwrap } from './client';

/** Automation (v1 API): templates a workspace can turn on, and the history of what they did. */
export type ExecutionStatus = 'pending' | 'running' | 'completed' | 'failed' | 'skipped';
export type StepId = 'owner' | 'task' | 'notify';

export interface AutomationRule {
  id: string;
  status: 'active' | 'paused';
  version: number;
  runCount: number;
  lastRunAt: string | null;
  updatedAt: string;
}
export interface AutomationTemplate {
  key: 'new_lead_follow_up';
  name: string;
  summary: string;
  trigger: 'lead_created';
  triggerLabel: string;
  steps: Array<{ id: StepId; label: string }>;
  usesSettings: Array<'leadAssignment' | 'firstResponseMinutes'>;
  rule: AutomationRule | null;
  failedRuns: number;
}
export interface AutomationExecution {
  id: string;
  ruleId: string;
  template: string | null;
  status: ExecutionStatus;
  leadId: string | null;
  leadName: string | null;
  summary: string | null;
  error: string | null;
  attempts: number;
  steps: Array<{ step: StepId; status: 'done' | 'kept' | 'skipped'; detail: string; at: string }>;
  startedAt: string | null;
  completedAt: string | null;
  createdAt: string;
}

export const automationApi = {
  list: () => unwrap(api.get<AutomationTemplate[]>('/api/v1/automations')),
  enable: (key: string) => unwrap(api.post<AutomationTemplate>(`/api/v1/automations/${key}/enable`)),
  pause: (key: string) => unwrap(api.post<AutomationTemplate>(`/api/v1/automations/${key}/pause`)),
  executions: (filters: { status?: ExecutionStatus }, cursor?: string) => api.get<AutomationExecution[]>('/api/v1/automations/executions', { query: { limit: 25, ...filters, cursor } }),
  retry: (id: string) => unwrap(api.post<AutomationExecution>(`/api/v1/automations/executions/${id}/retry`)),
};
