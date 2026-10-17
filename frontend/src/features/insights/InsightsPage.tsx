import { useState } from 'react';
import { Link as RouterLink } from 'react-router-dom';
import { Alert, Box, Button, Menu, MenuItem, Stack, Table, TableBody, TableCell, TableContainer, TableHead, TableRow, ToggleButton, ToggleButtonGroup, Typography } from '@mui/material';
import ExpandMoreRoundedIcon from '@mui/icons-material/ExpandMoreRounded';
import FileDownloadOutlinedIcon from '@mui/icons-material/FileDownloadOutlined';
import { format } from 'date-fns';
import { useQuery } from '@tanstack/react-query';
import { analyticsApi, type AttributionReport, type LeadFlow, type ReportOverview, type StepKey } from '@/api/analytics';
import { dashboardApi, type Overview } from '@/api/dashboard';
import { useAuth } from '@/features/auth/AuthProvider';
import { DrillCount, LeadsDrawer, type DrillSpec } from '@/features/analytics/LeadsDrawer';
import { makeLinks } from '@/features/dashboard/parts';
import { SpendPanel } from '@/features/attribution/SpendPanel';
import { SourceTag } from '@/features/agency/LeadRowParts';
import { useAgencyOptions } from '@/features/agency/shared';
import { formatMoney } from '@/features/leads/shared';
import { PageHeader } from '@/ui/PageHeader';
import { UserAvatar } from '@/ui/display';
import { Card, NowStrip, NowTile, ViewTabs } from '@/ui/kit';
import { Delta, Donut, FunnelShape } from '@/ui/viz';
import { ArrivalCard, LeadFlowCard, ReplySpeedCard } from './visuals';
import { BarList, dayLabel, pct, useRangeParams, type BarRow } from '@/ui/analytics';
import { EmptyState, ErrorState, SkeletonRows } from '@/ui/states';
import { ShareBar, TrendChart } from './charts';
import { PeriodPicker } from './PeriodPicker';

type Tab = 'overview' | 'team' | 'sources' | 'spend';

