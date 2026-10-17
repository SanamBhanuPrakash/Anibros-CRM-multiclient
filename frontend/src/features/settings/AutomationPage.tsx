import { useMemo, useState } from 'react';
import { Link as RouterLink } from 'react-router-dom';
import { Alert, Box, Button, Card, CardContent, Chip, MenuItem, Paper, Stack, Table, TableBody, TableCell, TableContainer, TableHead, TableRow, TextField, Tooltip, Typography } from '@mui/material';
import BoltOutlinedIcon from '@mui/icons-material/BoltOutlined';
import CheckCircleOutlineRoundedIcon from '@mui/icons-material/CheckCircleOutlineRounded';
import { useInfiniteQuery, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api, ApiProblem, unwrap } from '@/api/client';
import { automationApi, type AutomationExecution, type AutomationTemplate, type ExecutionStatus } from '@/api/automation';
import { useAuth } from '@/features/auth/AuthProvider';
import { RelativeTime } from '@/ui/display';
import { PageHeader } from '@/ui/PageHeader';
import { EmptyState, ErrorState, SkeletonRows } from '@/ui/states';
import { useToast } from '@/ui/toast';

const STATUS_LABEL: Record<ExecutionStatus, string> = { pending: 'Waiting to retry', running: 'Running', completed: 'Done', failed: 'Failed', skipped: 'Skipped' };
const STATUS_COLOR: Record<ExecutionStatus, 'default' | 'success' | 'warning' | 'error' | 'info'> = { pending: 'warning', running: 'info', completed: 'success', failed: 'error', skipped: 'default' };
const ASSIGNMENT_LABEL: Record<string, string> = { creator: 'Whoever adds the lead owns it', balanced: 'Least busy salesperson', unassigned: 'Leave unassigned' };

interface WorkspaceSettings {
  leadAssignment: string;
  firstResponseMinutes: number;
}

export function AutomationPage() {
  const { can } = useAuth();
  const manage = can('automation:manage');
  const qc = useQueryClient();
  const toast = useToast();
  const templates = useQuery({ queryKey: ['automations'], queryFn: automationApi.list, refetchInterval: 6_000 });
  const settings = useQuery({ queryKey: ['workspace-settings'], queryFn: () => unwrap(api.get<WorkspaceSettings>('/api/v1/workspace')) });
  const refresh = () => {
    void qc.invalidateQueries({ queryKey: ['automations'] });
    void qc.invalidateQueries({ queryKey: ['automation-runs'] });
  };
  const toggle = useMutation({
    mutationFn: (t: AutomationTemplate) => (t.rule?.status === 'active' ? automationApi.pause(t.key) : automationApi.enable(t.key)),
    onSuccess: (t) => {
      refresh();
      toast.success(t.rule?.status === 'active' ? 'Turned on. It applies to leads created from now on.' : 'Paused. New leads are left alone.');
    },
    onError: (e) => toast.error(e instanceof ApiProblem ? e.detail : 'Couldn’t change the automation'),
  });

  return (
    <>
      <PageHeader title="Automation" description="Routine work that happens on its own, every time, with a record of what it did." />
      {templates.isPending ? (
        <SkeletonRows rows={3} />
      ) : templates.isError ? (
        <ErrorState error={templates.error} onRetry={() => void templates.refetch()} />
      ) : (
        <Stack spacing={2.5}>
          {templates.data.map((t) => (
            <TemplateCard key={t.key} t={t} settings={settings.data} manage={manage} busy={toggle.isPending} onToggle={() => toggle.mutate(t)} />
          ))}
          <History />
        </Stack>
      )}
    </>
  );
}

