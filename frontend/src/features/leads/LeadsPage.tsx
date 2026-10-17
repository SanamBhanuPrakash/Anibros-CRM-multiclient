import { useEffect, useMemo, useState } from 'react';
import { useNavigate, useSearchParams } from 'react-router-dom';
import {
  Box, Button, Checkbox, Chip, Dialog, DialogActions, DialogContent, DialogTitle, FormControlLabel, IconButton, InputAdornment, ListItemIcon, ListItemText, Menu, MenuItem, Paper, Popover, Stack,
  Switch, Table, TableBody, TableCell, TableContainer, TableHead, TableRow, TextField, ToggleButton, ToggleButtonGroup, Tooltip, Typography,
} from '@mui/material';
import SearchRoundedIcon from '@mui/icons-material/SearchRounded';
import AddRoundedIcon from '@mui/icons-material/AddRounded';
import FileUploadOutlinedIcon from '@mui/icons-material/FileUploadOutlined';
import FileDownloadOutlinedIcon from '@mui/icons-material/FileDownloadOutlined';
import FilterListRoundedIcon from '@mui/icons-material/FilterListRounded';
import BookmarkBorderRoundedIcon from '@mui/icons-material/BookmarkBorderRounded';
import ViewListOutlinedIcon from '@mui/icons-material/ViewListOutlined';
import ViewKanbanOutlinedIcon from '@mui/icons-material/ViewKanbanOutlined';
import DeleteOutlineRoundedIcon from '@mui/icons-material/DeleteOutlineRounded';
import PeopleAltOutlinedIcon from '@mui/icons-material/PeopleAltOutlined';
import CallOutlinedIcon from '@mui/icons-material/CallOutlined';
import { useInfiniteQuery, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { ApiProblem } from '@/api/client';
import { leadsApi, viewsApi, type BulkRequest, type Lead, type LeadFilters, type LeadSource, type ReasonKind, type SavedView } from '@/api/crm';
import type { Role } from '@/api/endpoints';
import { useAuth } from '@/features/auth/AuthProvider';
import { UserAvatar } from '@/ui/display';
import { ViewTabs, When } from '@/ui/kit';
import { exportUrl as agencyExportUrl, type AgencyLeadFilters } from '@/api/agency';
import { downloadFile } from '@/features/agency/shared';
import { LogActivityDialog, type LogType } from '@/features/agency/LogActivityDialog';
import { OwnerCell, SourceTag } from '@/features/agency/LeadRowParts';
import WhatsAppIcon from '@mui/icons-material/WhatsApp';
import { PageHeader } from '@/ui/PageHeader';
import { EmptyState, ErrorState, SkeletonRows } from '@/ui/states';
import { useConfirm } from '@/ui/confirm';
import { useToast } from '@/ui/toast';
import { FollowupText, formatMoney, ScoreBadge, SlaChip, SOURCE_LABEL, StageChip, usePipelines, useDirectory } from './shared';
import { NewLeadDialog } from './NewLeadDialog';
import { ImportDialog } from './ImportDialog';
import { LeadBoard } from './LeadBoard';
import { ReasonDialog, kindOfStatus, useReasonCodeRequired } from './ReasonDialog';

const SORTS = [
  { id: 'created', label: 'Newest first', order: 'desc' },
  { id: 'updated', label: 'Recently updated', order: 'desc' },
  { id: 'score', label: 'Highest score', order: 'desc' },
  { id: 'value', label: 'Highest value', order: 'desc' },
  { id: 'followup', label: 'Next follow-up', order: 'asc' },
] as const;

function useDebounced<T>(value: T, ms: number): T {
  const [v, setV] = useState(value);
  useEffect(() => {
    const t = setTimeout(() => setV(value), ms);
    return () => clearTimeout(t);
  }, [value, ms]);
  return v;
}

/** The URL is the filter state: a copied link, a refresh, or the back button all land on the same view. */
function useFilterState() {
  const [params, setParams] = useSearchParams();
  const get = (k: string) => params.get(k) ?? undefined;
  const filters: LeadFilters = {
    q: get('q'),
    stageId: get('stage'),
    state: get('state') === 'all' ? undefined : ((get('state') as LeadFilters['state']) ?? 'open'),
    ownerId: get('owner'),
    source: get('source') as LeadSource | undefined,
    tag: get('tag'),
    sla: get('sla') as LeadFilters['sla'],
    followup: get('followup') as LeadFilters['followup'],
    scoreMin: get('score') ? Number(get('score')) : undefined,
    status: get('status'),
    createdFrom: get('from'),
    createdTo: get('to'),
    test: get('test') === 'exclude' ? ('exclude' as const) : undefined,
    sort: (get('sort') as LeadFilters['sort']) ?? 'created',
  };
  const set = (patch: Record<string, string | undefined>) =>
    setParams((p) => {
      const next = new URLSearchParams(p);
      for (const [k, v] of Object.entries(patch)) {
        if (v === undefined || v === '') next.delete(k);
        else next.set(k, v);
      }
      return next;
    }, { replace: true });
  return { params, filters, view: get('view') === 'board' ? ('board' as const) : ('list' as const), set, setParams };
}

const URL_KEYS: Record<string, keyof LeadFilters> = { q: 'q', stage: 'stageId', state: 'state', owner: 'ownerId', source: 'source', tag: 'tag', sla: 'sla', followup: 'followup', score: 'scoreMin', sort: 'sort', status: 'status', from: 'createdFrom', to: 'createdTo', test: 'test' };

const STATUS_WORD: Record<string, string> = { won: 'Won', lost: 'Lost', disqualified: 'Not a fit', open: 'New', contacted: 'Contacted', qualified: 'Qualified', viewing_scheduled: 'Site visit', negotiation: 'Negotiating' };
const dayLabel = (d: string) => new Date(`${d}T00:00:00`).toLocaleDateString(undefined, { day: 'numeric', month: 'short' });
/** Filters that arrive by link (the Dashboard) and have no control on this page: shown as chips so nobody wonders why a list is short. */
function scopeChips(f: LeadFilters): Array<{ key: string; label: string; clear: string[] }> {
  const out: Array<{ key: string; label: string; clear: string[] }> = [];
  if (f.status) out.push({ key: 'status', label: `Status: ${f.status.split(',').map((s) => STATUS_WORD[s] ?? s).join(', ')}`, clear: ['status'] });
  if (f.createdFrom || f.createdTo) out.push({ key: 'created', label: `Created ${f.createdFrom ? dayLabel(f.createdFrom) : 'start'} – ${f.createdTo ? dayLabel(f.createdTo) : 'today'}`, clear: ['from', 'to'] });
  if (f.test === 'exclude') out.push({ key: 'test', label: 'Test leads left out', clear: ['test'] });
  return out;
}

type Preset = 'open' | 'mine' | 'reply' | 'today' | 'unassigned' | 'closed';
const PRESETS: Array<{ id: Preset; label: string }> = [
  { id: 'open', label: 'Open' },
  { id: 'mine', label: 'Mine' },
  { id: 'reply', label: 'Needs reply' },
  { id: 'today', label: 'Follow-up today' },
  { id: 'unassigned', label: 'Not assigned' },
  { id: 'closed', label: 'Closed' },
];

/** Same filters, in the shape the agency-wide download understands (so an Excel file matches what is on screen). */
function toAgencyFilters(f: LeadFilters, clientId: string): AgencyLeadFilters {
  return { clientId, q: f.q, state: f.state, ownerId: f.ownerId, source: f.source, tag: f.tag, scoreMin: f.scoreMin, createdFrom: f.createdFrom, createdTo: f.createdTo, status: f.status, sla: f.sla, followup: f.followup, test: f.test };
}

export function LeadsPage() {
  const nav = useNavigate();
  const toast = useToast();
  const { can, scope, me, tenant, role, isAgencyMember } = useAuth();
  const { params, filters, view, set, setParams } = useFilterState();
  const myId = me?.user.id;
  const [text, setText] = useState(filters.q ?? '');
  const debounced = useDebounced(text.trim(), 300);
  useEffect(() => { if ((filters.q ?? '') !== debounced) set({ q: debounced || undefined }); }, [debounced]); // eslint-disable-line react-hooks/exhaustive-deps
  const [newOpen, setNewOpen] = useState(false);
  // "Add lead" on the Overview arrives as /leads?new=1: open the form once and tidy the address.
  useEffect(() => {
    if (params.get('new') !== '1') return;
    setNewOpen(true);
    const next = new URLSearchParams(params);
    next.delete('new');
    setParams(next, { replace: true });
  }, [params, setParams]);
  const [importOpen, setImportOpen] = useState(false);
  const [moreAnchor, setMoreAnchor] = useState<HTMLElement | null>(null);
  const [dlEl, setDlEl] = useState<HTMLElement | null>(null);
  const [busy, setBusy] = useState(false);
  const [logging, setLogging] = useState<{ lead: Lead; type: LogType } | null>(null);
  const pipelines = usePipelines();
  const defaultPipeline = pipelines.data?.find((p) => p.isDefault) ?? pipelines.data?.[0];
  const [boardPipelineId, setBoardPipelineId] = useState<string | undefined>();
  const boardPipeline = pipelines.data?.find((p) => p.id === boardPipelineId) ?? defaultPipeline;
  const directory = useDirectory();
  const owners = useMemo(() => new Map((directory.data ?? []).map((m) => [m.userId, m.name])), [directory.data]);
  const canSeeAll = scope('leads:read') === 'all';
  const sort = SORTS.find((s) => s.id === filters.sort) ?? SORTS[0];
  // "owner=me" arrives from links elsewhere in the app; the list API wants the person's id
  const ownerId = filters.ownerId === 'me' ? myId : filters.ownerId;
  const state = filters.state === ('all' as string) ? undefined : filters.state;
  // Callers only see their own leads, except the pool of leads nobody has taken yet: Not assigned is that pool for them.
  const apiFilters: LeadFilters = { ...filters, ownerId, state, sort: sort.id, order: sort.order, pipelineId: undefined, pool: filters.ownerId === 'unassigned' ? 'true' : undefined };

  const list = useInfiniteQuery({
    queryKey: ['leads', 'list', apiFilters],
    queryFn: ({ pageParam }) => leadsApi.list(apiFilters, pageParam),
    initialPageParam: undefined as string | undefined,
    getNextPageParam: (last) => last.page?.next ?? undefined,
    placeholderData: (prev) => prev,
    enabled: view === 'list',
  });
  const rows = useMemo(() => list.data?.pages.flatMap((p) => p.data) ?? [], [list.data]);
  const [selected, setSelected] = useState<Set<string>>(new Set());
  useEffect(() => setSelected(new Set()), [filters.q, filters.stageId, filters.state, filters.ownerId, filters.source, filters.tag, filters.sla, filters.followup, filters.scoreMin, filters.sort, filters.status, filters.createdFrom, filters.createdTo, filters.test, view]);

  const preset: Preset | null = (() => {
    const open = (filters.state ?? 'open') === 'open';
    const none = !filters.stageId && !filters.source && !filters.tag && !filters.scoreMin && !filters.status && !filters.createdFrom && !filters.createdTo && !filters.q;
    if (!none) return null;
    if ((filters.state as string) === 'closed' && !ownerId && !filters.sla && !filters.followup) return 'closed';
    if (!open) return null;
    if (!ownerId && !filters.sla && !filters.followup) return 'open';
    if (ownerId && ownerId === myId && !filters.sla && !filters.followup) return 'mine';
    if (ownerId === 'unassigned' && !filters.sla && !filters.followup) return 'unassigned';
    if (filters.sla === 'breached' && !ownerId && !filters.followup) return 'reply';
    if (filters.followup === 'today' && !ownerId && !filters.sla) return 'today';
    return null;
  })();
  const applyPreset = (id: Preset) => {
    setText('');
    set({ state: id === 'closed' ? 'closed' : undefined, owner: id === 'mine' ? myId : id === 'unassigned' ? 'unassigned' : undefined, sla: id === 'reply' ? 'breached' : undefined, followup: id === 'today' ? 'today' : undefined, stage: undefined, q: undefined, source: undefined, tag: undefined, score: undefined, status: undefined, from: undefined, to: undefined });
  };

  const filtering = Boolean(filters.q || filters.stageId || filters.ownerId || filters.source || filters.tag || filters.sla || filters.followup || filters.scoreMin || filters.status || filters.createdFrom || filters.createdTo || filters.test) || filters.state !== 'open';
  const chips = scopeChips(filters);
  const activeExtras = [filters.source, filters.tag, filters.sla, filters.followup, filters.scoreMin].filter(Boolean).length;
  const clear = () => { setText(''); setParams(new URLSearchParams(view === 'board' ? { view: 'board' } : {}), { replace: true }); };

  const applyView = (v: SavedView) => {
    const next = new URLSearchParams(view === 'board' ? { view: 'board' } : {});
    for (const [urlKey, field] of Object.entries(URL_KEYS)) {
      const val = (v.filters as Record<string, unknown>)[field];
      if (val !== undefined && val !== null && val !== '') next.set(urlKey, String(val));
    }
    if (v.sort && !next.has('sort')) next.set('sort', v.sort);
    setText(next.get('q') ?? '');
    setParams(next, { replace: true });
  };

  const download = async (format: 'xlsx' | 'csv', dataset: 'leads' | 'activities') => {
    setDlEl(null);
    if (!tenant) return;
    setBusy(true);
    try {
      toast.success(`Downloaded ${await downloadFile(agencyExportUrl(toAgencyFilters(apiFilters, tenant.id), format, dataset))}`);
    } catch (e) {
      toast.error(e instanceof ApiProblem ? e.detail : 'The download didn’t work. Please try again.');
    } finally {
      setBusy(false);
    }
  };
  const showValue = tenant?.industry === 'real_estate' || rows.some((r) => r.estimatedValue);

  return (
    <>
      <PageHeader
        title="Leads"
        description="Every enquiry for this client: who owns it, how warm it is and what needs doing next."
        actions={
          <>
            {can('leads:import') && <Button size="small" variant="outlined" color="inherit" startIcon={<FileUploadOutlinedIcon />} onClick={() => setImportOpen(true)}>Import</Button>}
            {can('leads:create') && <Button size="small" variant="contained" startIcon={<AddRoundedIcon />} onClick={() => setNewOpen(true)}>Add lead</Button>}
            {can('leads:export') && isAgencyMember && (
              <>
                <Button size="small" variant="outlined" color="inherit" startIcon={<FileDownloadOutlinedIcon />} disabled={busy} aria-haspopup="menu" onClick={(e) => setDlEl(e.currentTarget)}>{busy ? 'Preparing…' : 'Download'}</Button>
                <Menu anchorEl={dlEl} open={Boolean(dlEl)} onClose={() => setDlEl(null)} slotProps={{ paper: { sx: { width: 320 } } }}>
                  <MenuItem onClick={() => void download('xlsx', 'leads')}><ListItemText primary="Excel: everything" secondary="Leads, calls and messages, team activity" /></MenuItem>
                  <MenuItem onClick={() => void download('csv', 'leads')}><ListItemText primary="CSV: the leads" secondary="One row per lead, every detail" /></MenuItem>
                  <MenuItem onClick={() => void download('csv', 'activities')}><ListItemText primary="CSV: calls and messages" secondary="One row per call, WhatsApp, note…" /></MenuItem>
                  <Typography variant="caption" color="text.secondary" component="p" sx={{ px: 2, pt: 0.5, pb: 1 }}>Follows the filters on screen.</Typography>
                </Menu>
              </>
            )}
            {can('leads:export') && !isAgencyMember && <Button size="small" variant="outlined" color="inherit" startIcon={<FileDownloadOutlinedIcon />} component="a" href={leadsApi.exportUrl(apiFilters)} download>Export CSV</Button>}
          </>
        }
      />

      <ViewTabs label="Quick views" items={PRESETS} value={preset} onChange={applyPreset} />

      <Stack direction="row" spacing={1} sx={{ mb: 2, alignItems: 'center', flexWrap: 'wrap', rowGap: 1 }}>
        <TextField
          fullWidth={false} placeholder="Search name, phone, e-mail, campaign…" value={text} onChange={(e) => setText(e.target.value)}
          sx={{ flex: '1 1 260px', maxWidth: 420 }}
          slotProps={{ input: { startAdornment: <InputAdornment position="start"><SearchRoundedIcon fontSize="small" /></InputAdornment> }, htmlInput: { 'aria-label': 'Search leads', maxLength: 100 } }}
        />
        <TextField select fullWidth={false} value={filters.stageId ?? ''} onChange={(e) => set({ stage: e.target.value })} sx={{ minWidth: 150 }} slotProps={{ select: { displayEmpty: true }, htmlInput: { 'aria-label': 'Stage' } }}>
          <MenuItem value="">All stages</MenuItem>
          {defaultPipeline?.stages.map((s) => <MenuItem key={s.id} value={s.id}>{s.name}</MenuItem>)}
        </TextField>
        {canSeeAll && (
          <TextField select fullWidth={false} value={ownerId ?? ''} onChange={(e) => set({ owner: e.target.value })} sx={{ minWidth: 150 }} slotProps={{ select: { displayEmpty: true }, htmlInput: { 'aria-label': 'Owner' } }}>
            <MenuItem value="">Anyone’s</MenuItem>
            {me && <MenuItem value={me.user.id}>Mine</MenuItem>}
            <MenuItem value="unassigned">Not assigned</MenuItem>
            {(directory.data ?? []).filter((m) => m.userId !== me?.user.id).map((m) => <MenuItem key={m.userId} value={m.userId}>{m.name}</MenuItem>)}
          </TextField>
        )}
        <Button variant={activeExtras ? 'contained' : 'outlined'} color={activeExtras ? 'primary' : 'inherit'} startIcon={<FilterListRoundedIcon />} onClick={(e) => setMoreAnchor(e.currentTarget)}>
          Filters{activeExtras ? ` · ${activeExtras}` : ''}
        </Button>
        <SavedViews filters={apiFilters} onApply={applyView} />
        {filtering && preset === null && <Button color="inherit" onClick={clear}>Clear all</Button>}
        <Box sx={{ flex: 1 }} />
        {view === 'list' && (
          <TextField select fullWidth={false} value={sort.id} onChange={(e) => set({ sort: e.target.value === 'created' ? undefined : e.target.value })} sx={{ minWidth: 160 }} slotProps={{ htmlInput: { 'aria-label': 'Sort leads' } }}>
            {SORTS.map((s) => <MenuItem key={s.id} value={s.id}>{s.label}</MenuItem>)}
          </TextField>
        )}
        <ToggleButtonGroup size="small" exclusive value={view} onChange={(_, v) => v && set({ view: v === 'list' ? undefined : v })} aria-label="Layout">
          <ToggleButton value="list" aria-label="List"><ViewListOutlinedIcon fontSize="small" /></ToggleButton>
          <ToggleButton value="board" aria-label="Board"><ViewKanbanOutlinedIcon fontSize="small" /></ToggleButton>
        </ToggleButtonGroup>
      </Stack>

      {chips.length > 0 && (
        <Stack direction="row" spacing={1} sx={{ mb: 2, flexWrap: 'wrap', rowGap: 1 }} aria-label="Filters from a link">
          {chips.map((c) => <Chip key={c.key} size="small" color="primary" variant="outlined" label={c.label} onDelete={() => set(Object.fromEntries(c.clear.map((k) => [k, undefined])))} />)}
        </Stack>
      )}

      <MoreFilters anchor={moreAnchor} onClose={() => setMoreAnchor(null)} filters={filters} set={set} />

      {view === 'board' ? (
        boardPipeline ? (
          <>
            {(pipelines.data?.length ?? 0) > 1 && (
              <TextField select fullWidth={false} label="Pipeline" value={boardPipeline.id} onChange={(e) => setBoardPipelineId(e.target.value)} sx={{ mb: 2, minWidth: 220 }}>
                {pipelines.data!.map((p) => <MenuItem key={p.id} value={p.id}>{p.name}</MenuItem>)}
              </TextField>
            )}
            <LeadBoard pipeline={boardPipeline} ownerId={ownerId} q={filters.q} />
          </>
        ) : pipelines.isError ? <ErrorState error={pipelines.error} onRetry={() => void pipelines.refetch()} /> : <SkeletonRows rows={5} />
      ) : list.isPending ? (
        <SkeletonRows rows={8} height={48} />
      ) : list.isError ? (
        <ErrorState error={list.error} onRetry={() => void list.refetch()} />
      ) : rows.length === 0 ? (
        <Paper variant="outlined" sx={{ borderRadius: 2.5 }}>
          {filtering ? (
            <EmptyState icon={<SearchRoundedIcon />} title="No leads match" description="Try fewer filters, or search by the last digits of a phone number." action={<Button onClick={clear}>Clear filters</Button>} />
          ) : (
            <EmptyState
              icon={<PeopleAltOutlinedIcon />}
              title="No leads yet"
              description="Leads appear here as they come in from this client’s website and ads. You can also add one by hand or import a spreadsheet."
              action={<Stack direction="row" spacing={1}>{can('leads:create') && <Button variant="contained" onClick={() => setNewOpen(true)}>Add a lead</Button>}{can('leads:import') && <Button onClick={() => setImportOpen(true)}>Import from CSV</Button>}</Stack>}
            />
          )}
        </Paper>
      ) : (
        <>
          <LeadTable rows={rows} owners={owners} role={role} clientId={tenant?.id ?? ''} showValue={showValue} selectable={can('leads:bulk')} selected={selected} setSelected={setSelected} onOpen={(id) => { const l = rows.find((x) => x.id === id); if (l && !l.ownerId && role === 'sales_agent') return void toast.info('Take this lead first, then you can open it'); nav(`/leads/${id}`); }} onLog={(lead, type) => setLogging({ lead, type })} canLog={can('activities:create')} />
          <Stack direction="row" sx={{ mt: 2, alignItems: 'center', justifyContent: 'space-between' }}>
            <Typography variant="body2" color="text.secondary" aria-live="polite">{rows.length} lead{rows.length === 1 ? '' : 's'}{list.hasNextPage ? ' loaded' : ''}</Typography>
            {list.hasNextPage && <Button onClick={() => void list.fetchNextPage()} disabled={list.isFetchingNextPage}>{list.isFetchingNextPage ? 'Loading…' : 'Load more'}</Button>}
          </Stack>
        </>
      )}

      {selected.size > 0 && <BulkBar ids={[...selected]} clear={() => setSelected(new Set())} pipelineStages={defaultPipeline?.stages ?? []} />}
      <NewLeadDialog open={newOpen} onClose={() => setNewOpen(false)} onCreated={(l) => { setNewOpen(false); nav(`/leads/${l.id}`); }} onOpenExisting={(id) => nav(`/leads/${id}`)} />
      <ImportDialog open={importOpen} onClose={() => setImportOpen(false)} />
      {tenant && <LogActivityDialog open={Boolean(logging)} onClose={() => setLogging(null)} clientId={tenant.id} initialType={logging?.type} lead={logging ? { id: logging.lead.id, name: logging.lead.contactName, phone: logging.lead.contactPhone } : null} />}
    </>
  );
}


function MoreFilters({ anchor, onClose, filters, set }: { anchor: HTMLElement | null; onClose: () => void; filters: LeadFilters; set: (p: Record<string, string | undefined>) => void }) {
  return (
    <Popover open={Boolean(anchor)} anchorEl={anchor} onClose={onClose} anchorOrigin={{ vertical: 'bottom', horizontal: 'left' }}>
      <Stack spacing={2} sx={{ p: 2.5, width: 300 }}>
        <TextField select size="small" label="Source" value={filters.source ?? ''} onChange={(e) => set({ source: e.target.value })} slotProps={{ select: { displayEmpty: true }, inputLabel: { shrink: true } }}>
          <MenuItem value="">Any source</MenuItem>
          {Object.entries(SOURCE_LABEL).map(([k, v]) => <MenuItem key={k} value={k}>{v}</MenuItem>)}
        </TextField>
        <TextField select size="small" label="First reply" value={filters.sla ?? ''} onChange={(e) => set({ sla: e.target.value })} slotProps={{ select: { displayEmpty: true }, inputLabel: { shrink: true } }}>
          <MenuItem value="">Any</MenuItem>
          <MenuItem value="breached">Overdue</MenuItem>
          <MenuItem value="at_risk">Due soon</MenuItem>
        </TextField>
        <TextField select size="small" label="Follow-up" value={filters.followup ?? ''} onChange={(e) => set({ followup: e.target.value })} slotProps={{ select: { displayEmpty: true }, inputLabel: { shrink: true } }}>
          <MenuItem value="">Any</MenuItem>
          <MenuItem value="overdue">Overdue</MenuItem>
          <MenuItem value="today">Today</MenuItem>
        </TextField>
        <TextField size="small" label="Tag" value={filters.tag ?? ''} onChange={(e) => set({ tag: e.target.value.toLowerCase().slice(0, 40) })} />
        <TextField size="small" type="number" label="Minimum score" value={filters.scoreMin ?? ''} onChange={(e) => set({ score: e.target.value === '' ? undefined : String(Math.min(100, Math.max(0, Number(e.target.value)))) })} slotProps={{ htmlInput: { min: 0, max: 100 } }} />
        <Button onClick={() => set({ source: undefined, sla: undefined, followup: undefined, tag: undefined, score: undefined })}>Reset these</Button>
      </Stack>
    </Popover>
  );
}

function SavedViews({ filters, onApply }: { filters: LeadFilters; onApply: (v: SavedView) => void }) {
  const qc = useQueryClient();
  const toast = useToast();
  const { scope } = useAuth();
  const [anchor, setAnchor] = useState<HTMLElement | null>(null);
  const [saveOpen, setSaveOpen] = useState(false);
  const [name, setName] = useState('');
  const [shared, setShared] = useState(false);
  const views = useQuery({ queryKey: ['saved-views', 'lead'], queryFn: () => viewsApi.list('lead'), enabled: Boolean(anchor) || saveOpen, staleTime: 30_000 });
  const save = useMutation({
    mutationFn: () => {
      const { sort, order: _o, ...rest } = filters;
      void _o;
      return viewsApi.create({ entity: 'lead', name: name.trim(), filters: Object.fromEntries(Object.entries(rest).filter(([, v]) => v !== undefined && v !== '' && !(Array.isArray(v) && !v.length))), sort: sort ?? null, isShared: shared });
    },
    onSuccess: (v) => { void qc.invalidateQueries({ queryKey: ['saved-views'] }); toast.success(`Saved “${v.name}”`); setSaveOpen(false); setName(''); setShared(false); },
    onError: (e) => toast.error(e instanceof ApiProblem ? e.detail : 'Couldn’t save the view'),
  });
  const remove = useMutation({
    mutationFn: viewsApi.remove,
    onSuccess: () => void qc.invalidateQueries({ queryKey: ['saved-views'] }),
    onError: (e) => toast.error(e instanceof ApiProblem ? e.detail : 'Couldn’t delete the view'),
  });
  return (
    <>
      <Button size="small" color="inherit" variant="outlined" startIcon={<BookmarkBorderRoundedIcon />} onClick={(e) => setAnchor(e.currentTarget)}>Views</Button>
      <Menu anchorEl={anchor} open={Boolean(anchor)} onClose={() => setAnchor(null)} slotProps={{ paper: { sx: { minWidth: 260 } } }}>
        {views.isPending && <MenuItem disabled>Loading…</MenuItem>}
        {views.data?.length === 0 && <MenuItem disabled>No saved views yet</MenuItem>}
        {views.data?.map((v) => (
          <MenuItem key={v.id} onClick={() => { setAnchor(null); onApply(v); }}>
            <ListItemText secondary={v.isShared ? (v.isMine ? 'Shared with the team' : `Shared by ${v.ownerName}`) : undefined}>{v.name}</ListItemText>
            {v.isMine && (
              <Tooltip title="Delete view">
                <Box component="span" role="button" tabIndex={0} aria-label={`Delete view ${v.name}`} onClick={(e) => { e.stopPropagation(); remove.mutate(v.id); }} onKeyDown={(e) => { if (e.key === 'Enter') { e.stopPropagation(); remove.mutate(v.id); } }} sx={{ display: 'flex', p: 0.5, color: 'text.secondary', '&:hover': { color: 'error.main' } }}>
                  <DeleteOutlineRoundedIcon fontSize="small" />
                </Box>
              </Tooltip>
            )}
          </MenuItem>
        ))}
        <MenuItem divider={false} onClick={() => { setAnchor(null); setSaveOpen(true); }} sx={{ borderTop: 1, borderColor: 'divider', mt: 0.5 }}>
          <ListItemIcon><AddRoundedIcon fontSize="small" /></ListItemIcon>
          <ListItemText>Save current filters as a view…</ListItemText>
        </MenuItem>
      </Menu>
      <Dialog open={saveOpen} onClose={() => setSaveOpen(false)} fullWidth maxWidth="xs" aria-labelledby="save-view-title">
        <DialogTitle id="save-view-title">Save this view</DialogTitle>
        <DialogContent>
          <Stack spacing={1.5} sx={{ pt: 0.5 }} component="form" id="save-view-form" onSubmit={(e) => { e.preventDefault(); if (name.trim()) save.mutate(); }}>
            <TextField label="Name" autoFocus value={name} onChange={(e) => setName(e.target.value)} slotProps={{ htmlInput: { maxLength: 80 } }} helperText="e.g. “Hot leads, no reply yet”" />
            {scope('leads:bulk') === 'all' && <FormControlLabel control={<Switch checked={shared} onChange={(_, v) => setShared(v)} />} label="Share with the whole team" />}
          </Stack>
        </DialogContent>
        <DialogActions>
          <Button onClick={() => setSaveOpen(false)}>Cancel</Button>
          <Button type="submit" form="save-view-form" variant="contained" disabled={!name.trim() || save.isPending}>Save view</Button>
        </DialogActions>
      </Dialog>
    </>
  );
}

function LeadTable({ rows, owners, role, clientId, showValue, selectable, selected, setSelected, onOpen, onLog, canLog }: { rows: Lead[]; owners: Map<string, string>; role: Role | null; clientId: string; showValue: boolean; selectable: boolean; selected: Set<string>; setSelected: (s: Set<string>) => void; onOpen: (id: string) => void; onLog: (l: Lead, type: LogType) => void; canLog: boolean }) {
  const all = rows.length > 0 && rows.every((r) => selected.has(r.id));
  const some = rows.some((r) => selected.has(r.id));
  const toggle = (id: string) => { const n = new Set(selected);
    if (n.has(id)) n.delete(id);
    else n.add(id);
    setSelected(n);
  };
  return (
    <TableContainer component={Paper} variant="outlined" sx={{ borderRadius: 2.5 }}>
      <Table aria-label="Leads">
        <TableHead>
          <TableRow>
            {selectable && <TableCell padding="checkbox"><Checkbox size="small" checked={all} indeterminate={some && !all} onChange={() => setSelected(all ? new Set() : new Set(rows.map((r) => r.id)))} slotProps={{ input: { 'aria-label': 'Select all leads on screen' } }} /></TableCell>}
            <TableCell>Lead</TableCell>
            <TableCell sx={{ display: { xs: 'none', sm: 'table-cell' } }}>Stage</TableCell>
            <TableCell sx={{ display: { xs: 'none', lg: 'table-cell' } }}>Owner</TableCell>
            {showValue && <TableCell align="right" sx={{ display: { xs: 'none', md: 'table-cell' } }}>Value</TableCell>}
            <TableCell sx={{ display: { xs: 'none', md: 'table-cell' } }}>Source</TableCell>
            <TableCell sx={{ display: { xs: 'none', md: 'table-cell' } }}>Follow-up</TableCell>
            <TableCell sx={{ display: { xs: 'none', lg: 'table-cell' } }}>Last touch</TableCell>
            <TableCell align="center">Score</TableCell>
            <TableCell padding="checkbox"><span style={{ position: 'absolute', left: -9999 }}>Actions</span></TableCell>
          </TableRow>
        </TableHead>
        <TableBody>
          {rows.map((l) => (
            <TableRow key={l.id} hover selected={selected.has(l.id)} tabIndex={0} role="row" aria-label={`Open lead ${l.contactName ?? ''}`} onClick={() => onOpen(l.id)} onKeyDown={(e) => { if (e.key === 'Enter' && e.target === e.currentTarget) onOpen(l.id); }} sx={{ cursor: 'pointer', '&:last-child td': { borderBottom: 0 } }}>
              {selectable && <TableCell padding="checkbox" onClick={(e) => e.stopPropagation()}><Checkbox size="small" checked={selected.has(l.id)} onChange={() => toggle(l.id)} slotProps={{ input: { 'aria-label': `Select ${l.contactName ?? 'lead'}` } }} /></TableCell>}
              <TableCell sx={{ minWidth: { xs: 0, sm: 200 } }}>
                <Stack direction="row" spacing={1.25} sx={{ alignItems: 'center' }}>
                  <UserAvatar name={l.contactName ?? '?'} seed={l.contactId ?? l.id} size={30} />
                  <Box sx={{ minWidth: 0 }}>
                    <Stack direction="row" spacing={0.75} sx={{ alignItems: 'center' }}>
                      <Typography variant="subtitle2" noWrap>{l.contactName ?? 'Unnamed lead'}</Typography>
                      <SlaChip sla={l.sla} quiet />
                    </Stack>
                    <Typography variant="caption" color="text.secondary" noWrap component="div">{l.contactPhone ?? l.contactEmail ?? '—'}</Typography>
                    <Box sx={{ display: { xs: 'block', sm: 'none' }, mt: 0.5 }}><StageChip name={l.stageName} color={l.stageColor} /></Box>
                  </Box>
                </Stack>
              </TableCell>
              <TableCell sx={{ display: { xs: 'none', sm: 'table-cell' } }}><StageChip name={l.stageName} color={l.stageColor} /></TableCell>
              <TableCell sx={{ display: { xs: 'none', lg: 'table-cell' } }}><OwnerCell lead={{ id: l.id, clientId, ownerId: l.ownerId, ownerName: l.ownerId ? (owners.get(l.ownerId) ?? null) : null }} role={role} /></TableCell>
              {showValue && <TableCell align="right" sx={{ display: { xs: 'none', md: 'table-cell' } }}><Typography variant="numeric" component="span">{formatMoney(l.estimatedValue, l.currency)}</Typography></TableCell>}
              <TableCell sx={{ display: { xs: 'none', md: 'table-cell' } }}><SourceTag source={l.source} campaign={l.campaign} /></TableCell>
              <TableCell sx={{ display: { xs: 'none', md: 'table-cell' } }}><FollowupText at={l.nextFollowupAt} /></TableCell>
              <TableCell sx={{ display: { xs: 'none', lg: 'table-cell' } }}><When value={l.lastActivityAt} fallback="Never" /></TableCell>
              <TableCell align="center"><ScoreBadge lead={l} /></TableCell>
              <TableCell padding="checkbox" onClick={(e) => e.stopPropagation()}>
                {canLog && (
                  <Stack direction="row" sx={{ justifyContent: 'flex-end' }}>
                    <Tooltip title="Call and log it"><IconButton size="small" aria-label={`Log a call with ${l.contactName ?? 'lead'}`} onClick={() => onLog(l, 'call')}><CallOutlinedIcon fontSize="small" /></IconButton></Tooltip>
                    {l.contactPhone && <Tooltip title="WhatsApp and log it"><IconButton size="small" aria-label={`Log a WhatsApp message to ${l.contactName ?? 'lead'}`} onClick={() => onLog(l, 'whatsapp')}><WhatsAppIcon fontSize="small" /></IconButton></Tooltip>}
                  </Stack>
                )}
              </TableCell>
            </TableRow>
          ))}
        </TableBody>
      </Table>
    </TableContainer>
  );
}


function BulkBar({ ids, clear, pipelineStages }: { ids: string[]; clear: () => void; pipelineStages: Array<{ id: string; name: string; mapsToStatus: string }> }) {
  const qc = useQueryClient();
  const toast = useToast();
  const confirm = useConfirm();
  const directory = useDirectory();
  const [assignAnchor, setAssignAnchor] = useState<HTMLElement | null>(null);
  const [stageAnchor, setStageAnchor] = useState<HTMLElement | null>(null);
  const [tagOpen, setTagOpen] = useState(false);
  const [lossOpen, setLossOpen] = useState<{ stageId: string; name: string; kind: ReasonKind } | null>(null);
  const lossCodeRequired = useReasonCodeRequired(lossOpen?.stageId);
  const [tagText, setTagText] = useState('');
  const run = useMutation({
    mutationFn: (req: BulkRequest) => leadsApi.bulk(req),
    onSuccess: (r, req) => {
      void qc.invalidateQueries({ queryKey: ['leads'] });
      void qc.invalidateQueries({ queryKey: ['pipelines'] });
      const verb = { assign: 'reassigned', move_stage: 'moved', add_tags: 'tagged', remove_tags: 'untagged', archive: 'archived' }[req.action];
      if (r.skipped.length) toast.info(`${r.updated} ${verb}, ${r.skipped.length} skipped: ${r.skipped[0]!.reason}`);
      else toast.success(`${r.updated} lead${r.updated === 1 ? '' : 's'} ${verb}`);
      clear();
    },
    onError: (e) => toast.error(e instanceof ApiProblem ? e.errors[0]?.message ?? e.detail : 'That didn’t work. Please try again.'),
  });
  return (
    <Paper elevation={6} role="region" aria-label="Bulk actions" sx={{ position: 'fixed', bottom: 20, left: '50%', transform: 'translateX(-50%)', zIndex: 'appBar', px: 2, py: 1, display: 'flex', alignItems: 'center', gap: 1, borderRadius: 3, maxWidth: 'calc(100vw - 24px)', flexWrap: 'wrap' }}>
      <Typography variant="subtitle2" sx={{ mr: 1 }}>{ids.length} selected</Typography>
      <Button size="small" onClick={(e) => setAssignAnchor(e.currentTarget)}>Assign</Button>
      <Button size="small" onClick={(e) => setStageAnchor(e.currentTarget)}>Move to…</Button>
      <Button size="small" onClick={() => setTagOpen(true)}>Tag</Button>
      <Button size="small" color="error" onClick={async () => { if (await confirm({ title: `Archive ${ids.length} lead${ids.length === 1 ? '' : 's'}?`, body: 'They disappear from lists and boards. You can restore them from the lead page.', confirmLabel: 'Archive', destructive: true })) run.mutate({ ids, action: 'archive' }); }}>Archive</Button>
      <Button size="small" color="inherit" onClick={clear}>Clear</Button>

      <Menu anchorEl={assignAnchor} open={Boolean(assignAnchor)} onClose={() => setAssignAnchor(null)}>
        <MenuItem onClick={() => { setAssignAnchor(null); run.mutate({ ids, action: 'assign', ownerId: null }); }}><em>Unassign</em></MenuItem>
        {(directory.data ?? []).map((m) => <MenuItem key={m.userId} onClick={() => { setAssignAnchor(null); run.mutate({ ids, action: 'assign', ownerId: m.userId }); }}>{m.name}</MenuItem>)}
      </Menu>
      <Menu anchorEl={stageAnchor} open={Boolean(stageAnchor)} onClose={() => setStageAnchor(null)}>
        {pipelineStages.map((s) => (
          <MenuItem key={s.id} onClick={() => { setStageAnchor(null); const kind = kindOfStatus(s.mapsToStatus); if (kind) { setLossOpen({ stageId: s.id, name: s.name, kind }); } else run.mutate({ ids, action: 'move_stage', stageId: s.id }); }}>{s.name}</MenuItem>
        ))}
      </Menu>
      <Dialog open={tagOpen} onClose={() => setTagOpen(false)} fullWidth maxWidth="xs" aria-labelledby="bulk-tag-title">
        <DialogTitle id="bulk-tag-title">Tag {ids.length} lead{ids.length === 1 ? '' : 's'}</DialogTitle>
        <DialogContent><TextField autoFocus fullWidth label="Tags" helperText="Separate with commas" value={tagText} onChange={(e) => setTagText(e.target.value)} sx={{ mt: 1 }} /></DialogContent>
        <DialogActions>
          <Button onClick={() => setTagOpen(false)}>Cancel</Button>
          <Button color="inherit" disabled={!tagText.trim()} onClick={() => { run.mutate({ ids, action: 'remove_tags', tags: tagText.split(',').map((t) => t.trim()).filter(Boolean) }); setTagOpen(false); setTagText(''); }}>Remove</Button>
          <Button variant="contained" disabled={!tagText.trim()} onClick={() => { run.mutate({ ids, action: 'add_tags', tags: tagText.split(',').map((t) => t.trim()).filter(Boolean) }); setTagOpen(false); setTagText(''); }}>Add</Button>
        </DialogActions>
      </Dialog>
      <ReasonDialog
        open={Boolean(lossOpen)}
        title={`Move ${ids.length} lead${ids.length === 1 ? '' : 's'} to ${lossOpen?.name ?? ''}`}
        subject={`these ${ids.length} lead${ids.length === 1 ? '' : 's'}`}
        targetName={lossOpen?.name ?? ''}
        kind={lossOpen?.kind ?? 'lost'}
        codeRequired={lossCodeRequired}
        onCancel={() => setLossOpen(null)}
        onConfirm={(r) => { run.mutate({ ids, action: 'move_stage', stageId: lossOpen!.stageId, ...r }); setLossOpen(null); }}
      />
    </Paper>
  );
}
