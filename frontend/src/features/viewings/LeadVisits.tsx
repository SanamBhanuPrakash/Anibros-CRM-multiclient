import { useState } from 'react';
import { Box, Button, Paper, Stack, Typography } from '@mui/material';
import EventAvailableOutlinedIcon from '@mui/icons-material/EventAvailableOutlined';
import { useQuery } from '@tanstack/react-query';
import { viewingsApi } from '@/api/viewings';
import { useAuth } from '@/features/auth/AuthProvider';
import { BookVisitDialog } from './BookVisitDialog';
import { isOpenVisit, VisitStatusChip, whenOf } from './shared';
import { VisitDialog } from './VisitDialog';

/** Visits for one lead: the list, "Book a visit", and the visit dialog for everything that can happen to one. */
export function LeadVisits({ lead, canBook }: { lead: { id: string; name: string; closed: boolean }; canBook: boolean }) {
  const { can } = useAuth();
  const [book, setBook] = useState(false);
  const [open, setOpen] = useState<string | null>(null);
  const q = useQuery({ queryKey: ['viewings', 'lead', lead.id], queryFn: async () => (await viewingsApi.list({ leadId: lead.id })).data, enabled: can('viewings:read') });
  if (!can('viewings:read')) return null;
  const visits = [...(q.data ?? [])].sort((a, b) => Number(isOpenVisit(b)) - Number(isOpenVisit(a)) || new Date(a.scheduledAt).getTime() - new Date(b.scheduledAt).getTime());
  return (
    <Paper variant="outlined" sx={{ p: 2.5, borderRadius: 2.5 }} component="section" aria-labelledby="visits-title">
      <Stack direction="row" sx={{ justifyContent: 'space-between', alignItems: 'center', mb: 1 }}>
        <Typography id="visits-title" variant="h5" component="h2">Site visits</Typography>
        {canBook && !lead.closed && can('viewings:create') && <Button size="small" startIcon={<EventAvailableOutlinedIcon />} onClick={() => setBook(true)}>Book a visit</Button>}
      </Stack>
      {q.isPending ? <Typography variant="body2" color="text.secondary">Loading…</Typography> : visits.length === 0 ? (
        <Typography variant="body2" color="text.secondary">No visits yet. Book one so the buyer can see a property.</Typography>
      ) : (
        <Stack spacing={0.5}>
          {visits.map((v) => (
            <Box key={v.id} component="button" type="button" onClick={() => setOpen(v.id)} sx={{ all: 'unset', cursor: 'pointer', display: 'flex', gap: 1.25, alignItems: 'center', p: 1, borderRadius: 1.5, '&:hover, &:focus-visible': { bgcolor: 'action.hover' }, outlineOffset: 2 }} aria-label={`Open visit at ${v.property.title}, ${whenOf(v.scheduledAt)}`}>
              <VisitStatusChip status={v.status} />
              <Box sx={{ minWidth: 0 }}>
                <Typography variant="body2" noWrap sx={{ fontWeight: 600 }}>{v.property.title}</Typography>
                <Typography variant="caption" color="text.secondary">{whenOf(v.scheduledAt)}{v.agent ? ` · ${v.agent.name}` : ''}{v.outcome ? ` · ${v.outcome.label}` : ''}</Typography>
              </Box>
            </Box>
          ))}
        </Stack>
      )}
      <BookVisitDialog open={book} onClose={() => setBook(false)} lead={{ id: lead.id, name: lead.name }} />
      <VisitDialog visitId={open} onClose={() => setOpen(null)} />
    </Paper>
  );
}