function TemplateCard({ t, settings, manage, busy, onToggle }: { t: AutomationTemplate; settings?: WorkspaceSettings; manage: boolean; busy: boolean; onToggle: () => void }) {
  const on = t.rule?.status === 'active';
  const paused = t.rule?.status === 'paused';
  return (
    <Card variant="outlined" sx={{ borderRadius: 2.5 }}>
      <CardContent>
        <Stack direction={{ xs: 'column', sm: 'row' }} spacing={2} sx={{ justifyContent: 'space-between', alignItems: { sm: 'flex-start' } }}>
          <Box sx={{ minWidth: 0 }}>
            <Stack direction="row" spacing={1} sx={{ alignItems: 'center', flexWrap: 'wrap', rowGap: 0.5 }}>
              <BoltOutlinedIcon fontSize="small" color={on ? 'primary' : 'disabled'} aria-hidden />
              <Typography variant="h3" component="h2">{t.name}</Typography>
              <Chip size="small" label={on ? 'On' : paused ? 'Paused' : 'Off'} color={on ? 'success' : 'default'} variant={on ? 'filled' : 'outlined'} />
            </Stack>
            <Typography color="text.secondary" sx={{ mt: 0.75, maxWidth: '62ch' }}>{t.summary}</Typography>
          </Box>
          {manage ? (
            <Button variant={on ? 'outlined' : 'contained'} onClick={onToggle} disabled={busy} sx={{ flexShrink: 0 }}>
              {on ? 'Pause' : paused ? 'Turn on again' : 'Turn on'}
            </Button>
          ) : (
            <Typography variant="body2" color="text.secondary">Only an owner, admin or marketing manager can change this.</Typography>
          )}
        </Stack>

        <Box component="dl" sx={{ mt: 2.5, mb: 0, display: 'grid', gap: 1.25, gridTemplateColumns: { xs: '1fr', md: '180px 1fr' }, '& dt': { color: 'text.secondary', typography: 'body2' }, '& dd': { m: 0 } }}>
          <dt>When</dt>
          <dd><Typography variant="body2">{t.triggerLabel}</Typography></dd>
          <dt>What it does</dt>
          <dd>
            <Box component="ol" sx={{ m: 0, pl: 2.5, listStyleType: 'decimal' }}>
              {t.steps.map((s) => <li key={s.id}><Typography variant="body2">{s.label}</Typography></li>)}
            </Box>
          </dd>
          <dt>Uses your settings</dt>
          <dd>
            <Typography variant="body2">
              {settings ? `New leads go to: ${ASSIGNMENT_LABEL[settings.leadAssignment] ?? settings.leadAssignment}. First reply within ${settings.firstResponseMinutes > 0 ? settings.firstResponseMinutes : 30} minutes.` : 'Loading your settings…'}{' '}
              <Typography component={RouterLink} to="/settings/workspace" variant="body2" sx={{ color: 'primary.main', textDecoration: 'underline' }}>Change in Workspace settings</Typography>
            </Typography>
          </dd>
          <dt>Record</dt>
          <dd>
            <Typography variant="body2">
              {t.rule ? <>{t.rule.runCount} {t.rule.runCount === 1 ? 'run' : 'runs'} so far{t.rule.lastRunAt ? <>, last <RelativeTime value={t.rule.lastRunAt} /></> : ', none yet'}.</> : 'Not turned on yet, so it has not done anything.'}
              {t.failedRuns > 0 && <Typography component="span" variant="body2" color="error"> {t.failedRuns} failed, see below.</Typography>}
            </Typography>
          </dd>
        </Box>
        {on && <Alert severity="info" sx={{ mt: 2 }}>It applies to leads created from now on. It does not change leads that already exist.</Alert>}
      </CardContent>
    </Card>
  );
}

