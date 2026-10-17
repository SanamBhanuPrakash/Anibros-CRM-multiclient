import { useState, type ReactNode } from 'react';
import { Link as RouterLink } from 'react-router-dom';
import { Alert, Box, Button, Chip, LinearProgress, Paper, Stack, Typography } from '@mui/material';
import CheckCircleRoundedIcon from '@mui/icons-material/CheckCircleRounded';
import RadioButtonUncheckedRoundedIcon from '@mui/icons-material/RadioButtonUncheckedRounded';
import { format, formatDistanceToNowStrict } from 'date-fns';
import { useQuery } from '@tanstack/react-query';
import { homeApi, type Home } from '@/api/home';
import { useAuth } from '@/features/auth/AuthProvider';
import { formatMoney } from '@/features/leads/shared';
import { VisitDialog } from '@/features/viewings/VisitDialog';
import { PageHeader } from '@/ui/PageHeader';
import { ErrorState, SkeletonRows } from '@/ui/states';

const greeting = () => {
  const h = new Date().getHours();
  return h < 12 ? 'Good morning' : h < 17 ? 'Good afternoon' : 'Good evening';
};

function Section({ title, count, children, tone }: { title: string; count?: number; children: ReactNode; tone?: 'error' }) {
  const id = `s-${title.replace(/\W+/g, '-').toLowerCase()}`;
  return (
    <Paper variant="outlined" component="section" aria-labelledby={id} sx={{ p: 2.25, borderRadius: 2.5 }}>
      <Stack direction="row" sx={{ alignItems: 'baseline', justifyContent: 'space-between', mb: 1 }}>
        <Typography id={id} variant="h5" component="h2" sx={{ color: tone === 'error' && count ? 'error.main' : undefined }}>{title}</Typography>
        {count !== undefined && <Typography variant="body2" color="text.secondary" sx={{ fontVariantNumeric: 'tabular-nums' }}>{count}</Typography>}
      </Stack>
      {children}
    </Paper>
  );
}
const Empty = ({ children }: { children: ReactNode }) => <Typography variant="body2" color="text.secondary">{children}</Typography>;
const Row = ({ to, onClick, primary, secondary, end }: { to?: string; onClick?: () => void; primary: ReactNode; secondary?: ReactNode; end?: ReactNode }) => (
  <Box component={to ? RouterLink : 'button'} {...(to ? { to } : { type: 'button', onClick })} sx={{ all: 'unset', boxSizing: 'border-box', cursor: 'pointer', display: 'flex', gap: 1.5, alignItems: 'center', justifyContent: 'space-between', width: '100%', py: 0.75, px: 1, mx: -1, borderRadius: 1.5, '&:hover, &:focus-visible': { bgcolor: 'action.hover' }, outlineOffset: 2 }}>
    <Box sx={{ minWidth: 0 }}>
      <Typography variant="body2" noWrap sx={{ fontWeight: 600 }}>{primary}</Typography>
      {secondary && <Typography variant="caption" color="text.secondary" noWrap component="div">{secondary}</Typography>}
    </Box>
    {end}
  </Box>
);
const dueText = (iso: string) => {
  const d = new Date(iso);
  const dist = formatDistanceToNowStrict(d);
  return d.getTime() < Date.now() ? `${dist} overdue` : `in ${dist}`;
};

export function HomePage() {
  const { me } = useAuth();
  const q = useQuery({ queryKey: ['home'], queryFn: homeApi.get, refetchInterval: 30_000 });
  const [visit, setVisit] = useState<string | null>(null);
  const first = me?.user.name?.split(' ')[0];

  return (
    <>
      <PageHeader title={`${greeting()}${first ? `, ${first}` : ''}`} description={q.data ? `${format(new Date(), 'EEEE d MMMM')} · what needs you today` : 'What needs you today'} />
      {q.isPending ? <SkeletonRows rows={5} height={64} /> : q.isError ? <ErrorState error={q.error} onRetry={() => void q.refetch()} /> : <Body data={q.data} onVisit={setVisit} />}
      <VisitDialog visitId={visit} onClose={() => setVisit(null)} />
    </>
  );
}

