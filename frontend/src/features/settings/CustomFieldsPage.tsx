import { useEffect, useState } from 'react';
import { Alert, Box, Button, Chip, Dialog, DialogActions, DialogContent, DialogTitle, FormControlLabel, IconButton, MenuItem, Paper, Stack, Switch, TextField, Tooltip, Typography } from '@mui/material';
import AddRoundedIcon from '@mui/icons-material/AddRounded';
import ArrowUpwardRoundedIcon from '@mui/icons-material/ArrowUpwardRounded';
import ArrowDownwardRoundedIcon from '@mui/icons-material/ArrowDownwardRounded';
import EditOutlinedIcon from '@mui/icons-material/EditOutlined';
import VisibilityOffOutlinedIcon from '@mui/icons-material/VisibilityOffOutlined';
import VisibilityOutlinedIcon from '@mui/icons-material/VisibilityOutlined';
import DeleteOutlineRoundedIcon from '@mui/icons-material/DeleteOutlineRounded';
import DynamicFormOutlinedIcon from '@mui/icons-material/DynamicFormOutlined';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { ApiProblem } from '@/api/client';
import { customFieldsApi, type CustomField, type FieldType } from '@/api/customFields';
import { useAuth } from '@/features/auth/AuthProvider';
import { useAgencyClients } from '@/features/agency/shared';
import { PageHeader } from '@/ui/PageHeader';
import { EmptyState, ErrorState, SkeletonRows } from '@/ui/states';
import { useConfirm } from '@/ui/confirm';
import { useToast } from '@/ui/toast';

const TYPE_LABEL: Record<FieldType, string> = { text: 'Short text', number: 'Number', date: 'Date', boolean: 'Yes / No', select: 'Pick one', multi_select: 'Pick several', url: 'Web link', email: 'E-mail' };
const TYPE_HINT: Record<FieldType, string> = {
  text: 'Anything typed, up to 500 characters',
  number: 'For amounts, counts, party size…',
  date: 'A calendar date',
  boolean: 'A yes or no answer',
  select: 'Choose one from a list you define',
  multi_select: 'Choose any number from a list you define',
  url: 'A web address',
  email: 'An e-mail address',
};

/**
 * The questions THIS client wants captured on every lead (service wanted, party size, budget…).
 * Each client has its own list. Hiding a field keeps every answer already saved.
 */
