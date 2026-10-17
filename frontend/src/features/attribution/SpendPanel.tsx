import { useEffect, useState } from 'react';
import { Alert, Box, Button, Dialog, DialogActions, DialogContent, DialogContentText, DialogTitle, IconButton, InputAdornment, MenuItem, Stack, Table, TableBody, TableCell, TableHead, TableRow, TextField, Tooltip, Typography } from '@mui/material';
import AddRoundedIcon from '@mui/icons-material/AddRounded';
import EditOutlinedIcon from '@mui/icons-material/EditOutlined';
import DeleteOutlineRoundedIcon from '@mui/icons-material/DeleteOutlineRounded';
import FileUploadOutlinedIcon from '@mui/icons-material/FileUploadOutlined';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { ApiProblem } from '@/api/client';
import { analyticsApi, type Spend, type SpendImportResult } from '@/api/analytics';
import { formatMoney } from '@/features/leads/shared';
import { Panel } from '@/ui/Panel';
import { EmptyState, ErrorState, SkeletonRows } from '@/ui/states';
import { useConfirm } from '@/ui/confirm';
import { useToast } from '@/ui/toast';

const SOURCES: Array<[string, string]> = [['meta_ads', 'Meta ads'], ['google_ads', 'Google ads'], ['website', 'Website'], ['portal', 'Property portal'], ['referral', 'Referral'], ['walk_in', 'Walk-in'], ['direct', 'Direct']];
const CHANNELS: Array<[string, string]> = [['paid_social', 'Paid social'], ['paid_search', 'Paid search'], ['website', 'Website'], ['referral', 'Referral'], ['portal', 'Property portals'], ['offline', 'Offline'], ['direct', 'Direct'], ['untracked', 'Not recorded']];
const refresh = (qc: ReturnType<typeof useQueryClient>) => { for (const k of ['attribution', 'spend', 'analytics', 'reports']) void qc.invalidateQueries({ queryKey: [k] }); };
const problem = (e: unknown, fallback: string) => (e instanceof ApiProblem ? (e.errors[0]?.message ?? e.detail ?? fallback) : fallback);

