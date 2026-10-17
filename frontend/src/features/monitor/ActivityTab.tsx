import { Fragment, useEffect, useMemo, useState } from 'react';
import { Box, Button, Chip, Collapse, IconButton, InputAdornment, MenuItem, Paper, Stack, Table, TableBody, TableCell, TableContainer, TableHead, TableRow, TextField, Typography } from '@mui/material';
import SearchRoundedIcon from '@mui/icons-material/SearchRounded';
import KeyboardArrowDownRoundedIcon from '@mui/icons-material/KeyboardArrowDownRounded';
import { useInfiniteQuery } from '@tanstack/react-query';
import { monitorApi, type LogEvent, type LogFilters } from '@/api/monitor';
import { CopyButton, RelativeTime } from '@/ui/display';
import { EmptyState, ErrorState, SkeletonRows } from '@/ui/states';

const COMPONENT_LABEL: Record<string, string> = { api: 'Application', security: 'Security', worker: 'Background jobs', inbound: 'Lead intake', system: 'System' };

function useDebounced<T>(v: T, ms: number): T {
  const [x, setX] = useState(v);
  useEffect(() => { const t = setTimeout(() => setX(v), ms); return () => clearTimeout(t); }, [v, ms]);
  return x;
}

export function SeverityChip({ s }: { s: LogEvent['severity'] }) {
  return <Chip size="small" variant="outlined" label={s === 'warn' ? 'Warning' : s === 'info' ? 'Info' : 'Error'} color={s === 'warn' ? 'warning' : s === 'info' ? 'default' : 'error'} />;
}

