import { useEffect, useState } from 'react';
import { Alert, Autocomplete, Button, Chip, Dialog, DialogActions, DialogContent, DialogTitle, InputAdornment, Link as MuiLink, MenuItem, Stack, TextField } from '@mui/material';
import { Controller, useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { ApiProblem } from '@/api/client';
import { leadsApi, type Lead, type LeadSource } from '@/api/crm';
import { useAuth } from '@/features/auth/AuthProvider';
import { useToast } from '@/ui/toast';
import { SOURCE_LABEL, useDirectory } from './shared';
import { CustomFieldInputs, buildCustomFields, useLeadFields } from './CustomFieldInputs';

const schema = z
  .object({
    firstName: z.string().trim().min(1, 'First name is required').max(100),
    lastName: z.string().trim().max(100).optional(),
    email: z.string().trim().max(254).optional(),
    phone: z.string().trim().max(40).optional(),
    source: z.enum(['website', 'referral', 'portal', 'direct', 'meta_ads', 'google_ads', 'walk_in', 'other']),
    estimatedValue: z.string().trim().optional(),
    budgetMax: z.string().trim().optional(),
    locations: z.array(z.string()),
    tags: z.array(z.string()),
    ownerId: z.string().optional(),
  })
  .refine((v) => v.email || v.phone, { path: ['phone'], message: 'Add a phone number or an email address' });
type FormValues = z.infer<typeof schema>;

const empty: FormValues = { firstName: '', lastName: '', email: '', phone: '', source: 'direct', estimatedValue: '', budgetMax: '', locations: [], tags: [], ownerId: '' };

/** Rupee amounts as people type them: "85,00,000", "1.2cr", "50 lakh" → number (or undefined when empty, NaN when unreadable). */
export function parseMoney(raw: string | undefined): number | undefined {
  const t = (raw ?? '').toLowerCase().replace(/[,₹\s]/g, '');
  if (!t) return undefined;
  const m = /^(\d+(?:\.\d+)?)(cr|crore|l|lac|lakh|k)?$/.exec(t);
  if (!m) return Number.NaN;
  const n = Number(m[1]);
  const mult = m[2] === 'cr' || m[2] === 'crore' ? 1e7 : m[2] === 'l' || m[2] === 'lac' || m[2] === 'lakh' ? 1e5 : m[2] === 'k' ? 1e3 : 1;
  return Math.round(n * mult);
}

export function NewLeadDialog({ open, onClose, onCreated, onOpenExisting }: { open: boolean; onClose: () => void; onCreated: (l: Lead) => void; onOpenExisting: (leadId: string) => void }) {
  const qc = useQueryClient();
  const toast = useToast();
  const { scope, me, tenant } = useAuth();
  const realEstate = tenant?.industry === 'real_estate';
  const currency = tenant?.currency ?? 'INR';
  const symbol = new Intl.NumberFormat(undefined, { style: 'currency', currency, currencyDisplay: 'narrowSymbol' }).formatToParts(0).find((p) => p.type === 'currency')?.value ?? currency;
  const fields = useLeadFields();
  const [custom, setCustom] = useState<Record<string, unknown>>({});
  const [customErrors, setCustomErrors] = useState<Record<string, string>>({});
  const canAssign = scope('leads:assign') === 'all';
  const directory = useDirectory();
  const [formError, setFormError] = useState<string | null>(null);
  const [duplicateLeadId, setDuplicateLeadId] = useState<string | null>(null);
  const { control, register, handleSubmit, reset, setError, formState: { errors } } = useForm<FormValues>({ resolver: zodResolver(schema), defaultValues: empty });

  useEffect(() => {
    if (open) {
      reset({ ...empty, ownerId: me?.user.id ?? '' });
      setFormError(null);
      setDuplicateLeadId(null);
      setCustom({});
      setCustomErrors({});
    }
  }, [open, reset, me?.user.id]);

  const create = useMutation({
    mutationFn: (v: FormValues) => {
      const value = parseMoney(v.estimatedValue);
      const budget = parseMoney(v.budgetMax);
      if (Number.isNaN(value)) throw new ApiProblem(400, { errors: [{ path: 'body.estimatedValue', message: 'Enter an amount like 85,00,000 or 1.2 cr' }] });
      if (Number.isNaN(budget)) throw new ApiProblem(400, { errors: [{ path: 'body.budgetMax', message: 'Enter an amount like 85,00,000 or 1.2 cr' }] });
      return leadsApi.create({
        contact: { firstName: v.firstName, lastName: v.lastName || null, email: v.email || null, phone: v.phone || null },
        source: v.source as LeadSource,
        estimatedValue: value ?? null,
        ...(realEstate ? { budgetMax: budget ?? null, locations: v.locations } : {}),
        tags: v.tags,
        customFields: buildCustomFields(fields.data ?? [], custom, 'create'),
        ...(canAssign && v.ownerId ? { ownerId: v.ownerId } : {}),
      });
    },
    onSuccess: (lead) => {
      void qc.invalidateQueries({ queryKey: ['leads'] });
      void qc.invalidateQueries({ queryKey: ['contacts'] });
      void qc.invalidateQueries({ queryKey: ['pipelines'] });
      toast.success(`Lead added for ${lead.contactName}`);
      onCreated(lead);
    },
    onError: (e) => {
      if (!(e instanceof ApiProblem)) return setFormError('Couldn’t create the lead. Please try again.');
      if (e.status === 409 && e.extra.reason === 'duplicate_lead') return setDuplicateLeadId(String(e.extra.leadId));
      let mapped = false;
      const cf: Record<string, string> = {};
      for (const [k, message] of Object.entries(e.fieldErrors())) {
        const m = /(?:^|\.)customFields\.([^.]+)/.exec(k);
        if (m) {
          cf[m[1]!] = message;
          mapped = true;
          continue;
        }
        const name = k.replace(/^contact\./, '').split('.')[0] as keyof FormValues;
        if (name in empty) {
          setError(name, { message });
          mapped = true;
        }
      }
      setCustomErrors(cf);
      if (!mapped) setFormError(e.errors[0]?.message ?? e.detail);
    },
  });

  return (
    <Dialog open={open} onClose={create.isPending ? undefined : onClose} fullWidth maxWidth="sm" aria-labelledby="new-lead-title">
      <DialogTitle id="new-lead-title">New lead</DialogTitle>
      <DialogContent>
        <Stack component="form" id="new-lead-form" noValidate spacing={2} sx={{ pt: 0.5 }} onSubmit={handleSubmit((v) => { setFormError(null); setDuplicateLeadId(null); create.mutate(v); })}>
          {formError && <Alert severity="error">{formError}</Alert>}
          {duplicateLeadId && (
            <Alert severity="warning">
              This person already has an open lead.{' '}
              <MuiLink component="button" type="button" onClick={() => { onClose(); onOpenExisting(duplicateLeadId); }} sx={{ verticalAlign: 'baseline' }}>Open it</MuiLink> instead of starting a second one.
            </Alert>
          )}
          <Stack direction={{ xs: 'column', sm: 'row' }} spacing={2}>
            <TextField label="First name" autoFocus required fullWidth {...register('firstName')} error={Boolean(errors.firstName)} helperText={errors.firstName?.message} autoComplete="off" />
            <TextField label="Last name" fullWidth {...register('lastName')} autoComplete="off" />
          </Stack>
          <Stack direction={{ xs: 'column', sm: 'row' }} spacing={2}>
            <TextField label="Phone" type="tel" fullWidth {...register('phone')} error={Boolean(errors.phone)} helperText={errors.phone?.message ?? 'Existing contacts are matched by phone or email'} autoComplete="off" />
            <TextField label="Email" type="email" fullWidth {...register('email')} error={Boolean(errors.email)} helperText={errors.email?.message} autoComplete="off" />
          </Stack>
          <Stack direction={{ xs: 'column', sm: 'row' }} spacing={2}>
            <Controller control={control} name="source" render={({ field }) => (
              <TextField select label="Source" fullWidth value={field.value} onChange={field.onChange}>
                {Object.entries(SOURCE_LABEL).map(([k, v]) => <MenuItem key={k} value={k}>{v}</MenuItem>)}
              </TextField>
            )} />
            {canAssign && (
              <Controller control={control} name="ownerId" render={({ field }) => (
                <TextField select label="Owner" fullWidth value={field.value ?? ''} onChange={field.onChange} slotProps={{ select: { displayEmpty: true }, inputLabel: { shrink: true } }}>
                  <MenuItem value=""><em>Assign automatically</em></MenuItem>
                  {(directory.data ?? []).map((m) => <MenuItem key={m.userId} value={m.userId}>{m.name}{m.userId === me?.user.id ? ' (you)' : ''}</MenuItem>)}
                </TextField>
              )} />
            )}
          </Stack>
          <Stack direction={{ xs: 'column', sm: 'row' }} spacing={2}>
            <TextField label={realEstate ? 'Deal value' : 'Expected value'} fullWidth {...register('estimatedValue')} error={Boolean(errors.estimatedValue)} helperText={errors.estimatedValue?.message ?? (realEstate ? 'e.g. 85 lakh or 1.2 cr' : 'Optional')} slotProps={{ input: { startAdornment: <InputAdornment position="start">{symbol}</InputAdornment> } }} />
            {realEstate && <TextField label="Maximum budget" fullWidth {...register('budgetMax')} error={Boolean(errors.budgetMax)} helperText={errors.budgetMax?.message} slotProps={{ input: { startAdornment: <InputAdornment position="start">{symbol}</InputAdornment> } }} />}
          </Stack>
          {realEstate && <Controller control={control} name="locations" render={({ field }) => (
            <Autocomplete multiple freeSolo options={[] as string[]} value={field.value} onChange={(_, v) => field.onChange(v)}
              renderValue={(v, getItemProps) => v.map((o, i) => <Chip size="small" label={o} {...getItemProps({ index: i })} key={o} />)}
              renderInput={(p) => <TextField {...p} label="Preferred locations" helperText="Type and press Enter" />} />
          )} />}
          <Controller control={control} name="tags" render={({ field }) => (
            <Autocomplete multiple freeSolo options={[] as string[]} value={field.value} onChange={(_, v) => field.onChange(v)}
              renderValue={(v, getItemProps) => v.map((o, i) => <Chip size="small" label={o} {...getItemProps({ index: i })} key={o} />)}
              renderInput={(p) => <TextField {...p} label="Tags" helperText="Type and press Enter" />} />
          )} />
          {(fields.data ?? []).some((f) => f.visible) && <CustomFieldInputs fields={fields.data ?? []} values={custom} onChange={(k, val) => setCustom((c) => ({ ...c, [k]: val }))} errors={customErrors} />}
        </Stack>
      </DialogContent>
      <DialogActions>
        <Button onClick={onClose} disabled={create.isPending}>Cancel</Button>
        <Button type="submit" form="new-lead-form" variant="contained" disabled={create.isPending}>{create.isPending ? 'Adding…' : 'Add lead'}</Button>
      </DialogActions>
    </Dialog>
  );
}
