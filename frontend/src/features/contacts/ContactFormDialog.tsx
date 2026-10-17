import { useEffect, useState } from 'react';
import { Alert, Autocomplete, Button, Chip, Dialog, DialogActions, DialogContent, DialogTitle, Link as MuiLink, MenuItem, Stack, TextField } from '@mui/material';
import { Controller, useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { ApiProblem } from '@/api/client';
import { contactsApi, membersApi, type Contact, type ContactInput, type DuplicateHit } from '@/api/endpoints';
import { useAuth } from '@/features/auth/AuthProvider';
import { useToast } from '@/ui/toast';

const optional = z.string().trim().max(254).optional();
const schema = z.object({
  firstName: z.string().trim().min(1, 'First name is required').max(100),
  lastName: z.string().trim().max(100).optional(),
  email: optional,
  phone: z.string().trim().max(40).optional(),
  alternateEmails: z.array(z.string().trim().max(254)).max(5),
  alternatePhones: z.array(z.string().trim().max(40)).max(5),
  company: z.string().trim().max(150).optional(),
  jobTitle: z.string().trim().max(100).optional(),
  city: z.string().trim().max(100).optional(),
  ownerId: z.string().optional(),
  tags: z.array(z.string().trim().min(1).max(40)).max(30),
});
type FormValues = z.infer<typeof schema>;

const emptyValues: FormValues = { firstName: '', lastName: '', email: '', phone: '', alternateEmails: [], alternatePhones: [], company: '', jobTitle: '', city: '', ownerId: '', tags: [] };
const fromContact = (c: Contact): FormValues => ({
  firstName: c.firstName,
  lastName: c.lastName ?? '',
  email: c.email ?? '',
  phone: c.phone ?? '',
  alternateEmails: c.alternateEmails,
  alternatePhones: c.alternatePhones,
  company: c.company ?? '',
  jobTitle: c.jobTitle ?? '',
  city: c.city ?? '',
  ownerId: c.ownerId ?? '',
  tags: c.tags,
});

/** react-hook-form types array-field errors as a map of per-item errors; the server reports one message for the field. */
const arrayMessage = (e: unknown): string | undefined => (e as { message?: string } | undefined)?.message;

/** Empty string → null so "clear this field" is expressed explicitly on edit. */
const nullable = (v: string | undefined) => (v && v.trim() ? v.trim() : null);

/** Create or edit a contact. Server-side validation and duplicate detection are shown on the field that caused them. */
export function ContactFormDialog({ open, contact, onClose, onSaved, onOpenExisting }: {
  open: boolean;
  contact?: Contact;
  onClose: () => void;
  onSaved: (c: Contact) => void;
  onOpenExisting: (id: string) => void;
}) {
  const qc = useQueryClient();
  const toast = useToast();
  const { scope, me } = useAuth();
  const canAssign = scope('contacts:update') === 'all';
  const directory = useQuery({ queryKey: ['members', 'directory'], queryFn: membersApi.directory, enabled: open && canAssign });
  const [formError, setFormError] = useState<string | null>(null);
  const [duplicates, setDuplicates] = useState<DuplicateHit[]>([]);
  const editing = Boolean(contact);

  const { control, register, handleSubmit, reset, setError, formState: { errors, isDirty } } = useForm<FormValues>({ resolver: zodResolver(schema), defaultValues: emptyValues });
  useEffect(() => {
    if (open) {
      reset(contact ? fromContact(contact) : { ...emptyValues, ownerId: me?.user.id ?? '' });
      setFormError(null);
      setDuplicates([]);
    }
  }, [open, contact, me?.user.id, reset]);

  const save = useMutation({
    mutationFn: async (v: FormValues) => {
      const body: ContactInput = {
        firstName: v.firstName,
        lastName: nullable(v.lastName),
        email: nullable(v.email),
        phone: nullable(v.phone),
        alternateEmails: v.alternateEmails.filter(Boolean),
        alternatePhones: v.alternatePhones.filter(Boolean),
        company: nullable(v.company),
        jobTitle: nullable(v.jobTitle),
        city: nullable(v.city),
        tags: v.tags,
        ...(canAssign ? { ownerId: v.ownerId || null } : {}),
      };
      return contact ? contactsApi.update(contact.id, contact.version, body) : contactsApi.create(body);
    },
    onSuccess: (c) => {
      void qc.invalidateQueries({ queryKey: ['contacts'] });
      toast.success(editing ? 'Contact updated' : `${c.displayName} added`);
      onSaved(c);
    },
    onError: (e) => {
      if (!(e instanceof ApiProblem)) return setFormError('Couldn’t save the contact. Please try again.');
      if (e.status === 412) return setFormError('Someone else changed this contact while you were editing. Close this and reopen it to see their changes.');
      const fields = e.fieldErrors();
      let mapped = false;
      for (const [k, message] of Object.entries(fields)) {
        const name = k.split('.')[0] as keyof FormValues;
        if (name in emptyValues) {
          setError(name, { message });
          mapped = true;
        }
      }
      const dup = e.extra.duplicates as DuplicateHit[] | undefined;
      if (e.status === 409 && dup?.length) return setDuplicates(dup); // the warning below the title says it better than a generic error
      if (!mapped) setFormError(e.detail);
    },
  });

  return (
    <Dialog open={open} onClose={save.isPending ? undefined : onClose} fullWidth maxWidth="sm" aria-labelledby="contact-form-title">
      <DialogTitle id="contact-form-title">{editing ? 'Edit contact' : 'New contact'}</DialogTitle>
      <DialogContent>
        <Stack component="form" id="contact-form" spacing={2} sx={{ pt: 0.5 }} noValidate onSubmit={handleSubmit((v) => { setFormError(null); setDuplicates([]); save.mutate(v); })}>
          {formError && <Alert severity="error">{formError}</Alert>}
          {duplicates.length > 0 && (
            <Alert severity="warning">
              This email or phone number already belongs to{' '}
              {duplicates.map((d, i) => (
                <span key={d.id}>
                  {i > 0 && ', '}
                  <MuiLink component="button" type="button" onClick={() => { onClose(); onOpenExisting(d.id); }} sx={{ verticalAlign: 'baseline' }}>{d.displayName}</MuiLink>
                </span>
              ))}
              . Open that contact instead of creating a second one.
            </Alert>
          )}
          <Stack direction={{ xs: 'column', sm: 'row' }} spacing={2}>
            <TextField label="First name" autoFocus required fullWidth {...register('firstName')} error={Boolean(errors.firstName)} helperText={errors.firstName?.message} autoComplete="off" />
            <TextField label="Last name" fullWidth {...register('lastName')} error={Boolean(errors.lastName)} helperText={errors.lastName?.message} autoComplete="off" />
          </Stack>
          <Stack direction={{ xs: 'column', sm: 'row' }} spacing={2}>
            <TextField label="Email" type="email" fullWidth {...register('email')} error={Boolean(errors.email)} helperText={errors.email?.message} autoComplete="off" />
            <TextField label="Phone" type="tel" fullWidth {...register('phone')} error={Boolean(errors.phone)} helperText={errors.phone?.message ?? 'Any format. Add the country code if it isn’t local.'} autoComplete="off" />
          </Stack>
          <Controller control={control} name="alternateEmails" render={({ field }) => <ChipsInput label="Other emails" value={field.value} onChange={field.onChange} error={arrayMessage(errors.alternateEmails)} hint="Type and press Enter" />} />
          <Controller control={control} name="alternatePhones" render={({ field }) => <ChipsInput label="Other phone numbers" value={field.value} onChange={field.onChange} error={arrayMessage(errors.alternatePhones)} hint="Type and press Enter" />} />
          <Stack direction={{ xs: 'column', sm: 'row' }} spacing={2}>
            <TextField label="Company" fullWidth {...register('company')} autoComplete="off" />
            <TextField label="Job title" fullWidth {...register('jobTitle')} autoComplete="off" />
          </Stack>
          <Stack direction={{ xs: 'column', sm: 'row' }} spacing={2}>
            <TextField label="City" fullWidth {...register('city')} autoComplete="off" />
            {canAssign && (
              <Controller
                control={control}
                name="ownerId"
                render={({ field }) => (
                  <TextField select label="Owner" fullWidth value={field.value ?? ''} onChange={field.onChange} slotProps={{ select: { displayEmpty: true }, inputLabel: { shrink: true } }} disabled={directory.isPending}>
                    <MenuItem value=""><em>Unassigned</em></MenuItem>
                    {(directory.data ?? []).map((m) => <MenuItem key={m.userId} value={m.userId}>{m.name}{m.userId === me?.user.id ? ' (you)' : ''}</MenuItem>)}
                  </TextField>
                )}
              />
            )}
          </Stack>
          <Controller control={control} name="tags" render={({ field }) => <ChipsInput label="Tags" value={field.value} onChange={field.onChange} hint="Type and press Enter. Tags are lower-cased." />} />
        </Stack>
      </DialogContent>
      <DialogActions>
        <Button onClick={onClose} disabled={save.isPending}>Cancel</Button>
        <Button type="submit" form="contact-form" variant="contained" disabled={save.isPending || (editing && !isDirty)}>
          {save.isPending ? 'Saving…' : editing ? 'Save changes' : 'Add contact'}
        </Button>
      </DialogActions>
    </Dialog>
  );
}

/** Free-text chips: type a value, press Enter. */
function ChipsInput({ label, value, onChange, hint, error }: { label: string; value: string[]; onChange: (v: string[]) => void; hint?: string; error?: string }) {
  return (
    <Autocomplete
      multiple
      freeSolo
      options={[] as string[]}
      value={value}
      onChange={(_, v) => onChange(v.map((x) => x.trim()).filter(Boolean))}
      renderValue={(v, getItemProps) => v.map((o, i) => <Chip size="small" label={o} {...getItemProps({ index: i })} key={o} />)}
      renderInput={(p) => <TextField {...p} label={label} error={Boolean(error)} helperText={error ?? hint} />}
    />
  );
}
