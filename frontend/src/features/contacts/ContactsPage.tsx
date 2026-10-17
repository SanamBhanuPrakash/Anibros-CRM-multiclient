import { useEffect, useMemo, useState } from 'react';
import { useSearchParams } from 'react-router-dom';
import {
  Box, Button, Chip, InputAdornment, MenuItem, Paper, Stack, Table, TableBody, TableCell, TableContainer, TableHead, TableRow,
  TextField, ToggleButton, ToggleButtonGroup, Typography,
} from '@mui/material';
import SearchRoundedIcon from '@mui/icons-material/SearchRounded';
import PersonAddAltRoundedIcon from '@mui/icons-material/PersonAddAltRounded';
import ContactsOutlinedIcon from '@mui/icons-material/ContactsOutlined';
import { useInfiniteQuery, useQuery } from '@tanstack/react-query';
import { contactsApi, membersApi, type Contact, type ContactSort } from '@/api/endpoints';
import { useAuth } from '@/features/auth/AuthProvider';
import { RelativeTime, UserAvatar } from '@/ui/display';
import { PageHeader } from '@/ui/PageHeader';
import { EmptyState, ErrorState, SkeletonRows } from '@/ui/states';
import { ContactDrawer } from './ContactDrawer';
import { ContactFormDialog } from './ContactFormDialog';

const SORTS: Array<{ id: ContactSort; label: string; order: 'asc' | 'desc' }> = [
  { id: 'created', label: 'Newest first', order: 'desc' },
  { id: 'updated', label: 'Recently updated', order: 'desc' },
  { id: 'name', label: 'Name A–Z', order: 'asc' },
];

function useDebounced<T>(value: T, ms: number): T {
  const [v, setV] = useState(value);
  useEffect(() => {
    const t = setTimeout(() => setV(value), ms);
    return () => clearTimeout(t);
  }, [value, ms]);
  return v;
}

export function ContactsPage() {
  const { can, me } = useAuth();
  const [params, setParams] = useSearchParams();
  const openId = params.get('c');
  const [text, setText] = useState('');
  const [sortId, setSortId] = useState<ContactSort>('created');
  const [mineOnly, setMineOnly] = useState(false);
  const [createOpen, setCreateOpen] = useState(false);
  const q = useDebounced(text.trim(), 300);
  const sort = SORTS.find((s) => s.id === sortId)!;

  const filters = useMemo(() => ({ q: q || undefined, sort: sort.id, order: sort.order, ownerId: mineOnly ? me?.user.id : undefined }), [q, sort, mineOnly, me?.user.id]);
  const list = useInfiniteQuery({
    queryKey: ['contacts', 'list', filters],
    queryFn: ({ pageParam }) => contactsApi.list(filters, pageParam),
    initialPageParam: undefined as string | undefined,
    getNextPageParam: (last) => last.page?.next ?? undefined,
    placeholderData: (prev) => prev, // keep rows on screen while the next search is in flight
  });
  const directory = useQuery({ queryKey: ['members', 'directory'], queryFn: membersApi.directory, staleTime: 5 * 60_000 });
  const ownerName = useMemo(() => new Map((directory.data ?? []).map((m) => [m.userId, m.name])), [directory.data]);
  const rows = useMemo(() => list.data?.pages.flatMap((p) => p.data) ?? [], [list.data]);

  const open = (id: string | null) => {
    const next = new URLSearchParams(params);
    if (id) next.set('c', id);
    else next.delete('c');
    setParams(next, { replace: !id });
  };

  const filtering = Boolean(q) || mineOnly;
  return (
    <>
      <PageHeader
        title="Contacts"
        description="Everyone you’ve spoken to or might. One record per person, even when they enquire through three different channels."
        actions={can('contacts:create') && <Button variant="contained" startIcon={<PersonAddAltRoundedIcon />} onClick={() => setCreateOpen(true)}>New contact</Button>}
      />

      <Stack direction={{ xs: 'column', md: 'row' }} spacing={1.5} sx={{ mb: 2, alignItems: { md: 'center' } }}>
        <TextField
          size="small"
          fullWidth={false} /* the theme default is fullWidth, which collapses a flex item that also has flex: 1 */
          placeholder="Search name, company, email or phone"
          value={text}
          onChange={(e) => setText(e.target.value)}
          sx={{ flex: { md: '1 1 260px' }, width: { xs: '100%', md: 'auto' }, maxWidth: { md: 440 } }}
          slotProps={{ input: { startAdornment: <InputAdornment position="start"><SearchRoundedIcon fontSize="small" /></InputAdornment> }, htmlInput: { 'aria-label': 'Search contacts', maxLength: 100 } }}
        />
        <ToggleButtonGroup size="small" exclusive value={mineOnly ? 'mine' : 'all'} onChange={(_, v) => v && setMineOnly(v === 'mine')} aria-label="Whose contacts">
          <ToggleButton value="all">Everyone’s</ToggleButton>
          <ToggleButton value="mine">Mine</ToggleButton>
        </ToggleButtonGroup>
        <TextField select size="small" fullWidth={false} value={sortId} onChange={(e) => setSortId(e.target.value as ContactSort)} sx={{ minWidth: 190, width: { xs: '100%', md: 'auto' }, ml: { md: 'auto' } }} slotProps={{ htmlInput: { 'aria-label': 'Sort contacts' } }}>
          {SORTS.map((s) => <MenuItem key={s.id} value={s.id}>{s.label}</MenuItem>)}
        </TextField>
      </Stack>

      {list.isPending ? (
        <SkeletonRows rows={8} />
      ) : list.isError ? (
        <ErrorState error={list.error} onRetry={() => void list.refetch()} />
      ) : rows.length === 0 ? (
        filtering ? (
          <EmptyState icon={<SearchRoundedIcon />} title="No contacts match" description="Try a different spelling, or search by the last digits of a phone number." action={<Button onClick={() => { setText(''); setMineOnly(false); }}>Clear filters</Button>} />
        ) : (
          <EmptyState
            icon={<ContactsOutlinedIcon />}
            title="No contacts yet"
            description="Contacts are created automatically when a lead comes in. You can also add someone by hand."
            action={can('contacts:create') ? <Button variant="contained" onClick={() => setCreateOpen(true)}>Add your first contact</Button> : undefined}
          />
        )
      ) : (
        <>
          <ContactTable rows={rows} ownerName={ownerName} onOpen={open} selectedId={openId} />
          <Stack direction="row" sx={{ mt: 2, alignItems: 'center', justifyContent: 'space-between' }}>
            <Typography variant="body2" color="text.secondary" aria-live="polite">
              {rows.length} contact{rows.length === 1 ? '' : 's'}{list.hasNextPage ? ' loaded' : ''}
            </Typography>
            {list.hasNextPage && <Button onClick={() => void list.fetchNextPage()} disabled={list.isFetchingNextPage}>{list.isFetchingNextPage ? 'Loading…' : 'Load more'}</Button>}
          </Stack>
        </>
      )}

      <ContactFormDialog open={createOpen} onClose={() => setCreateOpen(false)} onSaved={(c) => { setCreateOpen(false); open(c.id); }} onOpenExisting={open} />
      <ContactDrawer contactId={openId} onClose={() => open(null)} onOpenOther={open} />
    </>
  );
}