function History() {
  const { can } = useAuth();
  const manage = can('automation:manage');
  const qc = useQueryClient();
  const toast = useToast();
  const [status, setStatus] = useState<ExecutionStatus | ''>('');
  const filters = status ? { status } : {};
  const list = useInfiniteQuery({
    queryKey: ['automation-runs', filters],
    queryFn: ({ pageParam }) => automationApi.executions(filters, pageParam),
    initialPageParam: undefined as string | undefined,
    getNextPageParam: (l) => l.page?.next ?? undefined,
    placeholderData: (p) => p,
    refetchInterval: 8_000,
  });
  const rows = useMemo(() => list.data?.pages.flatMap((p) => p.data) ?? [], [list.data]);
  const retry = useMutation({
    mutationFn: (id: string) => automationApi.retry(id),
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: ['automation-runs'] });
      void qc.invalidateQueries({ queryKey: ['automations'] });
      toast.success('Back in the queue. It will run again in a moment.');
    },
    onError: (e) => toast.error(e instanceof ApiProblem ? e.detail : 'Couldn’t retry that run'),
  });

  return (
    <Box component="section" aria-labelledby="history-title">
      <Stack direction="row" sx={{ mb: 1.5, justifyContent: 'space-between', alignItems: 'center', flexWrap: 'wrap', rowGap: 1 }}>
        <Typography id="history-title" variant="h3" component="h2">What it did</Typography>
        <TextField select size="small" fullWidth={false} value={status} onChange={(e) => setStatus(e.target.value as ExecutionStatus | '')} sx={{ minWidth: 190 }} slotProps={{ select: { displayEmpty: true }, htmlInput: { 'aria-label': 'Filter by result' } }}>
          <MenuItem value="">Every result</MenuItem>
          {(['completed', 'failed', 'pending', 'skipped'] as ExecutionStatus[]).map((s) => <MenuItem key={s} value={s}>{STATUS_LABEL[s]}</MenuItem>)}
        </TextField>
      </Stack>
      {list.isPending ? (
        <SkeletonRows rows={4} />
      ) : list.isError ? (
        <ErrorState error={list.error} onRetry={() => void list.refetch()} />
      ) : rows.length === 0 ? (
        <Paper variant="outlined">
          <EmptyState icon={<CheckCircleOutlineRoundedIcon />} title={status ? 'Nothing matches' : 'No runs yet'} description={status ? 'No runs have this result.' : 'Once the automation is on, each new lead gets a run here that says what was done for it.'} />
        </Paper>
      ) : (
        <>
          <TableContainer component={Paper} variant="outlined" sx={{ borderRadius: 2.5 }}>
            <Table size="small" aria-label="Automation runs">
              <TableHead>
                <TableRow><TableCell>When</TableCell><TableCell>Lead</TableCell><TableCell>Result</TableCell><TableCell align="right" /></TableRow>
              </TableHead>
              <TableBody>
                {rows.map((r) => <RunRow key={r.id} r={r} canRetry={manage} onRetry={() => retry.mutate(r.id)} busy={retry.isPending} />)}
              </TableBody>
            </Table>
          </TableContainer>
          {list.hasNextPage && (
            <Box sx={{ textAlign: 'center', mt: 1.5 }}>
              <Button onClick={() => void list.fetchNextPage()} disabled={list.isFetchingNextPage}>{list.isFetchingNextPage ? 'Loading…' : 'Show older runs'}</Button>
            </Box>
          )}
        </>
      )}
    </Box>
  );
}

function RunRow({ r, canRetry, onRetry, busy }: { r: AutomationExecution; canRetry: boolean; onRetry: () => void; busy: boolean }) {
  return (
    <TableRow hover>
      <TableCell sx={{ whiteSpace: 'nowrap', verticalAlign: 'top' }}><RelativeTime value={r.createdAt} /></TableCell>
      <TableCell sx={{ verticalAlign: 'top' }}>
        {r.leadId ? <Typography component={RouterLink} to={`/leads/${r.leadId}`} variant="body2" sx={{ color: 'primary.main' }}>{r.leadName || 'Open lead'}</Typography> : '—'}
      </TableCell>
      <TableCell sx={{ maxWidth: 520 }}>
        <Chip size="small" label={STATUS_LABEL[r.status]} color={STATUS_COLOR[r.status]} variant={r.status === 'completed' || r.status === 'skipped' ? 'outlined' : 'filled'} />
        {r.status === 'pending' && r.attempts > 0 && <Typography variant="caption" color="text.secondary" sx={{ ml: 1 }}>attempt {r.attempts} failed, trying again automatically</Typography>}
        {r.summary && <Typography variant="body2" color="text.secondary" sx={{ mt: 0.5 }}>{r.summary}</Typography>}
        {r.error && (
          <Tooltip title="The reason the last attempt failed">
            <Typography variant="body2" color="error" sx={{ mt: 0.5 }}>{r.error}</Typography>
          </Tooltip>
        )}
      </TableCell>
      <TableCell align="right" sx={{ whiteSpace: 'nowrap', verticalAlign: 'top' }}>
        {r.status === 'failed' && canRetry && <Button size="small" onClick={onRetry} disabled={busy}>Retry</Button>}
      </TableCell>
    </TableRow>
  );
}
