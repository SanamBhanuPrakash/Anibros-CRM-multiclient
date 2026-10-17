import { useEffect, useMemo, useState } from 'react';
import { Link as RouterLink, useNavigate, useSearchParams } from 'react-router-dom';
import { Box, Button, Card, CardActionArea, CardContent, Chip, InputAdornment, MenuItem, Paper, Stack, Table, TableBody, TableCell, TableContainer, TableHead, TableRow, TextField, ToggleButton, ToggleButtonGroup, Typography } from '@mui/material';
import AddRoundedIcon from '@mui/icons-material/AddRounded';
import SearchRoundedIcon from '@mui/icons-material/SearchRounded';
import GridViewRoundedIcon from '@mui/icons-material/GridViewRounded';
import ViewListOutlinedIcon from '@mui/icons-material/ViewListOutlined';
import ApartmentOutlinedIcon from '@mui/icons-material/ApartmentOutlined';
import { useInfiniteQuery, useQuery } from '@tanstack/react-query';
import { propertiesApi, type Property, type PropertyFilters, type PropertyStatus, type PropertyType } from '@/api/inventory';
import { useAuth } from '@/features/auth/AuthProvider';
import { formatMoney } from '@/features/leads/shared';
import { PageHeader } from '@/ui/PageHeader';
import { EmptyState, ErrorState, SkeletonRows } from '@/ui/states';
import { PropertyDialog } from './PropertyDialog';
import { PropertyImage, STATUS_LABEL, StatusChip, TYPE_LABEL, specsLine, useProjectOptions } from './shared';

const SORTS = [
  { id: 'created', label: 'Newest first', order: 'desc' },
  { id: 'price', label: 'Price: low to high', order: 'asc' },
  { id: 'price_desc', label: 'Price: high to low', order: 'desc' },
  { id: 'area', label: 'Largest first', order: 'desc' },
  { id: 'updated', label: 'Recently updated', order: 'desc' },
] as const;
const STATUS_FILTERS: PropertyStatus[] = ['available', 'under_offer', 'reserved', 'sold', 'rented', 'inactive'];

function useDebounced<T>(value: T, ms: number): T {
  const [v, setV] = useState(value);
  useEffect(() => { const t = setTimeout(() => setV(value), ms); return () => clearTimeout(t); }, [value, ms]);
  return v;
}

