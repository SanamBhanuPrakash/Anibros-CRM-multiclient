import { useState } from 'react';
import { Alert, Button, Paper, Stack, Typography } from '@mui/material';
import { Link as RouterLink } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import { dealsApi, type Reservation } from '@/api/deals';
import { useAuth } from '@/features/auth/AuthProvider';
import { formatMoney } from '@/features/leads/shared';
import { CloseDealDialog, CompleteDialog, OfferDialog, ReserveDialog } from './DealDialogs';
import { DealRow } from './LeadDeals';

/**
 * Who holds this property, and every offer, reservation and sale on it. Anyone who can read inventory sees THAT it is held and by which agent;
 * the buyer and the amounts are shown only to people who may see that buyer.
 */
export function PropertyDeals({ property }: { property: { id: string; title: string; price: number; status: string } }) {
  const { can } = useAuth();
  const [offer, setOffer] = useState(false);
  const [reserve, setReserve] = useState<Reservation | null>(null);
  const [complete, setComplete] = useState<Reservation | null>(null);
  const [closeDeal, setCloseDeal] = useState<Reservation | null>(null);
  const holder = useQuery({ queryKey: ['deals', 'holder', property.id], queryFn: () => dealsApi.holder(property.id) });
  const deals = useQuery({ queryKey: ['deals', 'property', property.id], queryFn: async () => (await dealsApi.list({ propertyId: property.id })).data, enabled: can('reservations:read') });

  const h = holder.data?.holder;
  const mayOffer = can('reservations:create') && ['available', 'under_offer'].includes(property.status);
  const list = (deals.data ?? []).slice(0, 12);
  if (!h && !list.length && !mayOffer && (holder.data?.openOffers ?? 0) === 0) return null;

  return (
    <Paper variant="outlined" sx={{ p: 2.5, borderRadius: 2.5 }} component="section" aria-labelledby="prop-deals-title">
      <Stack direction="row" sx={{ justifyContent: 'space-between', alignItems: 'center', mb: 1, gap: 1 }}>
        <Typography id="prop-deals-title" variant="h5" component="h2">Offers and reservation</Typography>
        {mayOffer && <Button size="small" onClick={() => setOffer(true)}>Record an offer</Button>}
      </Stack>
      {h && (
        <Alert severity={h.holdOverdue ? 'warning' : 'info'} sx={{ mb: 1.5 }}>
          {h.visible && h.buyer ? (
            <>Reserved for <b><RouterLink to={`/leads/${h.leadId}`}>{h.buyer}</RouterLink></b>{h.agent ? ` (${h.agent})` : ''}{h.agreedPrice !== null ? ` at ${formatMoney(h.agreedPrice, h.currency)}` : ''}.</>
          ) : (
            <>Reserved{h.agent ? ` by ${h.agent}` : ''}. The buyer’s details are only shown to people who can see that lead.</>
          )}
          {h.holdUntil ? ` ${h.holdOverdue ? 'The hold ended' : 'Held until'} ${new Date(h.holdUntil).toLocaleDateString()}.` : ''}
          {h.holdOverdue ? ' Nothing is released automatically: release it or record the sale.' : ''}
        </Alert>
      )}
      {!h && (holder.data?.openOffers ?? 0) > 0 && <Typography variant="body2" sx={{ mb: 1 }}>{holder.data!.openOffers} {holder.data!.openOffers === 1 ? 'buyer has' : 'buyers have'} an offer on this property.</Typography>}
      {list.length > 0 ? (
        <Stack spacing={0.75}>
          {list.map((d) => <DealRow key={d.id} deal={d} showBuyer onReserve={() => setReserve(d)} onComplete={() => setComplete(d)} onClose={() => setCloseDeal(d)} />)}
        </Stack>
      ) : (
        !h && <Typography variant="body2" color="text.secondary">No offers yet.</Typography>
      )}
      <OfferDialog open={offer} onClose={() => setOffer(false)} property={property} />
      <ReserveDialog deal={reserve} onClose={() => setReserve(null)} />
      <CompleteDialog deal={complete} onClose={() => setComplete(null)} />
      <CloseDealDialog deal={closeDeal} onClose={() => setCloseDeal(null)} />
    </Paper>
  );
}
