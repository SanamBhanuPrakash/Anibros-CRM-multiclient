import { useEffect, useState } from 'react';
import { Alert, Autocomplete, Button, Chip, Dialog, DialogActions, DialogContent, DialogTitle, Divider, FormControlLabel, InputAdornment, MenuItem, Stack, Switch, TextField, Typography } from '@mui/material';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { ApiProblem } from '@/api/client';
import { propertiesApi, type ListingType, type Property, type PropertyInput, type PropertyType, type Furnishing } from '@/api/inventory';
import { useAuth } from '@/features/auth/AuthProvider';
import { useDirectory } from '@/features/leads/shared';
import { parseMoney } from '@/features/leads/NewLeadDialog';
import { useToast } from '@/ui/toast';
import { FURNISHING_LABEL, TYPE_LABEL, useProjectOptions } from './shared';

interface Form {
  title: string; projectId: string; propertyType: PropertyType; listingType: ListingType; price: string; negotiable: boolean;
  bedrooms: string; bathrooms: string; area: string; carpet: string; unit: string; tower: string; floor: string; facing: string; furnishing: string;
  city: string; locality: string; address: string; possession: string; rera: string; description: string; amenities: string[]; tags: string[]; ownerId: string;
}
const empty: Form = { title: '', projectId: '', propertyType: 'apartment', listingType: 'sale', price: '', negotiable: true, bedrooms: '', bathrooms: '', area: '', carpet: '', unit: '', tower: '', floor: '', facing: '', furnishing: '', city: '', locality: '', address: '', possession: '', rera: '', description: '', amenities: [], tags: [], ownerId: '' };

const fromProperty = (p: Property): Form => ({
  title: p.title, projectId: p.projectId ?? '', propertyType: p.propertyType, listingType: p.listingType, price: String(p.price), negotiable: p.negotiable,
  bedrooms: p.bedrooms?.toString() ?? '', bathrooms: p.bathrooms?.toString() ?? '', area: p.areaSqft?.toString() ?? '', carpet: p.carpetAreaSqft?.toString() ?? '', unit: p.unitNumber ?? '', tower: p.tower ?? '',
  floor: p.floor?.toString() ?? '', facing: p.facing ?? '', furnishing: p.furnishing ?? '', city: p.city ?? '', locality: p.locality ?? '', address: p.address ?? '', possession: p.possessionDate ?? '', rera: p.reraId ?? '',
  description: p.description ?? '', amenities: p.amenities, tags: p.tags, ownerId: p.ownerId ?? '',
});

const num = (s: string) => (s.trim() === '' ? null : Number(s));

