import { useState } from 'react';
import { Box, Button, IconButton, Stack, Tooltip, Typography } from '@mui/material';
import CheckCircleRoundedIcon from '@mui/icons-material/CheckCircleRounded';
import RadioButtonUncheckedRoundedIcon from '@mui/icons-material/RadioButtonUncheckedRounded';
import CloseRoundedIcon from '@mui/icons-material/CloseRounded';
import { useQuery } from '@tanstack/react-query';
import { Link as RouterLink } from 'react-router-dom';
import { agencyApi } from '@/api/agency';
import { analyticsApi } from '@/api/analytics';
import { dashboardApi } from '@/api/dashboard';
import { pipelinesApi } from '@/api/crm';
import { useAuth } from '@/features/auth/AuthProvider';
import { ArrivalCard, LeadFlowCard, PulseCard, ReplySpeedCard } from '@/features/insights/visuals';
import { ErrorState, SkeletonRows } from '@/ui/states';
import { Card, NowStrip, NowTile, Pill, When } from '@/ui/kit';
import { SplitBar } from '@/ui/viz';
import { Attention } from './Attention';
import { roleName } from './shared';

const day = (d = new Date()) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
const money = (v: number | null, currency: string) => (v === null ? '—' : new Intl.NumberFormat(undefined, { style: 'currency', currency, notation: 'compact', maximumFractionDigits: 1 }).format(v));
const greeting = () => {
  const h = new Date().getHours();
  return h < 12 ? 'Good morning' : h < 17 ? 'Good afternoon' : 'Good evening';
};

const hintKey = (id: string) => `anibrosdesign.hide-start.${id}`;
const hidden = (id: string) => {
  try {
    return localStorage.getItem(hintKey(id)) === '1';
  } catch {
    return false;
  }
};

