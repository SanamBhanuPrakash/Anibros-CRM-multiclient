import { useMemo, useState } from 'react';
import { Box, Button, IconButton, InputAdornment, ListItemText, Menu, MenuItem, Paper, Stack, Table, TableBody, TableCell, TableContainer, TableHead, TableRow, TextField, ToggleButton, ToggleButtonGroup, Tooltip, Typography } from '@mui/material';
import AddRoundedIcon from '@mui/icons-material/AddRounded';
import SearchRoundedIcon from '@mui/icons-material/SearchRounded';
import MoreVertRoundedIcon from '@mui/icons-material/MoreVertRounded';
import GridViewRoundedIcon from '@mui/icons-material/GridViewRounded';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { Link as RouterLink, useNavigate } from 'react-router-dom';
import { ApiProblem } from '@/api/client';
import { agencyApi, type Client } from '@/api/agency';
import { useAuth } from '@/features/auth/AuthProvider';
import { openClient } from '@/app/scope';
import { PageHeader } from '@/ui/PageHeader';
import { EmptyState, ErrorState, SkeletonRows } from '@/ui/states';
import { useConfirm } from '@/ui/confirm';
import { useToast } from '@/ui/toast';
import { Card, NowStrip, NowTile, Pill, When } from '@/ui/kit';
import { AttentionByClient, ClientTreemap } from './Portfolio';
import { AddClientDialog } from './AddClientDialog';
import { Attention } from './Attention';
import { clientColor, roleName, useAgencyClients, useAgencyOverview } from './shared';

const money = (v: number | null, currency: string) => (v === null ? '—' : new Intl.NumberFormat(undefined, { style: 'currency', currency, notation: 'compact', maximumFractionDigits: 1 }).format(v));
const weekAgo = () => {
  const d = new Date();
  d.setDate(d.getDate() - 6);
  return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')}`;
};