const minutes = (m: number | null | undefined) => (m === null || m === undefined ? '—' : m < 60 ? `${Math.round(m)} min` : m < 1440 ? `${(m / 60).toFixed(1)} h` : `${(m / 1440).toFixed(1)} days`);
/** The API labels a day as 2026-10-14: show it the way people say it. */
const shortLabel = (l: string) => (/^\d{4}-\d{2}-\d{2}$/.test(l) ? format(new Date(`${l}T00:00:00`), 'd MMM') : l);
const dash = (v: number | null, f: (n: number) => string) => (v === null ? '—' : f(v));
/** One place for the numbers: how many leads, where they come from, how the team is doing, what ads cost. Every figure opens the leads behind it. */
export function InsightsPage() {
  const { can, tenant } = useAuth();
  const { from, to, includeTest, set, params } = useRangeParams();
  const tab = (['overview', 'team', 'sources', 'spend'].includes(params.get('tab') ?? '') ? params.get('tab') : 'overview') as Tab;
  const model = params.get('model') === 'last' ? 'last' : 'first';
  const [drill, setDrill] = useState<DrillSpec | null>(null);

  const canReport = can('reports:read');
  const canSources = can('attribution:read');
  const overview = useQuery({ queryKey: ['insights', 'overview', from, to, includeTest], queryFn: () => dashboardApi.overview({ from, to, includeTest }), placeholderData: (p) => p, enabled: can('dashboard:read') });
  const report = useQuery({ queryKey: ['insights', 'report', from, to, includeTest], queryFn: () => analyticsApi.report({ from, to, includeTest }), placeholderData: (p) => p, enabled: canReport });
  const flow = useQuery({ queryKey: ['insights', 'flow', from, to, includeTest], queryFn: () => analyticsApi.flow({ from, to, includeTest }), placeholderData: (p) => p, enabled: canReport });
  const attribution = useQuery({ queryKey: ['insights', 'attribution', from, to, includeTest, model], queryFn: () => analyticsApi.attribution({ from, to, includeTest, model }), placeholderData: (p) => p, enabled: canSources && (tab === 'sources' || tab === 'spend') });

  const range = overview.data?.range ?? report.data?.range;
  const currency = overview.data?.currency ?? report.data?.currency ?? tenant?.currency ?? 'INR';
  const money = (n: number) => formatMoney(n, currency);
  const label = range ? `${dayLabel(range.from)} – ${dayLabel(range.to)}` : 'Choose a period';
  const tabs: Array<{ id: Tab; label: string }> = [
    { id: 'overview', label: 'Overview' },
    ...(canReport || can('dashboard:read') ? [{ id: 'team' as Tab, label: 'Team' }] : []),
    ...(canSources ? [{ id: 'sources' as Tab, label: 'Sources' }, { id: 'spend' as Tab, label: 'Ad spend' }] : []),
  ];
  const scope = { from, to, includeTest };
  const errorOf = overview.error ?? report.error;

  return (
    <>
      <PageHeader
        title="Reports"
        description={`${label} · compared with the same number of days before`}
        actions={
          <>
            <PeriodPicker range={range} includeTest={includeTest} set={set} />
            {can('reports:export') && <Button size="small" variant="outlined" color="inherit" startIcon={<FileDownloadOutlinedIcon />} component="a" href={analyticsApi.exportUrl(scope)} download>Excel</Button>}
          </>
        }
      />
      <ViewTabs label="Report sections" items={tabs} value={tab} onChange={(id) => set({ tab: id === 'overview' ? undefined : id })} />

      {tab === 'overview' && (overview.isPending && report.isPending ? <SkeletonRows rows={5} height={80} /> : errorOf && !overview.data && !report.data ? <ErrorState error={errorOf} onRetry={() => { void overview.refetch(); void report.refetch(); }} /> : <OverviewTab o={overview.data ?? null} r={report.data ?? null} f={flow.data ?? null} money={money} range={range} includeTest={includeTest} onTab={(t) => set({ tab: t })} />)}
      {tab === 'team' && <TeamTab o={overview.data ?? null} r={report.data ?? null} pending={overview.isPending && report.isPending} includeTest={includeTest} range={range} />}
      {tab === 'sources' && (attribution.isPending ? <SkeletonRows rows={5} height={80} /> : attribution.isError ? <ErrorState error={attribution.error} onRetry={() => void attribution.refetch()} /> : attribution.data ? <SourcesTab d={attribution.data} model={model} setModel={(m) => set({ model: m === 'first' ? undefined : m })} money={money} onDrill={(metric, title, extra) => setDrill({ title, subtitle: `${dayLabel(attribution.data!.range.from)} – ${dayLabel(attribution.data!.range.to)}`, key: [metric, extra, from, to, includeTest, model], load: (cursor) => analyticsApi.attributionLeads({ ...scope, model, metric, ...extra }, cursor) })} /> : null)}
      {tab === 'spend' && <SpendPanel currency={currency} canManage={can('attribution:manage')} />}
      <LeadsDrawer spec={drill} currency={currency} onClose={() => setDrill(null)} />
    </>
  );
}

// ── overview ─────────────────────────────────────────────────────────────────────────────────────────

