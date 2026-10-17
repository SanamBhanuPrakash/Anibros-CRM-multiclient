import { useState } from 'react';
import { Box, Button, Chip, IconButton, Menu, MenuItem, Paper, Stack, Tooltip, Typography } from '@mui/material';
import { Link as RouterLink } from 'react-router-dom';
import AddHomeWorkOutlinedIcon from '@mui/icons-material/AddHomeWorkOutlined';
import MoreVertRoundedIcon from '@mui/icons-material/MoreVertRounded';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { dealsApi, shortlistApi, type Interest, type Reservation } from '@/api/deals';
import { useAuth } from '@/features/auth/AuthProvider';
import { formatMoney } from '@/features/leads/shared';
import { useToast } from '@/ui/toast';
import { CloseDealDialog, CompleteDialog, OfferDialog, ReserveDialog } from './DealDialogs';
import { DealStatusChip, INTEREST_LABEL, UnitPicker, invalidateDeals, problemText, type UnitOption } from './shared';

/**
 * One buyer's side of the deal: the properties they are considering, and every offer, reservation and sale.
 * Each action says what it will do to the property and the lead, and the server decides whether it is allowed.
 */
export function LeadDeals({ lead, canEdit }: { lead: { id: string; name: string; closed: boolean }; canEdit: boolean }) {
  const { can } = useAuth();
  const toast = useToast();
  const qc = useQueryClient();
  const mayDeal = can('reservations:create') && canEdit;
  const [offerFor, setOfferFor] = useState<{ id: string; title: string; price: number } | 'pick' | null>(null);
  const [reserve, setReserve] = useState<Reservation | null>(null);
  const [complete, setComplete] = useState<Reservation | null>(null);
  const [closeDeal, setCloseDeal] = useState<Reservation | null>(null);
  const [adding, setAdding] = useState(false);
  const [pick, setPick] = useState<UnitOption | null>(null);
  const [menu, setMenu] = useState<{ el: HTMLElement; item: Interest } | null>(null);

  const interest = useQuery({ queryKey: ['shortlist', lead.id], queryFn: () => shortlistApi.list(lead.id), enabled: can('properties:read') });
  const deals = useQuery({ queryKey: ['deals', 'lead', lead.id], queryFn: async () => (await dealsApi.list({ leadId: lead.id })).data, enabled: can('reservations:read') });

  const add = useMutation({
    mutationFn: (propertyId: string) => shortlistApi.add({ leadId: lead.id, propertyId }),
    onSuccess: () => { invalidateDeals(qc); setAdding(false); setPick(null); toast.success('Added to their shortlist'); },
    onError: (e) => toast.error(problemText(e, 'Couldn’t add the property')),
  });
  const change = useMutation({
    mutationFn: (v: { id: string; status?: Interest['status'] }) => shortlistApi.update(v.id, { status: v.status }),
    onSuccess: () => invalidateDeals(qc),
    onError: (e) => toast.error(problemText(e, 'Couldn’t change it')),
  });
  const remove = useMutation({
    mutationFn: (id: string) => shortlistApi.remove(id),
    onSuccess: () => { invalidateDeals(qc); toast.success('Removed from their shortlist'); },
    onError: (e) => toast.error(problemText(e, 'Couldn’t remove it')),
  });

  if (!can('properties:read') && !can('reservations:read')) return null;
  const list = interest.data ?? [];
  const history = deals.data ?? [];
  const open = history.filter((d) => d.status === 'offered' || d.status === 'reserved');
  const past = history.filter((d) => d.status === 'completed' || d.status === 'closed');

  return (
    <Paper variant="outlined" sx={{ p: 2.5, borderRadius: 2.5 }} component="section" aria-labelledby="deals-title">
      <Stack direction="row" sx={{ justifyContent: 'space-between', alignItems: 'center', mb: 1, gap: 1, flexWrap: 'wrap' }}>
        <Typography id="deals-title" variant="h5" component="h2">Properties and offers</Typography>
        <Stack direction="row" spacing={0.5}>
          {canEdit && !lead.closed && can('leads:update') && <Button size="small" startIcon={<AddHomeWorkOutlinedIcon />} onClick={() => setAdding((v) => !v)}>Add a property</Button>}
          {mayDeal && !lead.closed && <Button size="small" onClick={() => setOfferFor('pick')}>Record an offer</Button>}
        </Stack>
      </Stack>

      {adding && (
        <Stack direction="row" spacing={1} sx={{ mb: 1.5, alignItems: 'flex-start' }}>
          <Box sx={{ flex: 1 }}><UnitPicker value={pick} onChange={setPick} label="Property to consider" /></Box>
          <Button variant="contained" disabled={!pick || add.isPending} onClick={() => pick && add.mutate(pick.id)} sx={{ mt: 1 }}>Add</Button>
        </Stack>
      )}

      {open.length > 0 && (
        <Stack spacing={1} sx={{ mb: 1.5 }} aria-label="Open offers and reservations">
          {open.map((d) => (
            <DealRow key={d.id} deal={d} onReserve={() => setReserve(d)} onComplete={() => setComplete(d)} onClose={() => setCloseDeal(d)} />
          ))}
        </Stack>
      )}

      {interest.isPending && can('properties:read') ? <Typography variant="body2" color="text.secondary">Loading…</Typography> : list.length === 0 && open.length === 0 && past.length === 0 ? (
        <Typography variant="body2" color="text.secondary">Nothing yet. Add the properties this buyer is considering, from here or from Property matching, then record an offer when they make one.</Typography>
      ) : (
        <Stack spacing={0.25}>
          {list.filter((i) => !i.deal).map((i) => (
            <Stack key={i.id} direction="row" sx={{ alignItems: 'center', gap: 1, py: 0.5, opacity: i.status === 'rejected' ? 0.6 : 1 }}>
              <Box sx={{ minWidth: 0, flex: 1 }}>
                <Typography variant="body2" noWrap sx={{ fontWeight: 600 }} component={RouterLink} to={`/properties/${i.propertyId}`} style={{ color: 'inherit', textDecoration: 'none' }}>{i.property.title}</Typography>
                <Typography variant="caption" color="text.secondary" component="div">
                  {formatMoney(i.property.price, i.property.currency)}{i.property.location ? ` · ${i.property.location}` : ''}{i.matchScore !== null ? ` · ${i.matchScore}% match` : ''}
                </Typography>
              </Box>
              <Chip size="small" variant="outlined" label={INTEREST_LABEL[i.status]} />
              {i.property.status !== 'available' && <Chip size="small" color="warning" variant="outlined" label={i.property.status.replace('_', ' ')} />}
              {canEdit && (
                <Tooltip title="More"><IconButton size="small" aria-label={`More for ${i.property.title}`} onClick={(e) => setMenu({ el: e.currentTarget, item: i })}><MoreVertRoundedIcon fontSize="small" /></IconButton></Tooltip>
              )}
            </Stack>
          ))}
        </Stack>
      )}

      {past.length > 0 && (
        <Stack spacing={0.75} sx={{ mt: 1.5 }} aria-label="Finished deals">
          {past.map((d) => <DealRow key={d.id} deal={d} />)}
        </Stack>
      )}

      <Menu anchorEl={menu?.el} open={Boolean(menu)} onClose={() => setMenu(null)}>
        {menu && mayDeal && !lead.closed && menu.item.property.status !== 'reserved' && ['available', 'under_offer'].includes(menu.item.property.status) && (
          <MenuItem onClick={() => { setOfferFor({ id: menu.item.propertyId, title: menu.item.property.title, price: menu.item.property.price }); setMenu(null); }}>Record an offer</MenuItem>
        )}
        {menu && menu.item.status !== 'interested' && <MenuItem onClick={() => { change.mutate({ id: menu.item.id, status: 'interested' }); setMenu(null); }}>Mark as interested</MenuItem>}
        {menu && menu.item.status !== 'rejected' && <MenuItem onClick={() => { change.mutate({ id: menu.item.id, status: 'rejected' }); setMenu(null); }}>Not for them</MenuItem>}
        {menu && menu.item.status === 'rejected' && <MenuItem onClick={() => { change.mutate({ id: menu.item.id, status: 'shortlisted' }); setMenu(null); }}>Back on the shortlist</MenuItem>}
        {menu && <MenuItem onClick={() => { remove.mutate(menu.item.id); setMenu(null); }}>Remove from the shortlist</MenuItem>}
      </Menu>

      <OfferDialog open={Boolean(offerFor)} onClose={() => setOfferFor(null)} lead={{ id: lead.id, name: lead.name }} property={offerFor && offerFor !== 'pick' ? offerFor : undefined} />
      <ReserveDialog deal={reserve} onClose={() => setReserve(null)} />
      <CompleteDialog deal={complete} onClose={() => setComplete(null)} />
      <CloseDealDialog deal={closeDeal} onClose={() => setCloseDeal(null)} />
    </Paper>
  );
}

