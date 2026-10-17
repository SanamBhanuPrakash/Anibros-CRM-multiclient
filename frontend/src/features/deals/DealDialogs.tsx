import { useEffect, useState } from 'react';
import { Alert, Autocomplete, Button, Dialog, DialogActions, DialogContent, DialogContentText, DialogTitle, FormControl, FormControlLabel, FormLabel, InputAdornment, Radio, RadioGroup, Stack, TextField } from '@mui/material';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { addDays, format } from 'date-fns';
import { leadsApi } from '@/api/crm';
import { dealsApi, type Reservation, type ReservationResult } from '@/api/deals';
import { useToast } from '@/ui/toast';
import { formatMoney } from '@/features/leads/shared';
import { invalidateDeals, parseAmount, problemText, UnitPicker, type UnitOption } from './shared';

/** What to tell the person once a deal action has happened, including what it did to the lead's stage. */
const afterward = (r: ReservationResult, said: string) =>
  r.leadStage.moved && r.leadStage.stageName ? `${said} The lead moved to “${r.leadStage.stageName}”.` : r.leadStage.note && r.leadStage.note.startsWith('The lead was not moved') ? `${said} ${r.leadStage.note}` : said;

function useDone(onClose: () => void) {
  const qc = useQueryClient();
  const toast = useToast();
  return (r: ReservationResult, said: string) => {
    invalidateDeals(qc);
    toast.success(afterward(r, said));
    onClose();
  };
}

const money = (label: string, value: string, set: (v: string) => void, extra: { required?: boolean; helperText?: string; autoFocus?: boolean } = {}) => (
  <TextField
    label={label}
    value={value}
    onChange={(e) => set(e.target.value)}
    required={extra.required}
    autoFocus={extra.autoFocus}
    helperText={extra.helperText}
    slotProps={{ htmlInput: { inputMode: 'decimal', 'aria-label': label }, input: { startAdornment: <InputAdornment position="start">₹</InputAdornment> } }}
  />
);

/**
 * Record an offer. Either the buyer or the property may already be known (from a lead or a property page); the other is picked here.
 * The unit then shows as under offer, and the lead moves forward to negotiation.
 */
export function OfferDialog({ open, onClose, lead, property }: { open: boolean; onClose: () => void; lead?: { id: string; name: string }; property?: { id: string; title: string; price?: number } }) {
  const done = useDone(onClose);
  const [buyer, setBuyer] = useState<{ id: string; label: string } | null>(null);
  const [buyerQ, setBuyerQ] = useState('');
  const [unit, setUnit] = useState<UnitOption | null>(null);
  const [price, setPrice] = useState('');
  const [note, setNote] = useState('');
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    if (!open) return;
    setError(null);
    setBuyer(lead ? { id: lead.id, label: lead.name } : null);
    setBuyerQ('');
    setUnit(null);
    setPrice(property?.price ? String(property.price) : '');
    setNote('');
  }, [open, lead, property]);

  const buyers = useQuery({
    queryKey: ['leads', 'picker', 'open', buyerQ],
    queryFn: async () => (await leadsApi.list({ q: buyerQ || undefined, state: 'open' })).data.map((l) => ({ id: l.id, label: l.contactName ?? 'Unnamed lead' })),
    enabled: open && !lead,
  });
  const propertyId = property?.id ?? unit?.id;
  const save = useMutation({
    mutationFn: () => dealsApi.offer({ leadId: buyer!.id, propertyId: propertyId!, offerPrice: parseAmount(price), note: note.trim() || null }),
    onSuccess: (r) => done(r, 'Offer recorded. The property now shows as under offer.'),
    onError: (e) => setError(problemText(e, 'Couldn’t record the offer. Please try again.')),
  });
  const ready = Boolean(buyer && propertyId) && (price.trim() === '' || parseAmount(price) !== null);

  return (
    <Dialog open={open} onClose={onClose} fullWidth maxWidth="xs" aria-labelledby="offer-title">
      <DialogTitle id="offer-title">Record an offer</DialogTitle>
      <DialogContent>
        <Stack component="form" id="offer-form" spacing={2} sx={{ pt: 0.5 }} onSubmit={(e) => { e.preventDefault(); if (ready) save.mutate(); }}>
          {error && <Alert severity="error">{error}</Alert>}
          {lead ? <TextField label="Buyer" value={lead.name} disabled /> : (
            <Autocomplete
              options={buyers.data ?? []}
              loading={buyers.isFetching}
              value={buyer}
              onChange={(_, v) => setBuyer(v)}
              inputValue={buyerQ}
              onInputChange={(_, v, reason) => reason !== 'reset' && setBuyerQ(v)}
              filterOptions={(o) => o}
              isOptionEqualToValue={(a, b) => a.id === b.id}
              noOptionsText="No open leads match"
              renderInput={(p) => <TextField {...p} label="Buyer" placeholder="Search leads" />}
            />
          )}
          {property ? <TextField label="Property" value={property.title} disabled /> : (
            <UnitPicker value={unit} only={['available', 'under_offer']} onChange={(u) => { setUnit(u); if (u && !price) setPrice(String(u.price)); }} helperText="Only available and under-offer properties can take an offer" />
          )}
          {money('Offer price (optional)', price, setPrice, { helperText: 'What the buyer is offering. You can leave it blank and fill it in later.' })}
          <TextField label="Note" multiline minRows={2} value={note} onChange={(e) => setNote(e.target.value)} slotProps={{ htmlInput: { maxLength: 1000 } }} helperText="For example: wants parking included, subject to loan approval" />
        </Stack>
      </DialogContent>
      <DialogActions>
        <Button onClick={onClose}>Cancel</Button>
        <Button type="submit" form="offer-form" variant="contained" disabled={!ready || save.isPending}>{save.isPending ? 'Saving…' : 'Record offer'}</Button>
      </DialogActions>
    </Dialog>
  );
}

