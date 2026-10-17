import { useMemo, useRef, useState } from 'react';
import { Box, Divider, IconButton, InputBase, ListItemText, Menu, MenuItem, Tooltip, Typography } from '@mui/material';
import AddRoundedIcon from '@mui/icons-material/AddRounded';
import CloseRoundedIcon from '@mui/icons-material/CloseRounded';
import CheckRoundedIcon from '@mui/icons-material/CheckRounded';
import GridViewRoundedIcon from '@mui/icons-material/GridViewRounded';
import SearchRoundedIcon from '@mui/icons-material/SearchRounded';
import { useLocation, useNavigate } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import { agencyApi } from '@/api/agency';
import { useAuth } from '@/features/auth/AuthProvider';
import { clientColor } from '@/features/agency/shared';
import { closeClient, getScope, openClient, showAgency, useScopeState } from './scope';

const AGENCY_KEY = '__agency__';
const AGENCY_HOME = '/agency';
const CLIENT_HOME = '/overview';

/**
 * Browser-style tabs in the top bar: "All clients" plus one tab per client you have open. Each client remembers the page you were on
 * and keeps its own data, so you can hop between clients without losing your place.
 */
export function ClientTabs() {
  const { me, isAgencyMember, isAgencyAdmin, clients, hq } = useAuth();
  const { scope, tabs } = useScopeState();
  const navigate = useNavigate();
  const location = useLocation();
  const lastPlace = useRef(new Map<string, string>());
  const [anchor, setAnchor] = useState<HTMLElement | null>(null);
  const [needle, setNeedle] = useState('');

  // Notifications live inside each client: a badge on a client's tab says something is waiting there without opening it.
  const unreadQuery = useQuery({ queryKey: ['agency', 'unread'], queryFn: agencyApi.unreadByClient, enabled: Boolean(me), refetchInterval: 45_000, retry: false });
  const unread = useMemo(() => new Map((unreadQuery.data ?? []).map((u) => [u.clientId, u.unread])), [unreadQuery.data]);
  const byId = useMemo(() => new Map((me?.tenants ?? []).map((t) => [t.id, t])), [me?.tenants]);
  const open = tabs.map((id) => byId.get(id)).filter((t): t is NonNullable<typeof t> => Boolean(t));
  const pickable = useMemo(() => {
    const n = needle.trim().toLowerCase();
    const list = [...clients].sort((a, b) => a.name.localeCompare(b.name));
    return n ? list.filter((c) => c.name.toLowerCase().includes(n)) : list;
  }, [clients, needle]);

  const remember = () => lastPlace.current.set(scope ?? AGENCY_KEY, `${location.pathname}${location.search}`);
  const goAgency = () => {
    remember();
    showAgency();
    navigate(lastPlace.current.get(AGENCY_KEY) ?? AGENCY_HOME);
  };
  const goClient = (id: string) => {
    if (id !== scope) remember();
    openClient(id);
    navigate(lastPlace.current.get(id) ?? CLIENT_HOME);
  };
  const close = (id: string) => {
    lastPlace.current.delete(id);
    const wasOpen = scope === id;
    closeClient(id);
    if (wasOpen) {
      const next = getScope(); // the neighbouring tab, or null = the agency home
      navigate(next ? (lastPlace.current.get(next) ?? CLIENT_HOME) : (lastPlace.current.get(AGENCY_KEY) ?? AGENCY_HOME));
    }
  };

  // A person with one client and no agency has nothing to switch between.
  if (!me || (!isAgencyMember && clients.length <= 1)) return null;

  const tabSx = (selected: boolean) => ({
    display: 'inline-flex',
    alignItems: 'center',
    gap: 0.75,
    height: 32,
    px: 1.25,
    flexShrink: 0,
    maxWidth: 210,
    borderRadius: 2,
    border: 1,
    borderColor: selected ? 'divider' : 'transparent',
    bgcolor: selected ? 'background.default' : 'transparent',
    color: selected ? 'text.primary' : 'text.secondary',
    cursor: 'pointer',
    transition: 'background-color 120ms, color 120ms',
    '&:hover': { bgcolor: selected ? 'background.default' : 'surface.hover', color: 'text.primary' },
  });
  const reset = { all: 'unset', boxSizing: 'border-box', cursor: 'pointer', display: 'inline-flex', alignItems: 'center', gap: 0.75, minWidth: 0, '&:focus-visible': { outline: '2px solid', outlineColor: 'primary.main', outlineOffset: 2, borderRadius: 1 } } as const;

  return (
    <Box sx={{ display: 'flex', alignItems: 'center', gap: 0.5, minWidth: 0, flex: 1, overflowX: 'auto', py: 0.5 }} role="tablist" aria-label="Clients you have open">
      {isAgencyMember && (
        <Box sx={tabSx(scope === null)}>
          <Box component="button" type="button" role="tab" aria-selected={scope === null} aria-label="All clients" onClick={goAgency} sx={reset}>
            <GridViewRoundedIcon sx={{ fontSize: 16 }} />
            <Typography variant="body2" sx={{ fontWeight: 600, display: { xs: 'none', sm: 'block' } }} noWrap>All clients</Typography>
          </Box>
        </Box>
      )}
      {open.map((t) => {
        const selected = scope === t.id;
        return (
          <Box key={t.id} sx={{ ...tabSx(selected), pr: 0.5 }} role="presentation">
            <Box component="button" type="button" role="tab" aria-selected={selected} onClick={() => goClient(t.id)} sx={reset}>
              <Box component="span" sx={{ width: 8, height: 8, borderRadius: '50%', bgcolor: clientColor(t.id), flexShrink: 0 }} aria-hidden />
              <Typography variant="body2" sx={{ fontWeight: selected ? 600 : 500 }} noWrap>{t.name}</Typography>
              {!selected && (unread.get(t.id) ?? 0) > 0 && (
                <Box component="span" title={`${unread.get(t.id)} unread in ${t.name}`} aria-label={`${unread.get(t.id)} unread notifications`} sx={{ minWidth: 18, height: 18, px: 0.5, borderRadius: 9, bgcolor: 'error.main', color: 'error.contrastText', fontSize: 11, fontWeight: 700, display: 'inline-grid', placeItems: 'center', lineHeight: 1 }}>{(unread.get(t.id) ?? 0) > 99 ? '99+' : unread.get(t.id)}</Box>
              )}
            </Box>
            <Tooltip title={`Close ${t.name}`}>
              <IconButton size="small" aria-label={`Close ${t.name}`} onClick={() => close(t.id)} sx={{ p: 0.25, borderRadius: 1 }}>
                <CloseRoundedIcon sx={{ fontSize: 14 }} />
              </IconButton>
            </Tooltip>
          </Box>
        );
      })}
      <Tooltip title="Open a client">
        <IconButton size="small" aria-label="Open a client" aria-haspopup="menu" onClick={(e) => { setNeedle(''); setAnchor(e.currentTarget); }} sx={{ flexShrink: 0 }}>
          <AddRoundedIcon fontSize="small" />
        </IconButton>
      </Tooltip>
      <Menu anchorEl={anchor} open={Boolean(anchor)} onClose={() => setAnchor(null)} slotProps={{ paper: { sx: { width: 300, maxHeight: 420 } } }}>
        <Box sx={{ px: 1.5, pb: 1, display: 'flex', alignItems: 'center', gap: 1 }}>
          <SearchRoundedIcon fontSize="small" color="disabled" />
          <InputBase autoFocus fullWidth placeholder="Find a client…" value={needle} onChange={(e) => setNeedle(e.target.value)} onKeyDown={(e) => e.stopPropagation()} inputProps={{ 'aria-label': 'Find a client' }} sx={{ fontSize: '0.875rem' }} />
        </Box>
        <Divider />
        {pickable.length === 0 && <MenuItem disabled>{clients.length === 0 ? 'No clients yet' : 'No client matches'}</MenuItem>}
        {pickable.map((c) => (
          <MenuItem key={c.id} onClick={() => { setAnchor(null); goClient(c.id); }} selected={scope === c.id}>
            <Box component="span" sx={{ width: 8, height: 8, borderRadius: '50%', bgcolor: clientColor(c.id), mr: 1.25, flexShrink: 0 }} aria-hidden />
            <ListItemText primary={c.name} secondary={c.industry ? c.industry.replace(/_/g, ' ') : undefined} slotProps={{ primary: { noWrap: true, variant: 'body2' }, secondary: { sx: { textTransform: 'capitalize' } } }} />
            {tabs.includes(c.id) && <CheckRoundedIcon fontSize="small" color="primary" />}
          </MenuItem>
        ))}
        {isAgencyAdmin && hq && (
          <MenuItem onClick={() => { setAnchor(null); goClient(hq.id); }} selected={scope === hq.id} sx={{ borderTop: 1, borderColor: 'divider', mt: 0.5 }}>
            <Box component="span" sx={{ width: 8, height: 8, borderRadius: 0.5, bgcolor: 'text.disabled', mr: 1.25, flexShrink: 0 }} aria-hidden />
            <ListItemText primary="Our own leads" secondary="The agency’s workspace (not a client)" slotProps={{ primary: { noWrap: true, variant: 'body2' } }} />
          </MenuItem>
        )}
      </Menu>
    </Box>
  );
}