/** Create or edit a property. Numbers are checked here for kindness and again on the server for safety. */
export function PropertyDialog({ open, property, defaultProjectId, onClose, onSaved }: { open: boolean; property?: Property; defaultProjectId?: string; onClose: () => void; onSaved: (p: Property) => void }) {
  const qc = useQueryClient();
  const toast = useToast();
  const { scope, me } = useAuth();
  const canAssign = scope('properties:update') === 'all';
  const projects = useProjectOptions();
  const directory = useDirectory();
  const [f, setF] = useState<Form>(empty);
  const [error, setError] = useState<string | null>(null);
  const [fe, setFe] = useState<Record<string, string>>({});
  const set = <K extends keyof Form>(k: K, v: Form[K]) => setF((x) => ({ ...x, [k]: v }));

  useEffect(() => {
    if (!open) return;
    setError(null);
    setFe({});
    setF(property ? fromProperty(property) : { ...empty, projectId: defaultProjectId ?? '', ownerId: me?.user.id ?? '' });
  }, [open, property, defaultProjectId, me?.user.id]);

  const save = useMutation({
    mutationFn: () => {
      const price = parseMoney(f.price);
      if (price === undefined || Number.isNaN(price)) throw new ApiProblem(400, { errors: [{ path: 'body.price', message: 'Enter a price like 85,00,000 or 1.2 cr' }] });
      const nums = { bedrooms: num(f.bedrooms), bathrooms: num(f.bathrooms), areaSqft: num(f.area), carpetAreaSqft: num(f.carpet), floor: num(f.floor) };
      for (const [k, v] of Object.entries(nums)) if (v !== null && !Number.isFinite(v)) throw new ApiProblem(400, { errors: [{ path: `body.${k}`, message: 'Enter a number' }] });
      const body: PropertyInput = {
        title: f.title.trim(), projectId: f.projectId || null, propertyType: f.propertyType, listingType: f.listingType, price, negotiable: f.negotiable, ...nums,
        unitNumber: f.unit.trim() || null, tower: f.tower.trim() || null, facing: f.facing.trim() || null, furnishing: (f.furnishing || null) as Furnishing | null,
        city: f.city.trim() || null, locality: f.locality.trim() || null, address: f.address.trim() || null, possessionDate: f.possession || null, reraId: f.rera.trim() || null,
        description: f.description.trim() || null, amenities: f.amenities, tags: f.tags, ...(canAssign ? { ownerId: f.ownerId || null } : {}),
      };
      return property ? propertiesApi.update(property.id, property.version, body) : propertiesApi.create(body);
    },
    onSuccess: (p) => {
      void qc.invalidateQueries({ queryKey: ['properties'] });
      void qc.invalidateQueries({ queryKey: ['projects'] });
      toast.success(property ? 'Property updated' : `${p.title} added`);
      onSaved(p);
    },
    onError: (e) => {
      if (!(e instanceof ApiProblem)) return setError('Couldn’t save the property. Please try again.');
      if (e.status === 412) return setError('Someone else changed this property while you were editing. Close this and reopen it to see their changes.');
      const fields = e.fieldErrors();
      setFe(fields);
      if (!Object.keys(fields).length) setError(e.detail);
    },
  });

  const field = (k: keyof Form, label: string, extra: Record<string, unknown> = {}, errKey?: string) => (
    <TextField label={label} fullWidth value={f[k] as string} onChange={(e) => set(k, e.target.value as never)} error={Boolean(fe[errKey ?? String(k)])} helperText={fe[errKey ?? String(k)]} {...extra} />
  );
  const renderChips = (v: string[], getItemProps: (o: { index: number }) => object) => v.map((o, i) => <Chip size="small" label={o} {...getItemProps({ index: i })} key={o} />);

  return (
    <Dialog open={open} onClose={save.isPending ? undefined : onClose} fullWidth maxWidth="md" scroll="paper" aria-labelledby="property-dialog-title">
      <DialogTitle id="property-dialog-title">{property ? 'Edit property' : 'New property'}</DialogTitle>
      <DialogContent dividers>
        <Stack component="form" id="property-form" spacing={2.5} noValidate onSubmit={(e) => { e.preventDefault(); setError(null); setFe({}); save.mutate(); }}>
          {error && <Alert severity="error">{error}</Alert>}
          <Stack spacing={2}>
            <Typography variant="eyebrow">The basics</Typography>
            {field('title', 'Title', { required: true, autoFocus: true, slotProps: { htmlInput: { maxLength: 200 } } })}
            <Stack direction={{ xs: 'column', sm: 'row' }} spacing={2}>
              <TextField select label="Type" fullWidth value={f.propertyType} onChange={(e) => set('propertyType', e.target.value as PropertyType)}>{(Object.keys(TYPE_LABEL) as PropertyType[]).map((t) => <MenuItem key={t} value={t}>{TYPE_LABEL[t]}</MenuItem>)}</TextField>
              <TextField select label="Listed for" fullWidth value={f.listingType} onChange={(e) => set('listingType', e.target.value as ListingType)}><MenuItem value="sale">Sale</MenuItem><MenuItem value="rent">Rent</MenuItem><MenuItem value="lease">Lease</MenuItem></TextField>
              <TextField select label="Project" fullWidth value={f.projectId} onChange={(e) => set('projectId', e.target.value)} slotProps={{ select: { displayEmpty: true }, inputLabel: { shrink: true } }} error={Boolean(fe.projectId)} helperText={fe.projectId}>
                <MenuItem value=""><em>Not in a project</em></MenuItem>
                {(projects.data ?? []).map((p) => <MenuItem key={p.id} value={p.id}>{p.name}</MenuItem>)}
              </TextField>
            </Stack>
            <Stack direction={{ xs: 'column', sm: 'row' }} spacing={2} sx={{ alignItems: { sm: 'center' } }}>
              {field('price', f.listingType === 'sale' ? 'Price' : 'Rent / lease amount', { required: true, slotProps: { input: { startAdornment: <InputAdornment position="start">₹</InputAdornment> } }, helperText: fe.price ?? 'e.g. 85 lakh or 1.2 cr' })}
              <FormControlLabel sx={{ minWidth: 180 }} control={<Switch checked={f.negotiable} onChange={(_, v) => set('negotiable', v)} />} label="Negotiable" />
            </Stack>
          </Stack>
          <Divider />
          <Stack spacing={2}>
            <Typography variant="eyebrow">Size and layout</Typography>
            <Stack direction={{ xs: 'column', sm: 'row' }} spacing={2}>{field('bedrooms', 'Bedrooms', { type: 'number', slotProps: { htmlInput: { min: 0, max: 50 } } })}{field('bathrooms', 'Bathrooms', { type: 'number', slotProps: { htmlInput: { min: 0, max: 50 } } })}{field('area', 'Built-up area (sq ft)', { type: 'number' }, 'areaSqft')}{field('carpet', 'Carpet area (sq ft)', { type: 'number' }, 'carpetAreaSqft')}</Stack>
            <Stack direction={{ xs: 'column', sm: 'row' }} spacing={2}>
              {field('tower', 'Tower / block')}{field('unit', 'Unit number', {}, 'unitNumber')}{field('floor', 'Floor', { type: 'number' })}{field('facing', 'Facing')}
              <TextField select label="Furnishing" fullWidth value={f.furnishing} onChange={(e) => set('furnishing', e.target.value)} slotProps={{ select: { displayEmpty: true }, inputLabel: { shrink: true } }}>
                <MenuItem value=""><em>Not specified</em></MenuItem>
                {(Object.keys(FURNISHING_LABEL) as Array<keyof typeof FURNISHING_LABEL>).map((k) => <MenuItem key={k} value={k}>{FURNISHING_LABEL[k]}</MenuItem>)}
              </TextField>
            </Stack>
          </Stack>
          <Divider />
          <Stack spacing={2}>
            <Typography variant="eyebrow">Where and when</Typography>
            <Stack direction={{ xs: 'column', sm: 'row' }} spacing={2}>{field('city', 'City')}{field('locality', 'Locality')}</Stack>
            {field('address', 'Address')}
            <Stack direction={{ xs: 'column', sm: 'row' }} spacing={2}>{field('possession', 'Possession date', { type: 'date', slotProps: { inputLabel: { shrink: true } } }, 'possessionDate')}{field('rera', 'RERA registration', {}, 'reraId')}</Stack>
          </Stack>
          <Divider />
          <Stack spacing={2}>
            <Typography variant="eyebrow">Details</Typography>
            {field('description', 'Description', { multiline: true, minRows: 3, maxRows: 8, slotProps: { htmlInput: { maxLength: 5000 } } })}
            <Autocomplete multiple freeSolo options={[] as string[]} value={f.amenities} onChange={(_, v) => set('amenities', v)} renderValue={renderChips} renderInput={(p) => <TextField {...p} label="Amenities" helperText="Type and press Enter (pool, gym, clubhouse…)" />} />
            <Autocomplete multiple freeSolo options={[] as string[]} value={f.tags} onChange={(_, v) => set('tags', v)} renderValue={renderChips} renderInput={(p) => <TextField {...p} label="Tags" helperText="Type and press Enter" />} />
            {canAssign && (
              <TextField select label="Listing agent" value={f.ownerId} onChange={(e) => set('ownerId', e.target.value)} slotProps={{ select: { displayEmpty: true }, inputLabel: { shrink: true } }} error={Boolean(fe.ownerId)} helperText={fe.ownerId}>
                <MenuItem value=""><em>Unassigned</em></MenuItem>
                {(directory.data ?? []).map((m) => <MenuItem key={m.userId} value={m.userId}>{m.name}{m.userId === me?.user.id ? ' (you)' : ''}</MenuItem>)}
              </TextField>
            )}
          </Stack>
        </Stack>
      </DialogContent>
      <DialogActions>
        <Button onClick={onClose} disabled={save.isPending}>Cancel</Button>
        <Button type="submit" form="property-form" variant="contained" disabled={!f.title.trim() || save.isPending}>{save.isPending ? 'Saving…' : property ? 'Save changes' : 'Add property'}</Button>
      </DialogActions>
    </Dialog>
  );
}