/** Token received: hold the unit for this buyer. Booking is a milestone, not a sale. */
export function ReserveDialog({ deal, onClose }: { deal: Reservation | null; onClose: () => void }) {
  const done = useDone(onClose);
  const [token, setToken] = useState('');
  const [agreed, setAgreed] = useState('');
  const [until, setUntil] = useState('');
  const [error, setError] = useState<string | null>(null);
  useEffect(() => {
    if (!deal) return;
    setError(null);
    setToken('');
    setAgreed(String(deal.offerPrice ?? deal.property.price));
    setUntil(format(addDays(new Date(), 7), 'yyyy-MM-dd'));
  }, [deal]);
  const save = useMutation({
    mutationFn: () => dealsApi.reserve(deal!.id, deal!.version, { tokenAmount: parseAmount(token)!, agreedPrice: parseAmount(agreed)!, holdUntil: until ? new Date(`${until}T23:59:00`).toISOString() : null }),
    onSuccess: (r) => done(r, 'Reserved. Nobody else can reserve this property now.'),
    onError: (e) => setError(problemText(e, 'Couldn’t reserve the property. Please try again.')),
  });
  const ready = parseAmount(token) !== null && (parseAmount(agreed) ?? 0) > 0;
  return (
    <Dialog open={Boolean(deal)} onClose={onClose} fullWidth maxWidth="xs" aria-labelledby="reserve-title">
      <DialogTitle id="reserve-title">Reserve {deal?.property.title}</DialogTitle>
      <DialogContent>
        <Stack component="form" id="reserve-form" spacing={2} sx={{ pt: 0.5 }} onSubmit={(e) => { e.preventDefault(); if (ready) save.mutate(); }}>
          <DialogContentText>Hold this property for {deal?.lead.name}. Nobody else can reserve it until you release it or record the sale.</DialogContentText>
          {error && <Alert severity="error">{error}</Alert>}
          {money('Token received', token, setToken, { required: true, autoFocus: true, helperText: 'Enter 0 if no token was taken' })}
          {money('Agreed price', agreed, setAgreed, { required: true })}
          <TextField label="Hold until (optional)" type="date" value={until} onChange={(e) => setUntil(e.target.value)} slotProps={{ inputLabel: { shrink: true } }} helperText="Nothing is released automatically. You are reminded when this date has passed." />
        </Stack>
      </DialogContent>
      <DialogActions>
        <Button onClick={onClose}>Cancel</Button>
        <Button type="submit" form="reserve-form" variant="contained" disabled={!ready || save.isPending}>{save.isPending ? 'Reserving…' : 'Reserve'}</Button>
      </DialogActions>
    </Dialog>
  );
}