/** One client at a glance: how the period looks (shape, sources, timing, speed), what needs you right now, and where the leads are. */
export function ClientOverviewPage() {
  const { scopeId, me, can, isAgencyAdmin } = useAuth();
  const first = me?.user.name?.split(' ')[0];
  const [dismissed, setDismissed] = useState(() => (scopeId ? hidden(scopeId) : false));
  const client = useQuery({ queryKey: ['client', 'overview', scopeId], queryFn: () => agencyApi.client(scopeId!), enabled: Boolean(scopeId), refetchInterval: 60_000 });
  const pipelines = useQuery({ queryKey: ['pipelines'], queryFn: pipelinesApi.list, staleTime: 30_000 });
  const mayWatchTeam = can('reports:read') || isAgencyAdmin;
  const activity = useQuery({ queryKey: ['client', 'activity-today', scopeId], queryFn: () => agencyApi.activity({ clientId: scopeId!, from: day(), to: day() }), enabled: Boolean(scopeId) && mayWatchTeam, refetchInterval: 60_000, retry: false });
  const flow = useQuery({ queryKey: ['client', 'flow', scopeId], queryFn: () => analyticsApi.flow({ includeTest: false }), enabled: Boolean(scopeId) && can('reports:read'), staleTime: 60_000, retry: false });
  const overview = useQuery({ queryKey: ['client', 'overview-compare', scopeId], queryFn: () => dashboardApi.overview({ includeTest: false }), enabled: Boolean(scopeId) && can('dashboard:read'), staleTime: 60_000, retry: false });

  if (client.isPending) return <SkeletonRows rows={5} height={64} />;
  if (client.isError) return <ErrorState error={client.error} onRetry={() => void client.refetch()} />;
  const c = client.data;
  const s = c.stats;
  const pipeline = pipelines.data?.find((p) => p.isDefault) ?? pipelines.data?.[0];
  const stages = (pipeline?.stages ?? []).filter((st) => !st.isClosed);
  const openTotal = stages.reduce((n, st) => n + st.leadCount, 0);
  const filled = stages.filter((st) => st.leadCount > 0);
  const empty = stages.filter((st) => st.leadCount === 0);
  const manage = can('integrations:manage') || isAgencyAdmin;
  const maxStage = Math.max(1, ...filled.map((st) => st.leadCount));

  // Gentle starting hints, never a to-do list: a client that gets leads some other way simply ignores or hides them.
  const steps = [
    { done: c.assignedStaff > 0, label: 'Assign the team', to: '/settings/team', show: manage },
    { done: (s?.totalLeads ?? 0) > 0, label: 'Add the first lead', to: '/leads?new=1', show: can('leads:create') },
    { done: (s?.leadSources?.active ?? 0) > 0, label: 'Connect a lead source (only if leads arrive automatically)', to: '/integrations/lead-sources', show: manage },
  ].filter((x) => x.show);
  const showHint = !dismissed && steps.some((x) => !x.done) && ((s?.totalLeads ?? 0) === 0 || c.assignedStaff === 0);
  const hide = () => {
    setDismissed(true);
    try {
      if (scopeId) localStorage.setItem(hintKey(scopeId), '1');
    } catch {
      /* the hint just comes back next time */
    }
  };
  const f = flow.data;
  const industry = c.industry ? c.industry.replace(/_/g, ' ').replace(/^./, (x) => x.toUpperCase()) : null;

  return (
    <>
      <Box component="header" sx={{ mb: 2.5 }}>
        <Typography variant="eyebrow" color="text.secondary">{greeting()}{first ? `, ${first}` : ''}</Typography>
        <Stack direction="row" spacing={1.5} sx={{ alignItems: 'center', flexWrap: 'wrap', rowGap: 0.5, mt: 0.25 }}>
          <Typography variant="h1" component="h1" sx={{ fontSize: { xs: '1.625rem', md: '2rem' }, fontWeight: 650, letterSpacing: '-0.025em' }}>{c.name}</Typography>
          {industry && <Pill tone="accent">{industry}</Pill>}
          <Pill>{roleName(c.myRole)}</Pill>
        </Stack>
      </Box>

      {showHint && (
        <Box sx={{ mb: 2, px: 2, py: 1.25, borderRadius: 3, bgcolor: 'accentTone.bg', display: 'flex', gap: 1.5, alignItems: 'center', flexWrap: 'wrap' }}>
          <Typography variant="subtitle2" sx={{ mr: 0.5 }}>Getting started</Typography>
          {steps.map((st) => (
            <Button key={st.label} component={RouterLink} to={st.to} size="small" color="inherit" startIcon={st.done ? <CheckCircleRoundedIcon color="success" /> : <RadioButtonUncheckedRoundedIcon color="disabled" />} sx={{ color: st.done ? 'text.secondary' : 'text.primary', textDecoration: st.done ? 'line-through' : 'none' }}>{st.label}</Button>
          ))}
          <Tooltip title="Hide this for this client"><IconButton size="small" aria-label="Hide getting started" onClick={hide} sx={{ ml: 'auto' }}><CloseRoundedIcon fontSize="small" /></IconButton></Tooltip>
        </Box>
      )}

      {s && (
        <NowStrip sx={{ mb: 2.5 }}>
          <NowTile label="Open leads" value={s.openLeads} hint={`${s.newLast7Days} new this week · ${s.totalLeads} in total`} to="/leads" />
          <NowTile label="Waiting for a reply" value={s.slaBreached} tone="warning" hint="past the reply deadline" to="/leads?sla=breached" />
          <NowTile label="Not assigned" value={s.unassigned} tone="warning" hint="open, nobody owns them" to="/leads?owner=unassigned" />
          <NowTile label="Won" value={s.won} tone="success" hint={s.spendThisMonth !== null && s.spendThisMonth > 0 ? `${money(s.spendThisMonth, s.currency)} ad spend this month` : 'all time'} to="/leads?state=closed" />
        </NowStrip>
      )}

      {f && f.total >= 0 && (
        <>
          <Box sx={{ display: 'grid', gap: 2.5, gridTemplateColumns: { xs: '1fr', lg: 'minmax(0, 5fr) minmax(0, 7fr)' }, mb: 2.5, alignItems: 'stretch' }}>
            <PulseCard flow={f} previousTotal={overview.data?.leads?.previousCreated ?? null} />
            <LeadFlowCard flow={f} />
          </Box>
          <Box sx={{ display: 'grid', gap: 2.5, gridTemplateColumns: { xs: '1fr', lg: 'minmax(0, 7fr) minmax(0, 5fr)' }, mb: 2.5, alignItems: 'stretch' }}>
            <ArrivalCard flow={f} />
            <ReplySpeedCard flow={f} />
          </Box>
        </>
      )}

      <Attention clientId={scopeId ?? undefined} showTeam={can('leads:assign') || isAgencyAdmin} />

      <Box sx={{ display: 'grid', gap: 2.5, gridTemplateColumns: { xs: '1fr', lg: 'minmax(0, 3fr) minmax(0, 2fr)' }, alignItems: 'start' }}>
        <Card title="Pipeline right now" subtitle={pipeline ? `${openTotal} open lead${openTotal === 1 ? '' : 's'} in ${pipeline.name}` : undefined} actions={<Button size="small" color="inherit" component={RouterLink} to="/leads?view=board">Open board</Button>}>
          {pipelines.isPending ? (
            <SkeletonRows rows={3} height={28} />
          ) : stages.length === 0 ? (
            <Typography variant="body2" color="text.secondary">No pipeline yet. Set one up in Settings.</Typography>
          ) : openTotal === 0 ? (
            <Typography variant="body2" color="text.secondary">No open leads right now. New leads land in <b>{stages[0]!.name}</b>.</Typography>
          ) : (
            <>
              <Stack spacing={0.25}>
                {filled.map((st) => (
                  <Box key={st.id} component={RouterLink} to={`/leads?stage=${st.id}`} sx={{ display: 'grid', gridTemplateColumns: 'minmax(110px, 170px) minmax(0, 1fr) 34px', gap: 1.5, alignItems: 'center', py: 0.75, px: 1, mx: -1, borderRadius: 2, color: 'inherit', textDecoration: 'none', '&:hover': { bgcolor: 'action.hover' }, '&:focus-visible': { outline: '2px solid', outlineColor: 'primary.main' } }}>
                    <Typography variant="body2" noWrap>{st.name}</Typography>
                    <Box sx={{ height: 10, borderRadius: 5, bgcolor: 'action.hover', overflow: 'hidden' }}>
                      <Box sx={{ height: '100%', width: `${Math.max(4, (st.leadCount / maxStage) * 100)}%`, borderRadius: 5, bgcolor: st.color || 'primary.main' }} />
                    </Box>
                    <Typography variant="body2" sx={{ fontWeight: 650, textAlign: 'right', fontVariantNumeric: 'tabular-nums' }}>{st.leadCount}</Typography>
                  </Box>
                ))}
              </Stack>
              {empty.length > 0 && (
                <Typography variant="caption" color="text.disabled" component="p" sx={{ mt: 1.25 }}>
                  Empty right now: {empty.map((st) => st.name).join(' · ')}
                </Typography>
              )}
            </>
          )}
        </Card>

        <Stack spacing={2.5}>
          {mayWatchTeam && (
            <Card title="Team today" subtitle="Calls and messages logged so far" actions={<Button size="small" color="inherit" component={RouterLink} to={`/agency/activity?client=${scopeId}`}>Details</Button>}>
              {activity.isPending ? (
                <SkeletonRows rows={3} height={24} />
              ) : !activity.data || activity.data.rows.length === 0 ? (
                <Typography variant="body2" color="text.secondary">Nothing logged yet today.</Typography>
              ) : (
                <Stack spacing={1.5}>
                  {activity.data.rows.slice(0, 6).map((r) => {
                    const messages = r.whatsapp + r.sms + r.emails + r.instagram;
                    return (
                      <Box key={r.userId}>
                        <Stack direction="row" spacing={1} sx={{ alignItems: 'baseline', justifyContent: 'space-between', mb: 0.5 }}>
                          <Typography variant="body2" noWrap sx={{ minWidth: 0, fontWeight: 500 }}>{r.userName}</Typography>
                          <Typography variant="caption" color="text.secondary" sx={{ flexShrink: 0 }}>{r.calls} call{r.calls === 1 ? '' : 's'} · {messages} message{messages === 1 ? '' : 's'}</Typography>
                        </Stack>
                        <SplitBar height={8} parts={[{ key: 'c', label: 'Spoke to them', value: r.connected, color: '#10B981' }, { key: 'n', label: 'Calls not answered', value: Math.max(0, r.calls - r.connected), color: '#CBD5E1' }, { key: 'm', label: 'Messages', value: messages, color: '#6366F1' }]} />
                      </Box>
                    );
                  })}
                </Stack>
              )}
            </Card>
          )}

          <Card title="Lead capture">
            {s?.leadSources && s.leadSources.active > 0 ? (
              <Stack spacing={0.5}>
                <Stack direction="row" sx={{ justifyContent: 'space-between' }}>
                  <Typography variant="body2" color="text.secondary">Connected sources</Typography>
                  <Typography variant="body2" sx={{ fontWeight: 600 }}>{s.leadSources.active}</Typography>
                </Stack>
                <Stack direction="row" sx={{ justifyContent: 'space-between' }}>
                  <Typography variant="body2" color="text.secondary">Last lead received</Typography>
                  <Typography variant="body2"><When value={s.leadSources.lastReceivedAt ?? s.lastLeadAt} fallback="Never" /></Typography>
                </Stack>
              </Stack>
            ) : (
              <Stack direction="row" sx={{ justifyContent: 'space-between' }}>
                <Typography variant="body2" color="text.secondary">Last lead added</Typography>
                <Typography variant="body2"><When value={s?.lastLeadAt} fallback="None yet" /></Typography>
              </Stack>
            )}
            {manage && <Button size="small" color="inherit" component={RouterLink} to="/integrations/lead-sources" sx={{ mt: 0.5, ml: -1 }}>{s?.leadSources && s.leadSources.active > 0 ? 'Manage lead sources' : 'Connect a lead source'}</Button>}
          </Card>
        </Stack>
      </Box>
    </>
  );
}
