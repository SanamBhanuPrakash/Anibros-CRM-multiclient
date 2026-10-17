import { Link as RouterLink } from 'react-router-dom';
import { Alert, Box, Button, Chip, Paper, Stack, Typography } from '@mui/material';
import CheckCircleRoundedIcon from '@mui/icons-material/CheckCircleRounded';
import WarningAmberRoundedIcon from '@mui/icons-material/WarningAmberRounded';
import ErrorRoundedIcon from '@mui/icons-material/ErrorRounded';
import RefreshRoundedIcon from '@mui/icons-material/RefreshRounded';
import { useQuery } from '@tanstack/react-query';
import { monitorApi, type CheckStatus, type Overview } from '@/api/monitor';
import { SOURCE_LABEL } from '@/features/leads/shared';
import { RelativeTime } from '@/ui/display';
import { ErrorState, SkeletonRows } from '@/ui/states';

const TONE: Record<CheckStatus, { color: string; icon: React.ReactElement; label: string }> = {
  ok: { color: 'success.main', icon: <CheckCircleRoundedIcon fontSize="small" />, label: 'OK' },
  warn: { color: 'warning.main', icon: <WarningAmberRoundedIcon fontSize="small" />, label: 'Needs a look' },
  fail: { color: 'error.main', icon: <ErrorRoundedIcon fontSize="small" />, label: 'Problem' },
};
const BANNER: Record<Overview['status'], 'success' | 'warning' | 'error'> = { healthy: 'success', attention: 'warning', problem: 'error' };
const TITLE: Record<Overview['status'], string> = { healthy: 'All systems normal', attention: 'Needs attention', problem: 'Something is wrong' };

function Stat({ label, value, hint, to, tone }: { label: string; value: number | string; hint?: string; to?: string; tone?: 'warn' }) {
  const body = (
    <Paper variant="outlined" sx={{ p: 2, borderRadius: 2.5, height: '100%', borderColor: tone === 'warn' ? 'warning.main' : undefined }}>
      <Typography variant="caption" color="text.secondary">{label}</Typography>
      <Typography variant="h3" component="p" sx={{ fontVariantNumeric: 'tabular-nums' }}>{typeof value === 'number' ? value.toLocaleString() : value}</Typography>
      {hint && <Typography variant="caption" color="text.secondary">{hint}</Typography>}
    </Paper>
  );
  return to ? <Box component={RouterLink} to={to} sx={{ textDecoration: 'none', color: 'inherit', display: 'block' }}>{body}</Box> : body;
}

