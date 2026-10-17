import { Link as RouterLink } from 'react-router-dom';
import { Box, Button, Divider, Drawer, IconButton, Stack, Typography } from '@mui/material';
import CloseRoundedIcon from '@mui/icons-material/CloseRounded';
import { format } from 'date-fns';
import { useInfiniteQuery } from '@tanstack/react-query';
import type { DrillRow } from '@/api/analytics';
import { formatMoney } from '@/features/leads/shared';
import { EmptyState, ErrorState, SkeletonRows } from '@/ui/states';

export interface DrillSpec {
  /** Names the figure that was clicked, e.g. "Qualified leads · Meta ads". */
  title: string;
  subtitle?: string;
  /** Stable identity of the query, so reopening the same figure reuses what was loaded. */
  key: unknown[];
  load: (cursor?: string) => Promise<{ rows: DrillRow[]; next: string | null; total: number }>;
}

/**
 * The records behind one number. The list is produced by the same query as the figure, so what is counted is what is listed; the header
 * says how many there are. Opening a lead goes to the lead itself.
 */
export function LeadsDrawer({ spec, currency, onClose }: { spec: DrillSpec | null; currency: string; onClose: () => void }) {
  const q = useInfiniteQuery({
    queryKey: ['analytics', 'drill', ...(spec?.key ?? [])],
    queryFn: ({ pageParam }) => spec!.load(pageParam),
    initialPageParam: undefined as string | undefined,
    getNextPageParam: (l) => l.next ?? undefined,
    enabled: Boolean(spec),
  });
  const rows = q.data?.pages.flatMap((p) => p.rows) ?? [];
  const total = q.data?.pages[0]?.total ?? 0;
  return (
    <Drawer anchor="right" open={Boolean(spec)} onClose={onClose} slotProps={{ paper: { sx: { width: { xs: '100%', sm: 460 }, maxWidth: '100%' }, 'aria-label': spec?.title } as object }}>
      <Stack direction="row" sx={{ p: 2, alignItems: 'flex-start', justifyContent: 'space-between', gap: 1 }}>
        <Box sx={{ minWidth: 0 }}>
          <Typography variant="h4" component="h2">{spec?.title}</Typography>
          <Typography variant="body2" color="text.secondary">{q.isSuccess ? `${total.toLocaleString()} ${total === 1 ? 'lead' : 'leads'}${spec?.subtitle ? ` · ${spec.subtitle}` : ''}` : spec?.subtitle ?? 'Loading…'}</Typography>
        </Box>
        <IconButton aria-label="Close the list" onClick={onClose} edge="end"><CloseRoundedIcon /></IconButton>
      </Stack>
      <Divider />
      <Box sx={{ p: 2, overflow: 'auto', flex: 1 }}>
        {q.isPending ? <SkeletonRows rows={5} height={56} /> : q.isError ? <ErrorState error={q.error} onRetry={() => void q.refetch()} /> : rows.length === 0 ? (
          <EmptyState title="No leads" description="No lead is counted in this figure for the chosen days." />
        ) : (
          <Stack component="ul" spacing={0} sx={{ listStyle: 'none', m: 0, p: 0 }}>
            {rows.map((r) => (
              <Box component="li" key={r.id} sx={{ borderBottom: 1, borderColor: 'divider', '&:last-child': { borderBottom: 0 } }}>
                <Box component={RouterLink} to={`/leads/${r.id}`} onClick={onClose} sx={{ display: 'flex', gap: 1.5, py: 1.25, px: 0.5, color: 'inherit', textDecoration: 'none', '&:hover, &:focus-visible': { bgcolor: 'surface.hover' } }}>
                  <Box sx={{ minWidth: 0, flex: 1 }}>
                    <Typography variant="body2" sx={{ fontWeight: 600 }}>{r.name}</Typography>
                    <Typography variant="caption" color="text.secondary" component="div">
                      {[r.stageName, r.ownerName ?? 'No owner', format(new Date(r.createdAt), 'd MMM yyyy'), r.sourceLabel + (r.campaign ? ` · ${r.campaign}` : '')].join(' · ')}
                    </Typography>
                  </Box>
                  {r.revenue > 0 && <Typography variant="body2" sx={{ fontWeight: 600, color: 'success.main', whiteSpace: 'nowrap' }}>{formatMoney(r.revenue, currency)}</Typography>}
                </Box>
              </Box>
            ))}
          </Stack>
        )}
        {q.hasNextPage && <Button onClick={() => void q.fetchNextPage()} disabled={q.isFetchingNextPage} sx={{ mt: 1.5 }}>{q.isFetchingNextPage ? 'Loading…' : `Show more (${rows.length} of ${total})`}</Button>}
      </Box>
    </Drawer>
  );
}

/** A count that opens the records behind it. Zero is plain text: there is nothing to open. */
export function DrillCount({ value, label, onOpen }: { value: number; label: string; onOpen: () => void }) {
  if (value === 0) return <Typography component="span" variant="body2" sx={{ fontVariantNumeric: 'tabular-nums' }}>0</Typography>;
  return (
    <Box component="button" type="button" onClick={onOpen} aria-label={label} sx={{ all: 'unset', cursor: 'pointer', fontVariantNumeric: 'tabular-nums', fontSize: '0.8125rem', fontWeight: 600, color: 'primary.main', textDecoration: 'underline', textUnderlineOffset: 3, borderRadius: 0.5, '&:hover': { color: 'primary.dark' }, '&:focus-visible': { outline: '2px solid', outlineColor: 'primary.main', outlineOffset: 2 } }}>
      {value.toLocaleString()}
    </Box>
  );
}