/** "All clients": how every client is doing at a glance, and the place to add one. */
export function AgencyHomePage() {
  const nav = useNavigate();
  const qc = useQueryClient();
  const toast = useToast();
  const confirm = useConfirm();
  const { isAgencyAdmin, refresh } = useAuth();
  const [status, setStatus] = useState<'active' | 'archived'>('active');
  const [needle, setNeedle] = useState('');
  const [adding, setAdding] = useState(false);
  const [menu, setMenu] = useState<{ el: HTMLElement; client: Client } | null>(null);
  const overview = useAgencyOverview();
  const clients = useAgencyClients(status);

  const visit = (c: Client, to = '/overview') => {
    openClient(c.id);
    nav(to);
  };
  const archive = useMutation({
    mutationFn: ({ c, archived }: { c: Client; archived: boolean }) => (archived ? agencyApi.archiveClient(c.id) : agencyApi.restoreClient(c.id)),
    onSuccess: async (c) => {
      await refresh();
      void qc.invalidateQueries({ queryKey: ['agency'] });
      toast.success(c.status === 'archived' ? `${c.name} is archived` : `${c.name} is back`);
    },
    onError: (e) => toast.error(e instanceof ApiProblem ? e.detail : 'That didn’t work. Please try again.'),
  });

  const rows = useMemo(() => {
    const n = needle.trim().toLowerCase();
    return (clients.data ?? []).filter((c) => !n || c.name.toLowerCase().includes(n) || (c.industry ?? '').includes(n) || (c.contactName ?? '').toLowerCase().includes(n));
  }, [clients.data, needle]);
  const o = overview.data;

  return (
    <>
      <PageHeader
        title="Clients"
        description={o ? `${o.agency.name}: every client has its own leads, team and settings, completely separate from the others.` : 'Every client has its own leads, team and settings.'}
        actions={isAgencyAdmin && <Button variant="contained" startIcon={<AddRoundedIcon />} onClick={() => setAdding(true)}>Add client</Button>}
      />

      {o && (
        <NowStrip columns={5} sx={{ mb: 2.5 }}>
          <NowTile label="Clients" value={o.clients.active} hint={o.staff !== null ? `${o.staff} people on the team` : undefined} />
          <NowTile label="Open leads" value={o.totals.openLeads} hint={`${o.totals.totalLeads} in total`} to="/agency/leads" />
          <NowTile label="New this week" value={o.totals.newLast7Days} to={`/agency/leads?show=all&from=${weekAgo()}`} />
          <NowTile label="Waiting for a reply" value={o.totals.slaBreached} tone="warning" hint="past the reply deadline" to="/agency/leads?sla=breached" />
          <NowTile label="Not assigned" value={o.totals.unassigned} tone="warning" hint="open, nobody owns them" to="/agency/leads?owner=unassigned" />
        </NowStrip>
      )}

      {status === 'active' && (clients.data ?? []).filter((c) => c.status === 'active').length > 1 && (
        <Box sx={{ display: 'grid', gap: 2.5, gridTemplateColumns: { xs: '1fr', lg: 'minmax(0, 7fr) minmax(0, 4fr)' }, mb: 2.5, alignItems: 'start' }}>
          <Card title="Your clients, sized by open leads" subtitle="Bigger tile, more leads. An amber corner means leads are waiting for a reply.">
            <ClientTreemap clients={clients.data ?? []} />
          </Card>
          <AttentionByClient clients={clients.data ?? []} />
        </Box>
      )}

      <Attention showTeam={isAgencyAdmin} />

      <Stack direction={{ xs: 'column', sm: 'row' }} spacing={1.5} sx={{ mb: 1.5, alignItems: { sm: 'center' }, justifyContent: 'space-between' }}>
        <TextField placeholder="Find a client…" value={needle} onChange={(e) => setNeedle(e.target.value)} sx={{ maxWidth: 320 }} slotProps={{ input: { startAdornment: <InputAdornment position="start"><SearchRoundedIcon fontSize="small" /></InputAdornment> }, htmlInput: { 'aria-label': 'Find a client' } }} />
        {isAgencyAdmin && (
          <ToggleButtonGroup exclusive size="small" value={status} onChange={(_, v) => v && setStatus(v)} aria-label="Which clients">
            <ToggleButton value="active">Active</ToggleButton>
            <ToggleButton value="archived">Archived</ToggleButton>
          </ToggleButtonGroup>
        )}
      </Stack>

      {clients.isPending ? (
        <SkeletonRows rows={5} height={52} />
      ) : clients.isError ? (
        <ErrorState error={clients.error} onRetry={() => void clients.refetch()} />
      ) : rows.length === 0 ? (
        <Paper variant="outlined" sx={{ borderRadius: 2.5 }}>
          {needle ? (
            <EmptyState title={`No client matches “${needle}”`} />
          ) : status === 'archived' ? (
            <EmptyState icon={<GridViewRoundedIcon />} title="No archived clients" description="Clients you archive appear here. Nothing is deleted: you can bring one back at any time." />
          ) : (
            <EmptyState
              icon={<GridViewRoundedIcon />}
              title={isAgencyAdmin ? 'Add your first client' : 'No clients assigned to you yet'}
              description={isAgencyAdmin ? 'Each client gets a separate workspace for its leads, ad spend, lead sources and team. Adding one takes a minute.' : 'Ask an agency admin to assign you to a client. You will see it here.'}
              action={isAgencyAdmin ? <Button variant="contained" startIcon={<AddRoundedIcon />} onClick={() => setAdding(true)}>Add client</Button> : undefined}
            />
          )}
        </Paper>
      ) : (
        <TableContainer component={Paper} variant="outlined" sx={{ borderRadius: 2.5 }}>
          <Table aria-label="Clients">
            <TableHead>
              <TableRow>
                <TableCell>Client</TableCell>
                <TableCell align="right">Open leads</TableCell>
                <TableCell align="right">New (7 days)</TableCell>
                <TableCell align="right">Waiting for reply</TableCell>
                <TableCell align="right">Not assigned</TableCell>
                <TableCell align="right">Spend this month</TableCell>
                <TableCell>Lead capture</TableCell>
                <TableCell align="right">Team</TableCell>
                <TableCell padding="checkbox"><span style={{ position: 'absolute', left: -9999 }}>Actions</span></TableCell>
              </TableRow>
            </TableHead>
            <TableBody>
              {rows.map((c) => {
                const s = c.stats;
                const link = (count: number | undefined, query: string, warn = false) =>
                  count === undefined ? '—' : count === 0 ? <Box component="span" sx={{ color: 'text.disabled' }}>0</Box> : (
                    <Box component={RouterLink} to={`/agency/leads?client=${c.id}&${query}`} onClick={(e: React.MouseEvent) => e.stopPropagation()} sx={{ color: warn ? 'warning.main' : 'text.primary', fontWeight: warn ? 600 : 500, '&:hover': { textDecoration: 'underline' } }}>{count}</Box>
                  );
                return (
                  <TableRow key={c.id} hover sx={{ cursor: c.status === 'active' ? 'pointer' : 'default', '&:last-child td': { borderBottom: 0 } }} onClick={() => c.status === 'active' && visit(c)}>
                    <TableCell>
                      <Stack direction="row" spacing={1.25} sx={{ alignItems: 'center' }}>
                        <Box aria-hidden sx={{ width: 30, height: 30, borderRadius: 1.5, bgcolor: clientColor(c.id), color: '#fff', display: 'grid', placeItems: 'center', fontWeight: 700, fontSize: 13, flexShrink: 0 }}>{c.name.charAt(0).toUpperCase()}</Box>
                        <Box sx={{ minWidth: 0 }}>
                          <Typography variant="subtitle2" noWrap>{c.name}</Typography>
                          <Typography variant="caption" color="text.secondary" sx={{ textTransform: 'capitalize' }} noWrap component="div">
                            {c.industry ? c.industry.replace(/_/g, ' ') : 'Client'}{c.myRole && c.myRole !== 'tenant_owner' && c.myRole !== 'tenant_admin' ? ` · you: ${roleName(c.myRole).toLowerCase()}` : ''}
                          </Typography>
                        </Box>
                        {c.status === 'archived' && <Pill>Archived</Pill>}
                      </Stack>
                    </TableCell>
                    <TableCell align="right">{s ? link(s.openLeads, 'state=open') : '—'}</TableCell>
                    <TableCell align="right">{s ? link(s.newLast7Days, `show=all&from=${weekAgo()}`) : '—'}</TableCell>
                    <TableCell align="right">{s ? link(s.slaBreached, 'sla=breached', true) : '—'}</TableCell>
                    <TableCell align="right">{s ? link(s.unassigned, 'owner=unassigned', true) : '—'}</TableCell>
                    <TableCell align="right">{s ? money(s.spendThisMonth, s.currency) : '—'}</TableCell>
                    <TableCell>
                      {!s || s.leadSources === null ? '—' : s.leadSources.active > 0 ? (
                        <Pill tone="success" title={s.leadSources.lastReceivedAt ? `Last lead received ${new Date(s.leadSources.lastReceivedAt).toLocaleString()}` : 'Connected, no lead received yet'}>
                          {s.leadSources.lastReceivedAt ? <>Live · <When value={s.leadSources.lastReceivedAt} sx={{ color: 'inherit' }} /></> : 'Connected'}
                        </Pill>
                      ) : (
                        <Pill tone="warning" onClick={(e) => { e.stopPropagation(); visit(c, '/integrations/lead-sources'); }} title="Connect where this client’s leads come from">Not connected</Pill>
                      )}
                    </TableCell>
                    <TableCell align="right">{c.assignedStaff}</TableCell>
                    <TableCell padding="checkbox" onClick={(e) => e.stopPropagation()}>
                      {isAgencyAdmin && (
                        <Tooltip title="More"><IconButton size="small" aria-label={`More for ${c.name}`} onClick={(e) => setMenu({ el: e.currentTarget, client: c })}><MoreVertRoundedIcon fontSize="small" /></IconButton></Tooltip>
                      )}
                    </TableCell>
                  </TableRow>
                );
              })}
            </TableBody>
          </Table>
        </TableContainer>
      )}

      <Menu anchorEl={menu?.el} open={Boolean(menu)} onClose={() => setMenu(null)}>
        {menu?.client.status === 'active' && [
          <MenuItem key="open" onClick={() => { const c = menu.client; setMenu(null); visit(c); }}>Open</MenuItem>,
          <MenuItem key="leads" onClick={() => { const c = menu.client; setMenu(null); nav(`/agency/leads?client=${c.id}`); }}>Its leads (in All leads)</MenuItem>,
          <MenuItem key="team" onClick={() => { const c = menu.client; setMenu(null); visit(c, '/settings/team'); }}>Team</MenuItem>,
          <MenuItem key="settings" onClick={() => { const c = menu.client; setMenu(null); visit(c, '/settings/client'); }}>Details and settings</MenuItem>,
          <MenuItem key="archive" sx={{ color: 'error.main' }} onClick={async () => {
            const c = menu.client;
            setMenu(null);
            if (await confirm({ title: `Archive ${c.name}?`, body: 'Everyone loses access to this client and its lead capture and API keys stop working. Nothing is deleted and you can restore it at any time.', confirmLabel: 'Archive', destructive: true })) archive.mutate({ c, archived: true });
          }}>Archive…</MenuItem>,
        ]}
        {menu?.client.status === 'archived' && (
          <MenuItem onClick={() => { const c = menu.client; setMenu(null); archive.mutate({ c, archived: false }); }}>
            <ListItemText primary="Restore" secondary="Bring this client back" />
          </MenuItem>
        )}
      </Menu>

      <AddClientDialog
        open={adding}
        onClose={() => setAdding(false)}
        onCreated={(c) => {
          setAdding(false);
          openClient(c.id);
          nav('/overview');
        }}
      />
    </>
  );
}