function OverviewTab({ o, r, f, money, range, includeTest, onTab }: { o: Overview | null; r: ReportOverview | null; f: LeadFlow | null; money: (n: number) => string; range?: { from: string; to: string; days: number }; includeTest: boolean; onTab: (t: Tab) => void }) {
  const { tenant } = useAuth();
  const options = useAgencyOptions();
  const links = makeLinks(range ?? { from: '', to: '' }, includeTest, o?.tasks?.scope);
  const appointment = options.data?.industries.find((i) => i.code === tenant?.industry)?.appointment ?? 'Appointment';
  const realEstate = tenant?.industry === 'real_estate';
  const leads = o?.leads;
  const sales = o?.sales;
  const fr = r?.engagement.firstReply;
  const wonCount = r?.funnel?.sales ?? sales?.count ?? leads?.wonOfCreated ?? 0;
  const attention = [
    leads && leads.unassigned > 0 && { label: `${leads.unassigned} not assigned`, to: links.leadsUnassigned },
    leads && leads.lateFirstReplies > 0 && { label: `${leads.lateFirstReplies} waiting for a reply`, to: links.leadsLate },
    leads && leads.followupsOverdue > 0 && { label: `${leads.followupsOverdue} follow-ups overdue`, to: links.leadsFollowup },
    o?.tasks && o.tasks.overdue > 0 && { label: `${o.tasks.overdue} tasks overdue`, to: links.tasksOverdue },
  ].filter(Boolean) as Array<{ label: string; to: string }>;

  const steps = (r?.funnel?.steps ?? [])
    .filter((s) => realEstate || s.key !== 'booked' || s.count > 0)
    .map((s) => ({ key: s.key, label: s.key === 'leads' ? 'New leads' : s.key === 'qualified' ? 'Qualified' : s.key === 'visits' ? (realEstate ? 'Site visit done' : `${appointment} done`) : s.key === 'booked' ? (realEstate ? 'Booked' : 'Confirmed') : 'Won', count: s.count, ofPrevious: s.ofPrevious }));
  const sourceRows: BarRow[] = (o?.sources ?? []).slice(0, 6).map((s) => ({ key: s.source, label: s.label, value: s.leads, note: s.won ? `${s.won} won` : undefined, to: links.leadsSource(s.source) }));
  const stages = (o?.pipeline?.stages ?? []).filter((s) => !s.isClosed);
  const stageDays = new Map((r?.pipeline?.stages ?? []).map((s) => [s.id, s.averageDays]));
  const lost: BarRow[] = [...(r?.conversion?.lostReasons ?? []), ...(r?.conversion?.notAFitReasons ?? [])].filter((x) => x.count > 0).sort((a, b) => b.count - a.count).slice(0, 5).map((x) => ({ key: x.code, label: x.label, value: x.count, color: 'error.main' }));

  const lostSlices = [...(r?.conversion?.lostReasons ?? []), ...(r?.conversion?.notAFitReasons ?? [])].filter((x) => x.count > 0).sort((a, b) => b.count - a.count).slice(0, 6).map((x, i) => ({ key: x.code, label: x.label, value: x.count, color: ['#FB7185', '#F59E0B', '#94A3B8', '#8B5CF6', '#38BDF8', '#10B981'][i]! }));
  const lostTotal = lostSlices.reduce((n, x) => n + x.value, 0);

  return (
    <Stack spacing={2.5}>
      <NowStrip>
        <NowTile label="New leads" value={leads ? leads.created : '—'} hint={leads ? <>{leads.open.toLocaleString()} open right now <Delta current={leads.created} previous={leads.previousCreated} /></> : undefined} />
        <NowTile label="Won" value={wonCount} tone="success" hint={sales && sales.dealValue > 0 ? `${money(sales.dealValue)} in deals` : leads ? `${pct(leads.conversionRate)} of new leads` : undefined} />
        <NowTile label="Win rate" value={pct(r?.conversion?.winRate ?? leads?.conversionRate ?? null)} hint={r?.conversion ? `${r.conversion.won} won · ${r.conversion.lost} lost · ${r.conversion.notAFit} not a fit` : 'of leads created in this period'} />
        <NowTile label="First reply" value={fr ? minutes(fr.medianMinutes) : leads ? leads.lateFirstReplies : '—'} tone={!fr && leads && leads.lateFirstReplies > 0 ? 'warning' : undefined} hint={fr ? `${pct(fr.inTimeRate)} answered on time` : 'late replies right now'} />
      </NowStrip>

      {attention.length > 0 ? (
        <Stack direction="row" spacing={1} sx={{ flexWrap: 'wrap', rowGap: 1, alignItems: 'center' }}>
          <Typography variant="body2" color="text.secondary">Needs attention:</Typography>
          {attention.map((a) => <Button key={a.label} size="small" variant="outlined" color="warning" component={RouterLink} to={a.to} sx={{ borderRadius: 4 }}>{a.label}</Button>)}
        </Stack>
      ) : o ? (
        <Typography variant="body2" sx={{ color: 'success.main' }}>Nothing is overdue or waiting for an owner.</Typography>
      ) : null}

      {f && <LeadFlowCard flow={f} actions={<Button size="small" color="inherit" onClick={() => onTab('sources')}>Details</Button>} />}

      <Box sx={{ display: 'grid', gap: 2.5, gridTemplateColumns: { xs: '1fr', lg: 'minmax(0, 3fr) minmax(0, 2fr)' }, alignItems: 'start' }}>
        <Card title="Leads over time" subtitle={o ? `${o.bucket === 'day' ? 'Per day' : o.bucket === 'week' ? 'Per week' : 'Per month'}${(o.trend.some((p) => p.sales > 0)) ? ' · green line = won' : ''}` : undefined}>
          {o ? (o.trend.some((p) => p.leads > 0) ? <TrendChart points={o.trend.map((p) => ({ label: shortLabel(p.label), leads: p.leads, sales: p.sales }))} /> : <Typography variant="body2" color="text.secondary" sx={{ py: 4, textAlign: 'center' }}>No leads were created in this period.</Typography>) : <Typography variant="body2" color="text.secondary">You do not have access to this chart.</Typography>}
        </Card>
        <Card title="From lead to won" subtitle="How far leads got, and where they dropped out">
          {steps.length ? <FunnelShape steps={steps} /> : <Typography variant="body2" color="text.secondary">{r ? 'Nothing to show for this period.' : 'The funnel is shown to team leads, managers and admins.'}</Typography>}
        </Card>
      </Box>

      {f && (
        <Box sx={{ display: 'grid', gap: 2.5, gridTemplateColumns: { xs: '1fr', lg: 'minmax(0, 7fr) minmax(0, 5fr)' }, alignItems: 'stretch' }}>
          <ArrivalCard flow={f} />
          <ReplySpeedCard flow={f} onTimeRate={fr ? fr.inTimeRate : undefined} />
        </Box>
      )}

      <Box sx={{ display: 'grid', gap: 2.5, gridTemplateColumns: { xs: '1fr', lg: 'repeat(2, minmax(0, 1fr))' }, alignItems: 'start' }}>
        <Card title="Pipeline right now" subtitle={r?.pipeline ? `${r.pipeline.openLeads} open · average days in each stage` : undefined}>
          {stages.length === 0 ? <Typography variant="body2" color="text.secondary">No open leads.</Typography> : (
            <Stack spacing={0.25}>
              {stages.map((s) => (
                <Box key={s.id} component={RouterLink} to={links.leadsStage(s.id)} sx={{ display: 'grid', gridTemplateColumns: 'minmax(100px, 160px) minmax(0,1fr) 44px 30px', gap: 1.25, alignItems: 'center', py: 0.5, px: 0.75, mx: -0.75, borderRadius: 1.5, color: 'inherit', textDecoration: 'none', '&:hover': { bgcolor: 'action.hover' } }}>
                  <Typography variant="body2" noWrap color={s.count ? 'text.primary' : 'text.disabled'}>{s.name}</Typography>
                  <Box sx={{ height: 8, borderRadius: 4, bgcolor: 'action.hover', overflow: 'hidden' }}><Box sx={{ height: '100%', width: `${Math.max(s.count ? 4 : 0, (s.count / Math.max(1, ...stages.map((x) => x.count))) * 100)}%`, bgcolor: s.color, borderRadius: 4 }} /></Box>
                  <Typography variant="caption" color="text.secondary" sx={{ textAlign: 'right' }}>{stageDays.get(s.id) != null && s.count > 0 ? `${Number(stageDays.get(s.id)).toFixed(1)} d` : ''}</Typography>
                  <Typography variant="body2" sx={{ fontWeight: 650, textAlign: 'right' }}>{s.count}</Typography>
                </Box>
              ))}
            </Stack>
          )}
        </Card>
        <Card title="Why leads are lost" subtitle="Lost or not a fit, with the reason recorded">
          {lostSlices.length === 0 ? <Typography variant="body2" color="text.secondary">No lost leads with a reason in this period.</Typography> : <Donut slices={lostSlices} size={132} center={<Box><Typography variant="h2" component="div" sx={{ lineHeight: 1 }}>{lostTotal}</Typography><Typography variant="caption" color="text.secondary">lost</Typography></Box>} />}
        </Card>
      </Box>
    </Stack>
  );
}

