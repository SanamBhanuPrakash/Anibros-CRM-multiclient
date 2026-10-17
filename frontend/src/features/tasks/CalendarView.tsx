import { useMemo, useState } from 'react';
import { Box, Button, Checkbox, FormControlLabel, IconButton, Paper, Stack, ToggleButton, ToggleButtonGroup, Tooltip, Typography, type Theme } from '@mui/material';
import ChevronLeftRoundedIcon from '@mui/icons-material/ChevronLeftRounded';
import ChevronRightRoundedIcon from '@mui/icons-material/ChevronRightRounded';
import AddRoundedIcon from '@mui/icons-material/AddRounded';
import { addDays, addMonths, addWeeks, endOfMonth, endOfWeek, format, isSameDay, isSameMonth, setHours, startOfDay, startOfMonth, startOfWeek } from 'date-fns';
import { useQuery } from '@tanstack/react-query';
import { tasksApi, type Task, type TaskKind } from '@/api/work';
import { viewingsApi, type Viewing } from '@/api/viewings';
import { useAuth } from '@/features/auth/AuthProvider';
import { buyerOf } from '@/features/viewings/shared';
import { VisitDialog } from '@/features/viewings/VisitDialog';
import { Card } from '@/ui/kit';
import { ErrorState } from '@/ui/states';
import { TaskDialog } from './TaskDialog';
import { TaskRow } from './TaskRow';

type Entry = { key: string; at: Date; title: string; sub?: string; tone: Tone; overdue?: boolean; done?: boolean; task?: Task; visit?: Viewing };
type Tone = 'primary' | 'secondary' | 'warning' | 'info' | 'success' | 'neutral';

/** What each kind of entry looks like (colour + label), so a glance at the month tells calls from meetings from follow-ups. */
const TONE: Record<TaskKind | 'visit', Tone> = { call: 'primary', meeting: 'secondary', follow_up: 'warning', email: 'info', todo: 'neutral', visit: 'success' };
const LEGEND: Array<[string, Tone]> = [['Call', 'primary'], ['Follow-up', 'warning'], ['Meeting', 'secondary'], ['Email', 'info'], ['To-do', 'neutral']];

const chip = (tone: Tone, overdue?: boolean) => (t: Theme) => {
  const v = t.vars!.palette;
  const main = overdue ? v.error : tone === 'neutral' ? null : v[tone];
  return main
    ? { bgcolor: `rgba(${main.mainChannel} / 0.12)`, color: main.dark, borderLeft: `3px solid ${main.main}`, ...t.applyStyles('dark', { color: main.light }) }
    : { bgcolor: v.action.hover, color: v.text.primary, borderLeft: `3px solid ${v.text.disabled}` };
};
const dot = (tone: Tone) => (t: Theme) => ({ bgcolor: tone === 'neutral' ? t.vars!.palette.text.disabled : t.vars!.palette[tone].main });

async function allTasks(f: Parameters<typeof tasksApi.list>[0]): Promise<Task[]> {
  const out: Task[] = [];
  let cursor: string | undefined;
  for (let i = 0; i < 3; i++) {
    const r = await tasksApi.list({ ...f, limit: 200 }, cursor);
    out.push(...r.data);
    cursor = r.page?.next ?? undefined;
    if (!cursor) break;
  }
  return out;
}

/**
 * A real calendar: the whole month in a grid (or a single week), one colour per kind of work, and a day panel with everything on the chosen day
 * and a one-click "add". Tasks and (for real-estate clients) site visits are shown together; every entry is a stored record.
 */
