import { Alert, Box, Stack, Table, TableBody, TableCell, TableContainer, TableHead, TableRow, Tooltip, Typography } from '@mui/material';
import { format } from 'date-fns';
import { useQuery } from '@tanstack/react-query';
import { useNavigate } from 'react-router-dom';
import { agencyApi, type CompareRow } from '@/api/agency';
import { openClient } from '@/app/scope';
import { formatMoney } from '@/features/leads/shared';
import { PageHeader } from '@/ui/PageHeader';
import { Card, ClientChip, Pill } from '@/ui/kit';
import { dayLabel, pct, useRangeParams } from '@/ui/analytics';
import { EmptyState, ErrorState, SkeletonRows } from '@/ui/states';
import { Change, Kpi, TrendChart } from './charts';
import { PeriodPicker } from './PeriodPicker';

const minutes = (m: number | null | undefined) => (m === null || m === undefined ? '—' : m < 60 ? `${Math.round(m)} min` : m < 1440 ? `${(m / 60).toFixed(1)} h` : `${(m / 1440).toFixed(1)} days`);
const shortLabel = (l: string) => (/^\d{4}-\d{2}-\d{2}$/.test(l) ? format(new Date(`${l}T00:00:00`), 'd MMM') : l);

/**
 * Your clients side by side for the same days. Every figure comes from the same report each client has for itself, limited to what your role in
 * that client allows, so nothing here can show you something a client's own Reports page would hide. Open a row to see that client in full.
 */
