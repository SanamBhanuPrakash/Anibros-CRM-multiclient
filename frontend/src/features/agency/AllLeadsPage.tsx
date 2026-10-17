import { useEffect, useMemo, useState } from 'react';
import { Badge, Box, Button, IconButton, InputAdornment, ListItemIcon, ListItemText, Menu, MenuItem, Pagination, Paper, Popover, Stack, Table, TableBody, TableCell, TableContainer, TableHead, TableRow, TextField, Tooltip, Typography } from '@mui/material';
import SearchRoundedIcon from '@mui/icons-material/SearchRounded';
import CallOutlinedIcon from '@mui/icons-material/CallOutlined';
import WhatsAppIcon from '@mui/icons-material/WhatsApp';
import FileDownloadOutlinedIcon from '@mui/icons-material/FileDownloadOutlined';
import TuneRoundedIcon from '@mui/icons-material/TuneRounded';
import TableChartOutlinedIcon from '@mui/icons-material/TableChartOutlined';
import DescriptionOutlinedIcon from '@mui/icons-material/DescriptionOutlined';
import HistoryRoundedIcon from '@mui/icons-material/HistoryRounded';
import PeopleAltOutlinedIcon from '@mui/icons-material/PeopleAltOutlined';
import { useQuery } from '@tanstack/react-query';
import { useNavigate, useSearchParams } from 'react-router-dom';
import { ApiProblem } from '@/api/client';
import { agencyApi, exportUrl, type AgencyLead, type AgencyLeadFilters } from '@/api/agency';
import { customFieldsApi } from '@/api/customFields';
import { useAuth } from '@/features/auth/AuthProvider';
import { SlaChip, StageChip, FollowupText } from '@/features/leads/shared';
import { openClient } from '@/app/scope';
import { PageHeader } from '@/ui/PageHeader';
import { EmptyState, ErrorState, SkeletonRows } from '@/ui/states';
import { UserAvatar } from '@/ui/display';
import { ClientChip, ClientDot, ViewTabs, When } from '@/ui/kit';
import { useToast } from '@/ui/toast';
import { LogActivityDialog, type LogType } from './LogActivityDialog';
import { OwnerCell, SourceTag } from './LeadRowParts';
import { SOURCE_NAME, canExportAs, downloadFile, useAgencyClients } from './shared';

const PAGE_SIZE = 50;
type Preset = 'open' | 'mine' | 'reply' | 'today' | 'unassigned' | 'closed';
const PRESETS: Array<{ id: Preset; label: string }> = [
  { id: 'open', label: 'Open' },
  { id: 'mine', label: 'Mine' },
  { id: 'reply', label: 'Needs reply' },
  { id: 'today', label: 'Follow-up today' },
  { id: 'unassigned', label: 'Not assigned' },
  { id: 'closed', label: 'Closed' },
];