/** Live health of the workspace. Refreshes itself every 30 seconds; the number on each card explains itself in words. */
export function OverviewTab() {
  const q = useQuery({ queryKey: ['monitor', 'overview'], queryFn: monitorApi.overview, refetchInterval: 30_000 });
  if (q.isPending) return <SkeletonRows rows={5} height={80} />;
  if (q.isError) return <ErrorState error={q.error} onRetry={() => void q.refetch()} />;
  const o = q.data;
  const max = Math.max(1, ...(o.leads?.bySource7d.map((s) => s.n) ?? [1]));
  const d = o.intake.deliveries24h;

  return (
    <Stack spacing={3}>
      <Alert
        severity={BANNER[o.status]}
        sx={{ alignItems: 'center' }}
        action={<Button color="inherit" size="small" startIcon={<RefreshRoundedIcon />} onClick={() => void q.refetch()} disabled={q.isFetching}>{q.isFetching ? 'Checking…' : 'Check now'}</Button>}
      >
        <Typography variant="subtitle1" component="span" sx={{ fontWeight: 600 }}>{TITLE[o.status]}.</Typography> {o.summary}{' '}
        <Typography variant="caption" component="span" color="text.secondary">Checked <RelativeTime value={o.generatedAt} />.</Typography>
      </Alert>

      <Box sx={{ display: 'grid', gap: 1.5, gridTemplateColumns: { xs: 'minmax(0, 1fr)', sm: 'repeat(2, minmax(0, 1fr))', lg: 'repeat(5, minmax(0, 1fr))' } }} role="list" aria-label="System checks">
        {o.checks.map((c) => (
          <Paper key={c.id} role="listitem" variant="outlined" sx={{ p: 2, borderRadius: 2.5, borderColor: c.status === 'ok' ? 'divider' : TONE[c.status].color }}>
            <Stack direction="row" spacing={0.75} sx={{ alignItems: 'center', color: TONE[c.status].color }}>
              {TONE[c.status].icon}
              <Typography variant="caption" sx={{ fontWeight: 600 }}>{TONE[c.status].label}</Typography>
            </Stack>
            <Typography variant="subtitle2" sx={{ mt: 0.5 }}>{c.label}</Typography>
            <Typography variant="body2" color="text.secondary">{c.detail}</Typography>
          </Paper>
        ))}
      </Box>

      <Box sx={{ display: 'grid', gap: 1.5, gridTemplateColumns: { xs: 'repeat(2, minmax(0, 1fr))', md: 'repeat(4, minmax(0, 1fr))' } }}>
        {o.leads && <Stat label="Leads, last 24 hours" value={o.leads.created24h} hint={`${o.leads.created7d.toLocaleString()} in 7 days`} to="/leads" />}
        {o.leads && <Stat label="First reply overdue" value={o.leads.firstReplyOverdue} hint="Leads still waiting for a first response" to="/leads?sla=breached" tone={o.leads.firstReplyOverdue ? 'warn' : undefined} />}
        {o.leads && <Stat label="Overdue tasks" value={o.leads.tasksOverdue} to="/tasks" tone={o.leads.tasksOverdue ? 'warn' : undefined} />}
        <Stat label="Waiting background jobs" value={o.queue.queued + o.queue.running} hint={o.queue.oldestWaitingSeconds > 5 ? `Oldest has waited ${o.queue.oldestWaitingSeconds} s` : 'Emails, imports, lead intake'} />
        <Stat label="Errors, last 24 hours" value={o.events.errors24h} hint={`${o.events.security24h} security · ${o.events.warnings24h} warnings`} to="/operations?tab=activity" tone={o.events.errors24h ? 'warn' : undefined} />
        <Stat label="Changes recorded, 24 hours" value={o.audit.actions24h} to="/operations?tab=audit" />
        <Stat label="Lead sources" value={o.intake.activeSources} hint={o.intake.pausedSources ? `${o.intake.pausedSources} paused` : 'receiving'} to="/integrations/lead-sources" />
        <Stat label="Deliveries needing attention" value={d.needs_review + d.failed} hint={`${d.created + d.duplicate} processed in 24 h`} to="/integrations/lead-sources?tab=log" tone={d.needs_review + d.failed ? 'warn' : undefined} />
      </Box>

      <Box sx={{ display: 'grid', gap: 2, gridTemplateColumns: { xs: 'minmax(0, 1fr)', lg: 'repeat(2, minmax(0, 1fr))' } }}>
        {o.leads && (
          <Paper variant="outlined" sx={{ p: 2.5, borderRadius: 2.5 }}>
            <Typography variant="h5" component="h2" sx={{ mb: 1.5 }}>Where new leads came from (7 days)</Typography>
            {o.leads.bySource7d.length === 0 ? (
              <Typography variant="body2" color="text.secondary">No new leads in the last 7 days.</Typography>
            ) : (
              <Stack spacing={1.25} component="ul" sx={{ m: 0, p: 0, listStyle: 'none' }}>
                {o.leads.bySource7d.map((s) => (
                  <Box component="li" key={s.source}>
                    <Stack direction="row" sx={{ justifyContent: 'space-between' }}>
                      <Typography variant="body2">{SOURCE_LABEL[s.source as keyof typeof SOURCE_LABEL] ?? s.source}</Typography>
                      <Typography variant="body2" sx={{ fontVariantNumeric: 'tabular-nums' }}>{s.n.toLocaleString()}</Typography>
                    </Stack>
                    <Box sx={{ height: 6, borderRadius: 3, bgcolor: 'surface.sunken', mt: 0.5 }} aria-hidden>
                      <Box sx={{ height: 6, borderRadius: 3, bgcolor: 'primary.main', width: `${(s.n / max) * 100}%` }} />
                    </Box>
                  </Box>
                ))}
              </Stack>
            )}
          </Paper>
        )}
        <Paper variant="outlined" sx={{ p: 2.5, borderRadius: 2.5 }}>
          <Stack direction="row" sx={{ justifyContent: 'space-between', alignItems: 'baseline', mb: 1.5 }}>
            <Typography variant="h5" component="h2">Latest problems</Typography>
            <Button size="small" component={RouterLink} to="/operations?tab=activity">Open the activity log</Button>
          </Stack>
          {o.recent.length === 0 ? (
            <Typography variant="body2" color="text.secondary">Nothing has gone wrong recently.</Typography>
          ) : (
            <Stack spacing={1.25} component="ul" sx={{ m: 0, p: 0, listStyle: 'none' }}>
              {o.recent.map((e) => (
                <Box component="li" key={e.id}>
                  <Stack direction="row" spacing={1} sx={{ alignItems: 'center' }}>
                    <Chip size="small" label={e.severity === 'warn' ? 'Warning' : 'Error'} color={e.severity === 'warn' ? 'warning' : 'error'} variant="outlined" />
                    <Typography variant="body2" sx={{ minWidth: 0, flex: 1 }} noWrap title={e.message}>{e.message}</Typography>
                  </Stack>
                  <Typography variant="caption" color="text.secondary">{e.component} · <RelativeTime value={e.at} /></Typography>
                </Box>
              ))}
            </Stack>
          )}
        </Paper>
      </Box>
    </Stack>
  );
}
