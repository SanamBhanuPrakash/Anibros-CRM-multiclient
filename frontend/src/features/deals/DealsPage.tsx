import { useMemo, useState } from 'react';
import { Alert, Button, Chip, Stack, Tab, Tabs, Typography } from '@mui/material';
import { useSearchParams } from 'react-router-dom';
import HandshakeOutlinedIcon from '@mui/icons-material/HandshakeOutlined';
import { useInfiniteQuery } from '@tanstack/react-query';
import { dealsApi, type Reservation } from '@/api/deals';
import { useAuth } from '@/features/auth/AuthProvider';
import { formatMoney } from '@/features/leads/shared';
import { PageHeader } from '@/ui/PageHeader';
import { EmptyState, ErrorState, SkeletonRows } from '@/ui/states';
import { CloseDealDialog, CompleteDialog, ReserveDialog } from './DealDialogs';
import { DealRow } from './LeadDeals';

type Tab = 'open' | 'sold' | 'closed';
const STATUS: Record<Tab, string> = { open: 'offered,reserved', sold: 'completed', closed: 'closed' };

/**
 * Every offer, reservation and sale the person may see, in one place. Salespeople see their own buyers' deals; managers see all.
 * Won deal value in reports is the total of the "Sold" tab.
 */
export function DealsPage() {
  const { scope } = useAuth();
  // Links (the Dashboard) can open this page already narrowed: ?tab=sold&from=2026-09-15&to=2026-10-14&test=exclude
  const [params, setParams] = useSearchParams();
  const wanted = params.get('tab');
  const tab: Tab = wanted === 'sold' || wanted === 'closed' ? wanted : 'open';
  const setTab = (t: Tab) => setParams(t === 'open' ? {} : { tab: t }, { replace: true });
  const from = tab === 'sold' ? params.get('from') ?? undefined : undefined;
  const to = tab === 'sold' ? params.get('to') ?? undefined : undefined;
  const noTest = tab === 'sold' && params.get('test') === 'exclude';
  const only = tab === 'open' ? params.get('only') ?? undefined : undefined;
  const [reserve, setReserve] = useState<Reservation | null>(null);
  const [complete, setComplete] = useState<Reservation | null>(null);
  const [closeDeal, setCloseDeal] = useState<Reservation | null>(null);
  const list = useInfiniteQuery({
    queryKey: ['deals', 'list', tab, from, to, noTest, only],
    queryFn: ({ pageParam }) => dealsApi.list({ status: only === 'reserved' || only === 'offered' ? only : STATUS[tab], completedFrom: from, completedTo: to, test: noTest ? 'exclude' : undefined }, pageParam),
    initialPageParam: undefined as string | undefined,
    getNextPageParam: (l) => l.page?.next ?? undefined,
    placeholderData: (p) => p,
  });
  const rows = useMemo(() => list.data?.pages.flatMap((p) => p.data) ?? [], [list.data]);
  const overdue = rows.filter((d) => d.holdOverdue).length;
  const soldTotal = tab === 'sold' ? rows.reduce((n, d) => n + (d.dealValue ?? 0), 0) : 0;

  return (
    <>
      <PageHeader title="Offers and bookings" description={scope('reservations:read') === 'own' ? 'Offers, reservations and sales on your own buyers.' : 'Offers, reservations and sales across the team.'} />
      <Tabs value={tab} onChange={(_, v: Tab) => setTab(v)} aria-label="Deal status" sx={{ mb: 2, borderBottom: 1, borderColor: 'divider' }}>
        <Tab value="open" label="Open" />
        <Tab value="sold" label="Sold and rented" />
        <Tab value="closed" label="Closed" />
      </Tabs>
      {(from || to || noTest || only) && (
        <Stack direction="row" spacing={1} sx={{ mb: 2, flexWrap: 'wrap', rowGap: 1 }} aria-label="Filters from a link">
          {only && <Chip size="small" color="primary" variant="outlined" label={only === 'reserved' ? 'Reserved only' : 'Offers only'} onDelete={() => setParams({}, { replace: true })} />}
          {(from || to) && <Chip size="small" color="primary" variant="outlined" label={`Sold ${from ?? 'start'} to ${to ?? 'today'}`} onDelete={() => setParams({ tab: 'sold' }, { replace: true })} />}
          {noTest && <Chip size="small" color="primary" variant="outlined" label="Test leads left out" onDelete={() => { const n = new URLSearchParams(params); n.delete('test'); setParams(n, { replace: true }); }} />}
        </Stack>
      )}
      {tab === 'open' && overdue > 0 && <Alert severity="warning" sx={{ mb: 2 }}>{overdue} {overdue === 1 ? 'reservation has' : 'reservations have'} passed {overdue === 1 ? 'its' : 'their'} hold date. Nothing is released automatically: release or complete {overdue === 1 ? 'it' : 'them'}.</Alert>}
      {tab === 'sold' && rows.length > 0 && <Typography variant="body2" sx={{ mb: 2 }}>Recorded sales shown: <b>{formatMoney(soldTotal, rows[0]?.currency)}</b> across {rows.length}{list.hasNextPage ? '+' : ''} {rows.length === 1 ? 'deal' : 'deals'}.</Typography>}
      {list.isError ? <ErrorState error={list.error} onRetry={() => void list.refetch()} /> : list.isPending ? <SkeletonRows rows={5} height={64} /> : rows.length === 0 ? (
        <EmptyState
          icon={<HandshakeOutlinedIcon />}
          title={tab === 'open' ? 'No open offers or reservations' : tab === 'sold' ? 'No recorded sales yet' : 'Nothing closed'}
          description={tab === 'open' ? 'Record an offer from a lead or a property page. Reserving a property holds it for one buyer; recording the sale is what counts as won deal value.' : tab === 'sold' ? 'A sale is recorded from a reservation, or when a lead is marked won with the property and its final value.' : 'Offers and reservations that ended without a sale appear here, with the reason.'}
        />
      ) : (
        <Stack spacing={1}>
          {rows.map((d) => <DealRow key={d.id} deal={d} showBuyer onReserve={() => setReserve(d)} onComplete={() => setComplete(d)} onClose={() => setCloseDeal(d)} />)}
          {list.hasNextPage && <Button onClick={() => void list.fetchNextPage()} disabled={list.isFetchingNextPage} sx={{ alignSelf: 'center' }}>{list.isFetchingNextPage ? 'Loading…' : 'Show more'}</Button>}
        </Stack>
      )}
      <ReserveDialog deal={reserve} onClose={() => setReserve(null)} />
      <CompleteDialog deal={complete} onClose={() => setComplete(null)} />
      <CloseDealDialog deal={closeDeal} onClose={() => setCloseDeal(null)} />
    </>
  );
}
