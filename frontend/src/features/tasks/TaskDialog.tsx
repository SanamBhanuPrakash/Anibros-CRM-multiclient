import { useEffect, useState } from 'react';
import { Alert, Button, Dialog, DialogActions, DialogContent, DialogTitle, MenuItem, Stack, TextField, ToggleButton, ToggleButtonGroup } from '@mui/material';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { ApiProblem } from '@/api/client';
import { tasksApi, type Recurrence, type Task, type TaskKind, type TaskPriority } from '@/api/work';
import { useAuth } from '@/features/auth/AuthProvider';
import { useDirectory } from '@/features/leads/shared';
import { useToast } from '@/ui/toast';
import { LeadPicker, type PickedLead } from '@/features/leads/LeadPicker';

export const KIND_LABEL: Record<TaskKind, string> = { todo: 'To-do', call: 'Call', email: 'Email', meeting: 'Meeting', follow_up: 'Follow-up' };
export const PRIORITY_LABEL: Record<TaskPriority, string> = { low: 'Low', medium: 'Medium', high: 'High', urgent: 'Urgent' };
const REMINDERS: Array<[string, number | null]> = [['No reminder', null], ['At the due time', 0], ['15 minutes before', 15], ['1 hour before', 60], ['1 day before', 1440]];
const REPEATS: Array<[string, Recurrence | null]> = [['Does not repeat', null], ['Every day', { freq: 'daily', interval: 1 }], ['Every week', { freq: 'weekly', interval: 1 }], ['Every month', { freq: 'monthly', interval: 1 }]];

/** `datetime-local` wants local wall-clock text; the API wants an offset-aware instant. */
const toLocalInput = (iso: string | null) => (iso ? new Date(new Date(iso).getTime() - new Date().getTimezoneOffset() * 60_000).toISOString().slice(0, 16) : '');
const fromLocalInput = (v: string) => (v ? new Date(v).toISOString() : null);

/**
 * Add or edit a task. From a lead (`leadId` given) it is tied to that lead; from anywhere else you pick the lead here (or leave it a plain to-do).
 * `defaultDue` pre-fills the date when a task is added from a day on the calendar.
 */
