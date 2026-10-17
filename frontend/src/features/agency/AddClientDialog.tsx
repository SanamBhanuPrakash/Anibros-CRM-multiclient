import { useEffect, useState } from 'react';
import { Alert, Box, Button, Checkbox, Collapse, Dialog, DialogActions, DialogContent, DialogTitle, FormControlLabel, Link as MuiLink, MenuItem, Stack, TextField, Typography } from '@mui/material';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { ApiProblem } from '@/api/client';
import { agencyApi, type Client, type ClientRole } from '@/api/agency';
import { customFieldsApi } from '@/api/customFields';
import { useAuth } from '@/features/auth/AuthProvider';
import { useToast } from '@/ui/toast';
import { CLIENT_ROLE_OPTIONS, useAgencyClients, useAgencyOptions, useAgencyStaff } from './shared';

interface Form {
  name: string;
  industry: string;
  contactName: string;
  contactEmail: string;
  contactPhone: string;
  website: string;
  timezone: string;
  currency: string;
  copyFrom: string;
}
const EMPTY: Form = { name: '', industry: 'other', contactName: '', contactEmail: '', contactPhone: '', website: '', timezone: '', currency: '', copyFrom: '' };

/**
 * Add a client = create its own workspace: its own leads, pipeline, lead sources, spend and team, separate from every other client.
 * Employees can be assigned right away (or later from the client's team page).
 */