export function AgencyReportsPage() {
  const nav = useNavigate();
  const { from, to, includeTest, set } = useRangeParams();
  const q = useQuery({ queryKey: ['agency', 'report', from, to, includeTest], queryFn: () => agencyApi.report({ from, to, includeTest }), placeholderData: (p) => p, refetchInterval: 120_000 });
  const d = q.data;
  const range = d ? { from: d.range.from, to: d.range.to, days: Math.round((new Date(`${d.range.to}T00:00:00`).getTime() - new Date(`${d.range.from}T00:00:00`).getTime()) / 86_400_000) + 1 } : undefined;
  const open = (r: CompareRow) => {
    openClient(r.client.id);
    nav(`/reports?from=${d!.range.from}&to=${d!.range.to}${includeTest ? '&includeTest=true' : ''}`);
  };
  const top = Math.max(1, ...(d?.rows.map((r) => r.leads.created) ?? [1]));

  return (
    <>
      <PageHeader
        title="Compare clients"
        description={d ? `${dayLabel(d.range.from)} – ${dayLabel(d.range.to)} · the same days for every client` : 'Your clients side by side'}
        actions={<PeriodPicker range={range} includeTest={includeTest} set={set} />}
      />

      {q.isPending ? (
        <SkeletonRows rows={6} height={64} />
      ) : q.isError ? (
        <ErrorState error={q.error} onRetry={() => void q.refetch()} />
      ) : !d || d.rows.length === 0 ? (
        <>
          {d && d.excluded.length > 0 && <Alert severity="info" sx={{ mb: 2 }}>Your role does not include reports for: {d.excluded.map((x) => x.clientName).join(', ')}.</Alert>}
          <Card><EmptyState title="No clients to compare" description="Clients you belong to, and may see reports for, appear here." /></Card>
        </>
      ) : (
        <Stack spacing={2.5} sx={{ opacity: q.isPlaceholderData ? 0.6 : 1, transition: 'opacity 150ms' }}>
          {d.partial && <Alert severity="info">In clients where you can see only your own leads ({d.rows.filter((r) => r.scope === 'own').map((r) => r.client.name).join(', ')}) the figures count your leads only.</Alert>}
          {d.excluded.length > 0 && <Alert severity="info">Not included, because your role has no reports there: {d.excluded.map((x) => x.clientName).join(', ')}.</Alert>}

          <Card pad={0}>
            <Box sx={{ display: 'grid', gridTemplateColumns: { xs: 'repeat(2, 1fr)', md: 'repeat(5, 1fr)' }, '& > *': { borderRight: { md: 1 }, borderBottom: { xs: 1, md: 0 }, borderColor: 'divider' }, '& > *:last-child': { borderRight: 0 } }}>
              <Kpi label="Clients" value={d.totals.clients} note={d.partial ? 'some figures are yours only' : undefined} />
              <Kpi label="New leads" value={d.totals.leads.created.toLocaleString()} change={<Change current={d.totals.leads.created} previous={d.totals.leads.previousCreated} />} note="compared with the days before" />
              <Kpi label="Won" value={d.totals.leads.won.toLocaleString()} note={`${pct(d.totals.leads.winRate)} of the new leads`} />
              <Kpi label="Waiting for a reply" value={d.totals.leads.waitingForReply} tone="warning" note="past the reply deadline, right now" />
              <Kpi label="Not assigned" value={d.totals.leads.unassigned} tone="warning" note="open, nobody owns them" />
            </Box>
          </Card>

          {d.totals.byCurrency.some((c) => c.spend > 0 || c.dealValue > 0) && (
            <Stack direction="row" spacing={1} sx={{ flexWrap: 'wrap', rowGap: 1, alignItems: 'center' }}>
              <Typography variant="body2" color="text.secondary">Money (never added across currencies):</Typography>
              {d.totals.byCurrency.filter((c) => c.spend > 0 || c.dealValue > 0).map((c) => (
                <Pill key={c.currency}>{c.currency}: {c.dealValue > 0 ? `${formatMoney(c.dealValue, c.currency)} in deals` : 'no deals'}{c.spend > 0 ? ` · ${formatMoney(c.spend, c.currency)} spent` : ''}</Pill>
              ))}
            </Stack>
          )}

          {d.trend.some((p) => p.leads > 0) && (
            <Card title="New leads, all clients together" subtitle={d.bucket === 'day' ? 'Per day' : d.bucket === 'week' ? 'Per week' : d.bucket === 'month' ? 'Per month' : undefined}>
              <TrendChart points={d.trend.map((p) => ({ label: shortLabel(p.label), leads: p.leads, sales: 0 }))} />
            </Card>
          )}

          <Card pad={0} title="By client" subtitle="Open a client to see its full report">
            <TableContainer>
              <Table size="small" aria-label="Clients side by side">
                <TableHead>
                  <TableRow>
                    <TableCell>Client</TableCell>
                    <TableCell sx={{ width: '20%' }}>New leads</TableCell>
                    <TableCell align="right">Won</TableCell>
                    <TableCell align="right">Waiting</TableCell>
                    <TableCell align="right">Not assigned</TableCell>
                    <TableCell align="right">First reply</TableCell>
                    <TableCell align="right">Deals</TableCell>
                    <TableCell align="right">Spent</TableCell>
                    <TableCell align="right">Per lead</TableCell>
                  </TableRow>
                </TableHead>
                <TableBody>
                  {d.rows.map((r) => (
                    <TableRow key={r.client.id} hover sx={{ cursor: 'pointer' }} onClick={() => open(r)}>
                      <TableCell sx={{ minWidth: 180 }}>
                        <Stack direction="row" spacing={1} sx={{ alignItems: 'center' }}>
                          <ClientChip id={r.client.id} name={r.client.name} />
                          {r.scope === 'own' && <Tooltip title="You see only your own leads in this client, so these figures are yours only"><span><Pill tone="warning">Yours only</Pill></span></Tooltip>}
                        </Stack>
                      </TableCell>
                      <TableCell>
                        <Stack direction="row" spacing={1} sx={{ alignItems: 'center' }}>
                          <Box sx={{ flex: 1, height: 8, borderRadius: 4, bgcolor: 'action.hover', overflow: 'hidden' }}><Box sx={{ height: '100%', width: `${(r.leads.created / top) * 100}%`, bgcolor: 'primary.main', borderRadius: 4 }} /></Box>
                          <Typography variant="body2" sx={{ minWidth: 26, textAlign: 'right', fontWeight: 600 }}>{r.leads.created}</Typography>
                          <Box sx={{ minWidth: 44 }}><Change current={r.leads.created} previous={r.leads.previousCreated} /></Box>
                        </Stack>
                      </TableCell>
                      <TableCell align="right">{r.leads.won}{r.leads.winRate !== null && r.leads.won > 0 && <Typography variant="caption" color="text.secondary" component="div">{pct(r.leads.winRate)}</Typography>}</TableCell>
                      <TableCell align="right" sx={{ color: r.leads.waitingForReply ? 'warning.main' : undefined, fontWeight: r.leads.waitingForReply ? 600 : undefined }}>{r.leads.waitingForReply}</TableCell>
                      <TableCell align="right" sx={{ color: r.leads.unassigned ? 'warning.main' : undefined, fontWeight: r.leads.unassigned ? 600 : undefined }}>{r.leads.unassigned}</TableCell>
                      <TableCell align="right">{r.firstReply ? <>{minutes(r.firstReply.medianMinutes)}<Typography variant="caption" color="text.secondary" component="div">{pct(r.firstReply.inTimeRate)} on time</Typography></> : '—'}</TableCell>
                      <TableCell align="right">{r.deals ? (r.deals.count ? <>{r.deals.count}<Typography variant="caption" color="text.secondary" component="div">{formatMoney(r.deals.value, r.currency)}</Typography></> : '0') : '—'}</TableCell>
                      <TableCell align="right">{r.money && r.money.spend > 0 ? formatMoney(r.money.spend, r.currency) : '—'}</TableCell>
                      <TableCell align="right">{r.money?.costPerLead != null ? formatMoney(r.money.costPerLead, r.currency) : '—'}</TableCell>
                    </TableRow>
                  ))}
                </TableBody>
              </Table>
            </TableContainer>
          </Card>
          <Typography variant="caption" color="text.secondary">
            Days are whole calendar days in each client’s own time zone. “Won” counts leads created in these days that are now won; “Deals” are recorded sales. Waiting and not assigned are as of now.
          </Typography>
        </Stack>
      )}
    </>
  );
}