export function CustomFieldsPage() {
  const { can, isAgencyAdmin, tenant } = useAuth();
  const qc = useQueryClient();
  const toast = useToast();
  const confirm = useConfirm();
  const manage = can('custom_fields:manage');
  const [showHidden, setShowHidden] = useState(false);
  const [dialog, setDialog] = useState<{ field?: CustomField } | null>(null);
  const [copying, setCopying] = useState(false);
  const q = useQuery({ queryKey: ['custom-fields', 'all', showHidden], queryFn: () => customFieldsApi.list(undefined, showHidden) });
  const refresh = () => { void qc.invalidateQueries({ queryKey: ['custom-fields'] }); void qc.invalidateQueries({ queryKey: ['agency', 'fields'] }); };
  const fail = (e: unknown) => toast.error(e instanceof ApiProblem ? e.detail : 'That didn’t work. Please try again.');

  const toggle = useMutation({ mutationFn: (f: CustomField) => customFieldsApi.update(f.id, { visible: !f.visible }), onSuccess: (f) => { refresh(); toast.success(f.visible ? `“${f.label}” is back` : `“${f.label}” is hidden. Saved answers are kept.`); }, onError: fail });
  const reorder = useMutation({ mutationFn: (ids: string[]) => customFieldsApi.reorder(ids), onSuccess: refresh, onError: fail });

  const list = q.data ?? [];
  const visible = list.filter((f) => f.visible);
  const move = (f: CustomField, dir: -1 | 1) => {
    const ids = visible.map((x) => x.id);
    const i = ids.indexOf(f.id);
    const j = i + dir;
    if (i < 0 || j < 0 || j >= ids.length) return;
    [ids[i], ids[j]] = [ids[j]!, ids[i]!];
    reorder.mutate(ids);
  };

  return (
    <>
      <PageHeader
        title="Lead fields"
        description={`The extra details ${tenant?.name ?? 'this client'} wants on every lead. They show on the lead, in the new-lead form, in filters and in downloads.`}
        actions={manage && (
          <>
            {isAgencyAdmin && <Button variant="outlined" onClick={() => setCopying(true)}>Copy from another client</Button>}
            <Button variant="contained" startIcon={<AddRoundedIcon />} onClick={() => setDialog({})}>Add field</Button>
          </>
        )}
      />
      {q.isPending ? (
        <SkeletonRows rows={5} height={64} />
      ) : q.isError ? (
        <ErrorState error={q.error} onRetry={() => void q.refetch()} />
      ) : (
        <>
          {list.length === 0 ? (
            <Paper variant="outlined"><EmptyState icon={<DynamicFormOutlinedIcon />} title="No lead fields yet" description="Add the questions your team should record on each lead, for example what service they want or how many people are coming." action={manage ? <Button variant="contained" onClick={() => setDialog({})}>Add the first field</Button> : undefined} /></Paper>
          ) : (
            <Paper variant="outlined" sx={{ borderRadius: 2.5, maxWidth: 820 }}>
              {list.map((f) => {
                const idx = visible.findIndex((x) => x.id === f.id);
                return (
                  <Stack key={f.id} direction="row" spacing={1.5} sx={{ px: 2, py: 1.5, alignItems: 'center', borderBottom: 1, borderColor: 'divider', '&:last-child': { borderBottom: 0 }, opacity: f.visible ? 1 : 0.6 }}>
                    <Box sx={{ minWidth: 0, flex: 1 }}>
                      <Stack direction="row" spacing={1} sx={{ alignItems: 'center', flexWrap: 'wrap', rowGap: 0.5 }}>
                        <Typography variant="subtitle2">{f.label}</Typography>
                        <Chip size="small" variant="outlined" label={TYPE_LABEL[f.type]} />
                        {f.required && <Chip size="small" color="primary" variant="outlined" label="Required" />}
                        {!f.visible && <Chip size="small" label="Hidden" />}
                      </Stack>
                      <Typography variant="caption" color="text.secondary" component="div" noWrap>
                        {f.helpText ? `${f.helpText} · ` : ''}{f.type === 'select' || f.type === 'multi_select' ? f.options.map((o) => o.label).join(', ') : TYPE_HINT[f.type]}
                      </Typography>
                    </Box>
                    {manage && (
                      <Stack direction="row" spacing={0.25}>
                        {f.visible && <Tooltip title="Move up"><span><IconButton size="small" aria-label={`Move ${f.label} up`} disabled={idx <= 0 || reorder.isPending} onClick={() => move(f, -1)}><ArrowUpwardRoundedIcon fontSize="small" /></IconButton></span></Tooltip>}
                        {f.visible && <Tooltip title="Move down"><span><IconButton size="small" aria-label={`Move ${f.label} down`} disabled={idx < 0 || idx >= visible.length - 1 || reorder.isPending} onClick={() => move(f, 1)}><ArrowDownwardRoundedIcon fontSize="small" /></IconButton></span></Tooltip>}
                        <Tooltip title="Edit"><IconButton size="small" aria-label={`Edit ${f.label}`} onClick={() => setDialog({ field: f })}><EditOutlinedIcon fontSize="small" /></IconButton></Tooltip>
                        <Tooltip title={f.visible ? 'Hide (keeps saved answers)' : 'Show again'}>
                          <IconButton size="small" aria-label={`${f.visible ? 'Hide' : 'Show'} ${f.label}`} disabled={toggle.isPending} onClick={async () => { if (!f.visible || (await confirm({ title: `Hide “${f.label}”?`, body: 'It disappears from forms and filters. Answers already saved on leads are kept, and it still appears in downloads. You can show it again any time.', confirmLabel: 'Hide' }))) toggle.mutate(f); }}>
                            {f.visible ? <VisibilityOffOutlinedIcon fontSize="small" /> : <VisibilityOutlinedIcon fontSize="small" />}
                          </IconButton>
                        </Tooltip>
                      </Stack>
                    )}
                  </Stack>
                );
              })}
            </Paper>
          )}
          <FormControlLabel sx={{ mt: 1.5 }} control={<Switch size="small" checked={showHidden} onChange={(_, v) => setShowHidden(v)} />} label="Show hidden fields" />
        </>
      )}
      <FieldDialog state={dialog} onClose={() => setDialog(null)} onSaved={refresh} />
      <CopyDialog open={copying} onClose={() => setCopying(false)} onDone={refresh} />
    </>
  );
}

