import { useMemo, useState } from 'react';
import { Box, MenuItem, Paper, Stack, Table, TableBody, TableCell, TableContainer, TableHead, TableRow, TextField, Typography } from '@mui/material';
import SpeedOutlinedIcon from '@mui/icons-material/SpeedOutlined';
import { useQuery } from '@tanstack/react-query';
import { agencyApi } from '@/api/agency';
import { useAuth } from '@/features/auth/AuthProvider';
import { PageHeader } from '@/ui/PageHeader';
import { EmptyState, ErrorState, SkeletonRows } from '@/ui/states';
import { UserAvatar } from '@/ui/display';
import { Card, NowStrip, NowTile } from '@/ui/kit';
import { SplitBar } from '@/ui/viz';
import { clientColor, useAgencyClients } from './shared';

const CHANNEL_PARTS = [
  { key: 'spoke', label: 'Calls: spoke to them', color: '#10B981' },
  { key: 'calls', label: 'Calls: no answer', color: '#A7F3D0' },
  { key: 'wa', label: 'WhatsApp', color: '#25B866' },
  { key: 'sms', label: 'SMS', color: '#64748B' },
  { key: 'ig', label: 'Instagram', color: '#D6249F' },
  { key: 'em', label: 'E-mail', color: '#8B5CF6' },
  { key: 'mt', label: 'Meetings', color: '#F59E0B' },
] as const;
type Period = 'today' | 'yesterday' | '7d' | 'month' | 'custom';
const iso = (d: Date) => `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
const shift = (days: number) => {
  const d = new Date();
  d.setDate(d.getDate() + days);
  return d;
};

function range(p: Period, from: string, to: string): { from?: string; to?: string } {
  if (p === 'today') return {};
  if (p === 'yesterday') return { from: iso(shift(-1)), to: iso(shift(-1)) };
  if (p === '7d') return { from: iso(shift(-6)), to: iso(shift(0)) };
  if (p === 'month') return { from: iso(new Date(new Date().getFullYear(), new Date().getMonth(), 1)), to: iso(shift(0)) };
  return { from: from || undefined, to: to || from || undefined };
}

/** What each employee did: calls, messages, e-mails and meetings logged, per client. Employees see their own numbers; admins and team leads see the team. */
export function ActivityPage() {
  const { isAgencyAdmin } = useAuth();
  const [period, setPeriod] = useState<Period>('today');
  const [from, setFrom] = useState('');
  const [to, setTo] = useState('');
  const [clientId, setClientId] = useState('');
  const clients = useAgencyClients('active');
  const r = range(period, from, to);
  const q = useQuery({
    queryKey: ['agency', 'activity', clientId, r.from, r.to],
    queryFn: () => agencyApi.activity({ clientId: clientId || undefined, ...r }),
    refetchInterval: 60_000,
    placeholderData: (p) => p,
  });

  const byPerson = useMemo(() => {
    const m = new Map<string, { userId: string; userName: string; calls: number; connected: number; whatsapp: number; sms: number; emails: number; instagram: number; meetings: number; leads: number }>();
    for (const row of q.data?.rows ?? []) {
      const x = m.get(row.userId) ?? { userId: row.userId, userName: row.userName, calls: 0, connected: 0, whatsapp: 0, sms: 0, emails: 0, instagram: 0, meetings: 0, leads: 0 };
      x.calls += row.calls; x.connected += row.connected; x.whatsapp += row.whatsapp; x.sms += row.sms; x.emails += row.emails; x.instagram += row.instagram; x.meetings += row.meetings; x.leads += row.leadsTouched;
      m.set(row.userId, x);
    }
    return [...m.values()].sort((a, b) => b.calls + b.whatsapp + b.sms + b.instagram - (a.calls + a.whatsapp + a.sms + a.instagram));
  }, [q.data]);

  const maxTotal = Math.max(1, ...byPerson.map((p) => p.calls + p.whatsapp + p.sms + p.instagram + p.emails + p.meetings));
  const num = (n: number) => <Box component="span" sx={{ color: n === 0 ? 'text.disabled' : 'text.primary', fontVariantNumeric: 'tabular-nums' }}>{n}</Box>;
  const rows = q.data?.rows ?? [];

  return (
    <>
      <PageHeader title="Team activity" description={isAgencyAdmin ? 'Calls and messages your team logged, per person and per client.' : 'The calls and messages you logged. Team leads also see their team.'} />
      <Stack direction={{ xs: 'column', sm: 'row' }} spacing={1.5} sx={{ mb: 2 }}>
        <TextField select size="small" label="Period" value={period} onChange={(e) => setPeriod(e.target.value as Period)} sx={{ minWidth: 170 }}>
          <MenuItem value="today">Today</MenuItem>
          <MenuItem value="yesterday">Yesterday</MenuItem>
          <MenuItem value="7d">Last 7 days</MenuItem>
          <MenuItem value="month">This month</MenuItem>
          <MenuItem value="custom">Pick dates…</MenuItem>
        </TextField>
        {period === 'custom' && (
          <>
            <TextField size="small" type="date" label="From" value={from} onChange={(e) => setFrom(e.target.value)} slotProps={{ inputLabel: { shrink: true } }} />
            <TextField size="small" type="date" label="To" value={to} onChange={(e) => setTo(e.target.value)} slotProps={{ inputLabel: { shrink: true } }} />
          </>
        )}
        <TextField select size="small" label="Client" value={clientId} onChange={(e) => setClientId(e.target.value)} sx={{ minWidth: 220 }} slotProps={{ select: { displayEmpty: true }, inputLabel: { shrink: true } }}>
          <MenuItem value=""><em>All clients</em></MenuItem>
          {(clients.data ?? []).map((c) => <MenuItem key={c.id} value={c.id}>{c.name}</MenuItem>)}
        </TextField>
      </Stack>

      {q.isPending ? (
        <SkeletonRows rows={5} height={50} />
      ) : q.isError ? (
        <ErrorState error={q.error} onRetry={() => void q.refetch()} />
      ) : rows.length === 0 ? (
        <Paper variant="outlined"><EmptyState icon={<SpeedOutlinedIcon />} title="Nothing logged in this period" description="Calls, WhatsApp messages, SMS, e-mails and meetings appear here as soon as someone logs them on a lead." /></Paper>
      ) : (
        <Stack spacing={3} sx={{ opacity: q.isPlaceholderData ? 0.6 : 1 }}>
          <NowStrip columns={5}>
            <NowTile label="Calls" value={q.data!.totals.calls} />
            <NowTile label="Spoke to them" value={q.data!.totals.connected} tone="success" hint={q.data!.totals.calls ? `${Math.round((q.data!.totals.connected / q.data!.totals.calls) * 100)}% of calls` : undefined} />
            <NowTile label="WhatsApp" value={q.data!.totals.whatsapp} />
            <NowTile label="SMS and Instagram" value={q.data!.totals.sms + q.data!.totals.instagram} />
            <NowTile label="E-mails and meetings" value={q.data!.totals.emails + q.data!.totals.meetings} />
          </NowStrip>

          <Card title="Who did what" subtitle="Every bar is one person's logged work, split by kind. Longer bar, more done." >
            <Stack direction="row" sx={{ flexWrap: 'wrap', gap: 2, mb: 1.5 }}>
              {CHANNEL_PARTS.map((c) => <Stack key={c.key} direction="row" spacing={0.75} sx={{ alignItems: 'center' }}><Box sx={{ width: 10, height: 10, borderRadius: '3px', bgcolor: c.color }} /><Typography variant="caption" color="text.secondary">{c.label}</Typography></Stack>)}
            </Stack>
            <Stack spacing={1.75}>
              {byPerson.map((p) => {
                const total = p.calls + p.whatsapp + p.sms + p.instagram + p.emails + p.meetings;
                return (
                  <Box key={p.userId} sx={{ display: 'grid', gridTemplateColumns: { xs: '1fr', sm: '190px minmax(0, 1fr) 96px' }, gap: { xs: 0.5, sm: 2 }, alignItems: 'center' }}>
                    <Stack direction="row" spacing={1} sx={{ alignItems: 'center', minWidth: 0 }}><UserAvatar name={p.userName} seed={p.userId} size={28} /><Typography variant="body2" noWrap sx={{ fontWeight: 500 }}>{p.userName}</Typography></Stack>
                    <Box sx={{ width: `${Math.max(6, (total / maxTotal) * 100)}%`, minWidth: 24 }}>
                      <SplitBar height={14} parts={[
                        { key: 'spoke', label: 'Calls: spoke to them', value: p.connected, color: CHANNEL_PARTS[0]!.color },
                        { key: 'calls', label: 'Calls: no answer', value: Math.max(0, p.calls - p.connected), color: CHANNEL_PARTS[1]!.color },
                        { key: 'wa', label: 'WhatsApp', value: p.whatsapp, color: CHANNEL_PARTS[2]!.color },
                        { key: 'sms', label: 'SMS', value: p.sms, color: CHANNEL_PARTS[3]!.color },
                        { key: 'ig', label: 'Instagram', value: p.instagram, color: CHANNEL_PARTS[4]!.color },
                        { key: 'em', label: 'E-mail', value: p.emails, color: CHANNEL_PARTS[5]!.color },
                        { key: 'mt', label: 'Meetings', value: p.meetings, color: CHANNEL_PARTS[6]!.color },
                      ]} />
                    </Box>
                    <Typography variant="caption" color="text.secondary" sx={{ textAlign: { sm: 'right' } }}><b style={{ color: 'inherit', fontWeight: 650 }}>{total}</b> done · {p.leads} lead{p.leads === 1 ? '' : 's'}</Typography>
                  </Box>
                );
              })}
            </Stack>
          </Card>

          <Box>
            <Typography variant="h5" component="h2" sx={{ mb: 1 }}>By client</Typography>
            <TableContainer component={Paper} variant="outlined" sx={{ borderRadius: 2 }}>
              <Table aria-label="Activity by client and person">
                <TableHead>
                  <TableRow><TableCell>Client</TableCell><TableCell>Person</TableCell><TableCell align="right">Calls</TableCell><TableCell align="right">Spoke to them</TableCell><TableCell align="right">WhatsApp</TableCell><TableCell align="right">SMS</TableCell><TableCell align="right">Instagram</TableCell><TableCell align="right">E-mails</TableCell><TableCell align="right">Meetings</TableCell><TableCell align="right">Leads touched</TableCell></TableRow>
                </TableHead>
                <TableBody>
                  {rows.map((x) => (
                    <TableRow key={`${x.clientId}:${x.userId}`} sx={{ '&:last-child td': { borderBottom: 0 } }}>
                      <TableCell><Stack direction="row" spacing={1} sx={{ alignItems: 'center' }}><Box aria-hidden sx={{ width: 9, height: 9, borderRadius: '50%', bgcolor: clientColor(x.clientId) }} /><Typography variant="body2">{x.clientName}</Typography></Stack></TableCell>
                      <TableCell>{x.userName}</TableCell>
                      <TableCell align="right">{num(x.calls)}</TableCell><TableCell align="right">{num(x.connected)}</TableCell><TableCell align="right">{num(x.whatsapp)}</TableCell><TableCell align="right">{num(x.sms)}</TableCell><TableCell align="right">{num(x.instagram)}</TableCell><TableCell align="right">{num(x.emails)}</TableCell><TableCell align="right">{num(x.meetings)}</TableCell><TableCell align="right">{num(x.leadsTouched)}</TableCell>
                    </TableRow>
                  ))}
                </TableBody>
              </Table>
            </TableContainer>
          </Box>
        </Stack>
      )}
    </>
  );
}
