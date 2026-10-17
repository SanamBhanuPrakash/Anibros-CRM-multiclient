import { useState } from 'react';
import { Link as RouterLink } from 'react-router-dom';
import { Box, Checkbox, Chip, IconButton, ListItemIcon, ListItemText, Menu, MenuItem, Stack, Tooltip, Typography } from '@mui/material';
import MoreHorizRoundedIcon from '@mui/icons-material/MoreHorizRounded';
import CallOutlinedIcon from '@mui/icons-material/CallOutlined';
import MailOutlineRoundedIcon from '@mui/icons-material/MailOutlineRounded';
import EventOutlinedIcon from '@mui/icons-material/EventOutlined';
import EventRepeatOutlinedIcon from '@mui/icons-material/EventRepeatOutlined';
import EditOutlinedIcon from '@mui/icons-material/EditOutlined';
import BlockOutlinedIcon from '@mui/icons-material/BlockOutlined';
import ReplayRoundedIcon from '@mui/icons-material/ReplayRounded';
import DeleteOutlineRoundedIcon from '@mui/icons-material/DeleteOutlineRounded';
import { format, isToday, isTomorrow } from 'date-fns';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { ApiProblem } from '@/api/client';
import { tasksApi, type Task } from '@/api/work';
import { useAuth } from '@/features/auth/AuthProvider';
import { UserAvatar } from '@/ui/display';
import { useConfirm } from '@/ui/confirm';
import { useToast } from '@/ui/toast';
import { PRIORITY_LABEL } from './TaskDialog';

const KIND_ICON = { call: <CallOutlinedIcon fontSize="inherit" />, email: <MailOutlineRoundedIcon fontSize="inherit" />, meeting: <EventOutlinedIcon fontSize="inherit" />, follow_up: <EventRepeatOutlinedIcon fontSize="inherit" />, todo: null } as const;
const PRIORITY_COLOR = { urgent: 'error.main', high: 'warning.main', medium: 'text.disabled', low: 'divider' } as const;

export function dueLabel(iso: string | null): string {
  if (!iso) return 'No date';
  const d = new Date(iso);
  const time = format(d, 'h:mm a');
  if (isToday(d)) return `Today, ${time}`;
  if (isTomorrow(d)) return `Tomorrow, ${time}`;
  return `${format(d, d.getFullYear() === new Date().getFullYear() ? 'EEE d MMM' : 'd MMM yyyy')}, ${time}`;
}

/** Everything you can do to a task, with the same wording and safety (confirm, undo) wherever tasks are shown. */
export function useTaskActions() {
  const qc = useQueryClient();
  const toast = useToast();
  const refresh = () => {
    void qc.invalidateQueries({ queryKey: ['tasks'] });
    void qc.invalidateQueries({ queryKey: ['leads'] });
  };
  const fail = (e: unknown) => toast.error(e instanceof ApiProblem ? (e.errors[0]?.message ?? e.detail) : 'That didn’t work. Please try again.');
  const complete = useMutation({
    mutationFn: (t: Task) => tasksApi.complete(t.id),
    onSuccess: (r) => {
      refresh();
      toast.success(r.next ? `Done. Next one is ${dueLabel(r.next.dueDate).toLowerCase()}.` : 'Task done', { label: 'Undo', onClick: () => void tasksApi.reopen(r.task.id).then(() => (r.next ? tasksApi.remove(r.next.id).catch(() => undefined) : undefined)).then(refresh) });
    },
    onError: (e) => { fail(e); refresh(); },
  });
  const reopen = useMutation({ mutationFn: (t: Task) => tasksApi.reopen(t.id), onSuccess: refresh, onError: fail });
  const cancel = useMutation({ mutationFn: (t: Task) => tasksApi.cancel(t.id), onSuccess: () => { refresh(); toast.success('Task cancelled'); }, onError: fail });
  const remove = useMutation({ mutationFn: (t: Task) => tasksApi.remove(t.id), onSuccess: () => { refresh(); toast.success('Task deleted'); }, onError: fail });
  return { complete, reopen, cancel, remove };
}