interface OptionRow {
  value?: string;
  label: string;
}

function FieldDialog({ state, onClose, onSaved }: { state: { field?: CustomField } | null; onClose: () => void; onSaved: () => void }) {
  const toast = useToast();
  const editing = state?.field;
  const [label, setLabel] = useState('');
  const [type, setType] = useState<FieldType>('text');
  const [required, setRequired] = useState(false);
  const [helpText, setHelpText] = useState('');
  const [options, setOptions] = useState<OptionRow[]>([{ label: '' }]);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    if (state) {
      const f = state.field;
      setLabel(f?.label ?? '');
      setType(f?.type ?? 'text');
      setRequired(f?.required ?? false);
      setHelpText(f?.helpText ?? '');
      setOptions(f && f.options.length ? f.options.map((o) => ({ value: o.value, label: o.label })) : [{ label: '' }]);
      setError(null);
    }
  }, [state]);

  const choice = type === 'select' || type === 'multi_select';
  const save = useMutation({
    mutationFn: () => {
      const opts = options.map((o) => ({ ...o, label: o.label.trim() })).filter((o) => o.label).map((o) => (o.value ? { value: o.value, label: o.label } : o.label));
      if (editing) return customFieldsApi.update(editing.id, { label: label.trim(), required, helpText: helpText.trim() || null, ...(choice ? { options: opts } : {}) });
      return customFieldsApi.create({ label: label.trim(), type, required, helpText: helpText.trim() || null, ...(choice ? { options: opts } : {}) });
    },
    onSuccess: (f) => { toast.success(editing ? 'Saved' : `“${f.label}” added`); onSaved(); onClose(); },
    onError: (e) => setError(e instanceof ApiProblem ? (e.errors[0]?.message ?? e.detail) : 'Couldn’t save. Please try again.'),
  });

  return (
    <Dialog open={Boolean(state)} onClose={save.isPending ? undefined : onClose} fullWidth maxWidth="sm" aria-labelledby="field-dialog-title">
      <DialogTitle id="field-dialog-title">{editing ? `Edit “${editing.label}”` : 'Add a lead field'}</DialogTitle>
      <DialogContent>
        <Stack component="form" id="field-form" noValidate spacing={2} sx={{ pt: 0.5 }} onSubmit={(e) => { e.preventDefault(); setError(null); save.mutate(); }}>
          {error && <Alert severity="error">{error}</Alert>}
          <TextField label="Question or name" autoFocus required fullWidth value={label} onChange={(e) => setLabel(e.target.value)} helperText="e.g. Service needed, Party size, Preferred date" slotProps={{ htmlInput: { maxLength: 60 } }} />
          <TextField select label="Kind of answer" fullWidth value={type} onChange={(e) => setType(e.target.value as FieldType)} disabled={Boolean(editing)} helperText={editing ? 'The kind of answer can’t be changed once the field exists.' : TYPE_HINT[type]}>
            {(Object.keys(TYPE_LABEL) as FieldType[]).map((t) => <MenuItem key={t} value={t}>{TYPE_LABEL[t]}</MenuItem>)}
          </TextField>
          {choice && (
            <Box>
              <Typography variant="subtitle2" sx={{ mb: 0.75 }}>The choices</Typography>
              <Stack spacing={1}>
                {options.map((o, i) => (
                  <Stack key={i} direction="row" spacing={0.5} sx={{ alignItems: 'center' }}>
                    <TextField size="small" fullWidth value={o.label} placeholder={`Choice ${i + 1}`} onChange={(e) => setOptions(options.map((x, j) => (j === i ? { ...x, label: e.target.value } : x)))} slotProps={{ htmlInput: { 'aria-label': `Choice ${i + 1}`, maxLength: 80 } }}
                      onKeyDown={(e) => { if (e.key === 'Enter') { e.preventDefault(); setOptions([...options.slice(0, i + 1), { label: '' }, ...options.slice(i + 1)]); } }} />
                    <IconButton size="small" aria-label={`Remove choice ${i + 1}`} disabled={options.length <= 1} onClick={() => setOptions(options.filter((_, j) => j !== i))}><DeleteOutlineRoundedIcon fontSize="small" /></IconButton>
                  </Stack>
                ))}
              </Stack>
              <Button size="small" startIcon={<AddRoundedIcon />} sx={{ mt: 1 }} onClick={() => setOptions([...options, { label: '' }])}>Add a choice</Button>
              {editing && <Typography variant="caption" color="text.secondary" component="p" sx={{ mt: 0.5 }}>Renaming a choice keeps leads that already picked it. A choice that leads still use can’t be deleted.</Typography>}
            </Box>
          )}
          <TextField label="Hint for your team (optional)" fullWidth value={helpText} onChange={(e) => setHelpText(e.target.value)} slotProps={{ htmlInput: { maxLength: 200 } }} />
          <FormControlLabel control={<Switch checked={required} onChange={(_, v) => setRequired(v)} />} label="Required when someone adds a lead by hand" />
          {required && <Typography variant="caption" color="text.secondary" sx={{ mt: '-8px !important' }}>Leads that arrive automatically (ad forms, imports) are never blocked by this.</Typography>}
        </Stack>
      </DialogContent>
      <DialogActions>
        <Button onClick={onClose} disabled={save.isPending}>Cancel</Button>
        <Button type="submit" form="field-form" variant="contained" disabled={save.isPending || !label.trim()}>{save.isPending ? 'Saving…' : editing ? 'Save' : 'Add field'}</Button>
      </DialogActions>
    </Dialog>
  );
}

