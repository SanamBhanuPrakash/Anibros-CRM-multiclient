import { useEffect, useMemo, useState } from 'react';
import { Link as RouterLink } from 'react-router-dom';
import { Alert, Box, Button, MenuItem, Stack, TextField, Typography } from '@mui/material';
import SaveOutlinedIcon from '@mui/icons-material/SaveOutlined';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api, ApiProblem, unwrap } from '@/api/client';
import { useAuth } from '@/features/auth/AuthProvider';
import { RoutingEditor, type RoutingRule } from './RoutingEditor';
import { PageHeader } from '@/ui/PageHeader';
import { Panel } from '@/ui/Panel';
import { ErrorState, SkeletonRows } from '@/ui/states';
import { useToast } from '@/ui/toast';

interface Workspace {
  name: string;
  slug: string;
  timezone: string;
  currency: string;
  defaultCountry: string | null;
  leadAssignment: 'creator' | 'balanced' | 'unassigned';
  firstResponseMinutes: number;
  routing: RoutingRule[];
  version: number;
}

const CURRENCIES: Array<[string, string]> = [['INR', 'Indian rupee (INR)'], ['USD', 'US dollar (USD)'], ['EUR', 'Euro (EUR)'], ['GBP', 'British pound (GBP)'], ['AED', 'UAE dirham (AED)'], ['SGD', 'Singapore dollar (SGD)']];
const COUNTRIES: Array<[string, string]> = [
  ['IN', 'India'], ['AE', 'United Arab Emirates'], ['US', 'United States'], ['GB', 'United Kingdom'], ['SG', 'Singapore'], ['AU', 'Australia'], ['CA', 'Canada'], ['SA', 'Saudi Arabia'],
  ['QA', 'Qatar'], ['KW', 'Kuwait'], ['OM', 'Oman'], ['BH', 'Bahrain'], ['NZ', 'New Zealand'], ['DE', 'Germany'], ['FR', 'France'], ['MY', 'Malaysia'], ['LK', 'Sri Lanka'], ['NP', 'Nepal'], ['BD', 'Bangladesh'],
];
const ASSIGNMENT: Array<{ value: Workspace['leadAssignment']; label: string; help: string }> = [
  { value: 'creator', label: 'Whoever adds it', help: 'A lead goes to the person who creates it. Leads from imports and lead sources go to the least busy salesperson.' },
  { value: 'balanced', label: 'The least busy salesperson', help: 'Every new lead goes to the salesperson with the fewest open leads.' },
  { value: 'unassigned', label: 'Nobody: a pool anyone can take from', help: 'New leads wait in the unassigned list. A team lead or admin can assign them, and callers can take one for themselves.' },
];

function timeZones(current: string): string[] {
  let all: string[] = [];
  try {
    all = (Intl as unknown as { supportedValuesOf?: (k: string) => string[] }).supportedValuesOf?.('timeZone') ?? [];
  } catch {
    all = [];
  }
  if (!all.length) all = ['Asia/Kolkata', 'Asia/Dubai', 'Asia/Singapore', 'Europe/London', 'America/New_York', 'America/Los_Angeles', 'UTC'];
  return all.includes(current) ? all : [current, ...all];
}

/**
 * Workspace settings. Reads and writes ONE place, `/api/v1/workspace`, which changes only the fields it is sent: saving the name can never
 * erase lead-assignment rules, response deadlines or any other stored setting. Pipeline stages are edited in Pipelines, not duplicated here.
 */
