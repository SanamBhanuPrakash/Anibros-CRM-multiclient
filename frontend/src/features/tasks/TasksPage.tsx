import { useMemo, useState } from 'react';
import { Box, Button, Chip, InputAdornment, MenuItem, Stack, TextField, ToggleButton, ToggleButtonGroup, Typography } from '@mui/material';
import { Pill, ViewTabs } from '@/ui/kit';
import { useSearchParams } from 'react-router-dom';
import EventAvailableOutlinedIcon from '@mui/icons-material/EventAvailableOutlined';
import { CalendarView } from './CalendarView';
import { BookVisitDialog } from '@/features/viewings/BookVisitDialog';
import AddRoundedIcon from '@mui/icons-material/AddRounded';
import ChecklistRoundedIcon from '@mui/icons-material/ChecklistRounded';
import SearchRoundedIcon from '@mui/icons-material/SearchRounded';
import { addDays, endOfDay, isBefore, startOfDay } from 'date-fns';
import { useInfiniteQuery, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { ApiProblem } from '@/api/client';
import { tasksApi, type Task, type TaskFilters } from '@/api/work';
import { useAuth } from '@/features/auth/AuthProvider';
import { useDirectory } from '@/features/leads/shared';
import { PageHeader } from '@/ui/PageHeader';
import { EmptyState, ErrorState, SkeletonRows } from '@/ui/states';
import { useToast } from '@/ui/toast';
import { TaskDialog } from './TaskDialog';
import { TaskRow } from './TaskRow';

type Tab = 'mine' | 'team' | 'done';

function bucketOf(t: Task, now: Date): string {
  if (!t.dueDate) return 'No date';
  const d = new Date(t.dueDate);
  if (isBefore(d, now)) return 'Overdue';
  if (d <= endOfDay(now)) return 'Today';
  if (d <= endOfDay(addDays(now, 1))) return 'Tomorrow';
  if (d <= endOfDay(addDays(startOfDay(now), 7))) return 'This week';
  return 'Later';
}
const BUCKETS = ['Overdue', 'Today', 'Tomorrow', 'This week', 'Later', 'No date'];

export function TasksPage() {
  const { scope, me } = useAuth();
  const qc = useQueryClient();
  const toast = useToast();
  const manager = scope('tasks:read') === 'all';
  const [params, setParams] = useSearchParams();
  // Links (the Dashboard) can open this page already narrowed: ?tab=team&due=overdue&assignee=<id>
  const wantedTab = params.get('tab');
  const [tab, setTab] = useState<Tab>(wantedTab === 'done' ? 'done' : wantedTab === 'team' && manager ? 'team' : 'mine');
  const [dueNow, setDueNow] = useState<'overdue' | 'today' | undefined>(params.get('due') === 'overdue' ? 'overdue' : params.get('due') === 'today' ? 'today' : undefined);
  const [q, setQ] = useState('');
  const [assignee, setAssignee] = useState(manager ? (params.get('assignee') ?? 'any') : 'any');
  const [quick, setQuick] = useState('');
  const [dialog, setDialog] = useState<{ task?: Task } | null>(null);
  const [bookOpen, setBookOpen] = useState(false);
  const view = params.get('view') === 'calendar' ? 'calendar' : 'list';
  const directory = useDirectory();
  const { can, tenant } = useAuth();
  const realEstate = tenant?.industry === 'real_estate';

  const filters: TaskFilters = useMemo(
    () => ({
      q: q.trim() || undefined,
      status: tab === 'done' ? 'done' : 'open',
      assignee: tab === 'mine' ? 'me' : tab === 'done' ? (manager ? assignee : 'me') : assignee,
      due: tab === 'done' ? undefined : dueNow,
      sort: tab === 'done' ? 'created' : 'due',
      order: tab === 'done' ? 'desc' : 'asc',
    }),
    [tab, q, assignee, manager, dueNow]
  );
  const list = useInfiniteQuery({
    queryKey: ['tasks', 'list', filters],
    queryFn: ({ pageParam }) => tasksApi.list(filters, pageParam),
    initialPageParam: undefined as string | undefined,
    getNextPageParam: (l) => l.page?.next ?? undefined,
    placeholderData: (p) => p,
  });
  const summary = useQuery({ queryKey: ['tasks', 'summary'], queryFn: tasksApi.summary });
  const rows = useMemo(() => list.data?.pages.flatMap((p) => p.data) ?? [], [list.data]);
  const groups = useMemo(() => {
    if (tab === 'done') return [['Recently finished', rows] as const];
    const now = new Date();
    return BUCKETS.map((b) => [b, rows.filter((t) => bucketOf(t, now) === b)] as const).filter(([, ts]) => ts.length > 0);
  }, [rows, tab]);

  const add = useMutation({
    mutationFn: (title: string) => tasksApi.create({ title }),
    onSuccess: () => { setQuick(''); void qc.invalidateQueries({ queryKey: ['tasks'] }); },
    onError: (e) => toast.error(e instanceof ApiProblem ? (e.errors[0]?.message ?? e.detail) : 'Couldn’t add the task'),
  });
  const s = summary.data;

  return (
    <>
      <PageHeader
        title="Tasks"
        description={view === 'calendar' ? 'Everything planned, month by month. Double-click a day to add to it.' : 'Calls to make and follow-ups to send, in the order they matter. Tie a task to a lead and it shows on that lead.'}
        actions={
          <>
            <ToggleButtonGroup exclusive size="small" value={view} aria-label="Show as" onChange={(_, v: 'list' | 'calendar' | null) => v && setParams(v === 'calendar' ? { view: 'calendar' } : {}, { replace: true })}>
              <ToggleButton value="list" aria-label="List">List</ToggleButton>
              <ToggleButton value="calendar" aria-label="Calendar">Calendar</ToggleButton>
            </ToggleButtonGroup>
            {can('viewings:create') && realEstate && <Button startIcon={<EventAvailableOutlinedIcon />} onClick={() => setBookOpen(true)}>Book a visit</Button>}
            <Button variant="contained" startIcon={<AddRoundedIcon />} onClick={() => setDialog({})}>New task</Button>
          </>
        }
      />
      <BookVisitDialog open={bookOpen} onClose={() => setBookOpen(false)} />
      {view === 'calendar' && <CalendarView />}
      {view === 'list' && s && (
        <Stack direction="row" spacing={1} sx={{ mb: 2, flexWrap: 'wrap', rowGap: 1 }} aria-label="Task summary">
          <Pill tone={s.mine.overdue ? 'danger' : 'neutral'} onClick={() => { setTab('mine'); setDueNow('overdue'); }}>{s.mine.overdue} overdue</Pill>
          <Pill tone={s.mine.today ? 'accent' : 'neutral'} onClick={() => { setTab('mine'); setDueNow('today'); }}>{s.mine.today} due today</Pill>
          <Pill onClick={() => { setTab('mine'); setDueNow(undefined); }}>{s.mine.upcoming} this week</Pill>
          {s.team && <Pill tone={s.team.overdue || s.team.unassigned ? 'warning' : 'neutral'} onClick={() => { setTab('team'); setDueNow(undefined); }}>Team: {s.team.overdue} overdue · {s.team.unassigned} unassigned</Pill>}
        </Stack>
      )}
      {view === 'list' && <ViewTabs label="Task view" value={tab} onChange={setTab} items={[{ id: 'mine' as Tab, label: 'My tasks' }, ...(manager ? [{ id: 'team' as Tab, label: 'Team' }] : []), { id: 'done' as Tab, label: 'Done' }]} />}

      {view === 'list' && (<>
      {dueNow && tab !== 'done' && (
        <Stack direction="row" spacing={1} sx={{ mb: 2 }} aria-label="Filters from a link">
          <Chip size="small" color="primary" variant="outlined" label={dueNow === 'overdue' ? 'Overdue only' : 'Due today only'} onDelete={() => setDueNow(undefined)} />
        </Stack>
      )}
      <Stack direction="row" spacing={1.5} sx={{ mb: 2, flexWrap: 'wrap', rowGap: 1.5 }}>
        {tab !== 'done' && (
          <TextField
            size="small" fullWidth={false} placeholder="Add a task and press Enter" value={quick} onChange={(e) => setQuick(e.target.value)} disabled={add.isPending}
            onKeyDown={(e) => { if (e.key === 'Enter' && quick.trim() && !e.nativeEvent.isComposing) { e.preventDefault(); add.mutate(quick.trim()); } }}
            sx={{ flex: '1 1 280px', maxWidth: 460 }} slotProps={{ input: { startAdornment: <InputAdornment position="start"><AddRoundedIcon fontSize="small" /></InputAdornment> }, htmlInput: { 'aria-label': 'Quick add a task', maxLength: 200 } }}
          />
        )}
        <TextField size="small" fullWidth={false} placeholder="Search tasks" value={q} onChange={(e) => setQ(e.target.value)} sx={{ width: { xs: '100%', sm: 220 } }} slotProps={{ input: { startAdornment: <InputAdornment position="start"><SearchRoundedIcon fontSize="small" /></InputAdornment> }, htmlInput: { 'aria-label': 'Search tasks', maxLength: 100 } }} />
        {manager && tab !== 'mine' && (
          <TextField select size="small" fullWidth={false} value={assignee} onChange={(e) => setAssignee(e.target.value)} sx={{ minWidth: 170 }} slotProps={{ htmlInput: { 'aria-label': 'Assignee' } }}>
            <MenuItem value="any">Everyone</MenuItem>
            <MenuItem value="unassigned">Unassigned</MenuItem>
            {(directory.data ?? []).map((m) => <MenuItem key={m.userId} value={m.userId}>{m.name}{m.userId === me?.user.id ? ' (you)' : ''}</MenuItem>)}
          </TextField>
        )}
      </Stack>

      {list.isPending ? <SkeletonRows rows={6} height={52} /> : list.isError ? <ErrorState error={list.error} onRetry={() => void list.refetch()} /> : rows.length === 0 ? (
        <EmptyState
          icon={<ChecklistRoundedIcon />}
          title={tab === 'done' ? 'Nothing finished yet' : q ? 'No tasks match' : tab === 'mine' ? 'You’re all caught up' : 'No open tasks'}
          description={tab === 'done' ? 'Tasks you complete show up here.' : q ? 'Try a different word.' : 'Type a task above and press Enter, or open a lead to add a follow-up.'}
        />
      ) : (
        <Stack spacing={3}>
          {groups.map(([name, tasks]) => (
            <Box key={name} component="section" aria-labelledby={`g-${name}`}>
              <Typography id={`g-${name}`} variant="eyebrow" component="h2" sx={{ color: name === 'Overdue' ? 'error.main' : 'text.secondary', mb: 0.5 }}>{name} · {tasks.length}</Typography>
              <Box>{tasks.map((t) => <TaskRow key={t.id} task={t} showAssignee={tab !== 'mine'} onEdit={(x) => setDialog({ task: x })} />)}</Box>
            </Box>
          ))}
          {list.hasNextPage && <Button onClick={() => void list.fetchNextPage()} disabled={list.isFetchingNextPage} sx={{ alignSelf: 'flex-start' }}>{list.isFetchingNextPage ? 'Loading…' : 'Load more'}</Button>}
        </Stack>
      )}
      </>)}
      <TaskDialog open={Boolean(dialog)} task={dialog?.task} onClose={() => setDialog(null)} />
    </>
  );
}
