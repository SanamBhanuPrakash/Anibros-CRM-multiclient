import { api, unwrap } from './client';

/** Attribution, spend and reports (v1 API). Rates are null (shown as "—") when there is nothing to divide by. */
export type StepKey = 'leads' | 'qualified' | 'visits' | 'booked' | 'won';
export interface RangeInfo {
  from: string;
  to: string;
  days: number;
  timezone: string;
}
export interface StepCounts {
  leads: number;
  qualified: number;
  visits: number;
  booked: number;
  won: number;
  revenue: number;
}
export interface MoneyFigures {
  spend: number;
  costPerLead: number | null;
  costPerQualified: number | null;
  costPerVisit: number | null;
  costPerBooking: number | null;
  costPerSale: number | null;
  roi: number | null;
}
export interface SourceRow extends StepCounts {
  source: string;
  label: string;
  channel: string;
  channelLabel: string;
  spend: MoneyFigures | null;
}
export interface ChannelRow extends StepCounts {
  channel: string;
  label: string;
  spend: MoneyFigures | null;
}
export interface CampaignRow extends StepCounts {
  source: string;
  sourceLabel: string;
  campaign: string;
  spend: MoneyFigures | null;
}
export interface AttributionReport {
  range: RangeInfo;
  model: 'first' | 'last';
  includesTestLeads: boolean;
  currency: string;
  totals: StepCounts & { wonWithoutDealValue: number };
  tracking: { tracked: number; untracked: number; direct: number; withCampaign: number; reconstructed: number; sharePercent: number | null };
  sources: SourceRow[];
  channels: ChannelRow[];
  campaigns: CampaignRow[];
  money: MoneyFigures | null;
  lastLeadAt: string | null;
}
export interface DrillRow {
  id: string;
  name: string;
  createdAt: string;
  stageName: string;
  status: string;
  source: string;
  sourceLabel: string;
  campaign: string | null;
  ownerName: string | null;
  revenue: number;
  reconstructed: boolean;
}
export interface ChannelMapping {
  source: string;
  label: string;
  channel: string;
  channelLabel: string;
  isDefault: boolean;
}
export interface Spend {
  id: string;
  source: string;
  sourceLabel: string;
  campaign: string | null;
  periodStart: string;
  periodEnd: string;
  amount: number;
  currency: string;
  note: string | null;
  version: number;
  enteredBy: string | null;
  createdAt: string;
}
export interface SpendImportResult {
  dryRun: boolean;
  created: number;
  skipped: number;
  preview: Array<{ row: number; source: string; sourceLabel: string; campaign: string | null; periodStart: string; periodEnd: string; amount: number; status: 'new' | 'already_saved' }>;
}

export type FlowStageKey = 'new' | 'talking' | 'qualified' | 'booked' | 'won' | 'closed';
export interface LeadFlow {
  range: RangeInfo;
  total: number;
  sources: Array<{ source: string; label: string; total: number }>;
  stages: Array<{ key: FlowStageKey; label: string; total: number }>;
  links: Array<{ source: string; label: string; stage: FlowStageKey; count: number }>;
  /** 7 rows (Monday first) of 24 hourly counts, in the workspace time zone. */
  arrivals: { grid: number[][]; total: number; peak: { day: number; hour: number; count: number } | null };
  replyTimes: { buckets: Array<{ key: string; label: string; count: number }>; unanswered: number; median: number | null; answered: number };
  daily: Array<{ day: string; leads: number; won: number }>;
}

