import { api, unwrap } from './client';

/** The executive overview (v1 API): the whole business on one screen. Sections are null when the caller may not see them. */
export interface Overview {
  range: { from: string; to: string; days: number; timezone: string };
  previous: { from: string; to: string };
  bucket: 'day' | 'week' | 'month';
  includesTestLeads: boolean;
  currency: string;
  leads: {
    total: number;
    open: number;
    created: number;
    previousCreated: number;
    wonOfCreated: number;
    conversionRate: number | null;
    unassigned: number;
    lateFirstReplies: number;
    followupsOverdue: number;
  } | null;
  pipeline: {
    openValue: number;
    weightedValue: number;
    stages: Array<{ id: string; name: string; color: string; probability: number; status: string; isClosed: boolean; count: number; value: number }>;
    otherPipelines: { count: number; value: number };
  } | null;
  sources: Array<{ source: string; label: string; leads: number; won: number }> | null;
  sales: {
    count: number;
    dealValue: number;
    averageDealValue: number | null;
    previousCount: number;
    previousDealValue: number;
    reserved: number;
    openOffers: number;
    holdsOverdue: number;
  } | null;
  trend: Array<{ from: string; to: string; label: string; leads: number; sales: number; dealValue: number }>;
  tasks: { scope: 'all' | 'own'; open: number; overdue: number; dueToday: number; unassigned: number } | null;
  viewings: { upcoming: number; next7Days: number; completed: number; cancelled: number; noShow: number; unrecorded: number } | null;
  inventory: { total: number; byStatus: Record<string, number>; availableValue: number } | null;
  team: Array<{ userId: string; name: string; openLeads: number; leadsCreated: number; sales: number; dealValue: number; overdueTasks: number }> | null;
}

export const dashboardApi = {
  overview: (q: { from?: string; to?: string; includeTest: boolean }) =>
    unwrap(api.get<Overview>('/api/v1/dashboard/overview', { query: { from: q.from, to: q.to, includeTest: q.includeTest ? 'true' : 'false' } })),
};
