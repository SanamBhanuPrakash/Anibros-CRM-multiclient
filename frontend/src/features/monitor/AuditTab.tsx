import { useMemo, useState } from 'react';
import { Box, Button, Divider, Drawer, IconButton, MenuItem, Paper, Stack, Table, TableBody, TableCell, TableContainer, TableHead, TableRow, TextField, Typography } from '@mui/material';
import CloseRoundedIcon from '@mui/icons-material/CloseRounded';
import DownloadRoundedIcon from '@mui/icons-material/DownloadRounded';
import { useInfiniteQuery, useQuery } from '@tanstack/react-query';
import { monitorApi, type AuditEntry, type AuditFilters } from '@/api/monitor';
import { CopyButton, RelativeTime } from '@/ui/display';
import { EmptyState, ErrorState, SkeletonRows } from '@/ui/states';

const pretty = (action: string) => {
  const [what, verb] = action.split('.');
  const w = (what ?? '').replace(/_/g, ' ');
  return `${w ? w[0].toUpperCase() + w.slice(1) : action}${verb ? ` · ${verb.replace(/_/g, ' ')}` : ''}`;
};
const startOfDayIso = (d: string) => new Date(`${d}T00:00:00`).toISOString();
const nextDayIso = (d: string) => new Date(new Date(`${d}T00:00:00`).getTime() + 86_400_000).toISOString();

const show = (v: unknown) => (v === null || v === undefined ? '—' : typeof v === 'string' ? v : JSON.stringify(v));

function Changes({ before, after }: { before: unknown; after: unknown }) {
  const b = (before && typeof before === 'object' ? before : {}) as Record<string, unknown>;
  const a = (after && typeof after === 'object' ? after : {}) as Record<string, unknown>;
  const keys = [...new Set([...Object.keys(b), ...Object.keys(a)])].filter((k) => JSON.stringify(b[k]) !== JSON.stringify(a[k]));
  if (!before && !after) return <Typography variant="body2" color="text.secondary">No details were recorded for this action.</Typography>;
  if (!keys.length) return <Typography variant="body2" color="text.secondary">Nothing changed in the recorded fields.</Typography>;
  return (
    <Table size="small" aria-label="What changed">
      <TableHead><TableRow><TableCell>Field</TableCell><TableCell>Before</TableCell><TableCell>After</TableCell></TableRow></TableHead>
      <TableBody>
        {keys.map((k) => (
          <TableRow key={k}>
            <TableCell sx={{ verticalAlign: 'top', whiteSpace: 'nowrap' }}>{k}</TableCell>
            <TableCell sx={{ verticalAlign: 'top', color: 'text.secondary', wordBreak: 'break-word', maxWidth: 220 }}>{show(b[k])}</TableCell>
            <TableCell sx={{ verticalAlign: 'top', wordBreak: 'break-word', maxWidth: 220 }}>{show(a[k])}</TableCell>
          </TableRow>
        ))}
      </TableBody>
    </Table>
  );
}

function Detail({ id, onClose }: { id: string | null; onClose: () => void }) {
  const q = useQuery({ queryKey: ['monitor', 'audit', id], queryFn: () => monitorApi.auditDetail(id!), enabled: Boolean(id) });
  return (
    <Drawer anchor="right" open={Boolean(id)} onClose={onClose} slotProps={{ paper: { sx: { width: { xs: '100%', sm: 480 }, p: 3 }, 'aria-label': 'Audit entry' } }}>
      <Stack direction="row" sx={{ justifyContent: 'space-between', alignItems: 'center', mb: 2 }}>
        <Typography variant="h4" component="h2">{q.data ? pretty(q.data.action) : 'Audit entry'}</Typography>
        <IconButton aria-label="Close" onClick={onClose}><CloseRoundedIcon /></IconButton>
      </Stack>
      {q.isPending ? <SkeletonRows rows={4} /> : q.isError ? <ErrorState error={q.error} onRetry={() => void q.refetch()} /> : (
        <Stack spacing={2}>
          <Box component="dl" sx={{ m: 0, display: 'grid', gridTemplateColumns: 'auto 1fr', columnGap: 2, rowGap: 0.75, '& dt': { color: 'text.secondary', typography: 'body2' }, '& dd': { m: 0, typography: 'body2', wordBreak: 'break-all' } }}>
            <dt>When</dt><dd>{new Date(q.data.at).toLocaleString()}</dd>
            <dt>Who</dt><dd>{q.data.actorName ?? 'System'}</dd>
            <dt>Record</dt><dd>{q.data.entityType} · {q.data.entityId}</dd>
            <dt>IP address</dt><dd>{q.data.ip ?? '—'}</dd>
            <dt>Request id</dt><dd>{q.data.requestId ?? '—'} {q.data.requestId && <CopyButton value={q.data.requestId} label="Copy request id" />}</dd>
          </Box>
          <Divider />
          <Typography variant="subtitle2">What changed</Typography>
          <Changes before={q.data.before} after={q.data.after} />
          <Typography variant="caption" color="text.secondary">Sensitive values are masked. This entry cannot be edited or deleted.</Typography>
        </Stack>
      )}
    </Drawer>
  );
}