export function CalendarView() {
  const { scope, tenant } = useAuth();
  const manager = scope('tasks:read') === 'all';
  const realEstate = tenant?.industry === 'real_estate';
  const [span, setSpan] = useState<'month' | 'week'>('month');
  const [cursor, setCursor] = useState(() => new Date());
  const [picked, setPicked] = useState(() => new Date());
  const [team, setTeam] = useState(false);
  const [visitId, setVisitId] = useState<string | null>(null);
  const [editing, setEditing] = useState<Task | null>(null);
  const [adding, setAdding] = useState<{ due: Date } | null>(null);

  const { gridStart, gridEnd } = useMemo(() => {
    if (span === 'week') return { gridStart: startOfWeek(cursor, { weekStartsOn: 1 }), gridEnd: endOfWeek(cursor, { weekStartsOn: 1 }) };
    return { gridStart: startOfWeek(startOfMonth(cursor), { weekStartsOn: 1 }), gridEnd: endOfWeek(endOfMonth(cursor), { weekStartsOn: 1 }) };
  }, [cursor, span]);
  const range = { from: gridStart.toISOString(), to: addDays(startOfDay(gridEnd), 1).toISOString() };

  const visits = useQuery({ queryKey: ['viewings', 'range', range.from, range.to], queryFn: async () => (await viewingsApi.list(range)).data, enabled: realEstate });
  const tasks = useQuery({ queryKey: ['tasks', 'range', range.from, range.to, team], queryFn: () => allTasks({ status: 'open', assignee: team ? 'any' : 'me', dueFrom: range.from, dueTo: range.to }) });

  const byDay = useMemo(() => {
    const reminderIds = new Set((visits.data ?? []).map((v) => v.reminderTaskId).filter(Boolean));
    const entries: Entry[] = [
      ...(visits.data ?? []).map((v): Entry => ({ key: `v-${v.id}`, at: new Date(v.scheduledAt), title: v.property.title, sub: buyerOf(v), tone: 'success', visit: v })),
      ...(tasks.data ?? []).filter((t) => t.dueDate && !reminderIds.has(t.id)).map((t): Entry => ({ key: `t-${t.id}`, at: new Date(t.dueDate!), title: t.title, sub: t.leadName ?? undefined, tone: TONE[t.kind], overdue: t.isOverdue, task: t })),
    ].sort((a, b) => a.at.getTime() - b.at.getTime());
    const m = new Map<string, Entry[]>();
    for (const e of entries) {
      const k = format(e.at, 'yyyy-MM-dd');
      (m.get(k) ?? m.set(k, []).get(k)!).push(e);
    }
    return m;
  }, [visits.data, tasks.data]);

  const days: Date[] = [];
  for (let d = gridStart; d <= gridEnd; d = addDays(d, 1)) days.push(d);
  const weeks = Array.from({ length: days.length / 7 }, (_, i) => days.slice(i * 7, i * 7 + 7));
  const step = (n: number) => setCursor((c) => (span === 'month' ? addMonths(c, n) : addWeeks(c, n)));
  const goToday = () => { setCursor(new Date()); setPicked(new Date()); };
  const title = span === 'month' ? format(cursor, 'MMMM yyyy') : `${format(gridStart, 'd MMM')} – ${format(gridEnd, 'd MMM yyyy')}`;
  const cap = span === 'month' ? 3 : 10;
  const dayEntries = byDay.get(format(picked, 'yyyy-MM-dd')) ?? [];
  const monthTotal = [...byDay.values()].reduce((n, l) => n + l.length, 0);
  const start = (d: Date) => setAdding({ due: setHours(startOfDay(d), 9) });

  return (
    <Box>
      <Stack direction="row" spacing={1} sx={{ mb: 1.5, alignItems: 'center', flexWrap: 'wrap', rowGap: 1 }}>
        <Typography variant="h3" component="h2" aria-live="polite" sx={{ minWidth: 170 }}>{title}</Typography>
        <Stack direction="row" sx={{ alignItems: 'center' }}>
          <Tooltip title={span === 'month' ? 'Previous month' : 'Previous week'}><IconButton size="small" aria-label="Previous" onClick={() => step(-1)}><ChevronLeftRoundedIcon /></IconButton></Tooltip>
          <Tooltip title={span === 'month' ? 'Next month' : 'Next week'}><IconButton size="small" aria-label="Next" onClick={() => step(1)}><ChevronRightRoundedIcon /></IconButton></Tooltip>
        </Stack>
        <Button size="small" variant="outlined" color="inherit" onClick={goToday}>Today</Button>
        <ToggleButtonGroup exclusive size="small" value={span} onChange={(_, v) => v && setSpan(v)} aria-label="Calendar range">
          <ToggleButton value="month">Month</ToggleButton>
          <ToggleButton value="week">Week</ToggleButton>
        </ToggleButtonGroup>
        {manager && <FormControlLabel sx={{ ml: { sm: 'auto' } }} control={<Checkbox size="small" checked={team} onChange={(e) => setTeam(e.target.checked)} />} label="Whole team" />}
      </Stack>

      <Stack direction="row" spacing={2} sx={{ mb: 1.5, flexWrap: 'wrap', rowGap: 0.5 }} aria-label="Colour key">
        {[...LEGEND, ...(realEstate ? ([['Site visit', 'success']] as Array<[string, Tone]>) : [])].map(([label, tone]) => (
          <Stack key={label} direction="row" spacing={0.75} sx={{ alignItems: 'center' }}>
            <Box component="span" sx={[{ width: 8, height: 8, borderRadius: '50%' }, dot(tone)]} />
            <Typography variant="caption" color="text.secondary">{label}</Typography>
          </Stack>
        ))}
        <Stack direction="row" spacing={0.75} sx={{ alignItems: 'center' }}>
          <Box component="span" sx={{ width: 8, height: 8, borderRadius: '50%', bgcolor: 'error.main' }} />
          <Typography variant="caption" color="text.secondary">Overdue</Typography>
        </Stack>
      </Stack>

      {(tasks.isError || visits.isError) && <ErrorState error={(tasks.error ?? visits.error)!} onRetry={() => { void tasks.refetch(); void visits.refetch(); }} />}

      <Box sx={{ display: 'grid', gap: 2, gridTemplateColumns: { xs: '1fr', lg: 'minmax(0, 1fr) 320px' }, alignItems: 'start' }}>
        <Paper variant="outlined" sx={{ borderRadius: 2.5, overflow: 'hidden', opacity: tasks.isFetching ? 0.75 : 1, transition: 'opacity 120ms' }} role="grid" aria-label={title}>
          <Box role="row" sx={{ display: 'grid', gridTemplateColumns: 'repeat(7, minmax(0, 1fr))', borderBottom: 1, borderColor: 'divider', bgcolor: 'action.hover' }}>
            {weeks[0]!.map((d) => (
              <Typography key={d.toISOString()} role="columnheader" variant="overline" color="text.secondary" sx={{ px: 1, py: 0.75, textAlign: { xs: 'center', sm: 'left' } }}>{format(d, 'EEE')}</Typography>
            ))}
          </Box>
          {weeks.map((w, wi) => (
            <Box key={wi} role="row" sx={{ display: 'grid', gridTemplateColumns: 'repeat(7, minmax(0, 1fr))', borderBottom: wi < weeks.length - 1 ? 1 : 0, borderColor: 'divider' }}>
              {w.map((d, di) => {
                const list = byDay.get(format(d, 'yyyy-MM-dd')) ?? [];
                const today = isSameDay(d, new Date());
                const sel = isSameDay(d, picked);
                const out = span === 'month' && !isSameMonth(d, cursor);
                return (
                  <Box
                    key={d.toISOString()}
                    role="gridcell"
                    tabIndex={0}
                    aria-selected={sel}
                    aria-label={`${format(d, 'EEEE d MMMM')}, ${list.length} planned`}
                    onClick={() => setPicked(d)}
                    onDoubleClick={() => start(d)}
                    onKeyDown={(e) => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); setPicked(d); } }}
                    sx={(t) => ({
                      minHeight: { xs: span === 'month' ? 64 : 120, sm: span === 'month' ? 104 : 220 },
                      p: 0.75,
                      cursor: 'pointer',
                      borderLeft: di ? 1 : 0,
                      borderColor: 'divider',
                      bgcolor: sel ? `rgba(${t.vars!.palette.primary.mainChannel} / 0.06)` : out ? t.vars!.palette.action.hover : 'transparent',
                      outline: sel ? `2px solid ${t.vars!.palette.primary.main}` : 'none',
                      outlineOffset: -2,
                      '&:hover .add': { opacity: 1 },
                      '&:focus-visible': { outline: `2px solid ${t.vars!.palette.primary.main}`, outlineOffset: -2 },
                      minWidth: 0,
                    })}
                  >
                    <Stack direction="row" sx={{ justifyContent: 'space-between', alignItems: 'center', mb: 0.5 }}>
                      <Box sx={{ width: 24, height: 24, borderRadius: '50%', display: 'grid', placeItems: 'center', typography: 'caption', fontWeight: today ? 700 : 500, bgcolor: today ? 'primary.main' : 'transparent', color: today ? 'primary.contrastText' : out ? 'text.disabled' : 'text.primary' }}>{format(d, 'd')}</Box>
                      <IconButton className="add" size="small" aria-label={`Add a task on ${format(d, 'd MMMM')}`} onClick={(e) => { e.stopPropagation(); start(d); }} sx={{ opacity: { xs: 0, sm: 0 }, p: 0.25, '&:focus-visible': { opacity: 1 } }}><AddRoundedIcon sx={{ fontSize: 16 }} /></IconButton>
                    </Stack>
                    {/* phones: dots only; larger screens: titled chips */}
                    <Stack direction="row" spacing={0.4} sx={{ display: { xs: 'flex', sm: 'none' }, flexWrap: 'wrap', rowGap: 0.4 }}>
                      {list.slice(0, 6).map((e) => <Box key={e.key} component="span" sx={[{ width: 6, height: 6, borderRadius: '50%' }, e.overdue ? { bgcolor: 'error.main' } : dot(e.tone)]} />)}
                    </Stack>
                    <Stack spacing={0.4} sx={{ display: { xs: 'none', sm: 'flex' } }}>
                      {list.slice(0, cap).map((e) => (
                        <Box
                          key={e.key}
                          component="button"
                          type="button"
                          onClick={(ev) => { ev.stopPropagation(); setPicked(d); if (e.task) setEditing(e.task); else if (e.visit) setVisitId(e.visit.id); }}
                          title={`${format(e.at, 'HH:mm')} ${e.title}${e.sub ? ` · ${e.sub}` : ''}`}
                          sx={[{ all: 'unset', boxSizing: 'border-box', cursor: 'pointer', px: 0.75, py: 0.25, borderRadius: 0.75, typography: 'caption', lineHeight: 1.35, whiteSpace: 'nowrap', overflow: 'hidden', textOverflow: 'ellipsis', display: 'block', '&:hover': { filter: 'brightness(0.95)' }, '&:focus-visible': { outline: '2px solid', outlineColor: 'primary.main' } }, chip(e.tone, e.overdue)]}
                        >
                          <Box component="span" sx={{ fontWeight: 700, fontVariantNumeric: 'tabular-nums', mr: 0.5 }}>{format(e.at, 'HH:mm')}</Box>{e.title}
                        </Box>
                      ))}
                      {list.length > cap && <Typography variant="caption" color="text.secondary" sx={{ px: 0.75 }}>+{list.length - cap} more</Typography>}
                    </Stack>
                  </Box>
                );
              })}
            </Box>
          ))}
        </Paper>

        <Card
          title={format(picked, 'EEEE d MMMM')}
          subtitle={isSameDay(picked, new Date()) ? 'Today' : dayEntries.length ? `${dayEntries.length} planned` : undefined}
          actions={<Button size="small" variant="contained" startIcon={<AddRoundedIcon />} onClick={() => start(picked)}>Add</Button>}
          pad={1.5}
          sx={{ position: { lg: 'sticky' }, top: { lg: 16 } }}
        >
          {tasks.isPending ? (
            <Typography variant="body2" color="text.secondary">Loading…</Typography>
          ) : dayEntries.length === 0 ? (
            <Typography variant="body2" color="text.secondary">Nothing planned. Add a call, a follow-up or a to-do for this day{monthTotal === 0 ? ' and it will show on the calendar' : ''}.</Typography>
          ) : (
            <Stack>
              {dayEntries.map((e) =>
                e.task ? (
                  <TaskRow key={e.key} task={e.task} showAssignee={team} onEdit={setEditing} />
                ) : (
                  <Box key={e.key} component="button" type="button" onClick={() => setVisitId(e.visit!.id)} sx={{ all: 'unset', cursor: 'pointer', py: 1, px: 0.5, borderBottom: 1, borderColor: 'divider', '&:hover': { bgcolor: 'action.hover' } }}>
                    <Typography variant="subtitle2">Site visit · {format(e.at, 'HH:mm')} · {e.title}</Typography>
                    <Typography variant="caption" color="text.secondary">{e.sub}</Typography>
                  </Box>
                )
              )}
            </Stack>
          )}
          <Typography variant="caption" color="text.disabled" component="p" sx={{ mt: 1.5 }}>Tip: double-click a day to add something to it.</Typography>
        </Card>
      </Box>

      {realEstate && <VisitDialog visitId={visitId} onClose={() => setVisitId(null)} />}
      <TaskDialog open={Boolean(editing)} task={editing ?? undefined} onClose={() => setEditing(null)} />
      <TaskDialog open={Boolean(adding)} defaultDue={adding?.due} onClose={() => setAdding(null)} />
    </Box>
  );
}
