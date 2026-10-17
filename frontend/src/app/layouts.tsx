import { useEffect } from 'react';
import { Box, ButtonBase, Paper, Stack, Typography } from '@mui/material';
import { Link as RouterLink, Navigate, Outlet, useLocation, useNavigate } from 'react-router-dom';
import { useAuth } from '@/features/auth/AuthProvider';
import { clientColor } from '@/features/agency/shared';
import { PageHeader } from '@/ui/PageHeader';
import { openClient, showAgency } from './scope';
import { REAL_ESTATE_PAGES, SETTINGS_PAGES, type SubPage } from './nav';

/** A quiet row of links for pages that belong together (the report pages, the property pages). */
function SectionTabs({ items, label }: { items: SubPage[]; label: string }) {
  const { can } = useAuth();
  const { pathname } = useLocation();
  const shown = items.filter((i) => !i.permission || can(i.permission));
  if (shown.length < 2) return null;
  return (
    <Stack direction="row" component="nav" aria-label={label} sx={{ gap: 0.5, mb: 2.5, borderBottom: 1, borderColor: 'divider', overflowX: 'auto' }}>
      {shown.map((i) => {
        const active = pathname === i.to || pathname.startsWith(`${i.to}/`);
        return (
          <Box key={i.to} component={RouterLink} to={i.to} aria-current={active ? 'page' : undefined} sx={{ px: 1.5, py: 1, mb: '-1px', whiteSpace: 'nowrap', textDecoration: 'none', typography: 'body2', fontWeight: active ? 600 : 500, color: active ? 'primary.main' : 'text.secondary', borderBottom: 2, borderColor: active ? 'primary.main' : 'transparent', '&:hover': { color: active ? 'primary.main' : 'text.primary' } }}>
            {i.label}
          </Box>
        );
      })}
    </Stack>
  );
}

export function RealEstateLayout() {
  return (
    <>
      <SectionTabs items={REAL_ESTATE_PAGES} label="Properties" />
      <Outlet />
    </>
  );
}

/** Everything about setting a client up lives here, in one place, with its own small menu. */
export function SettingsLayout() {
  const { can } = useAuth();
  const { pathname } = useLocation();
  const groups = (['Client', 'Leads', 'Connect', 'Admin'] as const)
    .map((g) => ({ g, items: SETTINGS_PAGES.filter((p) => p.group === g && (!p.permission || can(p.permission))) }))
    .filter((x) => x.items.length > 0);
  return (
    <Box sx={{ display: 'grid', gap: { xs: 2, md: 4 }, gridTemplateColumns: { xs: '1fr', md: '190px minmax(0, 1fr)' }, alignItems: 'start' }}>
      <Box component="nav" aria-label="Settings" sx={{ position: { md: 'sticky' }, top: { md: 76 }, display: 'flex', flexDirection: { xs: 'row', md: 'column' }, gap: { xs: 0.5, md: 2 }, overflowX: { xs: 'auto', md: 'visible' }, pb: { xs: 1, md: 0 } }}>
        {groups.map(({ g, items }) => (
          <Box key={g} sx={{ display: 'flex', flexDirection: { xs: 'row', md: 'column' }, gap: 0.25 }}>
            <Typography variant="overline" color="text.secondary" sx={{ px: 1.25, display: { xs: 'none', md: 'block' } }}>{g}</Typography>
            {items.map((i) => {
              const active = pathname === i.to || pathname.startsWith(`${i.to}/`);
              return (
                <ButtonBase key={i.to} component={RouterLink} to={i.to} aria-current={active ? 'page' : undefined} sx={{ justifyContent: 'flex-start', px: 1.25, py: 0.75, borderRadius: 2, whiteSpace: 'nowrap', typography: 'body2', fontWeight: active ? 600 : 500, color: active ? 'primary.main' : 'text.secondary', bgcolor: active ? 'surface.selected' : 'transparent', '&:hover': { bgcolor: active ? 'surface.selected' : 'surface.hover', color: active ? 'primary.main' : 'text.primary' } }}>
                  {i.label}
                </ButtonBase>
              );
            })}
          </Box>
        ))}
      </Box>
      <Box sx={{ minWidth: 0 }}>
        <Outlet />
      </Box>
    </Box>
  );
}

/** Agency pages belong to "All clients": opening one while a client tab is selected returns to the agency view. */
export function AgencyLayout() {
  const { isAgencyMember } = useAuth();
  const { pathname } = useLocation();
  // Only when ARRIVING at an agency address (not whenever the open client changes: opening a client from here must not snap back).
  useEffect(() => {
    // The address bar is the truth at this moment: a remount that lands here a beat after Open client must not undo it.
    const now = window.location.pathname;
    if (now === '/agency' || now.startsWith('/agency/')) showAgency();
  }, [pathname]);
  if (!isAgencyMember) return <Navigate to="/overview" replace />;
  return <Outlet />;
}

/** Client pages need a client. At "All clients" there is none, so offer the choice instead of a broken page. */
export function ClientLayout() {
  const { scopeId, isAgencyMember } = useAuth();
  if (scopeId === null && isAgencyMember) return <ChooseClient />;
  return <Outlet />;
}

export function RootRedirect() {
  const { scopeId, isAgencyMember } = useAuth();
  return <Navigate to={scopeId === null && isAgencyMember ? '/agency' : '/overview'} replace />;
}

function ChooseClient() {
  const { clients } = useAuth();
  const navigate = useNavigate();
  return (
    <>
      <PageHeader title="Choose a client" description="This page belongs to one client’s workspace. Pick the client you want to work in." />
      <Box sx={{ display: 'grid', gap: 1.5, gridTemplateColumns: { xs: '1fr', sm: 'repeat(2, 1fr)', lg: 'repeat(3, 1fr)' } }}>
        {clients.map((c) => (
          <Paper key={c.id} variant="outlined" component="button" type="button" onClick={() => { openClient(c.id); navigate('/overview'); }} sx={{ all: 'unset', boxSizing: 'border-box', cursor: 'pointer', display: 'flex', alignItems: 'center', gap: 1.5, p: 2, borderRadius: 2.5, border: 1, borderColor: 'divider', bgcolor: 'background.paper', '&:hover': { borderColor: 'text.disabled' }, '&:focus-visible': { outline: '2px solid', outlineColor: 'primary.main' } }}>
            <Box aria-hidden sx={{ width: 36, height: 36, borderRadius: 2, bgcolor: clientColor(c.id), color: '#fff', display: 'grid', placeItems: 'center', fontWeight: 700 }}>{c.name.charAt(0).toUpperCase()}</Box>
            <Box sx={{ minWidth: 0 }}>
              <Typography variant="subtitle2" noWrap>{c.name}</Typography>
              <Typography variant="caption" color="text.secondary" sx={{ textTransform: 'capitalize' }}>{c.industry ? c.industry.replace(/_/g, ' ') : 'Client'}</Typography>
            </Box>
          </Paper>
        ))}
      </Box>
    </>
  );
}
