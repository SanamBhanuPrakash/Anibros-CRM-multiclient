import { api, unwrap } from './client';

/** Tasks and notifications (v1 API). */
export type TaskKind = 'todo' | 'call' | 'email' | 'meeting' | 'follow_up';
export type TaskPriority = 'low' | 'medium' | 'high' | 'urgent';
export type TaskStatus = 'pending' | 'in_progress' | 'completed' | 'cancelled';
export interface Recurrence {
  freq: 'daily' | 'weekly' | 'monthly';
  interval: number;
}

export interface Task {
  id: string;
  title: string;
  description: string | null;
  kind: TaskKind;
  priority: TaskPriority;
  status: TaskStatus;
  dueDate: string | null;
  remindBeforeMinutes: number | null;
  recurrence: Recurrence | null;
  assignedTo: string | null;
  assigneeName: string | null;
  createdBy: string | null;
  leadId: string | null;
  leadName: string | null;
  contactId: string | null;
  contactName: string | null;
  completedAt: string | null;
  isOverdue: boolean;
  version: number;
  createdAt: string;
  updatedAt: string;
}
export interface TaskInput {
  title: string;
  description?: string | null;
  kind?: TaskKind;
  priority?: TaskPriority;
  dueDate?: string | null;
  remindBeforeMinutes?: number | null;
  recurrence?: Recurrence | null;
  assignedTo?: string | null;
  leadId?: string | null;
  contactId?: string | null;
}
export interface TaskFilters {
  q?: string;
  assignee?: string;
  status?: 'open' | 'done' | 'all';
  priority?: TaskPriority;
  kind?: TaskKind;
  leadId?: string;
  contactId?: string;
  due?: 'overdue' | 'today' | 'week' | 'none';
  limit?: number;
  dueFrom?: string;
  dueTo?: string;
  sort?: 'due' | 'created' | 'priority';
  order?: 'asc' | 'desc';
}
export interface TaskSummary {
  mine: { overdue: number; today: number; upcoming: number; open: number };
  team: { overdue: number; unassigned: number; open: number } | null;
}

const clean = <T extends object>(o: T) => Object.fromEntries(Object.entries(o).filter(([, v]) => v !== undefined && v !== '')) as T;

export const tasksApi = {
  list: (f: TaskFilters, cursor?: string) => api.get<Task[]>('/api/v1/tasks', { query: { limit: 40, ...clean(f), cursor } }),
  summary: () => unwrap(api.get<TaskSummary>('/api/v1/tasks/summary')),
  get: (id: string) => unwrap(api.get<Task>(`/api/v1/tasks/${id}`)),
  create: (input: TaskInput) => unwrap(api.post<Task>('/api/v1/tasks', input)),
  update: (id: string, version: number, patch: Partial<TaskInput> & { status?: 'pending' | 'in_progress' }) => unwrap(api.patch<Task>(`/api/v1/tasks/${id}`, patch, { headers: { 'If-Match': `"${version}"` } })),
  complete: (id: string) => unwrap(api.post<{ task: Task; next: Task | null }>(`/api/v1/tasks/${id}/complete`)),
  reopen: (id: string) => unwrap(api.post<Task>(`/api/v1/tasks/${id}/reopen`)),
  cancel: (id: string) => unwrap(api.post<Task>(`/api/v1/tasks/${id}/cancel`)),
  remove: (id: string) => unwrap(api.delete(`/api/v1/tasks/${id}`)),
  bulk: (req: { ids: string[]; action: 'complete' | 'cancel' | 'reassign'; assignedTo?: string | null }) =>
    unwrap(api.post<{ updated: number; skipped: Array<{ id: string; reason: string }> }>('/api/v1/tasks/bulk', req)),
};

export interface AppNotification {
  id: string;
  title: string;
  message: string;
  type: string;
  link: string | null;
  entityType: string | null;
  entityId: string | null;
  isRead: boolean;
  readAt: string | null;
  createdAt: string;
}

export const notificationsApi = {
  list: (unread = false, cursor?: string) => api.get<AppNotification[]>('/api/v1/notifications', { query: { limit: 20, unread: unread ? 'true' : undefined, cursor } }),
  unreadCount: () => unwrap(api.get<{ count: number }>('/api/v1/notifications/unread-count')),
  markRead: (ids: string[]) => unwrap(api.post<{ updated: number }>('/api/v1/notifications/read', { ids })),
  markAllRead: () => unwrap(api.post<{ updated: number }>('/api/v1/notifications/read', { all: true })),
};