function CopyDialog({ open, onClose, onDone }: { open: boolean; onClose: () => void; onDone: () => void }) {
  const toast = useToast();
  const { scopeId } = useAuth();
  const clients = useAgencyClients('active');
  const [from, setFrom] = useState('');
  const [error, setError] = useState<string | null>(null);
  const copy = useMutation({
    mutationFn: () => customFieldsApi.copyFrom(scopeId!, from),
    onSuccess: (r) => { toast.success(r.copied.length ? `Copied ${r.copied.length} field${r.copied.length === 1 ? '' : 's'}${r.skipped.length ? ` (${r.skipped.length} already here)` : ''}` : 'Nothing to copy: this client already has all of them'); onDone(); onClose(); },
    onError: (e) => setError(e instanceof ApiProblem ? e.detail : 'Couldn’t copy. Please try again.'),
  });
  const choices = (clients.data ?? []).filter((c) => c.id !== scopeId);
  return (
    <Dialog open={open} onClose={copy.isPending ? undefined : onClose} fullWidth maxWidth="xs" aria-labelledby="copy-fields-title">
      <DialogTitle id="copy-fields-title">Copy lead fields from another client</DialogTitle>
      <DialogContent>
        <Stack spacing={2} sx={{ pt: 0.5 }}>
          {error && <Alert severity="error">{error}</Alert>}
          <Typography variant="body2" color="text.secondary">Copies the questions only, never any lead data. Fields this client already has are left as they are.</Typography>
          <TextField select label="Copy from" value={from} onChange={(e) => setFrom(e.target.value)} fullWidth>
            {choices.map((c) => <MenuItem key={c.id} value={c.id}>{c.name}</MenuItem>)}
          </TextField>
        </Stack>
      </DialogContent>
      <DialogActions>
        <Button onClick={onClose} disabled={copy.isPending}>Cancel</Button>
        <Button variant="contained" disabled={!from || copy.isPending} onClick={() => { setError(null); copy.mutate(); }}>{copy.isPending ? 'Copying…' : 'Copy'}</Button>
      </DialogActions>
    </Dialog>
  );
}