// ── team ─────────────────────────────────────────────────────────────────────────────────────────────

function TeamTab({ o, r, pending, includeTest, range }: { o: Overview | null; r: ReportOverview | null; pending: boolean; includeTest: boolean; range?: { from: string; to: string } }) {
  const links = makeLinks(range ?? { from: '', to: '' }, includeTest, o?.tasks?.scope);
  if (pending) return <SkeletonRows rows={5} height={56} />;
  const live = new Map((o?.team ?? []).map((t) => [t.userId, t]));
  const rows = (r?.team ?? []).map((t) => ({ id: t.userId, name: t.name, leads: t.leads, qualified: t.qualified, won: t.won, revenue: t.revenue, winRate: t.winRate, reply: t.medianFirstReplyMinutes, open: t.userId ? live.get(t.userId)?.openLeads : undefined, overdue: t.userId ? live.get(t.userId)?.overdueTasks : undefined }));
  const fallback = !r && o?.team ? o.team.map((t) => ({ id: t.userId, name: t.name, leads: t.leadsCreated, qualified: 0, won: t.sales, revenue: t.dealValue, winRate: null, reply: null, open: t.openLeads, overdue: t.overdueTasks })) : [];
  const list = rows.length ? rows : fallback;
  if (list.length === 0) return <EmptyState title="No team figures" description="Figures per person appear here once leads are assigned. Callers see only their own work in Activity." />;
  const top = Math.max(1, ...list.map((x) => x.leads));
  return (
    <Card pad={0} title="Who is doing what" subtitle="Leads each person owned in the period, and how they did with them">
      <TableContainer>
        <Table size="small" aria-label="Team performance">
          <TableHead>
            <TableRow>
              <TableCell>Person</TableCell>
              <TableCell sx={{ width: '24%' }}>Leads</TableCell>
              <TableCell align="right">Qualified</TableCell>
              <TableCell align="right">Won</TableCell>
              <TableCell align="right">Win rate</TableCell>
              <TableCell align="right">First reply</TableCell>
              <TableCell align="right">Open now</TableCell>
              <TableCell align="right">Tasks overdue</TableCell>
            </TableRow>
          </TableHead>
          <TableBody>
            {list.map((t) => (
              <TableRow key={t.id ?? 'none'} hover>
                <TableCell>
                  <Stack direction="row" spacing={1} sx={{ alignItems: 'center' }}>
                    <UserAvatar name={t.name} seed={t.id ?? undefined} size={26} />
                    {t.id ? <Typography component={RouterLink} to={links.leadsOwner(t.id)} variant="subtitle2" sx={{ color: 'inherit', textDecoration: 'none', '&:hover': { color: 'primary.main' } }}>{t.name}</Typography> : <Typography variant="subtitle2" color="text.secondary">{t.name}</Typography>}
                  </Stack>
                </TableCell>
                <TableCell>
                  <Stack direction="row" spacing={1} sx={{ alignItems: 'center' }}>
                    <Box sx={{ flex: 1, height: 8, borderRadius: 4, bgcolor: 'action.hover', overflow: 'hidden' }}><Box sx={{ height: '100%', width: `${(t.leads / top) * 100}%`, bgcolor: 'primary.main', borderRadius: 4 }} /></Box>
                    <Typography variant="body2" sx={{ minWidth: 24, textAlign: 'right', fontWeight: 600 }}>{t.leads}</Typography>
                  </Stack>
                </TableCell>
                <TableCell align="right">{t.qualified || '—'}</TableCell>
                <TableCell align="right">{t.won || '—'}</TableCell>
                <TableCell align="right">{pct(t.winRate)}</TableCell>
                <TableCell align="right">{minutes(t.reply)}</TableCell>
                <TableCell align="right">{t.open ?? '—'}</TableCell>
                <TableCell align="right" sx={{ color: t.overdue ? 'error.main' : undefined, fontWeight: t.overdue ? 600 : undefined }}>{t.overdue ?? '—'}</TableCell>
              </TableRow>
            ))}
          </TableBody>
        </Table>
      </TableContainer>
    </Card>
  );
}