export function AddClientDialog({ open, onClose, onCreated }: { open: boolean; onClose: () => void; onCreated: (c: Client) => void }) {
  const qc = useQueryClient();
  const toast = useToast();
  const { refresh, me } = useAuth();
  const options = useAgencyOptions();
  const staff = useAgencyStaff(open);
  const clients = useAgencyClients('active');
  const [f, setF] = useState<Form>(EMPTY);
  const [more, setMore] = useState(false);
  const [team, setTeam] = useState<Record<string, ClientRole>>({});
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    if (open) {
      setF({ ...EMPTY, timezone: me?.user.timezone ?? '' });
      setMore(false);
      setTeam({});
      setErrors({});
      setError(null);
    }
  }, [open, me?.user.timezone]);

  const employees = (staff.data?.staff ?? []).filter((s) => s.agencyRole === 'staff');
  const industry = options.data?.industries.find((i) => i.code === f.industry);

  const create = useMutation({
    mutationFn: async () => {
      const input = {
        name: f.name.trim(),
        industry: f.industry,
        contactName: f.contactName.trim() || null,
        contactEmail: f.contactEmail.trim() || null,
        contactPhone: f.contactPhone.trim() || null,
        website: f.website.trim() || null,
        ...(f.timezone.trim() ? { timezone: f.timezone.trim() } : {}),
        ...(f.currency.trim() ? { currency: f.currency.trim().toUpperCase() } : {}),
        staff: Object.entries(team).map(([userId, role]) => ({ userId, role })),
      };
      const client = await agencyApi.createClient(input);
      let copied = 0;
      if (f.copyFrom) copied = (await customFieldsApi.copyFrom(client.id, f.copyFrom)).copied.length;
      return { client, copied };
    },
    onSuccess: async ({ client, copied }) => {
      await refresh(); // the new client joins everyone's list of workspaces
      void qc.invalidateQueries({ queryKey: ['agency'] });
      toast.success(`${client.name} is ready${copied ? ` · ${copied} lead field${copied === 1 ? '' : 's'} copied` : ''}`);
      onCreated(client);
    },
    onError: (e) => {
      if (!(e instanceof ApiProblem)) return setError('Couldn’t add the client. Please try again.');
      const fe = e.fieldErrors();
      if (Object.keys(fe).length) {
        setErrors(fe);
        if (Object.keys(fe).some((k) => ['timezone', 'currency'].includes(k))) setMore(true);
      } else if (e.status === 409) setErrors({ name: e.detail });
      else setError(e.detail);
    },
  });

  const set = (k: keyof Form) => (e: React.ChangeEvent<HTMLInputElement>) => {
    setF({ ...f, [k]: e.target.value });
    if (errors[k]) setErrors({ ...errors, [k]: '' });
  };

  return (
    <Dialog open={open} onClose={create.isPending ? undefined : onClose} fullWidth maxWidth="sm" aria-labelledby="add-client-title">
      <DialogTitle id="add-client-title" sx={{ pb: 0.5 }}>Add a client</DialogTitle>
      <DialogContent>
        <Typography variant="body2" color="text.secondary" sx={{ mb: 2.5 }}>A new client gets its own private workspace: leads, pipeline, lead sources, spend and team.</Typography>
        <Stack component="form" id="add-client-form" noValidate spacing={3} onSubmit={(e) => { e.preventDefault(); setError(null); setErrors({}); if (!f.name.trim()) return setErrors({ name: 'Give the client a name' }); create.mutate(); }}>
          {error && <Alert severity="error">{error}</Alert>}

          <Stack spacing={2}>
            <TextField label="Client name" autoFocus required fullWidth value={f.name} onChange={set('name')} error={Boolean(errors.name)} helperText={errors.name || ' '} placeholder="e.g. Smile Dental Clinic" autoComplete="off" slotProps={{ htmlInput: { maxLength: 120 }, inputLabel: { shrink: true } }} sx={{ '& .MuiFormHelperText-root': { minHeight: 0, mt: 0.5 } }} />
            <TextField select label="Kind of business" fullWidth value={f.industry} onChange={set('industry')} error={Boolean(errors.industry)} helperText={errors.industry || undefined}>
              {(options.data?.industries ?? []).map((i) => <MenuItem key={i.code} value={i.code}>{i.label}</MenuItem>)}
            </TextField>
            <Typography variant="body2" color="text.secondary">
              {industry && industry.code !== 'real_estate' && industry.code !== 'other'
                ? <>Sets the pipeline wording (…{industry.appointment} booked → {industry.appointment} done → Won) and adds starter questions for each lead. Both can be changed later.</>
                : 'Sets the pipeline wording and adds starter questions for each lead. Both can be changed later.'}
            </Typography>
          </Stack>

          <Stack spacing={2}>
            <Typography variant="overline" color="text.secondary">Contact at the client (optional)</Typography>
            <Stack direction={{ xs: 'column', sm: 'row' }} spacing={2}>
              <TextField label="Name" fullWidth value={f.contactName} onChange={set('contactName')} autoComplete="off" />
              <TextField label="Phone" type="tel" fullWidth value={f.contactPhone} onChange={set('contactPhone')} autoComplete="off" />
            </Stack>
            <TextField label="E-mail" type="email" fullWidth value={f.contactEmail} onChange={set('contactEmail')} error={Boolean(errors.contactEmail)} helperText={errors.contactEmail} autoComplete="off" />
          </Stack>

          <Stack spacing={1}>
            <Typography variant="overline" color="text.secondary">Team on this client</Typography>
            {staff.isPending ? (
              <Typography variant="body2" color="text.secondary">Loading your team…</Typography>
            ) : employees.length === 0 ? (
              <Typography variant="body2" color="text.secondary">No employees yet. Invite them under <b>Team</b> and assign them later. Agency admins are added automatically.</Typography>
            ) : (
              <Stack spacing={0.5} sx={{ maxHeight: 220, overflow: 'auto', pr: 0.5 }}>
                {employees.map((s) => (
                  <Stack key={s.userId} direction="row" spacing={1} sx={{ alignItems: 'center' }}>
                    <FormControlLabel sx={{ flex: 1, minWidth: 0, m: 0 }} control={<Checkbox size="small" checked={s.userId in team} onChange={(_, on) => { const next = { ...team }; if (on) next[s.userId] = 'sales_agent'; else delete next[s.userId]; setTeam(next); }} />} label={<span>{s.name} <Typography component="span" variant="caption" color="text.secondary">{s.email}</Typography></span>} />
                    {s.userId in team && (
                      <TextField select size="small" value={team[s.userId]} onChange={(e) => setTeam({ ...team, [s.userId]: e.target.value as ClientRole })} sx={{ width: 170 }} slotProps={{ htmlInput: { 'aria-label': `Role of ${s.name}` } }}>
                        {CLIENT_ROLE_OPTIONS.map((o) => <MenuItem key={o.value} value={o.value}>{o.label}</MenuItem>)}
                      </TextField>
                    )}
                  </Stack>
                ))}
              </Stack>
            )}
            {Object.keys(team).length > 0 && <Typography variant="caption" color="text.secondary">New leads are shared automatically among the callers.</Typography>}
          </Stack>

          <Box>
            <MuiLink component="button" type="button" variant="body2" onClick={() => setMore(!more)} aria-expanded={more}>{more ? 'Fewer options' : 'More options'}</MuiLink>
            <Collapse in={more}>
              <Stack spacing={2} sx={{ pt: 2 }}>
                <TextField label="Website" fullWidth value={f.website} onChange={set('website')} placeholder="https://" />
                <Stack direction={{ xs: 'column', sm: 'row' }} spacing={2}>
                  <TextField label="Time zone" fullWidth value={f.timezone} onChange={set('timezone')} error={Boolean(errors.timezone)} helperText={errors.timezone || 'e.g. Asia/Kolkata'} />
                  <TextField label="Currency" fullWidth value={f.currency} onChange={set('currency')} error={Boolean(errors.currency)} helperText={errors.currency || 'e.g. INR'} slotProps={{ htmlInput: { maxLength: 3 } }} />
                </Stack>
                <TextField select label="Copy lead questions from" fullWidth value={f.copyFrom} onChange={set('copyFrom')} helperText="Copies the questions only, never any lead data" slotProps={{ select: { displayEmpty: true }, inputLabel: { shrink: true } }}>
                  <MenuItem value=""><em>Starter questions for this kind of business</em></MenuItem>
                  {(clients.data ?? []).map((c) => <MenuItem key={c.id} value={c.id}>{c.name}</MenuItem>)}
                </TextField>
              </Stack>
            </Collapse>
          </Box>
        </Stack>
      </DialogContent>
      <DialogActions>
        <Button onClick={onClose} disabled={create.isPending}>Cancel</Button>
        <Button type="submit" form="add-client-form" variant="contained" disabled={create.isPending}>{create.isPending ? 'Creating…' : 'Add client'}</Button>
      </DialogActions>
    </Dialog>
  );
}
