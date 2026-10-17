import { useEffect, useMemo, useState } from 'react';
import { Alert, Button, Dialog, DialogActions, DialogContent, DialogContentText, DialogTitle, InputAdornment, Stack, TextField } from '@mui/material';
import { useQuery } from '@tanstack/react-query';
import { dealsApi, shortlistApi } from '@/api/deals';
import { UnitPicker, parseAmount, type UnitOption } from './shared';

export interface SaleResult {
  propertyId: string;
  reservationId?: string;
  dealValue: number;
}

interface Candidate extends UnitOption {
  reservationId?: string;
  hint: string;
}

/**
 * Moving a lead to Won in a pipeline that requires a deal value (decision D3): which unit was sold, and for how much.
 * Offers the unit(s) the buyer already has a reservation or offer on first, then their shortlist, then any property.
 * The server records the sale and the stage move together; if the unit cannot be sold, neither happens.
 */
export function SaleDialog({ open, leadId, leadName, targetName, busy, error, onCancel, onConfirm }: {
  open: boolean;
  leadId: string;
  leadName: string;
  targetName: string;
  busy?: boolean;
  error?: string | null;
  onCancel: () => void;
  onConfirm: (r: SaleResult) => void;
}) {
  const [unit, setUnit] = useState<Candidate | UnitOption | null>(null);
  const [value, setValue] = useState('');
  const deals = useQuery({ queryKey: ['deals', 'lead', leadId, 'open'], queryFn: async () => (await dealsApi.list({ leadId, status: 'offered,reserved' })).data, enabled: open });
  const interest = useQuery({ queryKey: ['shortlist', leadId], queryFn: () => shortlistApi.list(leadId), enabled: open });

  const candidates = useMemo<Candidate[]>(() => {
    const out: Candidate[] = [];
    for (const d of [...(deals.data ?? [])].sort((a, b) => Number(b.status === 'reserved') - Number(a.status === 'reserved'))) {
      out.push({ id: d.propertyId, label: d.property.title, price: d.agreedPrice ?? d.offerPrice ?? d.property.price, status: d.property.status, reservationId: d.id, hint: d.status === 'reserved' ? 'Reserved for this buyer' : 'Offer made' });
    }
    for (const i of interest.data ?? []) {
      if (out.some((c) => c.id === i.propertyId) || i.property.archived || !['available', 'under_offer'].includes(i.property.status)) continue;
      out.push({ id: i.propertyId, label: i.property.title, price: i.property.price, status: i.property.status, hint: 'On their shortlist' });
    }
    return out;
  }, [deals.data, interest.data]);

  useEffect(() => {
    if (!open) return;
    setUnit(null);
    setValue('');
  }, [open]);
  // the obvious case needs no clicks: one reserved unit is preselected
  useEffect(() => {
    if (!open || unit || !candidates.length) return;
    const reserved = candidates.filter((c) => c.reservationId && c.hint.startsWith('Reserved'));
    if (reserved.length === 1) pick(reserved[0]);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [open, candidates]);

  const pick = (u: Candidate | UnitOption | null) => {
    setUnit(u);
    if (u) setValue(String(u.price));
  };
  const reservationId = unit && 'reservationId' in unit ? unit.reservationId : undefined;
  const amount = parseAmount(value);
  const valid = Boolean(unit) && (amount ?? 0) > 0;

  return (
    <Dialog open={open} onClose={onCancel} fullWidth maxWidth="xs" aria-labelledby="sale-title">
      <DialogTitle id="sale-title">Record the sale</DialogTitle>
      <DialogContent>
        <Stack component="form" id="sale-form" spacing={2} sx={{ pt: 0.5 }} onSubmit={(e) => { e.preventDefault(); if (valid && unit) onConfirm({ propertyId: unit.id, reservationId, dealValue: amount! }); }}>
          <DialogContentText>
            To mark {leadName} as {targetName.toLowerCase()}, say which property was sold and for how much. The property is marked sold or rented in the same step.
          </DialogContentText>
          {error && <Alert severity="error">{error}</Alert>}
          {candidates.length > 0 && (
            <Stack spacing={0.75} aria-label="Properties for this buyer">
              {candidates.map((c) => (
                <Button key={c.id} variant={unit?.id === c.id ? 'contained' : 'outlined'} color="inherit" onClick={() => pick(c)} sx={{ justifyContent: 'space-between', textTransform: 'none' }} aria-pressed={unit?.id === c.id}>
                  <span>{c.label}</span>
                  <span style={{ opacity: 0.7, fontSize: '0.8125rem' }}>{c.hint}</span>
                </Button>
              ))}
            </Stack>
          )}
          <UnitPicker value={unit && !candidates.some((c) => c.id === unit.id) ? unit : null} only={['available', 'under_offer', 'reserved']} onChange={pick} label={candidates.length ? 'Or another property' : 'Property sold'} />
          <TextField
            label="Final deal value"
            value={value}
            onChange={(e) => setValue(e.target.value)}
            required
            slotProps={{ htmlInput: { inputMode: 'decimal' }, input: { startAdornment: <InputAdornment position="start">₹</InputAdornment> } }}
            helperText="Only recorded sales count as won deal value in reports."
          />
        </Stack>
      </DialogContent>
      <DialogActions>
        <Button onClick={onCancel}>Cancel</Button>
        <Button type="submit" form="sale-form" variant="contained" color="success" disabled={!valid || busy}>{busy ? 'Recording…' : `Record sale and move to ${targetName}`}</Button>
      </DialogActions>
    </Dialog>
  );
}