function ContactTable({ rows, ownerName, onOpen, selectedId }: { rows: Contact[]; ownerName: Map<string, string>; onOpen: (id: string) => void; selectedId: string | null }) {
  return (
    <TableContainer component={Paper} variant="outlined" sx={{ borderRadius: 2.5 }}>
      <Table size="small" aria-label="Contacts">
        <TableHead>
          <TableRow>
            <TableCell>Name</TableCell>
            <TableCell sx={{ display: { xs: 'none', sm: 'table-cell' } }}>Email</TableCell>
            <TableCell sx={{ display: { xs: 'none', sm: 'table-cell' } }}>Phone</TableCell>
            <TableCell sx={{ display: { xs: 'none', lg: 'table-cell' } }}>Owner</TableCell>
            <TableCell align="right" sx={{ display: { xs: 'none', md: 'table-cell' } }}>Leads</TableCell>
            <TableCell sx={{ display: { xs: 'none', md: 'table-cell' } }}>Updated</TableCell>
          </TableRow>
        </TableHead>
        <TableBody>
          {rows.map((c) => (
            <TableRow
              key={c.id}
              hover
              selected={selectedId === c.id}
              tabIndex={0}
              role="row"
              aria-label={`Open ${c.displayName}`}
              onClick={() => onOpen(c.id)}
              onKeyDown={(e) => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); onOpen(c.id); } }}
              sx={{ cursor: 'pointer' }}
            >
              <TableCell>
                <Stack direction="row" spacing={1.5} sx={{ alignItems: 'center' }}>
                  <UserAvatar name={c.displayName} seed={c.id} />
                  <Box sx={{ minWidth: 0 }}>
                    <Typography variant="subtitle2" noWrap>{c.displayName}</Typography>
                    <Typography variant="caption" color="text.secondary" noWrap component="div">
                      {[c.jobTitle, c.company].filter(Boolean).join(' · ') || c.city || ' '}
                    </Typography>
                    <Typography variant="caption" color="text.secondary" noWrap component="div" sx={{ display: { xs: 'block', sm: 'none' } }}>{c.phone ?? c.email ?? ''}</Typography>
                    {c.tags.length > 0 && (
                      <Stack direction="row" spacing={0.5} sx={{ mt: 0.5, display: { sm: 'none' } }}>
                        {c.tags.slice(0, 2).map((t) => <Chip key={t} size="small" label={t} variant="outlined" />)}
                      </Stack>
                    )}
                  </Box>
                </Stack>
              </TableCell>
              <TableCell sx={{ display: { xs: 'none', sm: 'table-cell' }, maxWidth: 260 }}><Typography variant="body2" noWrap>{c.email ?? '—'}</Typography></TableCell>
              <TableCell sx={{ display: { xs: 'none', sm: 'table-cell' } }}><Typography variant="numeric" noWrap>{c.phone ?? '—'}</Typography></TableCell>
              <TableCell sx={{ display: { xs: 'none', lg: 'table-cell' }, color: 'text.secondary' }}>{c.ownerId ? (ownerName.get(c.ownerId) ?? '…') : 'Unassigned'}</TableCell>
              <TableCell align="right" sx={{ display: { xs: 'none', md: 'table-cell' } }}><Typography variant="numeric">{c.leadCount}</Typography></TableCell>
              <TableCell sx={{ display: { xs: 'none', md: 'table-cell' }, color: 'text.secondary' }}><RelativeTime value={c.updatedAt} /></TableCell>
            </TableRow>
          ))}
        </TableBody>
      </Table>
    </TableContainer>
  );
}