export function PropertiesPage() {
  const nav = useNavigate();
  const { can } = useAuth();
  const [params, setParams] = useSearchParams();
  const get = (k: string) => params.get(k) ?? undefined;
  const set = (patch: Record<string, string | undefined>) =>
    setParams((p) => {
      const n = new URLSearchParams(p);
      for (const [k, v] of Object.entries(patch)) {
        if (v === undefined || v === '') n.delete(k);
        else n.set(k, v);
      }
      return n;
    }, { replace: true });
  const view = get('view') === 'table' ? 'table' : 'grid';
  const statuses = (get('status') ?? '').split(',').filter(Boolean) as PropertyStatus[];
  const sortId = (get('sort') as (typeof SORTS)[number]['id']) ?? 'created';
  const sort = SORTS.find((s) => s.id === sortId) ?? SORTS[0];
  const [text, setText] = useState(get('q') ?? '');
  const q = useDebounced(text.trim(), 300);
  useEffect(() => { if ((get('q') ?? '') !== q) set({ q: q || undefined }); }, [q]); // eslint-disable-line react-hooks/exhaustive-deps
  const [createOpen, setCreateOpen] = useState(false);
  const projects = useProjectOptions();
  const summary = useQuery({ queryKey: ['properties', 'summary'], queryFn: propertiesApi.summary });

  const filters: PropertyFilters = useMemo(
    () => ({ q: get('q'), projectId: get('project'), status: statuses.join(',') || undefined, propertyType: get('type') as PropertyType | undefined, listingType: get('listing') as PropertyFilters['listingType'], bedroomsMin: get('beds') ? Number(get('beds')) : undefined, sort: sort.id === 'price_desc' ? 'price' : (sort.id as PropertyFilters['sort']), order: sort.order }),
    [params] // eslint-disable-line react-hooks/exhaustive-deps
  );
  const list = useInfiniteQuery({
    queryKey: ['properties', 'list', filters],
    queryFn: ({ pageParam }) => propertiesApi.list(filters, pageParam),
    initialPageParam: undefined as string | undefined,
    getNextPageParam: (l) => l.page?.next ?? undefined,
    placeholderData: (p) => p,
  });
  const rows = useMemo(() => list.data?.pages.flatMap((p) => p.data) ?? [], [list.data]);
  const filtering = Boolean(filters.q || filters.projectId || statuses.length || filters.propertyType || filters.listingType || filters.bedroomsMin);
  const s = summary.data;

  return (
    <>
      <PageHeader
        title="Properties"
        description="Every unit you can sell or rent, with its availability, price history and pictures."
        actions={can('properties:create') && <Button variant="contained" startIcon={<AddRoundedIcon />} onClick={() => setCreateOpen(true)}>New property</Button>}
      />
      {s && (
        <Stack direction="row" spacing={4} sx={{ mb: 2.5, flexWrap: 'wrap', rowGap: 1.5 }} aria-label="Inventory summary">
          {[['Available now', s.available.count.toLocaleString()], ['Available value', formatMoney(s.available.value)], ['Avg price / sq ft', s.available.avgPricePerSqft ? `₹${Math.round(s.available.avgPricePerSqft).toLocaleString('en-IN')}` : '—'], ['Total listed', s.total.toLocaleString()]].map(([label, value]) => (
            <Box key={label}><Typography variant="caption" color="text.secondary">{label}</Typography><Typography variant="h4" component="p" sx={{ fontVariantNumeric: 'tabular-nums' }}>{value}</Typography></Box>
          ))}
        </Stack>
      )}

      <Stack spacing={1.5} sx={{ mb: 2.5 }}>
        <Stack direction="row" spacing={1.5} sx={{ alignItems: 'center', flexWrap: 'wrap', rowGap: 1.5 }}>
          <TextField size="small" fullWidth={false} placeholder="Search title, locality, unit or project" value={text} onChange={(e) => setText(e.target.value)} sx={{ flex: '1 1 280px', maxWidth: 480 }} slotProps={{ input: { startAdornment: <InputAdornment position="start"><SearchRoundedIcon fontSize="small" /></InputAdornment> }, htmlInput: { 'aria-label': 'Search properties', maxLength: 100 } }} />
          <Box sx={{ flex: 1, display: { xs: 'none', md: 'block' } }} />
          <TextField select size="small" fullWidth={false} value={sort.id} onChange={(e) => set({ sort: e.target.value === 'created' ? undefined : e.target.value })} sx={{ minWidth: 190 }} slotProps={{ htmlInput: { 'aria-label': 'Sort properties' } }}>
            {SORTS.map((x) => <MenuItem key={x.id} value={x.id}>{x.label}</MenuItem>)}
          </TextField>
          <ToggleButtonGroup size="small" exclusive value={view} onChange={(_, v) => v && set({ view: v === 'grid' ? undefined : v })} aria-label="Layout">
            <ToggleButton value="grid" aria-label="Grid"><GridViewRoundedIcon fontSize="small" /></ToggleButton>
            <ToggleButton value="table" aria-label="Table"><ViewListOutlinedIcon fontSize="small" /></ToggleButton>
          </ToggleButtonGroup>
        </Stack>
        <Stack direction="row" spacing={1} sx={{ flexWrap: 'wrap', rowGap: 1, alignItems: 'center' }}>
          {STATUS_FILTERS.map((st) => {
            const on = statuses.includes(st);
            const count = s?.byStatus[st];
            return (
              <Chip key={st} label={`${STATUS_LABEL[st]}${count !== undefined ? ` · ${count}` : ''}`} clickable color={on ? 'primary' : 'default'} variant={on ? 'filled' : 'outlined'} onClick={() => set({ status: (on ? statuses.filter((x) => x !== st) : [...statuses, st]).join(',') || undefined })} aria-pressed={on} />
            );
          })}
          <TextField select size="small" fullWidth={false} value={get('project') ?? ''} onChange={(e) => set({ project: e.target.value })} sx={{ minWidth: 170 }} slotProps={{ select: { displayEmpty: true }, htmlInput: { 'aria-label': 'Project' } }}>
            <MenuItem value="">All projects</MenuItem>
            {(projects.data ?? []).map((p) => <MenuItem key={p.id} value={p.id}>{p.name}</MenuItem>)}
          </TextField>
          <TextField select size="small" fullWidth={false} value={get('type') ?? ''} onChange={(e) => set({ type: e.target.value })} sx={{ minWidth: 140 }} slotProps={{ select: { displayEmpty: true }, htmlInput: { 'aria-label': 'Property type' } }}>
            <MenuItem value="">Any type</MenuItem>
            {(Object.keys(TYPE_LABEL) as PropertyType[]).map((t) => <MenuItem key={t} value={t}>{TYPE_LABEL[t]}</MenuItem>)}
          </TextField>
          <TextField select size="small" fullWidth={false} value={get('listing') ?? ''} onChange={(e) => set({ listing: e.target.value })} sx={{ minWidth: 130 }} slotProps={{ select: { displayEmpty: true }, htmlInput: { 'aria-label': 'Listing type' } }}>
            <MenuItem value="">Sale & rent</MenuItem><MenuItem value="sale">For sale</MenuItem><MenuItem value="rent">For rent</MenuItem><MenuItem value="lease">For lease</MenuItem>
          </TextField>
          <TextField select size="small" fullWidth={false} value={get('beds') ?? ''} onChange={(e) => set({ beds: e.target.value })} sx={{ minWidth: 120 }} slotProps={{ select: { displayEmpty: true }, htmlInput: { 'aria-label': 'Minimum bedrooms' } }}>
            <MenuItem value="">Any beds</MenuItem>{[1, 2, 3, 4, 5].map((n) => <MenuItem key={n} value={String(n)}>{n}+ beds</MenuItem>)}
          </TextField>
          {filtering && <Button size="small" color="inherit" onClick={() => { setText(''); setParams(new URLSearchParams(view === 'table' ? { view: 'table' } : {}), { replace: true }); }}>Clear all</Button>}
        </Stack>
      </Stack>

      {list.isPending ? <SkeletonRows rows={6} height={90} /> : list.isError ? <ErrorState error={list.error} onRetry={() => void list.refetch()} /> : rows.length === 0 ? (
        filtering ? <EmptyState icon={<SearchRoundedIcon />} title="No properties match" description="Try fewer filters or a different word." /> : (
          <EmptyState icon={<ApartmentOutlinedIcon />} title="No properties yet" description="Add the units you sell or rent. Group them into projects, attach pictures and brochures, and the matching engine can start recommending them." action={can('properties:create') ? <Button variant="contained" onClick={() => setCreateOpen(true)}>Add your first property</Button> : undefined} />
        )
      ) : (
        <>
          {view === 'grid' ? (
            <Box sx={{ display: 'grid', gap: 2, gridTemplateColumns: 'repeat(auto-fill, minmax(260px, 1fr))' }}>
              {rows.map((p) => <PropertyCard key={p.id} p={p} onOpen={() => nav(`/properties/${p.id}`)} />)}
            </Box>
          ) : (
            <PropertyTable rows={rows} onOpen={(id) => nav(`/properties/${id}`)} />
          )}
          <Stack direction="row" sx={{ mt: 2, alignItems: 'center', justifyContent: 'space-between' }}>
            <Typography variant="body2" color="text.secondary" aria-live="polite">{rows.length} propert{rows.length === 1 ? 'y' : 'ies'}{list.hasNextPage ? ' loaded' : ''}</Typography>
            {list.hasNextPage && <Button onClick={() => void list.fetchNextPage()} disabled={list.isFetchingNextPage}>{list.isFetchingNextPage ? 'Loading…' : 'Load more'}</Button>}
          </Stack>
        </>
      )}
      <PropertyDialog open={createOpen} defaultProjectId={get('project')} onClose={() => setCreateOpen(false)} onSaved={(p) => { setCreateOpen(false); nav(`/properties/${p.id}`); }} />
    </>
  );
}