export function TaskRow({ task, showAssignee, showLead = true, onEdit }: { task: Task; showAssignee?: boolean; showLead?: boolean; onEdit: (t: Task) => void }) {
  const { can, me } = useAuth();
  const confirm = useConfirm();
  const a = useTaskActions();
  const [menu, setMenu] = useState<HTMLElement | null>(null);
  // React state, not the mutation's pending flag: TanStack notifies asynchronously and the tick must be instant.
  const [ticking, setTicking] = useState(false);
  const open = task.status === 'pending' || task.status === 'in_progress';
  const mine = task.assignedTo === me?.user.id || task.createdBy === me?.user.id;
  const mayChange = can('tasks:update') && (mine || me?.capabilities['tasks:update'] === 'all');

  return (
    <Stack direction="row" spacing={1} sx={{ alignItems: 'flex-start', py: 1, px: 0.5, borderBottom: 1, borderColor: 'divider', opacity: open ? 1 : 0.6 }}>
      <Tooltip title={!mayChange ? '' : open ? 'Mark done' : 'Reopen'}>
        <span>
          <Checkbox
            size="small"
            checked={!open || ticking}
            disabled={!mayChange || a.complete.isPending || a.reopen.isPending || task.status === 'cancelled'}
            onChange={() => {
              if (!open) return a.reopen.mutate(task);
              setTicking(true);
              a.complete.mutate(task, { onSettled: () => setTicking(false) });
            }}
            slotProps={{ input: { 'aria-label': open ? `Mark “${task.title}” done` : `Reopen “${task.title}”` } }}
            sx={{ mt: -0.25 }}
          />
        </span>
      </Tooltip>
      <Box sx={{ width: 4, alignSelf: 'stretch', borderRadius: 2, bgcolor: PRIORITY_COLOR[task.priority], flexShrink: 0 }} aria-hidden />
      <Box sx={{ minWidth: 0, flex: 1 }}>
        <Stack direction="row" spacing={0.75} sx={{ alignItems: 'center' }}>
          {KIND_ICON[task.kind] && <Box sx={{ color: 'text.secondary', display: 'flex', fontSize: 16 }} aria-label={task.kind}>{KIND_ICON[task.kind]}</Box>}
          <Typography variant="subtitle2" sx={{ textDecoration: task.status === 'completed' || task.status === 'cancelled' ? 'line-through' : 'none', wordBreak: 'break-word' }}>{task.title}</Typography>
          {task.recurrence && <Tooltip title={`Repeats ${task.recurrence.freq}`}><Box sx={{ color: 'text.secondary', display: 'flex', fontSize: 15 }}><EventRepeatOutlinedIcon fontSize="inherit" /></Box></Tooltip>}
          {task.status === 'cancelled' && <Chip size="small" label="Cancelled" />}
        </Stack>
        <Stack direction="row" spacing={1.25} sx={{ alignItems: 'center', flexWrap: 'wrap', rowGap: 0.25 }}>
          <Typography variant="caption" sx={{ color: task.isOverdue ? 'error.main' : 'text.secondary', fontWeight: task.isOverdue ? 600 : 400 }}>{task.isOverdue ? 'Overdue · ' : ''}{dueLabel(task.dueDate)}</Typography>
          {task.priority !== 'medium' && <Typography variant="caption" color="text.secondary">{PRIORITY_LABEL[task.priority]}</Typography>}
          {showLead && task.leadId && <Typography variant="caption" component={RouterLink} to={`/leads/${task.leadId}`} sx={{ color: 'primary.main', textDecoration: 'none', '&:hover': { textDecoration: 'underline' } }}>{task.leadName ?? 'Lead'}</Typography>}
          {task.contactId && !task.leadId && <Typography variant="caption" component={RouterLink} to={`/contacts?c=${task.contactId}`} sx={{ color: 'primary.main', textDecoration: 'none' }}>{task.contactName ?? 'Contact'}</Typography>}
        </Stack>
        {task.description && <Typography variant="caption" color="text.secondary" component="p" sx={{ mt: 0.25, whiteSpace: 'pre-wrap' }}>{task.description}</Typography>}
      </Box>
      {showAssignee && (task.assigneeName ? <Tooltip title={task.assigneeName} describeChild><Box><UserAvatar name={task.assigneeName} seed={task.assignedTo ?? undefined} size={24} /></Box></Tooltip> : <Typography variant="caption" color="text.disabled">Unassigned</Typography>)}
      {mayChange && (
        <>
          <IconButton size="small" aria-label={`Actions for ${task.title}`} onClick={(e) => setMenu(e.currentTarget)}><MoreHorizRoundedIcon fontSize="small" /></IconButton>
          <Menu anchorEl={menu} open={Boolean(menu)} onClose={() => setMenu(null)}>
            {open && <MenuItem onClick={() => { setMenu(null); onEdit(task); }}><ListItemIcon><EditOutlinedIcon fontSize="small" /></ListItemIcon><ListItemText>Edit</ListItemText></MenuItem>}
            {open && <MenuItem onClick={() => { setMenu(null); a.cancel.mutate(task); }}><ListItemIcon><BlockOutlinedIcon fontSize="small" /></ListItemIcon><ListItemText>Cancel task</ListItemText></MenuItem>}
            {!open && <MenuItem onClick={() => { setMenu(null); a.reopen.mutate(task); }}><ListItemIcon><ReplayRoundedIcon fontSize="small" /></ListItemIcon><ListItemText>Reopen</ListItemText></MenuItem>}
            {can('tasks:delete') && (
              <MenuItem sx={{ color: 'error.main' }} onClick={async () => { setMenu(null); if (await confirm({ title: 'Delete this task?', body: task.title, confirmLabel: 'Delete', destructive: true })) a.remove.mutate(task); }}>
                <ListItemIcon><DeleteOutlineRoundedIcon fontSize="small" color="error" /></ListItemIcon><ListItemText>Delete</ListItemText>
              </MenuItem>
            )}
          </Menu>
        </>
      )}
    </Stack>
  );
}
