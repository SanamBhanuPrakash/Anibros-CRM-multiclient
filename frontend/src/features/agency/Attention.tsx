import { useState } from 'react';
import { Box, Button, Stack, Typography } from '@mui/material';
import CallOutlinedIcon from '@mui/icons-material/CallOutlined';
import { useQuery } from '@tanstack/react-query';
import { useNavigate } from 'react-router-dom';
import { agencyApi, type AgencyLead, type AgencyTask } from '@/api/agency';
import { openClient } from '@/app/scope';
import { Card, ClientChip, Pill, When } from '@/ui/kit';
import { LogActivityDialog } from './LogActivityDialog';

type Why = { label: string; urgent: boolean };

/**
 * "Needs you today": your own open leads whose first reply or follow-up is overdue (or due today), across your clients (or in one).
 * Team leads and admins also see leads nobody has answered yet. Every row is a link to the lead and has a one-click "Log call".
 */
export function Attention({ clientId, showTeam = false }: { clientId?: string; showTeam?: boolean }) {
  const nav = useNavigate();
  const [logging, setLogging] = useState<AgencyLead | null>(null);
  const [all, setAll] = useState(false);
  const q = useQuery({
    queryKey: ['agency', 'attention', clientId ?? 'all', showTeam],
    queryFn: async () => {
      const base = { clientId, state: 'open' as const, pageSize: 8, test: 'exclude' as const };
      const mine = { ...base, ownerId: 'me' };
      const [reply, overdue, today, unanswered] = await Promise.all([
        agencyApi.leads({ ...mine, sla: 'breached' }),
        agencyApi.leads({ ...mine, followup: 'overdue' }),
        agencyApi.leads({ ...mine, followup: 'today' }),
        showTeam ? agencyApi.leads({ ...base, sla: 'breached' }) : Promise.resolve(null),
      ]);
      const seen = new Set<string>();
      const out: Array<{ lead: AgencyLead; why: Why }> = [];
      const add = (list: AgencyLead[] | undefined, why: Why) => {
        for (const lead of list ?? []) {
          if (seen.has(lead.id)) continue;
          seen.add(lead.id);
          out.push({ lead, why });
        }
      };
      add(reply.items, { label: 'Reply overdue', urgent: true });
      add(overdue.items, { label: 'Follow-up overdue', urgent: true });
      add(today.items, { label: 'Follow up today', urgent: false });
      add(unanswered?.items, { label: 'Nobody has replied', urgent: true });
      return out.slice(0, 8);
    },
    refetchInterval: 60_000,
  });
  const tasks = useQuery({ queryKey: ['agency', 'my-tasks'], queryFn: agencyApi.myTasks, refetchInterval: 60_000, retry: false });
  const myTasks = (tasks.data ?? []).filter((t) => !clientId || t.client.id === clientId).slice(0, 6);
  const LIMIT = 5;
  const queue = [...myTasks.map((t) => ({ kind: 'task' as const, t })), ...(q.data ?? []).map((x) => ({ kind: 'lead' as const, ...x }))];
  if ((q.isPending && tasks.isPending) || (!q.data?.length && !myTasks.length)) return null;
  return (
    <Card title="Needs you today" subtitle={clientId ? 'Waiting for a reply or a follow-up' : 'Across your clients: waiting for a reply or a follow-up'} pad={0} sx={{ mb: 2.5 }}>
      {(all ? queue : queue.slice(0, LIMIT)).map((row) => row.kind === 'task' ? <TaskLine key={row.t.id} t={row.t} hideClient={Boolean(clientId)} /> : ((({ lead, why }) => (
        <Stack key={lead.id} direction="row" spacing={1.5} sx={{ px: 2, py: 1, alignItems: 'center', borderTop: 1, borderColor: 'divider', '&:hover': { bgcolor: 'surface.hover' } }}>
          <Box sx={{ minWidth: 0, flex: 1, cursor: 'pointer' }} onClick={() => { openClient(lead.client.id); nav(`/leads/${lead.id}`); }}>
            <Typography variant="subtitle2" noWrap>{lead.contactName ?? 'Unnamed lead'}</Typography>
            <Stack direction="row" spacing={1} sx={{ alignItems: 'center', minWidth: 0 }}>
              {!clientId && <ClientChip id={lead.client.id} name={lead.client.name} />}
              {lead.contactPhone && <Typography variant="caption" color="text.secondary" noWrap>{lead.contactPhone}</Typography>}
            </Stack>
          </Box>
          <Pill tone={why.urgent ? 'danger' : 'neutral'}>{why.label}</Pill>
          <Button size="small" variant="outlined" color="inherit" startIcon={<CallOutlinedIcon />} onClick={() => setLogging(lead)}>Log call</Button>
        </Stack>
      ))(row)))}
      {queue.length > LIMIT && <Box sx={{ borderTop: 1, borderColor: 'divider', px: 2, py: 0.75 }}><Button size="small" color="inherit" onClick={() => setAll((v) => !v)}>{all ? 'Show fewer' : `Show all ${queue.length}`}</Button></Box>}
      <LogActivityDialog open={Boolean(logging)} onClose={() => setLogging(null)} clientId={logging?.client.id ?? ''} lead={logging ? { id: logging.id, name: logging.contactName, phone: logging.contactPhone } : null} />
    </Card>
  );
}

/** One of my own tasks that is due: opens the lead it is for (or the client's task list) in that client. */
function TaskLine({ t, hideClient }: { t: AgencyTask; hideClient: boolean }) {
  const nav = useNavigate();
  const go = () => {
    openClient(t.client.id);
    nav(t.leadId ? `/leads/${t.leadId}` : '/tasks');
  };
  return (
    <Stack direction="row" spacing={1.5} sx={{ px: 2, py: 1, alignItems: 'center', borderTop: 1, borderColor: 'divider', '&:hover': { bgcolor: 'surface.hover' } }}>
      <Box sx={{ minWidth: 0, flex: 1, cursor: 'pointer' }} onClick={go}>
        <Typography variant="subtitle2" noWrap>{t.title}</Typography>
        <Stack direction="row" spacing={1} sx={{ alignItems: 'center', minWidth: 0 }}>
          {!hideClient && <ClientChip id={t.client.id} name={t.client.name} />}
          {t.leadName && <Typography variant="caption" color="text.secondary" noWrap>{t.leadName}</Typography>}
        </Stack>
      </Box>
      <Pill tone={t.overdue ? 'danger' : 'neutral'}>{t.overdue ? 'Task overdue' : 'Task today'}</Pill>
      <Typography variant="caption" sx={{ minWidth: 64, textAlign: 'right' }}><When value={t.dueDate} due /></Typography>
    </Stack>
  );
}