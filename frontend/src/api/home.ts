import { api, unwrap } from './client';

/** Home ("what needs me today") and global search (v1 API). */
export interface HomeTask {
  id: string;
  title: string;
  kind: string;
  priority: string;
  dueDate: string | null;
  leadId: string | null;
  leadName: string | null;
}
export interface HomeVisit {
  id: string;
  scheduledAt: string;
  durationMinutes: number;
  leadId: string;
  leadName: string | null;
  propertyTitle: string;
  needsOutcome: boolean;
}
export interface HomeReply {
  leadId: string;
  leadName: string | null;
  dueAt: string;
  overdue: boolean;
}
interface Counted<T> {
  count: number;
  items: T[];
}
export interface Home {
  today: string;
  timezone: string;
  myDay: {
    tasks: { overdue: Counted<HomeTask>; today: Counted<HomeTask> } | null;
    viewings: { today: Counted<HomeVisit>; next7Days: number } | null;
    waitingForReply: Counted<HomeReply> | null;
  };
  team: { unassignedLeads: Counted<{ leadId: string; leadName: string | null; createdAt: string }>; lateFirstReplies: number; overdueTasks: number | null; unrecordedVisits: number } | null;
  pipeline: { pipelineId: string; name: string; stages: Array<{ id: string; name: string; color: string; probability: number; count: number; value: number }>; openLeads: number; openValue: number; weightedValue: number } | null;
  attention: { deliveriesNeedingReview: number; failedAutomationRuns: number } | null;
  getStarted: { items: Array<{ key: string; label: string; href: string; done: boolean }>; complete: boolean } | null;
}
export const homeApi = { get: () => unwrap(api.get<Home>('/api/v1/home')) };

export interface SearchHit {
  id: string;
  title: string;
  subtitle: string | null;
  href: string;
}
export type SearchKind = 'leads' | 'contacts' | 'properties' | 'projects' | 'tasks';
export const searchApi = {
  search: (q: string, signal?: AbortSignal) => unwrap(api.get<{ query: string; results: Partial<Record<SearchKind, SearchHit[]>> }>('/api/v1/search', { query: { q }, signal })),
};