/** Who did what, and when. Append-only on the server; this is a read-only explorer with CSV export. */
export function AuditTab() {
  const [action, setAction] = useState('');
  const [entityType, setEntityType] = useState('');
  const [actorId, setActorId] = useState('');
  const [from, setFrom] = useState('');
  const [to, setTo] = useState('');
  const [detail, setDetail] = useState<string | null>(null);
  const facets = useQuery({ queryKey: ['monitor', 'facets'], queryFn: monitorApi.facets, staleTime: 5 * 60_000 });
  const filters: AuditFilters = { ...(action ? { action } : {}), ...(entityType ? { entityType } : {}), ...(actorId ? { actorId } : {}), ...(from ? { from: startOfDayIso(from) } : {}), ...(to ? { to: nextDayIso(to) } : {}) };
  const list = useInfiniteQuery({
    queryKey: ['monitor', 'audit', filters],
    queryFn: ({ pageParam }) => monitorApi.audit(filters, pageParam),
    initialPageParam: undefined as string | undefined,
    getNextPageParam: (l) => l.page?.next ?? undefined,
    placeholderData: (p) => p,
  });
  const rows = useMemo<AuditEntry[]>(() => list.data?.pages.flatMap((p) => p.data) ?? [], [list.data]);
  const families = useMemo(() => [...new Set((facets.data?.actions ?? []).map((a) => a.split('.')[0]))], [facets.data]);
  const filtering = Boolean(action || entityType || actorId || from || to);

  return (
    <>
      <Stack direction="row" spacing={1.5} sx={{ mb: 2, flexWrap: 'wrap', rowGap: 1.5, alignItems: 'center' }}>
        <TextField select size="small" fullWidth={false} value={action} onChange={(e) => setAction(e.target.value)} sx={{ minWidth: 190 }} slotProps={{ select: { displayEmpty: true }, htmlInput: { 'aria-label': 'Action' } }}>
          <MenuItem value="">Every action</MenuItem>
          {families.map((f) => <MenuItem key={f} value={f}>All “{f.replace(/_/g, ' ')}” actions</MenuItem>)}
          {(facets.data?.actions ?? []).map((a) => <MenuItem key={a} value={a}>{pretty(a)}</MenuItem>)}
        </TextField>
        <TextField select size="small" fullWidth={false} value={entityType} onChange={(e) => setEntityType(e.target.value)} sx={{ minWidth: 170 }} slotProps={{ select: { displayEmpty: true }, htmlInput: { 'aria-label': 'Record type' } }}>
          <MenuItem value="">Every record type</MenuItem>
          {(facets.data?.entityTypes ?? []).map((t) => <MenuItem key={t} value={t}>{t.replace(/_/g, ' ')}</MenuItem>)}
        </TextField>
        <TextField select size="small" fullWidth={false} value={actorId} onChange={(e) => setActorId(e.target.value)} sx={{ minWidth: 170 }} slotProps={{ select: { displayEmpty: true }, htmlInput: { 'aria-label': 'Person' } }}>
          <MenuItem value="">Everyone</MenuItem>
          {(facets.data?.people ?? []).map((p) => <MenuItem key={p.id} value={p.id}>{p.name}</MenuItem>)}
        </TextField>
        <TextField size="small" fullWidth={false} type="date" label="From" value={from} onChange={(e) => setFrom(e.target.value)} slotProps={{ inputLabel: { shrink: true } }} />
        <TextField size="small" fullWidth={false} type="date" label="To" value={to} onChange={(e) => setTo(e.target.value)} slotProps={{ inputLabel: { shrink: true } }} />
        {filtering && <Button size="small" color="inherit" onClick={() => { setAction(''); setEntityType(''); setActorId(''); setFrom(''); setTo(''); }}>Clear</Button>}
        <Box sx={{ flex: 1 }} />
        <Button component="a" href={monitorApi.exportUrl(filters)} startIcon={<DownloadRoundedIcon />}>Export CSV</Button>
      </Stack>

      {list.isPending ? <SkeletonRows rows={6} /> : list.isError ? <ErrorState error={list.error} onRetry={() => void list.refetch()} /> : rows.length === 0 ? (
        <EmptyState title="No entries" description={filtering ? 'Nothing matches these filters.' : 'Changes to leads, contacts, properties, sources and settings are recorded here.'} />
      ) : (
        <>
          <TableContainer component={Paper} variant="outlined" sx={{ borderRadius: 2.5 }}>
            <Table size="small" aria-label="Audit trail">
              <TableHead>
                <TableRow><TableCell>When</TableCell><TableCell>Who</TableCell><TableCell>Action</TableCell><TableCell sx={{ display: { xs: 'none', md: 'table-cell' } }}>Record</TableCell><TableCell sx={{ display: { xs: 'none', lg: 'table-cell' } }}>IP address</TableCell></TableRow>
              </TableHead>
              <TableBody>
                {rows.map((a) => (
                  <TableRow key={a.id} hover tabIndex={0} aria-label={`${pretty(a.action)} details`} onClick={() => setDetail(a.id)} onKeyDown={(e) => { if (e.key === 'Enter') setDetail(a.id); }} sx={{ cursor: 'pointer' }}>
                    <TableCell sx={{ whiteSpace: 'nowrap' }}><RelativeTime value={a.at} /></TableCell>
                    <TableCell>{a.actorName ?? <Typography component="span" variant="body2" color="text.secondary">System</Typography>}</TableCell>
                    <TableCell>{pretty(a.action)}</TableCell>
                    <TableCell sx={{ display: { xs: 'none', md: 'table-cell' }, color: 'text.secondary' }}>{a.entityType.replace(/_/g, ' ')} · {a.entityId.slice(0, 8)}</TableCell>
                    <TableCell sx={{ display: { xs: 'none', lg: 'table-cell' }, color: 'text.secondary', fontFamily: 'ui-monospace, Menlo, monospace', fontSize: 12.5 }}>{a.ip ?? '—'}</TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          </TableContainer>
          {list.hasNextPage && <Box sx={{ mt: 2, textAlign: 'center' }}><Button onClick={() => void list.fetchNextPage()} disabled={list.isFetchingNextPage}>{list.isFetchingNextPage ? 'Loading…' : 'Load more'}</Button></Box>}
        </>
      )}
      <Detail id={detail} onClose={() => setDetail(null)} />
    </>
  );
}