/** Notable events (errors, denials, slow requests, failed jobs, rejected deliveries). Newest first; expand a row for the details. */
export function ActivityTab() {
  const [severity, setSeverity] = useState<LogFilters['severity'] | ''>('');
  const [component, setComponent] = useState('');
  const [text, setText] = useState('');
  const q = useDebounced(text.trim(), 300);
  const [open, setOpen] = useState<string | null>(null);
  const filters: LogFilters = { ...(severity ? { severity } : {}), ...(component ? { component } : {}), ...(q ? { q } : {}) };
  const list = useInfiniteQuery({
    queryKey: ['monitor', 'events', filters],
    queryFn: ({ pageParam }) => monitorApi.events(filters, pageParam),
    initialPageParam: undefined as string | undefined,
    getNextPageParam: (l) => l.page?.next ?? undefined,
    placeholderData: (p) => p,
    refetchInterval: 30_000,
  });
  const rows = useMemo(() => list.data?.pages.flatMap((p) => p.data) ?? [], [list.data]);

  return (
    <>
      <Stack direction="row" spacing={1.5} sx={{ mb: 2, flexWrap: 'wrap', rowGap: 1.5 }}>
        <TextField size="small" fullWidth={false} placeholder="Search messages" value={text} onChange={(e) => setText(e.target.value)} sx={{ flex: '1 1 240px', maxWidth: 380 }}
          slotProps={{ input: { startAdornment: <InputAdornment position="start"><SearchRoundedIcon fontSize="small" /></InputAdornment> }, htmlInput: { 'aria-label': 'Search the activity log', maxLength: 100 } }} />
        <TextField select size="small" fullWidth={false} value={severity} onChange={(e) => setSeverity(e.target.value as LogFilters['severity'] | '')} sx={{ minWidth: 190 }} slotProps={{ select: { displayEmpty: true }, htmlInput: { 'aria-label': 'Severity' } }}>
          <MenuItem value="">Warnings and errors</MenuItem>
          <MenuItem value="error">Errors only</MenuItem>
        </TextField>
        <TextField select size="small" fullWidth={false} value={component} onChange={(e) => setComponent(e.target.value)} sx={{ minWidth: 190 }} slotProps={{ select: { displayEmpty: true }, htmlInput: { 'aria-label': 'Area' } }}>
          <MenuItem value="">Every area</MenuItem>
          {Object.entries(COMPONENT_LABEL).map(([k, v]) => <MenuItem key={k} value={k}>{v}</MenuItem>)}
        </TextField>
      </Stack>
      {list.isPending ? <SkeletonRows rows={6} /> : list.isError ? <ErrorState error={list.error} onRetry={() => void list.refetch()} /> : rows.length === 0 ? (
        <EmptyState title="Nothing to show" description={filters.q || filters.component || filters.severity ? 'No events match these filters.' : 'No warnings or errors have been recorded for this workspace in the last 30 days.'} />
      ) : (
        <>
          <TableContainer component={Paper} variant="outlined" sx={{ borderRadius: 2.5 }}>
            <Table size="small" aria-label="Activity log">
              <TableHead>
                <TableRow><TableCell sx={{ width: 40 }} /><TableCell>When</TableCell><TableCell>Level</TableCell><TableCell>Area</TableCell><TableCell>What happened</TableCell></TableRow>
              </TableHead>
              <TableBody>
                {rows.map((e) => (
                  <Fragment key={e.id}>
                    <TableRow hover onClick={() => setOpen(open === e.id ? null : e.id)} sx={{ cursor: 'pointer', '& > td': { borderBottom: open === e.id ? 0 : undefined } }}>
                      <TableCell padding="checkbox"><IconButton size="small" aria-label={open === e.id ? 'Hide details' : 'Show details'} aria-expanded={open === e.id}><KeyboardArrowDownRoundedIcon sx={{ transform: open === e.id ? 'rotate(180deg)' : 'none', transition: 'transform .2s' }} /></IconButton></TableCell>
                      <TableCell sx={{ whiteSpace: 'nowrap' }}><RelativeTime value={e.at} /></TableCell>
                      <TableCell><SeverityChip s={e.severity} /></TableCell>
                      <TableCell>{COMPONENT_LABEL[e.component] ?? e.component}</TableCell>
                      <TableCell sx={{ maxWidth: 520 }}>{e.message}{e.userName && <Typography variant="caption" color="text.secondary" component="div">{e.userName}</Typography>}</TableCell>
                    </TableRow>
                    <TableRow>
                      <TableCell colSpan={5} sx={{ py: 0, borderBottom: open === e.id ? undefined : 0 }}>
                        <Collapse in={open === e.id} unmountOnExit>
                          <Box sx={{ py: 1.5 }}>
                            {e.requestId && (
                              <Stack direction="row" sx={{ alignItems: 'center', gap: 0.5, mb: 1 }}>
                                <Typography variant="caption" color="text.secondary">Request id</Typography>
                                <Typography variant="caption" sx={{ fontFamily: 'ui-monospace, Menlo, monospace' }}>{e.requestId}</Typography>
                                <CopyButton value={e.requestId} label="Copy request id" />
                                <Typography variant="caption" color="text.secondary">— quote this to support to trace the request.</Typography>
                              </Stack>
                            )}
                            <Box component="pre" tabIndex={0} aria-label="Event details" sx={{ m: 0, p: 1.5, borderRadius: 2, bgcolor: 'surface.sunken', overflowX: 'auto', fontSize: 12.5, fontFamily: 'ui-monospace, Menlo, monospace' }}>{JSON.stringify(e.context, null, 2)}</Box>
                          </Box>
                        </Collapse>
                      </TableCell>
                    </TableRow>
                  </Fragment>
                ))}
              </TableBody>
            </Table>
          </TableContainer>
          <Typography variant="caption" color="text.secondary" sx={{ display: 'block', mt: 1 }}>Kept for 30 days. Passwords, tokens and secrets are removed before anything is stored.</Typography>
          {list.hasNextPage && <Box sx={{ mt: 2, textAlign: 'center' }}><Button onClick={() => void list.fetchNextPage()} disabled={list.isFetchingNextPage}>{list.isFetchingNextPage ? 'Loading…' : 'Load more'}</Button></Box>}
        </>
      )}
    </>
  );
}