export interface ReportOverview {
  range: RangeInfo;
  includesTestLeads: boolean;
  currency: string;
  funnel: {
    steps: Array<{ key: StepKey; label: string; help: string; count: number; ofPrevious: number | null; ofLeads: number | null }>;
    revenue: number;
    sales: number;
    averageDealValue: number | null;
    wonWithoutDealValue: number;
    averageDaysToWin: number | null;
  } | null;
  conversion: {
    winRate: number | null;
    won: number;
    lost: number;
    notAFit: number;
    stillOpen: number;
    lostReasons: Array<{ code: string; label: string; count: number }>;
    notAFitReasons: Array<{ code: string; label: string; count: number }>;
  } | null;
  pipeline: {
    openLeads: number;
    openValue: number;
    weightedValue: number;
    stages: Array<{ id: string; name: string; color: string; probability: number; isClosed: boolean; count: number; value: number; observations: number; averageDays: number | null; medianDays: number | null }>;
    otherPipelines: { count: number; value: number };
  } | null;
  engagement: {
    visits: { held: number; cancelled: number; noShow: number; upcoming: number; unrecorded: number } | null;
    followUps: { leadsOverdue: number; leadsWithNoFollowUp: number; tasksOverdue: number | null } | null;
    firstReply: { leads: number; replied: number; inTimeRate: number | null; medianMinutes: number | null } | null;
  };
  inventory: { total: number; byStatus: Record<string, number>; availableValue: number } | null;
  team: Array<{ userId: string | null; name: string; leads: number; qualified: number; visits: number; booked: number; won: number; revenue: number; winRate: number | null; medianFirstReplyMinutes: number | null }> | null;
}

type Scope = { from?: string; to?: string; includeTest: boolean };
const q = (s: Scope, extra: Record<string, string | number | undefined> = {}) => ({ from: s.from, to: s.to, includeTest: s.includeTest ? 'true' : 'false', ...extra });

export const analyticsApi = {
  attribution: (s: Scope & { model: 'first' | 'last' }) => unwrap(api.get<AttributionReport>('/api/v1/attribution', { query: q(s, { model: s.model }) })),
  attributionLeads: async (s: Scope & { model: 'first' | 'last'; metric: StepKey; source?: string; campaign?: string; noCampaign?: boolean }, cursor?: string) => {
    const r = await api.get<DrillRow[]>('/api/v1/attribution/leads', { query: q(s, { model: s.model, metric: s.metric, source: s.source, campaign: s.campaign, noCampaign: s.noCampaign ? 'true' : undefined, limit: 25, cursor }) });
    return { rows: r.data, next: r.page?.next ?? null, total: Number(r.headers?.get?.('x-total-count') ?? r.data.length) };
  },
  channels: () => unwrap(api.get<ChannelMapping[]>('/api/v1/attribution/channels')),
  setChannel: (source: string, channel: string) => unwrap(api.put<ChannelMapping[]>(`/api/v1/attribution/channels/${source}`, { channel })),
  spend: (range?: { from?: string; to?: string }) => unwrap(api.get<Spend[]>('/api/v1/spend', { query: range })),
  addSpend: (b: { source: string; campaign?: string | null; periodStart: string; periodEnd: string; amount: number; note?: string | null }) => unwrap(api.post<Spend>('/api/v1/spend', b)),
  updateSpend: (id: string, version: number, b: { campaign?: string | null; periodStart?: string; periodEnd?: string; amount?: number; note?: string | null }) => unwrap(api.patch<Spend>(`/api/v1/spend/${id}`, b, { headers: { 'If-Match': `"${version}"` } })),
  deleteSpend: (id: string) => unwrap(api.delete(`/api/v1/spend/${id}`)),
  importSpend: (csv: string, dryRun: boolean) => unwrap(api.post<SpendImportResult>('/api/v1/spend/import', { csv, dryRun })),
  report: (s: Scope) => unwrap(api.get<ReportOverview>('/api/v1/reports/overview', { query: q(s) })),
  flow: (s: Scope) => unwrap(api.get<LeadFlow>('/api/v1/reports/flow', { query: q(s) })),
  reportLeads: async (s: Scope & { metric: StepKey; owner?: string }, cursor?: string) => {
    const r = await api.get<DrillRow[]>('/api/v1/reports/leads', { query: q(s, { metric: s.metric, owner: s.owner, limit: 25, cursor }) });
    return { rows: r.data, next: r.page?.next ?? null, total: Number(r.headers?.get?.('x-total-count') ?? r.data.length) };
  },
  exportUrl: (s: Scope) => {
    const p = new URLSearchParams(Object.entries(q(s)).filter(([, v]) => v !== undefined).map(([k, v]) => [k, String(v)]));
    return `/api/v1/reports/export?${p.toString()}`;
  },
};