/** Record the sale from a deal: the final value, then the property is sold and the lead is won. */
export function CompleteDialog({ deal, onClose }: { deal: Reservation | null; onClose: () => void }) {
  const done = useDone(onClose);
  const [value, setValue] = useState('');
  const [error, setError] = useState<string | null>(null);
  useEffect(() => {
    if (!deal) return;
    setError(null);
    setValue(String(deal.agreedPrice ?? deal.offerPrice ?? deal.property.price));
  }, [deal]);
  const save = useMutation({
    mutationFn: () => dealsApi.complete(deal!.id, deal!.version, { dealValue: parseAmount(value)! }),
    onSuccess: (r) => done(r, deal?.property.listingType === 'sale' ? 'Sale recorded.' : 'Rental recorded.'),
    onError: (e) => setError(problemText(e, 'Couldn’t record the sale. Please try again.')),
  });
  const ready = (parseAmount(value) ?? 0) > 0;
  const rental = deal?.property.listingType !== 'sale';
  return (
    <Dialog open={Boolean(deal)} onClose={onClose} fullWidth maxWidth="xs" aria-labelledby="complete-title">
      <DialogTitle id="complete-title">{rental ? 'Record the rental' : 'Record the sale'}</DialogTitle>
      <DialogContent>
        <Stack component="form" id="complete-form" spacing={2} sx={{ pt: 0.5 }} onSubmit={(e) => { e.preventDefault(); if (ready) save.mutate(); }}>
          <DialogContentText>
            {deal?.property.title} will be marked {rental ? 'rented' : 'sold'}, {deal?.lead.name} will be marked won, and other buyers’ open offers on it will be closed. Only recorded sales count as won deal value in reports.
          </DialogContentText>
          {error && <Alert severity="error">{error}</Alert>}
          {money(rental ? 'Final rental value' : 'Final deal value', value, setValue, { required: true, autoFocus: true, helperText: deal?.agreedPrice ? `Agreed price was ${formatMoney(deal.agreedPrice, deal.currency)}` : undefined })}
        </Stack>
      </DialogContent>
      <DialogActions>
        <Button onClick={onClose}>Cancel</Button>
        <Button type="submit" form="complete-form" variant="contained" color="success" disabled={!ready || save.isPending}>{save.isPending ? 'Recording…' : rental ? 'Record rental' : 'Record sale'}</Button>
      </DialogActions>
    </Dialog>
  );
}

/** End an offer or reservation without a sale. The lead is not moved: the person decides whether the buyer is lost. */
export function CloseDealDialog({ deal, onClose }: { deal: Reservation | null; onClose: () => void }) {
  const done = useDone(onClose);
  const reasons = useQuery({ queryKey: ['deals', 'close-reasons'], queryFn: dealsApi.closeReasons, staleTime: 3_600_000, enabled: Boolean(deal) });
  const [reason, setReason] = useState('');
  const [note, setNote] = useState('');
  const [error, setError] = useState<string | null>(null);
  useEffect(() => {
    if (!deal) return;
    setError(null);
    setReason('');
    setNote('');
  }, [deal]);
  const save = useMutation({
    mutationFn: () => dealsApi.close(deal!.id, deal!.version, { reason, note: note.trim() || null }),
    onSuccess: (r) => done(r, deal?.status === 'reserved' ? 'Reservation released. The property is available again.' : 'Offer closed.'),
    onError: (e) => setError(problemText(e, 'Couldn’t close it. Please try again.')),
  });
  const ready = Boolean(reason) && (reason !== 'other' || note.trim().length >= 2);
  const reserved = deal?.status === 'reserved';
  return (
    <Dialog open={Boolean(deal)} onClose={onClose} fullWidth maxWidth="xs" aria-labelledby="close-deal-title">
      <DialogTitle id="close-deal-title">{reserved ? 'Release the reservation' : 'Close the offer'}</DialogTitle>
      <DialogContent>
        <Stack component="form" id="close-deal-form" spacing={2} sx={{ pt: 0.5 }} onSubmit={(e) => { e.preventDefault(); if (ready) save.mutate(); }}>
          <DialogContentText>
            {reserved ? `${deal?.property.title} goes back on the market.` : `${deal?.lead.name}’s offer on ${deal?.property.title} ends.`} The lead stays where it is.
          </DialogContentText>
          {error && <Alert severity="error">{error}</Alert>}
          <FormControl>
            <FormLabel id="close-reason-label">Why did it end?</FormLabel>
            <RadioGroup aria-labelledby="close-reason-label" value={reason} onChange={(e) => setReason(e.target.value)}>
              {(reasons.data ?? []).map((r) => <FormControlLabel key={r.code} value={r.code} control={<Radio size="small" />} label={r.label} />)}
            </RadioGroup>
          </FormControl>
          <TextField label={reason === 'other' ? 'Explain (required)' : 'Note (optional)'} multiline minRows={2} value={note} onChange={(e) => setNote(e.target.value)} slotProps={{ htmlInput: { maxLength: 500 } }} />
        </Stack>
      </DialogContent>
      <DialogActions>
        <Button onClick={onClose}>Keep it</Button>
        <Button type="submit" form="close-deal-form" variant="contained" color="warning" disabled={!ready || save.isPending}>{save.isPending ? 'Saving…' : reserved ? 'Release' : 'Close offer'}</Button>
      </DialogActions>
    </Dialog>
  );
}