function Body({ data, onVisit }: { data: Home; onVisit: (id: string) => void }) {
  const d = data;
  const mine = d.myDay;
  const quiet = !mine.tasks?.overdue.count && !mine.tasks?.today.count && !mine.viewings?.today.count && !mine.waitingForReply?.count;
  const todayItems = [
    ...(mine.viewings?.today.items ?? []).map((v) => ({ at: new Date(v.scheduledAt).getTime(), node: (
      <Row key={`v-${v.id}`} onClick={() => onVisit(v.id)} primary={`Visit: ${v.propertyTitle}`} secondary={`${format(new Date(v.scheduledAt), 'HH:mm')} · ${v.leadName ?? 'Buyer'}`} end={v.needsOutcome ? <Chip size="small" color="warning" label="Record outcome" /> : undefined} />
    ) })),
    ...(mine.tasks?.today.items ?? []).map((t) => ({ at: t.dueDate ? new Date(t.dueDate).getTime() : 0, node: <Row key={`t-${t.id}`} to={t.leadId ? `/leads/${t.leadId}` : '/tasks'} primary={t.title} secondary={`${t.dueDate ? format(new Date(t.dueDate), 'HH:mm') : ''}${t.leadName ? ` · ${t.leadName}` : ''}`} /> })),
  ].sort((a, b) => a.at - b.at);

  return (
    <Stack spacing={2.5}>
      {d.getStarted && !d.getStarted.complete && (
        <Paper variant="outlined" component="section" aria-labelledby="gs" sx={{ p: 2.25, borderRadius: 2.5 }}>
          <Stack direction="row" sx={{ justifyContent: 'space-between', alignItems: 'baseline' }}>
            <Typography id="gs" variant="h5" component="h2">Get started</Typography>
            <Typography variant="body2" color="text.secondary">{d.getStarted.items.filter((i) => i.done).length} of {d.getStarted.items.length} done</Typography>
          </Stack>
          <LinearProgress variant="determinate" value={(d.getStarted.items.filter((i) => i.done).length / d.getStarted.items.length) * 100} sx={{ my: 1.25, borderRadius: 1 }} aria-label="Setup progress" />
          <Box component="ul" sx={{ m: 0, p: 0, listStyle: 'none', display: 'grid', gap: 0.5, gridTemplateColumns: { xs: '1fr', md: '1fr 1fr' } }}>
            {d.getStarted.items.map((i) => (
              <Box component="li" key={i.key}>
                <Button component={RouterLink} to={i.href} color="inherit" disabled={false} startIcon={i.done ? <CheckCircleRoundedIcon color="success" /> : <RadioButtonUncheckedRoundedIcon color="disabled" />} sx={{ justifyContent: 'flex-start', textDecoration: i.done ? 'line-through' : 'none', color: i.done ? 'text.secondary' : 'text.primary' }}>{i.label}</Button>
              </Box>
            ))}
          </Box>
        </Paper>
      )}

      {d.attention && (d.attention.deliveriesNeedingReview > 0 || d.attention.failedAutomationRuns > 0) && (
        <Alert severity="warning" action={<Button color="inherit" size="small" component={RouterLink} to="/operations">Open Monitor</Button>}>
          {d.attention.deliveriesNeedingReview > 0 && <>{d.attention.deliveriesNeedingReview} lead {d.attention.deliveriesNeedingReview === 1 ? 'delivery needs' : 'deliveries need'} review (<RouterLink to="/integrations/lead-sources?tab=log">see them</RouterLink>). </>}
          {d.attention.failedAutomationRuns > 0 && <>{d.attention.failedAutomationRuns} automation {d.attention.failedAutomationRuns === 1 ? 'run' : 'runs'} failed (<RouterLink to="/settings/automation">see them</RouterLink>).</>}
        </Alert>
      )}

      <Box sx={{ display: 'grid', gap: 2.5, gridTemplateColumns: { xs: '1fr', lg: 'minmax(0, 3fr) minmax(0, 2fr)' }, alignItems: 'start' }}>
        <Stack spacing={2.5}>
          {quiet && !d.getStarted?.items.some((i) => !i.done) && <Alert severity="success" icon={false}>You are all caught up. Nothing is overdue and nothing is waiting for you today.</Alert>}

          {mine.waitingForReply && mine.waitingForReply.count > 0 && (
            <Section title="Waiting for your first reply" count={mine.waitingForReply.count} tone="error">
              {mine.waitingForReply.items.map((r) => (
                <Row key={r.leadId} to={`/leads/${r.leadId}`} primary={r.leadName ?? 'New lead'} secondary="Reply, call or meet to stop the clock" end={<Chip size="small" color={r.overdue ? 'error' : 'default'} variant={r.overdue ? 'filled' : 'outlined'} label={dueText(r.dueAt)} />} />
              ))}
              {mine.waitingForReply.count > mine.waitingForReply.items.length && <Empty>…and {mine.waitingForReply.count - mine.waitingForReply.items.length} more in <RouterLink to="/leads?sla=breached">Leads</RouterLink>.</Empty>}
            </Section>
          )}

          {mine.tasks && mine.tasks.overdue.count > 0 && (
            <Section title="Overdue" count={mine.tasks.overdue.count} tone="error">
              {mine.tasks.overdue.items.map((t) => <Row key={t.id} to={t.leadId ? `/leads/${t.leadId}` : '/tasks'} primary={t.title} secondary={t.leadName} end={<Chip size="small" color="error" label={t.dueDate ? dueText(t.dueDate) : 'overdue'} />} />)}
              {mine.tasks.overdue.count > mine.tasks.overdue.items.length && <Empty>…and {mine.tasks.overdue.count - mine.tasks.overdue.items.length} more in <RouterLink to="/tasks">Tasks</RouterLink>.</Empty>}
            </Section>
          )}

          {(mine.tasks || mine.viewings) && (
            <Section title="Today" count={(mine.tasks?.today.count ?? 0) + (mine.viewings?.today.count ?? 0)}>
              {todayItems.length === 0 ? <Empty>Nothing else is planned for today.</Empty> : todayItems.map((i) => i.node)}
              {mine.viewings && mine.viewings.next7Days > 0 && <Empty>{mine.viewings.next7Days} {mine.viewings.next7Days === 1 ? 'visit' : 'visits'} in the next 7 days. <RouterLink to="/tasks?view=calendar">Open the calendar</RouterLink>.</Empty>}
            </Section>
          )}
        </Stack>

        <Stack spacing={2.5}>
          {d.team && (
            <Section title="Your team" count={d.team.unassignedLeads.count + d.team.lateFirstReplies + d.team.unrecordedVisits}>
              <Stack spacing={0.5}>
                <Typography variant="body2"><strong>{d.team.unassignedLeads.count}</strong> {d.team.unassignedLeads.count === 1 ? 'lead has' : 'leads have'} no owner</Typography>
                {d.team.unassignedLeads.items.map((l) => <Row key={l.leadId} to={`/leads/${l.leadId}`} primary={l.leadName ?? 'New lead'} secondary={`Arrived ${formatDistanceToNowStrict(new Date(l.createdAt))} ago`} end={<Button size="small" component="span">Assign</Button>} />)}
                <Typography variant="body2" sx={{ mt: 1 }}><strong>{d.team.lateFirstReplies}</strong> first {d.team.lateFirstReplies === 1 ? 'reply is' : 'replies are'} late{d.team.overdueTasks !== null ? <> · <strong>{d.team.overdueTasks}</strong> {d.team.overdueTasks === 1 ? 'task is' : 'tasks are'} overdue</> : null}</Typography>
                <Typography variant="body2"><strong>{d.team.unrecordedVisits}</strong> {d.team.unrecordedVisits === 1 ? 'visit has' : 'visits have'} not been recorded yet</Typography>
              </Stack>
            </Section>
          )}

          {d.pipeline && (
            <Section title="Pipeline">
              {d.pipeline.openLeads === 0 ? <Empty>No open leads yet. Add one from <RouterLink to="/leads">Leads</RouterLink> or connect a lead source.</Empty> : (
                <>
                  <Stack spacing={1}>
                    {d.pipeline.stages.filter((s) => s.count > 0).map((s) => (
                      <Box key={s.id}>
                        <Stack direction="row" sx={{ justifyContent: 'space-between' }}><Typography variant="body2">{s.name}</Typography><Typography variant="body2" sx={{ fontVariantNumeric: 'tabular-nums' }}>{s.count} · {formatMoney(s.value)}</Typography></Stack>
                        <LinearProgress variant="determinate" value={(s.count / Math.max(...d.pipeline!.stages.map((x) => x.count), 1)) * 100} aria-label={`${s.name}: ${s.count} leads`} sx={{ height: 6, borderRadius: 3, '& .MuiLinearProgress-bar': { bgcolor: s.color } }} />
                      </Box>
                    ))}
                  </Stack>
                  <Typography variant="body2" sx={{ mt: 1.5 }}>{d.pipeline.openLeads} open leads · {formatMoney(d.pipeline.openValue)} estimated</Typography>
                  <Typography variant="caption" color="text.secondary">Weighted forecast {formatMoney(d.pipeline.weightedValue)}: each stage’s chance of winning is an estimate set in your pipeline settings, not a measured result.</Typography>
                </>
              )}
            </Section>
          )}
        </Stack>
      </Box>
    </Stack>
  );
}