export function WorkspaceSettingsPage() {
  const { can } = useAuth();
  const toast = useToast();
  const qc = useQueryClient();
  const canManage = can('tenant:update');
  const q = useQuery({ queryKey: ['workspace'], queryFn: () => unwrap(api.get<Workspace>('/api/v1/workspace')) });
  const ws = q.data;

  const [name, setName] = useState('');
  const [timezone, setTimezone] = useState('');
  const [currency, setCurrency] = useState('INR');
  const [country, setCountry] = useState('');
  const [assignment, setAssignment] = useState<Workspace['leadAssignment']>('creator');
  const [firstReply, setFirstReply] = useState('30');
  const [routing, setRouting] = useState<RoutingRule[]>([]);
  const [error, setError] = useState('');
  const fill = (w: Workspace) => {
    setName(w.name);
    setTimezone(w.timezone);
    setCurrency(w.currency.toUpperCase());
    setAssignment(w.leadAssignment);
    setCountry(w.defaultCountry ?? '');
    setFirstReply(String(w.firstResponseMinutes));
    setRouting(w.routing ?? []);
  };
  useEffect(() => { if (ws) fill(ws); }, [ws?.version]); // eslint-disable-line react-hooks/exhaustive-deps

  const zones = useMemo(() => timeZones(timezone || 'UTC'), [timezone]);
  const replyMinutes = Number(firstReply);
  const replyValid = firstReply.trim() !== '' && Number.isInteger(replyMinutes) && replyMinutes >= 0 && replyMinutes <= 10080;
  const nameValid = name.trim().length > 0;
  const dirty = !!ws && (name !== ws.name || timezone !== ws.timezone || currency !== ws.currency.toUpperCase() || assignment !== ws.leadAssignment || country !== (ws.defaultCountry ?? '') || replyMinutes !== ws.firstResponseMinutes || JSON.stringify(routing) !== JSON.stringify(ws.routing ?? []));

  const save = useMutation({
    mutationFn: async () => {
      const patch: Record<string, string | number | null | RoutingRule[]> = {};
      if (name !== ws!.name) patch.name = name.trim();
      if (timezone !== ws!.timezone) patch.timezone = timezone;
      if (currency !== ws!.currency.toUpperCase()) patch.currency = currency;
      if (assignment !== ws!.leadAssignment) patch.leadAssignment = assignment;
      if (country !== (ws!.defaultCountry ?? '')) patch.defaultCountry = country || null;
      if (replyMinutes !== ws!.firstResponseMinutes) patch.firstResponseMinutes = replyMinutes;
      if (JSON.stringify(routing) !== JSON.stringify(ws!.routing ?? [])) patch.routing = routing.filter((r) => r.userIds.length > 0);
      return unwrap(api.patch<Workspace>('/api/v1/workspace', patch, { headers: { 'If-Match': `"${ws!.version}"` } }));
    },
    onSuccess: (w) => {
      setError('');
      qc.setQueryData(['workspace'], w);
      for (const k of ['me', 'dashboard', 'home']) void qc.invalidateQueries({ queryKey: [k] });
      toast.success('Settings saved');
    },
    onError: async (e) => {
      if (e instanceof ApiProblem && e.status === 412) {
        setError('Someone else changed these settings. The latest values have been loaded; review them and save again.');
        await qc.invalidateQueries({ queryKey: ['workspace'] });
      } else setError(e instanceof ApiProblem ? (e.errors[0]?.message ?? e.detail) : 'We couldn’t save the settings. Please try again.');
    },
  });

  if (q.isPending) return <><PageHeader title="Workspace settings" /><SkeletonRows rows={5} height={72} /></>;
  if (q.isError) return <><PageHeader title="Workspace settings" /><ErrorState error={q.error} onRetry={() => void q.refetch()} /></>;
  const disabled = !canManage || save.isPending;
  const chosen = ASSIGNMENT.find((a) => a.value === assignment);

  return (
    <>
      <PageHeader title="Workspace settings" description={`Name, time zone, currency, phone country and how new leads are handled for ${ws?.name}.`} />
      {!canManage && <Alert severity="info" sx={{ mb: 2 }}>You can read these settings. Changing them needs an owner or administrator.</Alert>}
      <Box component="form" noValidate onSubmit={(e) => { e.preventDefault(); if (dirty && replyValid && nameValid && canManage) save.mutate(); }}>
        <Box sx={{ display: 'grid', gap: 2.5, gridTemplateColumns: { xs: 'minmax(0, 1fr)', md: 'repeat(2, minmax(0, 1fr))' }, alignItems: 'start' }}>
          <Panel title="Workspace" note="Who this workspace is, and the time and money it uses.">
            <Stack spacing={2.25}>
              <TextField label="Workspace name" value={name} onChange={(e) => setName(e.target.value)} disabled={disabled} required error={!nameValid} helperText={nameValid ? undefined : 'Enter a name'} slotProps={{ htmlInput: { maxLength: 120 } }} />
              <TextField select label="Time zone" value={timezone} onChange={(e) => setTimezone(e.target.value)} disabled={disabled} helperText="Task reminders, recurring tasks, reports and the Dashboard’s days use this time zone." slotProps={{ select: { MenuProps: { slotProps: { paper: { sx: { maxHeight: 360 } } } } } }}>
                {zones.map((z) => <MenuItem key={z} value={z}>{z.replace(/_/g, ' ')}</MenuItem>)}
              </TextField>
              <TextField select label="Country for phone numbers" value={country} onChange={(e) => setCountry(e.target.value)} disabled={disabled} helperText="Used to read phone numbers that are typed without a country code.">
                <MenuItem value="">Not set</MenuItem>
                {COUNTRIES.map(([v, l]) => <MenuItem key={v} value={v}>{l}</MenuItem>)}
                {country && !COUNTRIES.some(([c]) => c === country) && <MenuItem value={country}>{country}</MenuItem>}
              </TextField>
              <TextField select label="Currency" value={currency} onChange={(e) => setCurrency(e.target.value)} disabled={disabled} helperText="Amounts on leads, properties, sales and reports are shown in this currency.">
                {CURRENCIES.map(([v, l]) => <MenuItem key={v} value={v}>{l}</MenuItem>)}
              </TextField>
            </Stack>
          </Panel>

          <Stack spacing={2.5}>
            <Panel title="New leads" note="Who gets a lead, and how fast someone should reply.">
              <Stack spacing={2.25}>
                <TextField select label="Who gets a new lead" value={assignment} onChange={(e) => setAssignment(e.target.value as Workspace['leadAssignment'])} disabled={disabled} helperText={chosen?.help}>
                  {ASSIGNMENT.map((a) => <MenuItem key={a.value} value={a.value}>{a.label}</MenuItem>)}
                </TextField>
                <TextField
                  label="Reply to a new lead within (minutes)"
                  type="number"
                  value={firstReply}
                  onChange={(e) => setFirstReply(e.target.value)}
                  disabled={disabled}
                  error={!replyValid}
                  helperText={replyValid ? 'Sets the first-reply deadline on every new lead and when the follow-up call task is due. 0 means no deadline (tasks then default to 30 minutes).' : 'Enter a whole number from 0 to 10080'}
                  slotProps={{ htmlInput: { min: 0, max: 10080, inputMode: 'numeric' } }}
                />
              </Stack>
            </Panel>
            <Panel title="Send leads to specific people" note="Optional. Send every lead to one person, or route by source. Several people share the work, the least busy first.">
              <RoutingEditor rules={routing} onChange={setRouting} disabled={disabled} />
            </Panel>
            <Panel title="Sales pipeline">
              <Typography variant="body2" color="text.secondary" sx={{ mb: 1 }}>Stages, their order and what counts as won or lost are managed in Pipelines. They are not edited here.</Typography>
              <Button component={RouterLink} to="/settings/pipelines" size="small">Open pipeline settings</Button>
            </Panel>
          </Stack>
        </Box>

        {canManage && (
          <Stack direction="row" spacing={1.5} sx={{ mt: 2.5, alignItems: 'center', justifyContent: 'flex-end', flexWrap: 'wrap', rowGap: 1 }}>
            {error && <Typography role="alert" variant="body2" color="error">{error}</Typography>}
            {dirty && !save.isPending && <Typography variant="body2" color="text.secondary">You have unsaved changes.</Typography>}
            <Button color="inherit" disabled={!dirty || save.isPending} onClick={() => { if (ws) { fill(ws); setError(''); } }}>Discard changes</Button>
            <Button type="submit" variant="contained" startIcon={<SaveOutlinedIcon />} disabled={!dirty || !replyValid || !nameValid || save.isPending}>{save.isPending ? 'Saving…' : 'Save changes'}</Button>
          </Stack>
        )}
      </Box>
    </>
  );
}
