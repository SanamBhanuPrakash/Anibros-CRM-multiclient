import { useEffect, useState } from 'react';
import { Alert, Box, Button, Divider, MenuItem, Paper, Stack, TextField, Typography } from '@mui/material';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Link as RouterLink, useNavigate } from 'react-router-dom';
import { ApiProblem } from '@/api/client';
import { agencyApi } from '@/api/agency';
import { useAuth } from '@/features/auth/AuthProvider';
import { closeClient } from '@/app/scope';
import { PageHeader } from '@/ui/PageHeader';
import { ErrorState, SkeletonRows } from '@/ui/states';
import { useConfirm } from '@/ui/confirm';
import { useToast } from '@/ui/toast';
import { useAgencyOptions } from './shared';

/** The open client's details. Agency admins edit them and can archive the client; everyone else sees them. */
export function ClientSettingsPage() {
  const { scopeId, isAgencyAdmin, refresh, tenant } = useAuth();
  const qc = useQueryClient();
  const nav = useNavigate();
  const toast = useToast();
  const confirm = useConfirm();
  const options = useAgencyOptions();
  const id = scopeId ?? tenant?.id ?? '';
  const q = useQuery({ queryKey: ['agency', 'client', id], queryFn: () => agencyApi.client(id), enabled: Boolean(id) });
  const [f, setF] = useState({ name: '', industry: 'other', website: '', contactName: '', contactEmail: '', contactPhone: '', notes: '', timezone: '', currency: '' });
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    const c = q.data;
    if (c) setF({ name: c.name, industry: c.industry ?? 'other', website: c.website ?? '', contactName: c.contactName ?? '', contactEmail: c.contactEmail ?? '', contactPhone: c.contactPhone ?? '', notes: c.notes ?? '', timezone: c.timezone, currency: c.currency });
  }, [q.data]);

  const save = useMutation({
    mutationFn: () => agencyApi.updateClient(id, { name: f.name.trim(), industry: f.industry, website: f.website.trim() || null, contactName: f.contactName.trim() || null, contactEmail: f.contactEmail.trim() || null, contactPhone: f.contactPhone.trim() || null, notes: f.notes.trim() || null, timezone: f.timezone.trim(), currency: f.currency.trim().toUpperCase() }),
    onSuccess: async () => { await refresh(); void qc.invalidateQueries({ queryKey: ['agency'] }); toast.success('Saved'); },
    onError: (e) => {
      if (!(e instanceof ApiProblem)) return setError('Couldn’t save. Please try again.');
      const fe = e.fieldErrors();
      if (Object.keys(fe).length) setErrors(fe);
      else if (e.status === 409) setErrors({ name: e.detail });
      else setError(e.detail);
    },
  });
  const archive = useMutation({
    mutationFn: () => agencyApi.archiveClient(id),
    onSuccess: async (c) => { closeClient(id); await refresh(); void qc.invalidateQueries({ queryKey: ['agency'] }); nav('/agency'); toast.success(`${c.name} is archived`); },
    onError: (e) => toast.error(e instanceof ApiProblem ? e.detail : 'Couldn’t archive. Please try again.'),
  });

  if (q.isPending) return <><PageHeader title="Client details" /><SkeletonRows rows={6} /></>;
  if (q.isError) return <><PageHeader title="Client details" /><ErrorState error={q.error} onRetry={() => void q.refetch()} /></>;
  const c = q.data;
  const edit = isAgencyAdmin;
  const set = (k: keyof typeof f) => (e: React.ChangeEvent<HTMLInputElement>) => { setF({ ...f, [k]: e.target.value }); if (errors[k]) setErrors({ ...errors, [k]: '' }); };

  return (
    <>
      <PageHeader title="Client details" description={`${c.name} · added ${new Date(c.createdAt).toLocaleDateString()}`} crumbs={[{ label: 'Clients', to: '/agency' }, { label: c.name }]} />
      <Stack spacing={3} sx={{ maxWidth: 760 }}>
        <Paper variant="outlined" sx={{ p: 3, borderRadius: 2.5 }}>
          <Stack component="form" spacing={2} noValidate onSubmit={(e) => { e.preventDefault(); setError(null); setErrors({}); save.mutate(); }}>
            {error && <Alert severity="error">{error}</Alert>}
            {!edit && <Alert severity="info">Only agency admins can change these details.</Alert>}
            <TextField label="Client name" value={f.name} onChange={set('name')} disabled={!edit} error={Boolean(errors.name)} helperText={errors.name} fullWidth required />
            <TextField select label="Kind of business" value={f.industry} onChange={set('industry')} disabled={!edit} fullWidth helperText="Changing this does not rename existing pipeline stages or remove lead fields.">
              {(options.data?.industries ?? []).map((i) => <MenuItem key={i.code} value={i.code}>{i.label}</MenuItem>)}
            </TextField>
            <Stack direction={{ xs: 'column', sm: 'row' }} spacing={2}>
              <TextField label="Contact person" value={f.contactName} onChange={set('contactName')} disabled={!edit} fullWidth />
              <TextField label="Their phone" value={f.contactPhone} onChange={set('contactPhone')} disabled={!edit} fullWidth />
            </Stack>
            <TextField label="Their e-mail" value={f.contactEmail} onChange={set('contactEmail')} disabled={!edit} error={Boolean(errors.contactEmail)} helperText={errors.contactEmail} fullWidth />
            <TextField label="Website" value={f.website} onChange={set('website')} disabled={!edit} fullWidth />
            <Stack direction={{ xs: 'column', sm: 'row' }} spacing={2}>
              <TextField label="Time zone" value={f.timezone} onChange={set('timezone')} disabled={!edit} error={Boolean(errors.timezone)} helperText={errors.timezone || 'Reminders and report days follow it'} fullWidth />
              <TextField label="Currency" value={f.currency} onChange={set('currency')} disabled={!edit} error={Boolean(errors.currency)} helperText={errors.currency} fullWidth slotProps={{ htmlInput: { maxLength: 3 } }} />
            </Stack>
            <TextField label="Notes (only your agency sees these)" value={f.notes} onChange={set('notes')} disabled={!edit} multiline minRows={2} maxRows={8} fullWidth slotProps={{ htmlInput: { maxLength: 4000 } }} />
            {edit && <Box><Button type="submit" variant="contained" disabled={save.isPending || !f.name.trim()}>{save.isPending ? 'Saving…' : 'Save changes'}</Button></Box>}
          </Stack>
        </Paper>

        <Paper variant="outlined" sx={{ p: 3, borderRadius: 2.5 }}>
          <Typography variant="h5" component="h2" sx={{ mb: 0.5 }}>This client at a glance</Typography>
          <Typography variant="body2" color="text.secondary" sx={{ mb: 1.5 }}>{c.assignedStaff} employee{c.assignedStaff === 1 ? '' : 's'} assigned{c.stats ? ` · ${c.stats.openLeads} open leads` : ''}.</Typography>
          <Stack direction="row" spacing={1} sx={{ flexWrap: 'wrap', gap: 1 }}>
            <Button component={RouterLink} to="/settings/team" variant="outlined" size="small">Team</Button>
            <Button component={RouterLink} to="/settings/custom-fields" variant="outlined" size="small">Lead fields</Button>
            <Button component={RouterLink} to="/integrations/lead-sources" variant="outlined" size="small">Lead sources</Button>
            <Button component={RouterLink} to="/settings/api-keys" variant="outlined" size="small">API keys</Button>
          </Stack>
        </Paper>

        {edit && (
          <Paper variant="outlined" sx={{ p: 3, borderRadius: 2.5, borderColor: 'error.main' }}>
            <Typography variant="h5" component="h2" sx={{ mb: 0.5 }}>Archive this client</Typography>
            <Divider sx={{ my: 1.5 }} />
            <Typography variant="body2" color="text.secondary" sx={{ mb: 1.5 }}>Everyone loses access, and its lead capture and API keys stop working. Nothing is deleted, and you can restore the client any time from the Clients page (Archived).</Typography>
            <Button color="error" variant="outlined" disabled={archive.isPending} onClick={async () => { if (await confirm({ title: `Archive ${c.name}?`, body: 'Everyone loses access to this client and new leads stop arriving. Nothing is deleted.', confirmLabel: 'Archive', destructive: true })) archive.mutate(); }}>Archive {c.name}…</Button>
          </Paper>
        )}
      </Stack>
    </>
  );
}