/** One offer, reservation or sale with the figures that matter and what can be done with it next. */
export function DealRow({ deal, onReserve, onComplete, onClose, showBuyer }: { deal: Reservation; onReserve?: () => void; onComplete?: () => void; onClose?: () => void; showBuyer?: boolean }) {
  const figures = [
    deal.status === 'completed' && deal.dealValue !== null ? `Sold for ${formatMoney(deal.dealValue, deal.currency)}` : null,
    deal.status === 'reserved' ? `Agreed ${formatMoney(deal.agreedPrice, deal.currency)} · token ${formatMoney(deal.tokenAmount, deal.currency)}` : null,
    deal.status === 'offered' && deal.offerPrice !== null ? `Offered ${formatMoney(deal.offerPrice, deal.currency)}` : null,
    deal.status === 'closed' && deal.closedReason ? deal.closedReason.label : null,
  ].filter(Boolean);
  return (
    <Paper variant="outlined" sx={{ p: 1.25, borderRadius: 2, borderColor: deal.holdOverdue ? 'warning.main' : undefined }}>
      <Stack direction="row" sx={{ alignItems: 'center', columnGap: 1, rowGap: 0.75, flexWrap: 'wrap' }}>
        <DealStatusChip deal={deal} />
        <Box sx={{ minWidth: 0, flex: '1 1 170px' }}>
          <Typography variant="body2" noWrap sx={{ fontWeight: 600 }} component={RouterLink} to={showBuyer ? `/leads/${deal.leadId}` : `/properties/${deal.propertyId}`} style={{ color: 'inherit', textDecoration: 'none' }}>
            {showBuyer ? `${deal.lead.name} · ${deal.property.title}` : deal.property.title}
          </Typography>
          <Typography variant="caption" color="text.secondary" component="div">
            {figures.join(' · ')}
            {deal.holdOverdue && deal.holdUntil ? ` · hold ended ${new Date(deal.holdUntil).toLocaleDateString()}` : deal.status === 'reserved' && deal.holdUntil ? ` · held until ${new Date(deal.holdUntil).toLocaleDateString()}` : ''}
            {deal.lead.ownerName && showBuyer ? ` · ${deal.lead.ownerName}` : ''}
          </Typography>
        </Box>
        <Stack direction="row" spacing={0.5} sx={{ ml: 'auto' }}>
          {onReserve && deal.allowed.includes('reserve') && <Button size="small" variant="outlined" onClick={onReserve}>Reserve</Button>}
          {onComplete && deal.allowed.includes('complete') && <Button size="small" variant="contained" color="success" onClick={onComplete}>Record sale</Button>}
          {onClose && deal.allowed.includes('close') && <Button size="small" color="inherit" onClick={onClose}>{deal.status === 'reserved' ? 'Release' : 'Close'}</Button>}
        </Stack>
      </Stack>
    </Paper>
  );
}