function PropertyCard({ p, onOpen }: { p: Property; onOpen: () => void }) {
  return (
    <Card variant="outlined" sx={{ borderRadius: 3, overflow: 'hidden', opacity: p.status === 'inactive' ? 0.7 : 1 }}>
      <CardActionArea onClick={onOpen} aria-label={`Open ${p.title}`}>
        <Box sx={{ position: 'relative' }}>
          <PropertyImage fileId={p.coverFileId} alt="" radius={0} />
          <Box sx={{ position: 'absolute', top: 10, left: 10 }}><StatusChip status={p.status} /></Box>
          {p.imageCount > 1 && <Chip size="small" label={`${p.imageCount} photos`} sx={{ position: 'absolute', bottom: 10, right: 10, bgcolor: 'rgba(0,0,0,0.6)', color: '#fff' }} />}
        </Box>
        <CardContent sx={{ pb: '16px !important' }}>
          <Typography variant="h4" component="p" sx={{ fontVariantNumeric: 'tabular-nums' }}>{formatMoney(p.price, p.currency)}{p.listingType !== 'sale' ? <Typography component="span" variant="body2" color="text.secondary"> / {p.listingType === 'rent' ? 'month' : 'lease'}</Typography> : null}</Typography>
          <Typography variant="subtitle2" noWrap sx={{ mt: 0.25 }}>{p.title}</Typography>
          <Typography variant="body2" color="text.secondary" noWrap>{specsLine(p)}</Typography>
          <Typography variant="caption" color="text.secondary" noWrap component="div" sx={{ mt: 0.5 }}>{[p.projectName, p.location].filter(Boolean).join(' · ') || ' '}</Typography>
        </CardContent>
      </CardActionArea>
    </Card>
  );
}

