import { useEffect, useState } from 'react';
import { Link as RouterLink } from 'react-router-dom';
import { Alert, Box, Button, Checkbox, Dialog, DialogActions, DialogContent, DialogTitle, FormControl, FormControlLabel, FormLabel, MenuItem, Radio, RadioGroup, Stack, TextField, Typography } from '@mui/material';
import FileDownloadOutlinedIcon from '@mui/icons-material/FileDownloadOutlined';
import { addDays, setHours, setMinutes as setMinuteOf } from 'date-fns';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { ApiProblem } from '@/api/client';
import { leadsApi } from '@/api/crm';
import { viewingsApi, type CompleteResult, type Viewing } from '@/api/viewings';
import { useAuth } from '@/features/auth/AuthProvider';
import { useDirectory } from '@/features/leads/shared';
import { useToast } from '@/ui/toast';
import { buyerOf, DURATIONS, fromLocalInput, isOpenVisit, toLocalInput, VisitStatusChip, whenOf } from './shared';

type Mode = 'view' | 'reschedule' | 'cancel' | 'complete' | 'noshow' | 'done';

/** One visit: what it is, and everything that can happen to it. Open from the calendar, a lead or Home. */
export function VisitDialog({ visitId, onClose }: { visitId: string | null; onClose: () => void }) {
  const { can, scope, me } = useAuth();
  const qc = useQueryClient();
  const toast = useToast();
  const directory = useDirectory();
  const [mode, setMode] = useState<Mode>('view');
  const [error, setError] = useState<string | null>(null);
  const [result, setResult] = useState<CompleteResult | null>(null);
  // reschedule
  const [when, setWhen] = useState('');
  const [minutes, setMinutes] = useState(30);
  const [agent, setAgent] = useState('');
  const [notes, setNotes] = useState('');
  // cancel / complete / no-show
  const [text, setText] = useState('');
  const [outcome, setOutcome] = useState('');
  const [followUp, setFollowUp] = useState(true);
  const [followWhen, setFollowWhen] = useState('');

  const q = useQuery({ queryKey: ['viewings', 'one', visitId], queryFn: () => viewingsApi.get(visitId!), enabled: Boolean(visitId) });
  const outcomes = useQuery({ queryKey: ['viewings', 'outcomes'], queryFn: viewingsApi.outcomes, staleTime: 3_600_000, enabled: Boolean(visitId) });
  const v: Viewing | undefined = q.data;
  const mayChange = can('viewings:update');
  const mayAssign = scope('leads:assign') === 'all';

  useEffect(() => {
    setMode('view');
    setError(null);
    setResult(null);
    setText('');
    setOutcome('');
    setFollowUp(true);
    setFollowWhen(toLocalInput(setMinuteOf(setHours(addDays(new Date(), 2), 10), 0)));
  }, [visitId]);
  useEffect(() => {
    if (v) {
      setWhen(toLocalInput(v.scheduledAt));
      setMinutes(v.durationMinutes);
      setAgent(v.agentId ?? '');
      setNotes(v.notes ?? '');
    }
  }, [v]);

  const refresh = () => {
    for (const k of ['viewings', 'tasks', 'leads', 'home', 'calendar']) void qc.invalidateQueries({ queryKey: [k] });
  };
  const fail = (e: unknown) => setError(e instanceof ApiProblem ? (e.errors[0]?.message ?? e.detail) : 'That didn’t work. Please try again.');
  const reschedule = useMutation({
    mutationFn: () => viewingsApi.reschedule(v!.id, v!.version, { scheduledAt: fromLocalInput(when), durationMinutes: minutes, notes: notes.trim() || null, ...(mayAssign && agent ? { agentId: agent } : {}) }),
    onSuccess: () => { refresh(); toast.success('Visit moved. The agent’s reminder moved with it.'); setMode('view'); },
    onError: (e) => (e instanceof ApiProblem && e.status === 412 ? (setError('Someone else changed this visit. The latest details are shown; check them and try again.'), void q.refetch()) : fail(e)),
  });
  const cancel = useMutation({
    mutationFn: () => viewingsApi.cancel(v!.id, text.trim()),
    onSuccess: () => { refresh(); toast.success('Visit cancelled. The slot is free again.'); onClose(); },
    onError: fail,
  });
  const noShow = useMutation({
    mutationFn: () => viewingsApi.noShow(v!.id, text.trim()),
    onSuccess: () => { refresh(); toast.success('Marked as a no-show.'); onClose(); },
    onError: fail,
  });
  const complete = useMutation({
    mutationFn: () => viewingsApi.complete(v!.id, { outcome, note: text.trim() || undefined, followUp: followUp ? { dueAt: fromLocalInput(followWhen) } : undefined }),
    onSuccess: (r) => { refresh(); setResult(r); setMode('done'); },
    onError: fail,
  });
  const moveLead = useMutation({
    mutationFn: (stageId: string) => leadsApi.move(v!.leadId, { stageId }),
    onSuccess: (l) => { refresh(); toast.success(`${l.contactName ?? 'Lead'} moved to ${l.stageName}`); onClose(); },
    onError: fail,
  });

  const open = Boolean(visitId);
  const started = v ? new Date(v.scheduledAt).getTime() <= Date.now() + 15 * 60_000 : false;
  const pastStart = v ? new Date(v.scheduledAt).getTime() <= Date.now() : false;
  const live = v && isOpenVisit(v);

  return (
    <Dialog open={open} onClose={onClose} fullWidth maxWidth="xs" aria-labelledby="visit-title">
      <DialogTitle id="visit-title">{v ? `Visit: ${v.property.title}` : 'Visit'}</DialogTitle>
      <DialogContent>
        {q.isPending && <Typography color="text.secondary">Loading…</Typography>}
        {q.isError && <Alert severity="error">Couldn’t load this visit. It may have been removed, or you may not have access.</Alert>}
        {error && <Alert severity="error" sx={{ mb: 2 }}>{error}</Alert>}
        {v && mode === 'view' && (
          <Stack spacing={1.5} sx={{ pt: 0.5 }}>
            <Stack direction="row" spacing={1} sx={{ alignItems: 'center' }}>
              <VisitStatusChip status={v.status} />
              <Typography variant="subtitle2">{whenOf(v.scheduledAt)} · {v.durationMinutes} min</Typography>
            </Stack>
            <Box component="dl" sx={{ m: 0, display: 'grid', gridTemplateColumns: '90px 1fr', rowGap: 0.75, '& dt': { color: 'text.secondary', typography: 'body2' }, '& dd': { m: 0, typography: 'body2' } }}>
              <dt>Buyer</dt><dd><Typography component={RouterLink} to={`/leads/${v.leadId}`} variant="body2" sx={{ color: 'primary.main' }} onClick={onClose}>{buyerOf(v)}</Typography></dd>
              <dt>Property</dt><dd><Typography component={RouterLink} to={`/properties/${v.propertyId}`} variant="body2" sx={{ color: 'primary.main' }} onClick={onClose}>{v.property.title}</Typography>{v.property.location ? ` · ${v.property.location}` : ''}</dd>
              <dt>Agent</dt><dd>{v.agent?.name ?? 'Not assigned'}</dd>
              {v.notes && (<><dt>Notes</dt><dd>{v.notes}</dd></>)}
              {v.outcome && (<><dt>Outcome</dt><dd>{v.outcome.label}{v.outcomeNote ? `. ${v.outcomeNote}` : ''}</dd></>)}
              {v.status === 'no_show' && v.outcomeNote && (<><dt>Note</dt><dd>{v.outcomeNote}</dd></>)}
              {v.cancelReason && (<><dt>Reason</dt><dd>{v.cancelReason}</dd></>)}
            </Box>
            {live && mayChange && v.status && !started && <Typography variant="caption" color="text.secondary">You can record how the visit went from 15 minutes before it starts.</Typography>}
          </Stack>
        )}

        {v && mode === 'reschedule' && (
          <Stack component="form" id="visit-form" spacing={2} sx={{ pt: 0.5 }} onSubmit={(e) => { e.preventDefault(); reschedule.mutate(); }}>
            <Stack direction="row" spacing={1.5}>
              <TextField label="Date and time" type="datetime-local" value={when} onChange={(e) => setWhen(e.target.value)} slotProps={{ inputLabel: { shrink: true } }} sx={{ flex: 2 }} />
              <TextField select label="Length" value={minutes} onChange={(e) => setMinutes(Number(e.target.value))} sx={{ flex: 1 }}>
                {[...new Set([...DURATIONS, v.durationMinutes])].sort((a, b) => a - b).map((d) => <MenuItem key={d} value={d}>{d} min</MenuItem>)}
              </TextField>
            </Stack>
            {mayAssign && (
              <TextField select label="Agent" value={agent} onChange={(e) => setAgent(e.target.value)}>
                {(directory.data ?? []).map((m) => <MenuItem key={m.userId} value={m.userId}>{m.name}{m.userId === me?.user.id ? ' (you)' : ''}</MenuItem>)}
              </TextField>
            )}
            <TextField label="Notes" multiline minRows={2} value={notes} onChange={(e) => setNotes(e.target.value)} slotProps={{ htmlInput: { maxLength: 1000 } }} />
          </Stack>
        )}

        {v && mode === 'cancel' && (
          <Stack spacing={2} sx={{ pt: 0.5 }}>
            <Typography>Cancel the visit on {whenOf(v.scheduledAt)}? The slot becomes free and the reminder task is closed.</Typography>
            <TextField label="Reason (optional)" value={text} onChange={(e) => setText(e.target.value)} slotProps={{ htmlInput: { maxLength: 500 } }} autoFocus />
          </Stack>
        )}

        {v && mode === 'noshow' && (
          <Stack spacing={2} sx={{ pt: 0.5 }}>
            <Typography>{buyerOf(v)} did not come to the visit. This closes the reminder task; you can follow up from the lead.</Typography>
            <TextField label="Note (optional)" value={text} onChange={(e) => setText(e.target.value)} slotProps={{ htmlInput: { maxLength: 1000 } }} autoFocus />
          </Stack>
        )}

        {v && mode === 'complete' && (
          <Stack spacing={2} sx={{ pt: 0.5 }}>
            <FormControl>
              <FormLabel id="outcome-label" sx={{ mb: 0.5 }}>How did it go?</FormLabel>
              <RadioGroup aria-labelledby="outcome-label" value={outcome} onChange={(e) => { setOutcome(e.target.value); setFollowUp(e.target.value !== 'not_interested'); }}>
                {(outcomes.data ?? []).map((o) => <FormControlLabel key={o.code} value={o.code} control={<Radio size="small" />} label={o.label} />)}
              </RadioGroup>
            </FormControl>
            <TextField label="Note (optional)" multiline minRows={2} value={text} onChange={(e) => setText(e.target.value)} slotProps={{ htmlInput: { maxLength: 1000 } }} />
            <Box>
              <FormControlLabel control={<Checkbox checked={followUp} onChange={(e) => setFollowUp(e.target.checked)} />} label="Add a follow-up task" />
              {followUp && <TextField label="Follow up on" type="datetime-local" value={followWhen} onChange={(e) => setFollowWhen(e.target.value)} slotProps={{ inputLabel: { shrink: true } }} sx={{ mt: 1 }} />}
            </Box>
          </Stack>
        )}

        {mode === 'done' && result && (
          <Stack spacing={1.5} sx={{ pt: 0.5 }}>
            <Alert severity="success">Visit recorded: {result.viewing.outcome?.label}.{result.followUp ? ` Follow-up task added: “${result.followUp.title}”.` : ''}</Alert>
            {result.suggestedStage ? (
              <Typography>Move the lead to <strong>{result.suggestedStage.name}</strong>?</Typography>
            ) : (
              <Typography color="text.secondary">The lead’s stage already reflects this visit.</Typography>
            )}
          </Stack>
        )}
      </DialogContent>

      <DialogActions sx={{ flexWrap: 'wrap', gap: 0.5 }}>
        {mode === 'view' && v && (
          <>
            <Button startIcon={<FileDownloadOutlinedIcon />} component="a" href={viewingsApi.icsUrl(v.id)} sx={{ mr: 'auto' }}>Calendar file</Button>
            {live && mayChange && (
              <>
                <Button color="inherit" onClick={() => { setError(null); setText(''); setMode('cancel'); }}>Cancel visit</Button>
                {pastStart && <Button color="inherit" onClick={() => { setError(null); setText(''); setMode('noshow'); }}>No-show</Button>}
                <Button onClick={() => { setError(null); setMode('reschedule'); }}>Reschedule</Button>
                {started && <Button variant="contained" onClick={() => { setError(null); setText(''); setMode('complete'); }}>Record visit</Button>}
              </>
            )}
            <Button onClick={onClose}>Close</Button>
          </>
        )}
        {mode === 'reschedule' && (
          <>
            <Button onClick={() => { setError(null); setMode('view'); }}>Back</Button>
            <Button type="submit" form="visit-form" variant="contained" disabled={reschedule.isPending || !when}>{reschedule.isPending ? 'Saving…' : 'Save'}</Button>
          </>
        )}
        {mode === 'cancel' && (
          <>
            <Button onClick={() => setMode('view')}>Keep visit</Button>
            <Button color="error" variant="contained" disabled={cancel.isPending} onClick={() => cancel.mutate()}>Cancel the visit</Button>
          </>
        )}
        {mode === 'noshow' && (
          <>
            <Button onClick={() => setMode('view')}>Back</Button>
            <Button variant="contained" disabled={noShow.isPending} onClick={() => noShow.mutate()}>Mark as no-show</Button>
          </>
        )}
        {mode === 'complete' && (
          <>
            <Button onClick={() => setMode('view')}>Back</Button>
            <Button variant="contained" disabled={!outcome || complete.isPending || (followUp && !followWhen)} onClick={() => complete.mutate()}>{complete.isPending ? 'Saving…' : 'Save'}</Button>
          </>
        )}
        {mode === 'done' && result && (
          <>
            <Button onClick={onClose}>{result.suggestedStage ? 'Not now' : 'Close'}</Button>
            {result.suggestedStage && <Button variant="contained" disabled={moveLead.isPending} onClick={() => moveLead.mutate(result.suggestedStage!.stageId)}>Move to {result.suggestedStage.name}</Button>}
          </>
        )}
      </DialogActions>
    </Dialog>
  );
}