// ── sources ──────────────────────────────────────────────────────────────────────────────────────────

const SHARE_COLORS = ['#4F46E5', '#0EA5E9', '#10B981', '#F59E0B', '#EC4899', '#8B5CF6', '#64748B'];

function SourcesTab({ d, model, setModel, money, onDrill }: { d: AttributionReport; model: 'first' | 'last'; setModel: (m: 'first' | 'last') => void; money: (n: number) => string; onDrill: (metric: StepKey, title: string, extra: { source?: string; campaign?: string }) => void }) {
  const { tenant } = useAuth();
  const options = useAgencyOptions();
  const [by, setBy] = useState<'source' | 'campaign' | 'channel'>('source');
  const [menu, setMenu] = useState<HTMLElement | null>(null);
  const appointment = options.data?.industries.find((i) => i.code === tenant?.industry)?.appointment ?? 'Appointment';
  const realEstate = tenant?.industry === 'real_estate';
  if (d.totals.leads === 0) return <EmptyState title="No leads in this period" description="Try a wider period. Leads show up here as soon as they are created, from any source." action={<Button component={RouterLink} to="/integrations/lead-sources" variant="outlined">Connect a lead source</Button>} />;
  const rows: Array<{ key: string; name: string; source?: string; sub?: string; counts: AttributionReport['totals']; spend: AttributionReport['money']; drill?: { source?: string; campaign?: string } }> =
    by === 'source' ? d.sources.map((s) => ({ key: s.source, name: s.label, source: s.source, sub: s.channelLabel, counts: { ...s, wonWithoutDealValue: 0 }, spend: s.spend, drill: { source: s.source } }))
    : by === 'channel' ? d.channels.map((c) => ({ key: c.channel, name: c.label, counts: { ...c, wonWithoutDealValue: 0 }, spend: c.spend }))
    : d.campaigns.map((c) => ({ key: `${c.source}|${c.campaign}`, name: c.campaign, source: c.source, sub: c.sourceLabel, counts: { ...c, wonWithoutDealValue: 0 }, spend: c.spend, drill: { source: c.source, campaign: c.campaign } }));
  const hasSpend = rows.some((r) => r.spend);
  const t = d.tracking;
  const share = d.sources.map((s, i) => ({ key: s.source, label: s.label, value: s.leads, color: SHARE_COLORS[i % SHARE_COLORS.length]! }));
  const stepHead = { leads: 'Leads', qualified: 'Qualified', visits: realEstate ? 'Site visit' : appointment, booked: realEstate ? 'Booked' : 'Confirmed', won: 'Won' } as const;
  const keys: StepKey[] = ['leads', 'qualified', 'visits', ...(realEstate || d.totals.booked > 0 ? (['booked'] as StepKey[]) : []), 'won'];

  return (
    <Stack spacing={2.5}>
      <Card title={`${d.totals.leads.toLocaleString()} leads from ${d.sources.length} source${d.sources.length === 1 ? '' : 's'}`} subtitle={`${d.totals.won} won${d.totals.revenue > 0 ? ` · ${money(d.totals.revenue)} in deals` : ''}${d.money ? ` · ${money(d.money.spend)} spent` : ''}`}
        actions={
          <ToggleButtonGroup size="small" exclusive value={model} aria-label="Credit the" onChange={(_, m: 'first' | 'last' | null) => m && setModel(m)}>
            <ToggleButton value="first">First touch</ToggleButton>
            <ToggleButton value="last">Last touch</ToggleButton>
          </ToggleButtonGroup>
        }>
        <ShareBar rows={share} />
        <Stack direction="row" sx={{ mt: 1.25, flexWrap: 'wrap', columnGap: 2.5, rowGap: 0.5 }}>
          {share.map((s) => (
            <Stack key={s.key} direction="row" spacing={0.75} sx={{ alignItems: 'center' }}>
              <Box component="span" sx={{ width: 8, height: 8, borderRadius: '2px', bgcolor: s.color }} />
              <Typography variant="caption">{s.label} <b>{s.value}</b> <Typography component="span" variant="caption" color="text.secondary">({Math.round((s.value / d.totals.leads) * 100)}%)</Typography></Typography>
            </Stack>
          ))}
        </Stack>
        {t.sharePercent !== null && t.sharePercent < 0.8 && <Alert severity="warning" sx={{ mt: 1.5 }}>{t.untracked} leads have no recorded source, so they cannot be credited to a channel. Set the source when adding a lead.</Alert>}
      </Card>

      <Card pad={0} title="Sources, campaigns and what became of them" subtitle="Each lead is credited once. Click a number to see those leads."
        actions={
          <>
            <Button size="small" color="inherit" endIcon={<ExpandMoreRoundedIcon />} onClick={(e) => setMenu(e.currentTarget)}>By {by}</Button>
            <Menu anchorEl={menu} open={Boolean(menu)} onClose={() => setMenu(null)}>
              {(['source', 'campaign', 'channel'] as const).map((k) => <MenuItem key={k} selected={by === k} onClick={() => { setBy(k); setMenu(null); }}>By {k}{k === 'campaign' && d.campaigns.length ? ` (${d.campaigns.length})` : ''}</MenuItem>)}
            </Menu>
          </>
        }>
        {rows.length === 0 ? (
          <Typography variant="body2" color="text.secondary" sx={{ p: 2 }}>No campaign names were recorded on leads in this period. They appear only when a form or ad platform passes one along.</Typography>
        ) : (
          <TableContainer>
            <Table size="small" aria-label="Attribution">
              <TableHead>
                <TableRow>
                  <TableCell>{by === 'source' ? 'Source' : by === 'campaign' ? 'Campaign' : 'Channel'}</TableCell>
                  {keys.map((k) => <TableCell key={k} align="right">{stepHead[k]}</TableCell>)}
                  {hasSpend && <><TableCell align="right">Spent</TableCell><TableCell align="right">Per lead</TableCell><TableCell align="right">Per sale</TableCell></>}
                </TableRow>
              </TableHead>
              <TableBody>
                {rows.map((r) => (
                  <TableRow key={r.key} hover>
                    <TableCell sx={{ minWidth: 170 }}>{r.source ? <SourceTag source={r.source} campaign={by === 'campaign' ? r.sub : by === 'source' ? r.sub : null} /> : <Typography variant="body2" sx={{ fontWeight: 600 }}>{r.name}</Typography>}{by === 'campaign' && <Typography variant="body2" sx={{ fontWeight: 600, mt: 0.25 }}>{r.name}</Typography>}</TableCell>
                    {keys.map((k, i) => (
                      <TableCell key={k} align="right">
                        {r.drill ? <DrillCount value={r.counts[k]} label={`${r.counts[k]} ${stepHead[k].toLowerCase()} for ${r.name}`} onOpen={() => onDrill(k, `${stepHead[k]} · ${r.name}`, r.drill!)} /> : r.counts[k].toLocaleString()}
                        {i > 0 && r.counts.leads > 0 && r.counts[k] > 0 && <Typography variant="caption" color="text.secondary" component="div">{pct(r.counts[k] / r.counts.leads)}</Typography>}
                      </TableCell>
                    ))}
                    {hasSpend && <><TableCell align="right">{r.spend ? money(r.spend.spend) : '—'}</TableCell><TableCell align="right">{dash(r.spend?.costPerLead ?? null, money)}</TableCell><TableCell align="right">{dash(r.spend?.costPerSale ?? null, money)}</TableCell></>}
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          </TableContainer>
        )}
      </Card>
      {!d.money && <Typography variant="body2" color="text.secondary">Add what you spent under <b>Ad spend</b> to see the cost per lead and per sale. Nothing is estimated.</Typography>}
    </Stack>
  );
}