function PropertyTable({ rows, onOpen }: { rows: Property[]; onOpen: (id: string) => void }) {
  return (
    <TableContainer component={Paper} variant="outlined" sx={{ borderRadius: 2.5 }}>
      <Table size="small" aria-label="Properties">
        <TableHead>
          <TableRow>
            <TableCell>Property</TableCell><TableCell>Status</TableCell><TableCell align="right">Price</TableCell>
            <TableCell sx={{ display: { xs: 'none', md: 'table-cell' } }}>Size</TableCell>
            <TableCell sx={{ display: { xs: 'none', lg: 'table-cell' } }}>Project</TableCell>
            <TableCell sx={{ display: { xs: 'none', lg: 'table-cell' } }}>Location</TableCell>
          </TableRow>
        </TableHead>
        <TableBody>
          {rows.map((p) => (
            <TableRow key={p.id} hover tabIndex={0} role="row" aria-label={`Open ${p.title}`} onClick={() => onOpen(p.id)} onKeyDown={(e) => { if (e.key === 'Enter') onOpen(p.id); }} sx={{ cursor: 'pointer' }}>
              <TableCell>
                <Stack direction="row" spacing={1.5} sx={{ alignItems: 'center' }}>
                  <Box sx={{ width: 56, flexShrink: 0 }}><PropertyImage fileId={p.coverFileId} alt="" radius={1} /></Box>
                  <Box sx={{ minWidth: 0 }}><Typography variant="subtitle2" noWrap>{p.title}</Typography><Typography variant="caption" color="text.secondary">{TYPE_LABEL[p.propertyType]}{p.unitNumber ? ` · ${p.tower ? `${p.tower}-` : ''}${p.unitNumber}` : ''}</Typography></Box>
                </Stack>
              </TableCell>
              <TableCell><StatusChip status={p.status} /></TableCell>
              <TableCell align="right"><Typography variant="numeric">{formatMoney(p.price, p.currency)}</Typography></TableCell>
              <TableCell sx={{ display: { xs: 'none', md: 'table-cell' }, color: 'text.secondary' }}>{specsLine(p)}</TableCell>
              <TableCell sx={{ display: { xs: 'none', lg: 'table-cell' } }}>{p.projectId ? <Typography component={RouterLink} to={`/projects`} variant="body2" onClick={(e) => e.stopPropagation()} sx={{ color: 'primary.main', textDecoration: 'none' }}>{p.projectName}</Typography> : '—'}</TableCell>
              <TableCell sx={{ display: { xs: 'none', lg: 'table-cell' }, color: 'text.secondary' }}>{p.location ?? '—'}</TableCell>
            </TableRow>
          ))}
        </TableBody>
      </Table>
    </TableContainer>
  );
}
