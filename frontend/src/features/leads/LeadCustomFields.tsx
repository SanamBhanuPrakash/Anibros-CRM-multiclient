import { useEffect, useState } from 'react';
import { Alert, Box, Button, Dialog, DialogActions, DialogContent, DialogTitle, Paper, Stack, Typography } from '@mui/material';
import EditOutlinedIcon from '@mui/icons-material/EditOutlined';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { ApiProblem } from '@/api/client';
import { leadsApi, type LeadDetail } from '@/api/crm';
import { useToast } from '@/ui/toast';
import { CustomFieldInputs, buildCustomFields, showFieldValue, useLeadFields } from './CustomFieldInputs';

/** The client's own questions about this lead (service wanted, party size, product…), with their answers. Shown only if the client has any. */
export function LeadCustomFields({ lead, canEdit }: { lead: LeadDetail; canEdit: boolean }) {
  const fields = useLeadFields();
  const [editing, setEditing] = useState(false);
  const shown = fields.data ?? [];
  // Values saved under a field that was later hidden are still shown (read-only) so nothing the team recorded disappears.
  if (!shown.length && !Object.keys(lead.customFields).length) return null;
  const known = new Set(shown.map((f) => f.key));
  const orphans = Object.entries(lead.customFields).filter(([k]) => !known.has(k));
  return (
    <Paper variant="outlined" sx={{ p: 2.5, borderRadius: 2.5 }}>
      <Stack direction="row" sx={{ alignItems: 'center', justifyContent: 'space-between', mb: 1.5 }}>
        <Typography variant="h5" component="h2">About this lead</Typography>
        {canEdit && shown.length > 0 && <Button size="small" startIcon={<EditOutlinedIcon />} onClick={() => setEditing(true)}>Edit</Button>}
      </Stack>
      <Box component="dl" sx={{ m: 0, display: 'grid', gridTemplateColumns: 'auto 1fr', columnGap: 2.5, rowGap: 1, '& dt': { color: 'text.secondary', typography: 'body2' }, '& dd': { m: 0, typography: 'body2', textAlign: 'right', minWidth: 0, wordBreak: 'break-word' } }}>
        {shown.map((f) => (
          <Box key={f.key} sx={{ display: 'contents' }}>
            <dt>{f.label}</dt>
            <dd>{f.type === 'url' && typeof lead.customFields[f.key] === 'string' ? <a href={String(lead.customFields[f.key])} target="_blank" rel="noopener noreferrer">{String(lead.customFields[f.key])}</a> : showFieldValue(f, lead.customFields[f.key])}</dd>
          </Box>
        ))}
        {orphans.map(([k, v]) => (
          <Box key={k} sx={{ display: 'contents' }}>
            <dt>{k.replace(/_/g, ' ')}</dt>
            <dd>{typeof v === 'object' ? JSON.stringify(v) : String(v)}</dd>
          </Box>
        ))}
      </Box>
      <EditFieldsDialog open={editing} lead={lead} onClose={() => setEditing(false)} />
    </Paper>
  );
}

function EditFieldsDialog({ open, lead, onClose }: { open: boolean; lead: LeadDetail; onClose: () => void }) {
  const qc = useQueryClient();
  const toast = useToast();
  const fields = useLeadFields();
  const [values, setValues] = useState<Record<string, unknown>>({});
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [error, setError] = useState<string | null>(null);
  useEffect(() => {
    if (open) {
      setValues({ ...lead.customFields });
      setErrors({});
      setError(null);
    }
  }, [open, lead.customFields]);
  const save = useMutation({
    mutationFn: () => leadsApi.update(lead.id, lead.version, { customFields: buildCustomFields(fields.data ?? [], values, 'edit') }),
    onSuccess: () => { void qc.invalidateQueries({ queryKey: ['leads'] }); toast.success('Saved'); onClose(); },
    onError: (e) => {
      if (!(e instanceof ApiProblem)) return setError('Couldn’t save. Please try again.');
      if (e.status === 412) return setError('Someone else changed this lead while you were editing. Close this and reopen it to see their changes.');
      const mapped: Record<string, string> = {};
      for (const [k, m] of Object.entries(e.fieldErrors())) if (k.startsWith('customFields.')) mapped[k.slice('customFields.'.length)] = m.replace(/^[^:]+: /, '');
      if (Object.keys(mapped).length) setErrors(mapped);
      else setError(e.errors[0]?.message ?? e.detail);
    },
  });
  return (
    <Dialog open={open} onClose={save.isPending ? undefined : onClose} fullWidth maxWidth="sm" aria-labelledby="edit-fields-title">
      <DialogTitle id="edit-fields-title">About this lead</DialogTitle>
      <DialogContent>
        <Stack component="form" id="edit-fields-form" spacing={2} sx={{ pt: 0.5 }} onSubmit={(e) => { e.preventDefault(); setError(null); setErrors({}); save.mutate(); }}>
          {error && <Alert severity="error">{error}</Alert>}
          <CustomFieldInputs fields={fields.data ?? []} values={values} errors={errors} onChange={(k, v) => setValues((cur) => ({ ...cur, [k]: v }))} />
        </Stack>
      </DialogContent>
      <DialogActions>
        <Button onClick={onClose} disabled={save.isPending}>Cancel</Button>
        <Button type="submit" form="edit-fields-form" variant="contained" disabled={save.isPending}>{save.isPending ? 'Saving…' : 'Save'}</Button>
      </DialogActions>
    </Dialog>
  );
}
