import { useState } from 'react';
import { Box, Button, Paper, Stack, Typography } from '@mui/material';
import AddRoundedIcon from '@mui/icons-material/AddRounded';
import { useQuery } from '@tanstack/react-query';
import { tasksApi, type Task } from '@/api/work';
import { useAuth } from '@/features/auth/AuthProvider';
import { ErrorState, SkeletonRows } from '@/ui/states';
import { TaskDialog } from './TaskDialog';
import { TaskRow } from './TaskRow';

/** Open tasks for one lead, right where the salesperson is looking. Finished ones stay on the timeline. */
export function LeadTasks({ leadId, leadName }: { leadId: string; leadName: string }) {
  const { can } = useAuth();
  const [dialog, setDialog] = useState<{ task?: Task } | null>(null);
  const q = useQuery({ queryKey: ['tasks', 'lead', leadId], queryFn: async () => (await tasksApi.list({ leadId, status: 'open', assignee: 'any' })).data });
  return (
    <Paper variant="outlined" sx={{ p: 2.5, borderRadius: 2.5 }}>
      <Stack direction="row" sx={{ alignItems: 'center', justifyContent: 'space-between', mb: 1 }}>
        <Typography variant="h5" component="h2">Tasks</Typography>
        {can('tasks:create') && <Button size="small" startIcon={<AddRoundedIcon />} onClick={() => setDialog({})}>Add</Button>}
      </Stack>
      {q.isPending ? <SkeletonRows rows={2} height={40} /> : q.isError ? <ErrorState error={q.error} onRetry={() => void q.refetch()} /> : q.data.length === 0 ? (
        <Typography variant="body2" color="text.secondary">Nothing planned. Add a follow-up so this lead doesn’t go quiet.</Typography>
      ) : (
        <Box>{q.data.map((t) => <TaskRow key={t.id} task={t} showLead={false} showAssignee onEdit={(x) => setDialog({ task: x })} />)}</Box>
      )}
      <TaskDialog open={Boolean(dialog)} task={dialog?.task} leadId={leadId} linkLabel={leadName} onClose={() => setDialog(null)} />
    </Paper>
  );
}
