import { useEffect, useState } from 'react';
import { Alert, Autocomplete, Button, Dialog, DialogActions, DialogContent, DialogTitle, MenuItem, Stack, TextField } from '@mui/material';
import { addHours, startOfHour } from 'date-fns';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { ApiProblem } from '@/api/client';
import { leadsApi } from '@/api/crm';
import { propertiesApi, type Property } from '@/api/inventory';
import { viewingsApi } from '@/api/viewings';
import { useAuth } from '@/features/auth/AuthProvider';
import { useDirectory } from '@/features/leads/shared';
import { useToast } from '@/ui/toast';
import { DURATIONS, fromLocalInput, toLocalInput } from './shared';

interface Option {
  id: string;
  label: string;
}

/**
 * Book a site visit. The buyer or the property may already be known (from a lead or a property page); the other is picked here.
 * Only properties that can be visited (available or under offer) are offered; the server enforces the same rules and says why when it refuses.
 */
export function BookVisitDialog({ open, onClose, lead, property }: { open: boolean; onClose: () => void; lead?: { id: string; name: string }; property?: { id: string; title: string } }) {
  const { scope, me } = useAuth();
  const toast = useToast();
  const qc = useQueryClient();
  const directory = useDirectory();
  const mayAssign = scope('leads:assign') === 'all';
  const [buyer, setBuyer] = useState<Option | null>(null);
  const [unit, setUnit] = useState<Option | null>(null);
  const [buyerQ, setBuyerQ] = useState('');
  const [unitQ, setUnitQ] = useState('');
  const [when, setWhen] = useState('');
  const [minutes, setMinutes] = useState(30);
  const [agent, setAgent] = useState('');
  const [notes, setNotes] = useState('');
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    if (!open) return;
    setError(null);
    setBuyer(lead ? { id: lead.id, label: lead.name } : null);
    setUnit(property ? { id: property.id, label: property.title } : null);
    setBuyerQ('');
    setUnitQ('');
    setWhen(toLocalInput(addHours(startOfHour(new Date()), 24)));
    setMinutes(30);
    setAgent(me?.user.id ?? '');
    setNotes('');
  }, [open, lead, property, me?.user.id]);

  const buyers = useQuery({
    queryKey: ['leads', 'picker', buyerQ],
    queryFn: async () => (await leadsApi.list({ q: buyerQ || undefined, state: 'open' })).data.map((l) => ({ id: l.id, label: l.contactName ?? 'Unnamed lead' })),
    enabled: open && !lead,
  });
  const units = useQuery({
    queryKey: ['properties', 'picker', unitQ],
    queryFn: async () => (await propertiesApi.list({ q: unitQ || undefined })).data.filter((p: Property) => p.status === 'available' || p.status === 'under_offer').map((p: Property) => ({ id: p.id, label: `${p.title}${p.unitNumber ? ` · Unit ${p.unitNumber}` : ''}` })),
    enabled: open && !property,
  });

  const book = useMutation({
    mutationFn: () => viewingsApi.book({ leadId: buyer!.id, propertyId: unit!.id, scheduledAt: fromLocalInput(when), durationMinutes: minutes, notes: notes.trim() || null, agentId: mayAssign ? agent || null : undefined }),
    onSuccess: () => {
      for (const k of ['viewings', 'tasks', 'leads', 'home', 'calendar']) void qc.invalidateQueries({ queryKey: [k] });
      toast.success('Visit booked. A reminder was added to the agent’s tasks.');
      onClose();
    },
    onError: (e) => setError(e instanceof ApiProblem ? (e.errors[0]?.message ?? e.detail) : 'Couldn’t book the visit. Please try again.'),
  });
  const ready = Boolean(buyer && unit && when && !Number.isNaN(new Date(when).getTime()));

  return (
    <Dialog open={open} onClose={onClose} fullWidth maxWidth="xs" aria-labelledby="book-visit-title">
      <DialogTitle id="book-visit-title">Book a site visit</DialogTitle>
      <DialogContent>
        <Stack component="form" id="book-visit-form" spacing={2} sx={{ pt: 0.5 }} onSubmit={(e) => { e.preventDefault(); if (ready) book.mutate(); }}>
          {error && <Alert severity="error">{error}</Alert>}
          {lead ? <TextField label="Buyer" value={lead.name} disabled /> : (
            <Autocomplete options={buyers.data ?? []} loading={buyers.isFetching} value={buyer} onChange={(_, v) => setBuyer(v)} inputValue={buyerQ} onInputChange={(_, v, reason) => reason !== 'reset' && setBuyerQ(v)} filterOptions={(o) => o} isOptionEqualToValue={(a, b) => a.id === b.id} noOptionsText="No open leads match" renderInput={(p) => <TextField {...p} label="Buyer" placeholder="Search leads" />} />
          )}
          {property ? <TextField label="Property" value={property.title} disabled /> : (
            <Autocomplete options={units.data ?? []} loading={units.isFetching} value={unit} onChange={(_, v) => setUnit(v)} inputValue={unitQ} onInputChange={(_, v, reason) => reason !== 'reset' && setUnitQ(v)} filterOptions={(o) => o} isOptionEqualToValue={(a, b) => a.id === b.id} noOptionsText="No available properties match" renderInput={(p) => <TextField {...p} label="Property" placeholder="Search available properties" helperText="Only available and under-offer properties can be visited" />} />
          )}
          <Stack direction="row" spacing={1.5}>
            <TextField label="Date and time" type="datetime-local" value={when} onChange={(e) => setWhen(e.target.value)} slotProps={{ inputLabel: { shrink: true } }} sx={{ flex: 2 }} />
            <TextField select label="Length" value={minutes} onChange={(e) => setMinutes(Number(e.target.value))} sx={{ flex: 1 }}>
              {DURATIONS.map((d) => <MenuItem key={d} value={d}>{d} min</MenuItem>)}
            </TextField>
          </Stack>
          {mayAssign && (
            <TextField select label="Agent" value={agent} onChange={(e) => setAgent(e.target.value)}>
              {(directory.data ?? []).map((m) => <MenuItem key={m.userId} value={m.userId}>{m.name}{m.userId === me?.user.id ? ' (you)' : ''}</MenuItem>)}
            </TextField>
          )}
          <TextField label="Notes" multiline minRows={2} value={notes} onChange={(e) => setNotes(e.target.value)} slotProps={{ htmlInput: { maxLength: 1000 } }} helperText="For example: gate code, which floor, what the buyer wants to see" />
        </Stack>
      </DialogContent>
      <DialogActions>
        <Button onClick={onClose}>Cancel</Button>
        <Button type="submit" form="book-visit-form" variant="contained" disabled={!ready || book.isPending}>{book.isPending ? 'Booking…' : 'Book visit'}</Button>
      </DialogActions>
    </Dialog>
  );
}