/** All leads across the clients you belong to. The filters live in the address, so a view can be bookmarked or shared. */
export function AllLeadsPage() {
  const nav = useNavigate();
  const toast = useToast();
  const { me, isAgencyAdmin } = useAuth();
  const [sp, setSp] = useSearchParams();
  const get = (k: string) => sp.get(k) ?? '';
  const set = (patch: Record<string, string | undefined>) => {
    const next = new URLSearchParams(sp);
    for (const [k, v] of Object.entries(patch)) {
      if (v === undefined || v === '') next.delete(k);
      else next.set(k, v);
    }
    if (!('page' in patch)) next.delete('page');
    setSp(next, { replace: true });
  };

  const clientId = get('client');
  const show = (get('show') || get('state') || 'open') as 'open' | 'closed' | 'all';
  const owner = get('owner');
  const page = Math.max(1, Number(get('page')) || 1);
  const [search, setSearch] = useState(get('q'));
  useEffect(() => {
    const t = setTimeout(() => search.trim() !== get('q') && set({ q: search.trim() || undefined }), 300);
    return () => clearTimeout(t);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [search]);

  const filters: AgencyLeadFilters = {
    clientId: clientId || undefined,
    q: get('q') || undefined,
    state: show === 'all' ? undefined : show,
    ownerId: owner || undefined,
    source: get('source') || undefined,
    sla: (get('sla') || undefined) as AgencyLeadFilters['sla'],
    followup: (get('followup') || undefined) as AgencyLeadFilters['followup'],
    createdFrom: get('from') || undefined,
    createdTo: get('to') || undefined,
    field: get('field') || undefined,
    fieldValue: get('field') ? get('value') || undefined : undefined,
    pool: owner === 'unassigned' ? ('true' as const) : undefined,
    test: 'exclude',
  };
  const sort = (get('sort') || 'created') as 'created' | 'updated' | 'score' | 'value';

  const preset: Preset | null = (() => {
    if (get('q') || get('source') || get('from') || get('to') || get('field')) return null;
    const sla = get('sla');
    const fu = get('followup');
    if (show === 'closed' && !owner && !sla && !fu) return 'closed';
    if (show !== 'open') return null;
    if (!owner && !sla && !fu) return 'open';
    if (owner === 'me' && !sla && !fu) return 'mine';
    if (owner === 'unassigned' && !sla && !fu) return 'unassigned';
    if (sla === 'breached' && !owner && !fu) return 'reply';
    if (fu === 'today' && !owner && !sla) return 'today';
    return null;
  })();
  const applyPreset = (id: Preset) => {
    setSearch('');
    set({ show: id === 'closed' ? 'closed' : undefined, state: undefined, owner: id === 'mine' ? 'me' : id === 'unassigned' ? 'unassigned' : undefined, sla: id === 'reply' ? 'breached' : undefined, followup: id === 'today' ? 'today' : undefined, q: undefined, source: undefined, from: undefined, to: undefined, field: undefined, value: undefined });
  };

  const clients = useAgencyClients('active');
  const q = useQuery({
    queryKey: ['agency', 'leads', filters, page, sort],
    queryFn: () => agencyApi.leads({ ...filters, page, pageSize: PAGE_SIZE, sort, order: 'desc' }),
    placeholderData: (p) => p,
    refetchInterval: 60_000,
  });
  const fields = useQuery({ queryKey: ['agency', 'fields', clientId], queryFn: () => customFieldsApi.list(clientId), enabled: Boolean(clientId), staleTime: 60_000 });

  const [moreEl, setMoreEl] = useState<HTMLElement | null>(null);
  const [dlEl, setDlEl] = useState<HTMLElement | null>(null);
  const [busy, setBusy] = useState(false);
  const [logging, setLogging] = useState<{ lead: AgencyLead; type: LogType } | null>(null);
  const moreCount = ['source', 'from', 'to', 'field'].filter((k) => get(k)).length;
  const mayDownload = isAgencyAdmin || (clients.data ?? []).some((c) => canExportAs(c.myRole));
  const field = fields.data?.find((f) => f.key === get('field'));

  const download = async (format: 'xlsx' | 'csv', dataset: 'leads' | 'activities') => {
    setDlEl(null);
    setBusy(true);
    try {
      toast.success(`Downloaded ${await downloadFile(exportUrl(filters, format, dataset))}`);
    } catch (e) {
      toast.error(e instanceof ApiProblem ? e.detail : 'The download didn’t work. Please try again.');
    } finally {
      setBusy(false);
    }
  };

  const open = (l: AgencyLead) => {
    // A caller can only open leads that are theirs: a lead nobody owns has to be taken first.
    if (!l.ownerId && roleOf.get(l.client.id) === 'sales_agent') return void toast.info('Take this lead first, then you can open it');
    openClient(l.client.id);
    nav(`/leads/${l.id}`);
  };
  const data = q.data;
  const countOf = useMemo(() => new Map((data?.byClient ?? []).map((b) => [b.clientId, b.total])), [data]);
  const myId = me?.user.id;
  const picked = clients.data?.find((c) => c.id === clientId);
  const roleOf = useMemo(() => new Map((clients.data ?? []).map((c) => [c.id, c.myRole])), [clients.data]);

  return (
    <>
      <PageHeader
        title="All leads"
        description={picked ? `${picked.name}: every lead, ready to filter and download.` : 'Every lead from the clients you work on, in one list.'}
        actions={
          mayDownload && (
            <>
              <Button size="small" variant="outlined" color="inherit" startIcon={<FileDownloadOutlinedIcon />} disabled={busy} onClick={(e) => setDlEl(e.currentTarget)} aria-haspopup="menu">{busy ? 'Preparing…' : 'Download'}</Button>
              <Menu anchorEl={dlEl} open={Boolean(dlEl)} onClose={() => setDlEl(null)} slotProps={{ paper: { sx: { width: 340 } } }}>
                <MenuItem onClick={() => void download('xlsx', 'leads')}>
                  <ListItemIcon><TableChartOutlinedIcon fontSize="small" /></ListItemIcon>
                  <ListItemText primary="Excel: everything" secondary="Leads, calls and messages, team activity, clients" slotProps={{ secondary: { sx: { whiteSpace: 'normal' } } }} />
                </MenuItem>
                <MenuItem onClick={() => void download('csv', 'leads')}>
                  <ListItemIcon><DescriptionOutlinedIcon fontSize="small" /></ListItemIcon>
                  <ListItemText primary="CSV: the leads" secondary="One row per lead, every detail" />
                </MenuItem>
                <MenuItem onClick={() => void download('csv', 'activities')}>
                  <ListItemIcon><HistoryRoundedIcon fontSize="small" /></ListItemIcon>
                  <ListItemText primary="CSV: calls and messages" secondary="One row per call, WhatsApp, note…" />
                </MenuItem>
                <Typography variant="caption" color="text.secondary" component="p" sx={{ px: 2, pt: 0.5, pb: 1 }}>Follows the filters on screen{clientId ? ' and this client only' : ''}.</Typography>
              </Menu>
            </>
          )
        }
      />

      <ViewTabs label="Quick views" items={PRESETS} value={preset} onChange={applyPreset} />

      <Stack direction="row" spacing={1} sx={{ mb: 2, alignItems: 'center', flexWrap: 'wrap', rowGap: 1 }}>
        <TextField select fullWidth={false} value={clientId} onChange={(e) => set({ client: e.target.value, field: undefined, value: undefined })} sx={{ minWidth: 220 }} slotProps={{ select: { displayEmpty: true }, htmlInput: { 'aria-label': 'Client' } }}>
          <MenuItem value=""><em>All clients{data && !clientId ? ` (${data.page.total})` : ''}</em></MenuItem>
          {(clients.data ?? []).map((c) => (
            <MenuItem key={c.id} value={c.id}>
              <ClientDot id={c.id} />
              <Box component="span" sx={{ ml: 1.25 }}>{c.name}{!clientId && countOf.has(c.id) ? ` (${countOf.get(c.id)})` : ''}</Box>
            </MenuItem>
          ))}
        </TextField>
        <TextField fullWidth={false} placeholder="Search name, phone, e-mail, campaign…" value={search} onChange={(e) => setSearch(e.target.value)} sx={{ flex: '1 1 240px', maxWidth: 400 }} slotProps={{ input: { startAdornment: <InputAdornment position="start"><SearchRoundedIcon fontSize="small" /></InputAdornment> }, htmlInput: { 'aria-label': 'Search leads', maxLength: 100 } }} />
        <Badge color="primary" badgeContent={moreCount} invisible={!moreCount}>
          <Button variant="outlined" color="inherit" startIcon={<TuneRoundedIcon />} onClick={(e) => setMoreEl(e.currentTarget)}>Filters</Button>
        </Badge>
        <Box sx={{ flex: 1 }} />
        <TextField select fullWidth={false} value={sort} onChange={(e) => set({ sort: e.target.value === 'created' ? undefined : e.target.value })} sx={{ minWidth: 160 }} slotProps={{ htmlInput: { 'aria-label': 'Sort leads' } }}>
          <MenuItem value="created">Newest first</MenuItem>
          <MenuItem value="updated">Recently updated</MenuItem>
          <MenuItem value="score">Highest score</MenuItem>
          <MenuItem value="value">Highest value</MenuItem>
        </TextField>
      </Stack>

      <Popover open={Boolean(moreEl)} anchorEl={moreEl} onClose={() => setMoreEl(null)} anchorOrigin={{ vertical: 'bottom', horizontal: 'left' }}>
        <Stack spacing={2} sx={{ p: 2.5, width: 320 }}>
          <TextField select label="Where it came from" value={get('source')} onChange={(e) => set({ source: e.target.value })} slotProps={{ select: { displayEmpty: true }, inputLabel: { shrink: true } }}>
            <MenuItem value="">Anywhere</MenuItem>
            {['website', 'meta_ads', 'google_ads', 'referral', 'portal', 'direct', 'walk_in', 'other'].map((s) => <MenuItem key={s} value={s}>{SOURCE_NAME(s)}</MenuItem>)}
          </TextField>
          <Stack direction="row" spacing={1.5}>
            <TextField type="date" label="Added from" value={get('from')} onChange={(e) => set({ from: e.target.value })} slotProps={{ inputLabel: { shrink: true } }} />
            <TextField type="date" label="to" value={get('to')} onChange={(e) => set({ to: e.target.value })} slotProps={{ inputLabel: { shrink: true } }} />
          </Stack>
          {clientId ? (
            <Stack spacing={1.5}>
              <TextField select label="Lead field" value={get('field')} onChange={(e) => set({ field: e.target.value, value: undefined })} slotProps={{ select: { displayEmpty: true }, inputLabel: { shrink: true } }} helperText={fields.data?.length === 0 ? 'This client has no custom fields yet' : undefined}>
                <MenuItem value="">Any</MenuItem>
                {(fields.data ?? []).map((f) => <MenuItem key={f.key} value={f.key}>{f.label}</MenuItem>)}
              </TextField>
              {field && (field.type === 'select' || field.type === 'multi_select' ? (
                <TextField select label="Is" value={get('value')} onChange={(e) => set({ value: e.target.value })}>
                  {field.options.map((o) => <MenuItem key={o.value} value={o.value}>{o.label}</MenuItem>)}
                </TextField>
              ) : field.type === 'boolean' ? (
                <TextField select label="Is" value={get('value')} onChange={(e) => set({ value: e.target.value })}>
                  <MenuItem value="true">Yes</MenuItem>
                  <MenuItem value="false">No</MenuItem>
                </TextField>
              ) : (
                <TextField label="Equals" value={get('value')} onChange={(e) => set({ value: e.target.value })} />
              ))}
            </Stack>
          ) : (
            <Typography variant="caption" color="text.secondary">Choose a client to filter by its own lead fields.</Typography>
          )}
          <Button onClick={() => set({ source: undefined, from: undefined, to: undefined, field: undefined, value: undefined })}>Reset these</Button>
        </Stack>
      </Popover>

      {q.isPending ? (
        <SkeletonRows rows={8} height={48} />
      ) : q.isError ? (
        <ErrorState error={q.error} onRetry={() => void q.refetch()} />
      ) : data!.items.length === 0 ? (
        <Paper variant="outlined" sx={{ borderRadius: 2.5 }}>
          <EmptyState icon={<PeopleAltOutlinedIcon />} title="No leads match" description={preset === 'open' ? 'Leads from your clients appear here as they come in.' : 'Try another quick view, or remove a filter.'} action={preset !== 'open' ? <Button onClick={() => { setSearch(''); applyPreset('open'); }}>Show open leads</Button> : undefined} />
        </Paper>
      ) : (
        <>
          <TableContainer component={Paper} variant="outlined" sx={{ borderRadius: 2.5, opacity: q.isPlaceholderData ? 0.6 : 1, transition: 'opacity 150ms' }}>
            <Table aria-label="Leads from all clients">
              <TableHead>
                <TableRow>
                  <TableCell>Lead</TableCell>
                  {!clientId && <TableCell>Client</TableCell>}
                  <TableCell>Stage</TableCell>
                  <TableCell>Owner</TableCell>
                  <TableCell sx={{ display: { xs: 'none', lg: 'table-cell' } }}>Source</TableCell>
                  <TableCell>Follow-up</TableCell>
                  <TableCell sx={{ display: { xs: 'none', lg: 'table-cell' } }}>Last touch</TableCell>
                  <TableCell align="right">Calls</TableCell>
                  <TableCell padding="checkbox"><span style={{ position: 'absolute', left: -9999 }}>Actions</span></TableCell>
                </TableRow>
              </TableHead>
              <TableBody>
                {data!.items.map((l) => (
                  <TableRow key={l.id} hover sx={{ cursor: 'pointer', '&:last-child td': { borderBottom: 0 } }} onClick={() => open(l)}>
                    <TableCell sx={{ minWidth: 200 }}>
                      <Stack direction="row" spacing={1.25} sx={{ alignItems: 'center' }}>
                        <UserAvatar name={l.contactName ?? '?'} seed={l.id} size={30} />
                        <Box sx={{ minWidth: 0 }}>
                          <Stack direction="row" spacing={0.75} sx={{ alignItems: 'center' }}>
                            <Typography variant="subtitle2" noWrap>{l.contactName ?? 'Unnamed lead'}</Typography>
                            <SlaChip sla={l.sla} quiet />
                          </Stack>
                          <Typography variant="caption" color="text.secondary" noWrap component="div">{l.contactPhone ?? l.contactEmail ?? '—'}</Typography>
                        </Box>
                      </Stack>
                    </TableCell>
                    {!clientId && (
                      <TableCell onClick={(e) => e.stopPropagation()}>
                        <ClientChip id={l.client.id} name={l.client.name} onClick={() => set({ client: l.client.id })} />
                      </TableCell>
                    )}
                    <TableCell><StageChip name={l.stageName} color={l.stageColor} /></TableCell>
                    <TableCell><OwnerCell lead={{ id: l.id, clientId: l.client.id, ownerId: l.ownerId, ownerName: l.ownerName }} role={roleOf.get(l.client.id)} /></TableCell>
                    <TableCell sx={{ display: { xs: 'none', lg: 'table-cell' } }}><SourceTag source={l.source} campaign={l.campaign} /></TableCell>
                    <TableCell><FollowupText at={l.nextFollowupAt} /></TableCell>
                    <TableCell sx={{ display: { xs: 'none', lg: 'table-cell' } }}><When value={l.touches.lastTouchAt} fallback="Never" /></TableCell>
                    <TableCell align="right">
                      {l.touches.calls ? (
                        <Tooltip title={`${l.touches.calls} call${l.touches.calls === 1 ? '' : 's'}${l.touches.connected ? `, ${l.touches.connected} spoke to them` : ''}`}>
                          <Box component="span" sx={{ fontWeight: 500, color: l.touches.connected ? 'success.main' : 'text.primary' }}>{l.touches.calls}</Box>
                        </Tooltip>
                      ) : <Box component="span" sx={{ color: 'text.disabled' }}>0</Box>}
                    </TableCell>
                    <TableCell padding="checkbox" onClick={(e) => e.stopPropagation()}>
                      <Stack direction="row" sx={{ justifyContent: 'flex-end' }}>
                        <Tooltip title="Call and log it"><IconButton size="small" aria-label={`Log a call with ${l.contactName ?? 'lead'}`} onClick={() => setLogging({ lead: l, type: 'call' })}><CallOutlinedIcon fontSize="small" /></IconButton></Tooltip>
                        {l.contactPhone && <Tooltip title="WhatsApp and log it"><IconButton size="small" aria-label={`Log a WhatsApp message to ${l.contactName ?? 'lead'}`} onClick={() => setLogging({ lead: l, type: 'whatsapp' })}><WhatsAppIcon fontSize="small" /></IconButton></Tooltip>}
                      </Stack>
                    </TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          </TableContainer>
          <Stack direction={{ xs: 'column', sm: 'row' }} spacing={1.5} sx={{ mt: 2, alignItems: 'center', justifyContent: 'space-between' }}>
            <Typography variant="body2" color="text.secondary">
              {(data!.page.page - 1) * data!.page.pageSize + 1}–{(data!.page.page - 1) * data!.page.pageSize + data!.items.length} of {data!.page.total.toLocaleString()} lead{data!.page.total === 1 ? '' : 's'}
            </Typography>
            {data!.page.totalPages > 1 && <Pagination count={data!.page.totalPages} page={data!.page.page} onChange={(_, p) => set({ page: String(p) })} shape="rounded" size="small" />}
          </Stack>
        </>
      )}

      <LogActivityDialog open={Boolean(logging)} onClose={() => setLogging(null)} clientId={logging?.lead.client.id ?? ''} initialType={logging?.type} lead={logging ? { id: logging.lead.id, name: logging.lead.contactName, phone: logging.lead.contactPhone } : null} />
    </>
  );
}