export function TaskDialog({ open, task, leadId, contactId, linkLabel, defaultDue, onClose }: { open: boolean; task?: Task; leadId?: string; contactId?: string; linkLabel?: string; defaultDue?: Date; onClose: () => void }) {
  const qc = useQueryClient();
  const toast = useToast();
  const { scope, me } = useAuth();
  const canAssign = scope('tasks:update') === 'all';
  const directory = useDirectory();
  const [f, setF] = useState({ title: '', description: '', kind: 'todo' as TaskKind, priority: 'medium' as TaskPriority, due: '', remind: '' as string, repeat: '0', assignee: '' });
  const [lead, setLead] = useState<PickedLead | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [fieldErrors, setFieldErrors] = useState<Record<string, string>>({});

  useEffect(() => {
    if (!open) return;
    setError(null);
    setFieldErrors({});
    setF({
      title: task?.title ?? '',
      description: task?.description ?? '',
      kind: task?.kind ?? 'todo',
      priority: task?.priority ?? 'medium',
      due: task ? toLocalInput(task.dueDate) : defaultDue ? toLocalInput(defaultDue.toISOString()) : '',
      remind: task?.remindBeforeMinutes === null || task?.remindBeforeMinutes === undefined ? '' : String(task.remindBeforeMinutes),
      repeat: String(Math.max(0, REPEATS.findIndex(([, r]) => r && task?.recurrence && r.freq === task.recurrence.freq))),
      assignee: task?.assignedTo ?? me?.user.id ?? '',
    });
    setLead(task?.leadId ? { id: task.leadId, name: task.leadName ?? 'Lead', phone: null } : null);
  }, [open, task, me?.user.id, defaultDue]);

  const save = useMutation({
    mutationFn: () => {
      const body = {
        title: f.title.trim(),
        description: f.description.trim() || null,
        kind: f.kind,
        priority: f.priority,
        dueDate: fromLocalInput(f.due),
        remindBeforeMinutes: f.remind === '' ? null : Number(f.remind),
        recurrence: REPEATS[Number(f.repeat)]?.[1] ?? null,
        ...(canAssign ? { assignedTo: f.assignee || null } : {}),
      };
      if (task) return tasksApi.update(task.id, task.version, { ...body, ...(leadId ? {} : { leadId: lead?.id ?? null }) });
      return tasksApi.create({ ...body, leadId: leadId ?? lead?.id ?? undefined, contactId: contactId ?? undefined });
    },
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: ['tasks'] });
      void qc.invalidateQueries({ queryKey: ['leads'] });
      toast.success(task ? 'Task updated' : 'Task added');
      onClose();
    },
    onError: (e) => {
      if (!(e instanceof ApiProblem)) return setError('Couldn’t save the task. Please try again.');
      if (e.status === 412) return setError('Someone else changed this task while you were editing. Close this and reopen it to see their changes.');
      const fe = e.fieldErrors();
      setFieldErrors(fe);
      if (!Object.keys(fe).length) setError(e.detail);
    },
  });

  return (
    <Dialog open={open} onClose={save.isPending ? undefined : onClose} fullWidth maxWidth="sm" aria-labelledby="task-dialog-title">
      <DialogTitle id="task-dialog-title">{task ? 'Edit task' : 'New task'}</DialogTitle>
      <DialogContent>
        <Stack component="form" id="task-form" spacing={2} sx={{ pt: 0.5 }} onSubmit={(e) => { e.preventDefault(); setError(null); setFieldErrors({}); if (f.title.trim()) save.mutate(); }}>
          {error && <Alert severity="error">{error}</Alert>}
          {linkLabel && !task && <Alert severity="info" icon={false}>Linked to {linkLabel}</Alert>}
          {!leadId && !contactId && <LeadPicker value={lead} onChange={setLead} label="For which lead? (optional)" helperText={lead ? 'It shows on this lead and sets its next follow-up' : 'Leave empty for a general to-do'} />}
          <TextField label="What needs doing?" autoFocus required value={f.title} onChange={(e) => setF({ ...f, title: e.target.value })} error={Boolean(fieldErrors.title)} helperText={fieldErrors.title} slotProps={{ htmlInput: { maxLength: 200 } }} />
          <ToggleButtonGroup size="small" exclusive value={f.kind} onChange={(_, v) => v && setF({ ...f, kind: v })} aria-label="Type" sx={{ flexWrap: 'wrap' }}>
            {(Object.keys(KIND_LABEL) as TaskKind[]).map((k) => <ToggleButton key={k} value={k}>{KIND_LABEL[k]}</ToggleButton>)}
          </ToggleButtonGroup>
          <Stack direction={{ xs: 'column', sm: 'row' }} spacing={2}>
            <TextField label="Due" type="datetime-local" fullWidth value={f.due} onChange={(e) => setF({ ...f, due: e.target.value })} slotProps={{ inputLabel: { shrink: true } }} error={Boolean(fieldErrors.dueDate)} helperText={fieldErrors.dueDate} />
            <TextField select label="Priority" fullWidth value={f.priority} onChange={(e) => setF({ ...f, priority: e.target.value as TaskPriority })}>
              {(Object.keys(PRIORITY_LABEL) as TaskPriority[]).map((p) => <MenuItem key={p} value={p}>{PRIORITY_LABEL[p]}</MenuItem>)}
            </TextField>
          </Stack>
          <Stack direction={{ xs: 'column', sm: 'row' }} spacing={2}>
            <TextField select label="Remind me" fullWidth value={f.remind} onChange={(e) => setF({ ...f, remind: e.target.value })} error={Boolean(fieldErrors.remindBeforeMinutes)} helperText={fieldErrors.remindBeforeMinutes ?? (f.remind !== '' && !f.due ? 'Set a due time to get a reminder' : undefined)}>
              {REMINDERS.map(([label, v]) => <MenuItem key={label} value={v === null ? '' : String(v)}>{label}</MenuItem>)}
            </TextField>
            <TextField select label="Repeat" fullWidth value={f.repeat} onChange={(e) => setF({ ...f, repeat: e.target.value })} helperText={f.repeat !== '0' ? 'The next one is created when you complete this' : undefined}>
              {REPEATS.map(([label], i) => <MenuItem key={label} value={String(i)}>{label}</MenuItem>)}
            </TextField>
          </Stack>
          {canAssign && (
            <TextField select label="Assigned to" value={f.assignee} onChange={(e) => setF({ ...f, assignee: e.target.value })} slotProps={{ select: { displayEmpty: true }, inputLabel: { shrink: true } }}>
              <MenuItem value=""><em>Unassigned</em></MenuItem>
              {(directory.data ?? []).map((m) => <MenuItem key={m.userId} value={m.userId}>{m.name}{m.userId === me?.user.id ? ' (you)' : ''}</MenuItem>)}
            </TextField>
          )}
          <TextField label="Notes" multiline minRows={2} maxRows={6} value={f.description} onChange={(e) => setF({ ...f, description: e.target.value })} slotProps={{ htmlInput: { maxLength: 4000 } }} />
        </Stack>
      </DialogContent>
      <DialogActions>
        <Button onClick={onClose} disabled={save.isPending}>Cancel</Button>
        <Button type="submit" form="task-form" variant="contained" disabled={!f.title.trim() || save.isPending}>{save.isPending ? 'Saving…' : task ? 'Save changes' : 'Add task'}</Button>
      </DialogActions>
    </Dialog>
  );
}