/** Spend entered by hand or by CSV (ad-platform sync needs accounts and comes later), and which channel each source is grouped under. */
export function SpendPanel({ currency, canManage }: { currency: string; canManage: boolean }) {
  const qc = useQueryClient();
  const toast = useToast();
  const confirm = useConfirm();
  const [editing, setEditing] = useState<Spend | 'new' | null>(null);
  const [importing, setImporting] = useState(false);
  const spend = useQuery({ queryKey: ['spend', 'list'], queryFn: () => analyticsApi.spend() });
  const remove = useMutation({
    mutationFn: (s: Spend) => analyticsApi.deleteSpend(s.id),
    onSuccess: () => { refresh(qc); toast.success('Spend entry deleted'); },
    onError: (e) => toast.error(problem(e, 'Couldn’t delete it')),
  });
  return (
    <Stack spacing={2.5}>
      <Panel
        title="Marketing spend"
        note="What you spent, by source and (optionally) campaign. Each entry is spread evenly over its own days, so any period gets its fair share. Entries for the same source and campaign cannot overlap."
        action={canManage ? (
          <Stack direction="row" spacing={1}>
            <Button size="small" startIcon={<FileUploadOutlinedIcon />} onClick={() => setImporting(true)}>Import CSV</Button>
            <Button size="small" variant="contained" startIcon={<AddRoundedIcon />} onClick={() => setEditing('new')}>Add spend</Button>
          </Stack>
        ) : undefined}
      >
        {spend.isPending ? <SkeletonRows rows={3} height={44} /> : spend.isError ? <ErrorState error={spend.error} onRetry={() => void spend.refetch()} /> : spend.data.length === 0 ? (
          <EmptyState title="No spend entered yet" description={canManage ? 'Add what you spent on each source to see cost per lead, cost per sale and return. Type it in, or import a CSV with the columns source, campaign, from, to, amount.' : 'Spend appears here once an owner, administrator or marketing manager enters it.'} action={canManage ? <Button variant="contained" onClick={() => setEditing('new')}>Add the first entry</Button> : undefined} />
        ) : (
          <Box sx={{ overflowX: 'auto' }}>
            <Table size="small" aria-label="Spend entries">
              <TableHead>
                <TableRow>
                  <TableCell>Period</TableCell>
                  <TableCell>Source</TableCell>
                  <TableCell>Campaign</TableCell>
                  <TableCell align="right">Amount</TableCell>
                  <TableCell>Note</TableCell>
                  {canManage && <TableCell align="right"><span className="visually-hidden" style={{ position: 'absolute', width: 1, height: 1, overflow: 'hidden', clip: 'rect(0 0 0 0)' }}>Actions</span></TableCell>}
                </TableRow>
              </TableHead>
              <TableBody>
                {spend.data.map((s) => (
                  <TableRow key={s.id}>
                    <TableCell sx={{ whiteSpace: 'nowrap' }}>{s.periodStart} → {s.periodEnd}</TableCell>
                    <TableCell>{s.sourceLabel}</TableCell>
                    <TableCell>{s.campaign ?? <Typography component="span" variant="body2" color="text.secondary">Whole source</Typography>}</TableCell>
                    <TableCell align="right" sx={{ fontWeight: 600 }}>{formatMoney(s.amount, s.currency)}</TableCell>
                    <TableCell sx={{ maxWidth: 240, overflowWrap: 'anywhere' }}>{s.note ?? ''}</TableCell>
                    {canManage && (
                      <TableCell align="right" sx={{ whiteSpace: 'nowrap' }}>
                        <Tooltip title="Edit"><IconButton size="small" aria-label={`Edit spend for ${s.sourceLabel}, ${s.periodStart}`} onClick={() => setEditing(s)}><EditOutlinedIcon fontSize="small" /></IconButton></Tooltip>
                        <Tooltip title="Delete"><IconButton size="small" aria-label={`Delete spend for ${s.sourceLabel}, ${s.periodStart}`} onClick={async () => { if (await confirm({ title: 'Delete this spend entry?', body: `${formatMoney(s.amount, s.currency)} on ${s.sourceLabel}${s.campaign ? ` · ${s.campaign}` : ''}, ${s.periodStart} to ${s.periodEnd}. Reports will no longer include it.`, confirmLabel: 'Delete', destructive: true })) remove.mutate(s); }}><DeleteOutlineRoundedIcon fontSize="small" /></IconButton></Tooltip>
                      </TableCell>
                    )}
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          </Box>
        )}
      </Panel>
      <ChannelsPanel canManage={canManage} />
      <SpendDialog target={editing} currency={currency} onClose={() => setEditing(null)} />
      <ImportDialog open={importing} onClose={() => setImporting(false)} />
    </Stack>
  );
}

function ChannelsPanel({ canManage }: { canManage: boolean }) {
  const qc = useQueryClient();
  const toast = useToast();
  const q = useQuery({ queryKey: ['attribution', 'channels'], queryFn: analyticsApi.channels });
  const set = useMutation({
    mutationFn: (v: { source: string; channel: string }) => analyticsApi.setChannel(v.source, v.channel),
    onSuccess: () => { refresh(qc); toast.success('Channel updated. Reports group this source under it from now on.'); },
    onError: (e) => toast.error(problem(e, 'Couldn’t change the channel')),
  });
  return (
    <Panel title="Channels" note="Each source belongs to a channel (the kind of marketing). Change the grouping if it does not fit how you work; past leads regroup at once.">
      {q.isPending ? <SkeletonRows rows={4} height={40} /> : q.isError ? <ErrorState error={q.error} onRetry={() => void q.refetch()} /> : (
        <Table size="small" aria-label="Channel for each source">
          <TableHead>
            <TableRow><TableCell>Source</TableCell><TableCell>Channel</TableCell></TableRow>
          </TableHead>
          <TableBody>
            {q.data.map((m) => (
              <TableRow key={m.source}>
                <TableCell>{m.label}</TableCell>
                <TableCell sx={{ width: 260 }}>
                  {canManage ? (
                    <TextField select value={m.channel} onChange={(e) => set.mutate({ source: m.source, channel: e.target.value })} disabled={set.isPending} slotProps={{ htmlInput: { 'aria-label': `Channel for ${m.label}` } }} helperText={m.isDefault ? 'Default' : 'Changed from the default'}>
                      {CHANNELS.map(([v, l]) => <MenuItem key={v} value={v}>{l}</MenuItem>)}
                    </TextField>
                  ) : <>{m.channelLabel}{m.isDefault ? '' : ' (changed)'}</>}
                </TableCell>
              </TableRow>
            ))}
          </TableBody>
        </Table>
      )}
    </Panel>
  );
}

function SpendDialog({ target, currency, onClose }: { target: Spend | 'new' | null; currency: string; onClose: () => void }) {
  const qc = useQueryClient();
  const toast = useToast();
  const edit = target && target !== 'new' ? target : null;
  const [source, setSource] = useState('meta_ads');
  const [campaign, setCampaign] = useState('');
  const [start, setStart] = useState('');
  const [end, setEnd] = useState('');
  const [amount, setAmount] = useState('');
  const [note, setNote] = useState('');
  const [error, setError] = useState('');
  useEffect(() => {
    if (!target) return;
    setError('');
    setSource(edit?.source ?? 'meta_ads');
    setCampaign(edit?.campaign ?? '');
    setStart(edit?.periodStart ?? '');
    setEnd(edit?.periodEnd ?? '');
    setAmount(edit ? String(edit.amount) : '');
    setNote(edit?.note ?? '');
  }, [target]); // eslint-disable-line react-hooks/exhaustive-deps
  const amt = Number(amount.replace(/[, ]/g, ''));
  const valid = Boolean(start && end && end >= start && amount.trim() !== '' && Number.isFinite(amt) && amt >= 0);
  const save = useMutation({
    mutationFn: () => (edit
      ? analyticsApi.updateSpend(edit.id, edit.version, { campaign: campaign.trim() || null, periodStart: start, periodEnd: end, amount: amt, note: note.trim() || null })
      : analyticsApi.addSpend({ source, campaign: campaign.trim() || null, periodStart: start, periodEnd: end, amount: amt, note: note.trim() || null })),
    onSuccess: () => { refresh(qc); toast.success(edit ? 'Spend updated' : 'Spend added'); onClose(); },
    onError: (e) => setError(problem(e, 'We couldn’t save it. Please try again.')),
  });
  return (
    <Dialog open={Boolean(target)} onClose={onClose} maxWidth="xs" aria-labelledby="spend-title">
      <DialogTitle id="spend-title">{edit ? 'Edit spend' : 'Add spend'}</DialogTitle>
      <DialogContent>
        <Stack component="form" id="spend-form" spacing={2} sx={{ pt: 0.5 }} onSubmit={(e) => { e.preventDefault(); if (valid) save.mutate(); }}>
          {error && <Alert severity="error">{error}</Alert>}
          <TextField select label="Source" value={source} onChange={(e) => setSource(e.target.value)} disabled={Boolean(edit)} helperText={edit ? 'The source of an entry cannot be changed. Delete it and add a new one.' : undefined}>
            {SOURCES.map(([v, l]) => <MenuItem key={v} value={v}>{l}</MenuItem>)}
          </TextField>
          <TextField label="Campaign (optional)" value={campaign} onChange={(e) => setCampaign(e.target.value)} helperText="Leave empty for spend on the whole source. Use the same name your leads carry so it lines up." slotProps={{ htmlInput: { maxLength: 150 } }} />
          <Stack direction="row" spacing={1.5}>
            <TextField label="From" type="date" value={start} onChange={(e) => setStart(e.target.value)} required slotProps={{ inputLabel: { shrink: true } }} />
            <TextField label="To" type="date" value={end} onChange={(e) => setEnd(e.target.value)} required error={Boolean(start && end && end < start)} slotProps={{ inputLabel: { shrink: true }, htmlInput: { min: start } }} />
          </Stack>
          <TextField label={`Amount (${currency})`} value={amount} onChange={(e) => setAmount(e.target.value)} required slotProps={{ htmlInput: { inputMode: 'decimal' }, input: { startAdornment: <InputAdornment position="start">{currency === 'INR' ? '₹' : currency}</InputAdornment> } }} helperText="The total for the whole period, up to a year." />
          <TextField label="Note (optional)" value={note} onChange={(e) => setNote(e.target.value)} slotProps={{ htmlInput: { maxLength: 300 } }} />
        </Stack>
      </DialogContent>
      <DialogActions>
        <Button onClick={onClose}>Cancel</Button>
        <Button type="submit" form="spend-form" variant="contained" disabled={!valid || save.isPending}>{save.isPending ? 'Saving…' : 'Save'}</Button>
      </DialogActions>
    </Dialog>
  );
}

function ImportDialog({ open, onClose }: { open: boolean; onClose: () => void }) {
  const qc = useQueryClient();
  const toast = useToast();
  const [csv, setCsv] = useState('');
  const [name, setName] = useState('');
  const [preview, setPreview] = useState<SpendImportResult | null>(null);
  const [errors, setErrors] = useState<string[]>([]);
  const [message, setMessage] = useState('');
  useEffect(() => { if (open) { setCsv(''); setName(''); setPreview(null); setErrors([]); setMessage(''); } }, [open]);
  const check = useMutation({
    mutationFn: (dry: boolean) => analyticsApi.importSpend(csv, dry),
    onSuccess: (r) => {
      setErrors([]);
      setMessage('');
      if (r.dryRun) setPreview(r);
      else { refresh(qc); toast.success(`${r.created} ${r.created === 1 ? 'entry' : 'entries'} imported${r.skipped ? `, ${r.skipped} already saved` : ''}`); onClose(); }
    },
    onError: (e) => {
      setPreview(null);
      if (e instanceof ApiProblem && e.errors.length) { setErrors(e.errors.map((x) => `${x.path}: ${x.message}`)); setMessage(e.detail ?? ''); }
      else { setErrors([]); setMessage(problem(e, 'We couldn’t read that file.')); }
    },
  });
  const onFile = async (f: File | undefined) => {
    if (!f) return;
    setName(f.name);
    setPreview(null);
    setErrors([]);
    setMessage('');
    if (f.size > 900_000) return setMessage('That file is too large. Split it into files of under 1,000 rows.');
    setCsv(await f.text());
  };
  return (
    <Dialog open={open} onClose={onClose} maxWidth="sm" aria-labelledby="import-title">
      <DialogTitle id="import-title">Import spend from a CSV</DialogTitle>
      <DialogContent>
        <DialogContentText sx={{ mb: 1.5 }}>
          Columns (by header): <b>source</b>, <b>campaign</b> (optional), <b>from</b>, <b>to</b> (YYYY-MM-DD), <b>amount</b>, note (optional). Sources can be written as “Meta”, “Google Ads”, “Facebook” and so on. The whole file is checked first and nothing is saved if any row is wrong. Rows already saved are skipped, so importing the same file twice changes nothing.
        </DialogContentText>
        <Stack spacing={1.5}>
          <Button component="label" variant="outlined" startIcon={<FileUploadOutlinedIcon />} sx={{ alignSelf: 'flex-start' }}>
            {name || 'Choose a CSV file'}
            <input hidden type="file" accept=".csv,text/csv,text/plain" onChange={(e) => void onFile(e.target.files?.[0])} />
          </Button>
          <TextField label="…or paste the rows" multiline minRows={4} value={csv} onChange={(e) => { setCsv(e.target.value); setPreview(null); }} slotProps={{ htmlInput: { style: { fontFamily: 'var(--a-fontFamily-mono, monospace)', fontSize: 12 } } }} />
          {message && <Alert severity="error">{message}</Alert>}
          {errors.length > 0 && <Alert severity="error"><Box component="ul" sx={{ m: 0, pl: 2 }}>{errors.slice(0, 12).map((e) => <li key={e}>{e}</li>)}</Box></Alert>}
          {preview && (
            <Alert severity="success">
              {preview.created} new {preview.created === 1 ? 'entry' : 'entries'}{preview.skipped ? `, ${preview.skipped} already saved (skipped)` : ''}. Nothing is saved until you import.
              <Box component="ul" sx={{ m: 0, mt: 0.5, pl: 2 }}>
                {preview.preview.slice(0, 6).map((p) => <li key={p.row}>Row {p.row}: {p.sourceLabel}{p.campaign ? ` · ${p.campaign}` : ''}, {p.periodStart} → {p.periodEnd}, {p.amount.toLocaleString()} ({p.status === 'new' ? 'new' : 'already saved'})</li>)}
              </Box>
            </Alert>
          )}
        </Stack>
      </DialogContent>
      <DialogActions>
        <Button onClick={onClose}>Cancel</Button>
        <Button disabled={!csv.trim() || check.isPending} onClick={() => check.mutate(true)}>Check the file</Button>
        <Button variant="contained" disabled={!preview || preview.created === 0 || check.isPending} onClick={() => check.mutate(false)}>Import {preview?.created ?? 0}</Button>
      </DialogActions>
    </Dialog>
  );
}
