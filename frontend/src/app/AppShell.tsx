import { useCallback, useEffect, useState, type ReactNode } from 'react';
import { Box, Button, Divider, Drawer, IconButton, List, ListItemButton, ListItemIcon, ListItemText, Menu, MenuItem, Stack, ToggleButton, ToggleButtonGroup, Tooltip, Typography, useMediaQuery } from '@mui/material';
import { useColorScheme, useTheme } from '@mui/material/styles';
import MenuRoundedIcon from '@mui/icons-material/MenuRounded';
import SearchRoundedIcon from '@mui/icons-material/SearchRounded';
import AddRoundedIcon from '@mui/icons-material/AddRounded';
import LogoutRoundedIcon from '@mui/icons-material/LogoutRounded';
import ChevronLeftRoundedIcon from '@mui/icons-material/ChevronLeftRounded';
import ChevronRightRoundedIcon from '@mui/icons-material/ChevronRightRounded';
import LightModeOutlinedIcon from '@mui/icons-material/LightModeOutlined';
import DarkModeOutlinedIcon from '@mui/icons-material/DarkModeOutlined';
import SettingsBrightnessOutlinedIcon from '@mui/icons-material/SettingsBrightnessOutlined';
import { Link as RouterLink, useLocation, useNavigate } from 'react-router-dom';
import { useAuth } from '@/features/auth/AuthProvider';
import { useRealtimeStatus } from '@/features/realtime/RealtimeProvider';
import { UserAvatar } from '@/ui/display';
import { clientColor, roleName } from '@/features/agency/shared';
import { NewLeadDialog } from '@/features/leads/NewLeadDialog';
import { LogoMark } from './Logo';
import { useNavGroups, type NavItem } from './nav';
import { CommandPalette } from './CommandPalette';
import { NotificationBell } from './NotificationBell';
import { ClientTabs } from './ClientTabs';

const SIDEBAR = 208;
const SIDEBAR_RAIL = 60;
const TOPBAR = 52;

const readCollapsed = () => {
  try {
    return localStorage.getItem('nav-collapsed') === '1';
  } catch {
    return false;
  }
};

export function AppShell({ children }: { children: ReactNode }) {
  const theme = useTheme();
  const desktop = useMediaQuery(theme.breakpoints.up('md'));
  const { can, scopeId } = useAuth();
  const navigate = useNavigate();
  const location = useLocation();
  const groups = useNavGroups();
  const [collapsed, setCollapsed] = useState(readCollapsed);
  const [mobileOpen, setMobileOpen] = useState(false);
  const [palette, setPalette] = useState(false);
  const [newLead, setNewLead] = useState(false);

  const path = location.pathname;
  const isActive = (item: NavItem) => (item.exact ? path === item.to : (item.matches ?? [item.to]).some((m) => path === m || path.startsWith(`${m}/`)));

  useEffect(() => setMobileOpen(false), [path]);
  useEffect(() => {
    try {
      localStorage.setItem('nav-collapsed', collapsed ? '1' : '0');
    } catch {
      /* private mode */
    }
  }, [collapsed]);

  // ⌘/Ctrl+K opens the palette from anywhere; "/" does too unless the user is typing.
  useEffect(() => {
    const onKey = (e: KeyboardEvent) => {
      const typing = /^(INPUT|TEXTAREA|SELECT)$/.test((e.target as HTMLElement)?.tagName) || (e.target as HTMLElement)?.isContentEditable;
      if ((e.metaKey || e.ctrlKey) && e.key.toLowerCase() === 'k') {
        e.preventDefault();
        setPalette((o) => !o);
      } else if (e.key === '/' && !typing) {
        e.preventDefault();
        setPalette(true);
      }
    };
    window.addEventListener('keydown', onKey);
    return () => window.removeEventListener('keydown', onKey);
  }, []);

  const rail = desktop && collapsed;
  const width = rail ? SIDEBAR_RAIL : SIDEBAR;

  const sidebar = (
    <Box component="nav" aria-label="Primary" sx={{ display: 'flex', flexDirection: 'column', height: '100%', bgcolor: 'background.paper' }}>
      <WhereAmI compact={rail} />
      <Box sx={{ flex: 1, overflow: 'auto', px: rail ? 0.75 : 1, pb: 2 }}>
        <List component="div" dense disablePadding>
          {groups.flatMap((g) => g.items).map((item) => {
            const active = isActive(item);
            return (
              <Tooltip key={item.to} title={rail ? item.label : ''} placement="right">
                <ListItemButton
                  component={RouterLink}
                  to={item.to}
                  selected={active}
                  aria-current={active ? 'page' : undefined}
                  sx={{
                    minHeight: 36,
                    mb: 0.25,
                    px: 1.25,
                    justifyContent: rail ? 'center' : 'flex-start',
                    color: 'text.secondary',
                    '&.Mui-selected': { bgcolor: 'surface.selected', color: 'primary.main', '& .MuiListItemIcon-root': { color: 'primary.main' } },
                  }}
                >
                  <ListItemIcon sx={{ minWidth: rail ? 0 : 32, color: 'text.secondary', '& svg': { fontSize: 19 } }}>{item.icon}</ListItemIcon>
                  {!rail && <ListItemText primary={item.label} slotProps={{ primary: { variant: 'body2', sx: { fontWeight: active ? 600 : 500 } } }} />}
                </ListItemButton>
              </Tooltip>
            );
          })}
        </List>
      </Box>
      {desktop && (
        <>
          <Divider />
          <Box sx={{ p: 0.75, display: 'flex', justifyContent: rail ? 'center' : 'flex-end' }}>
            <Tooltip title={collapsed ? 'Expand sidebar' : 'Collapse sidebar'} placement="right">
              <IconButton size="small" onClick={() => setCollapsed((c) => !c)} aria-label={collapsed ? 'Expand sidebar' : 'Collapse sidebar'}>
                {collapsed ? <ChevronRightRoundedIcon fontSize="small" /> : <ChevronLeftRoundedIcon fontSize="small" />}
              </IconButton>
            </Tooltip>
          </Box>
        </>
      )}
    </Box>
  );

  return (
    <Box sx={{ minHeight: '100dvh', bgcolor: 'background.default' }}>
      <Box component="a" href="#main" sx={{ position: 'absolute', left: 8, top: -48, zIndex: 2000, bgcolor: 'primary.main', color: 'primary.contrastText', px: 2, py: 1, borderRadius: 1, '&:focus': { top: 8 } }}>
        Skip to content
      </Box>

      {/* Top bar: where you are (client tabs) and the things you do from anywhere */}
      <Box component="header" sx={{ position: 'sticky', top: 0, zIndex: (t) => t.zIndex.appBar, height: TOPBAR, display: 'flex', alignItems: 'center', gap: 1, px: { xs: 1, md: 1.5 }, bgcolor: 'background.paper', borderBottom: 1, borderColor: 'divider' }}>
        {!desktop && (
          <IconButton edge="start" aria-label="Open navigation" onClick={() => setMobileOpen(true)}>
            <MenuRoundedIcon />
          </IconButton>
        )}
        <Box component={RouterLink} to="/" aria-label="Anibros Design home" sx={{ display: 'flex', alignItems: 'center', flexShrink: 0, mr: 0.5, ml: { md: 0.5 } }}>
          <LogoMark size={26} />
        </Box>
        <ClientTabs />
        <Box sx={{ flex: { xs: 0, md: '0 0 auto' } }} />
        <Box
          component="button"
          type="button"
          onClick={() => setPalette(true)}
          aria-label="Search or jump to…"
          sx={{ all: 'unset', cursor: 'pointer', boxSizing: 'border-box', display: { xs: 'none', md: 'flex' }, alignItems: 'center', gap: 1, px: 1.25, height: 32, width: 220, borderRadius: 2, border: 1, borderColor: 'divider', bgcolor: 'background.default', color: 'text.secondary', '&:hover': { borderColor: 'text.disabled' }, '&:focus-visible': { outline: '2px solid', outlineColor: 'primary.main', outlineOffset: 1 } }}
        >
          <SearchRoundedIcon sx={{ fontSize: 17 }} />
          <Typography variant="body2" sx={{ flex: 1 }} noWrap>Search…</Typography>
          <Typography variant="caption" sx={{ border: 1, borderColor: 'divider', borderRadius: 1, px: 0.6, lineHeight: 1.5 }}>⌘K</Typography>
        </Box>
        <IconButton aria-label="Search" onClick={() => setPalette(true)} sx={{ display: { xs: 'inline-flex', md: 'none' } }}><SearchRoundedIcon /></IconButton>
        {scopeId && can('leads:create') && (
          <Button variant="contained" size="small" startIcon={<AddRoundedIcon />} onClick={() => setNewLead(true)} sx={{ flexShrink: 0, '& .MuiButton-startIcon': { mr: { xs: 0, sm: 0.5 }, ml: { xs: 0, sm: -0.25 } } }}>
            <Box component="span" sx={{ display: { xs: 'none', sm: 'inline' } }}>New lead</Box>
          </Button>
        )}
        <LiveIndicator />
        {can('notifications:read') && <NotificationBell />}
        <UserMenu />
        {scopeId && <Box aria-hidden sx={{ position: 'absolute', left: 0, right: 0, bottom: -1, height: 2, bgcolor: clientColor(scopeId) }} />}
      </Box>

      <Box sx={{ display: 'flex' }}>
        {desktop ? (
          <Drawer variant="permanent" open sx={{ width, flexShrink: 0, transition: 'width 160ms ease', '& .MuiDrawer-paper': { width, top: TOPBAR, height: `calc(100dvh - ${TOPBAR}px)`, boxSizing: 'border-box', borderRight: 1, borderColor: 'divider', transition: 'width 160ms ease', overflowX: 'hidden' } }}>
            {sidebar}
          </Drawer>
        ) : (
          <Drawer variant="temporary" open={mobileOpen} onClose={() => setMobileOpen(false)} ModalProps={{ keepMounted: true }} sx={{ '& .MuiDrawer-paper': { width: SIDEBAR } }}>
            {sidebar}
          </Drawer>
        )}
        <Box component="main" id="main" tabIndex={-1} sx={{ flex: 1, minWidth: 0, px: { xs: 2, md: 3 }, py: { xs: 2, md: 3 }, maxWidth: 1560, mx: 'auto', width: '100%', outline: 'none' }}>
          {children}
        </Box>
      </Box>

      <CommandPalette open={palette} onClose={() => setPalette(false)} />
      <NewLeadDialog open={newLead} onClose={() => setNewLead(false)} onCreated={(l) => { setNewLead(false); navigate(`/leads/${l.id}`); }} onOpenExisting={(id) => navigate(`/leads/${id}`)} />
    </Box>
  );
}

function LiveIndicator() {
  const s = useRealtimeStatus();
  const map = { live: ['success.main', 'Live: changes appear instantly'], connecting: ['warning.main', 'Connecting…'], reconnecting: ['warning.main', 'Reconnecting: data refreshes automatically'], offline: ['text.disabled', 'Offline: updates paused'] } as const;
  const [color, label] = map[s];
  return (
    <Tooltip title={label}>
      <Box role="status" aria-label={label} sx={{ width: 28, height: 28, display: 'grid', placeItems: 'center' }}>
        <Box sx={{ width: 8, height: 8, borderRadius: '50%', bgcolor: color }} />
      </Box>
    </Tooltip>
  );
}

/** Which place the sidebar belongs to: the agency, or the client that is open (and your role there). */
function WhereAmI({ compact }: { compact: boolean }) {
  const { scopeId, tenant, hq, isAgencyAdmin } = useAuth();
  const title = scopeId ? (tenant?.name ?? '…') : (hq?.name ?? 'Agency');
  const sub = scopeId ? `${tenant?.industry ? tenant.industry.replace(/_/g, ' ') : tenant?.kind === 'agency_hq' ? 'Your own company' : 'Client'}${tenant ? ` · ${roleName(tenant.role)}` : ''}` : isAgencyAdmin ? 'All clients' : 'Your clients';
  return (
    <Box sx={{ px: compact ? 1 : 1.5, py: 1.5 }}>
      <Stack direction="row" spacing={1.25} sx={{ alignItems: 'center', minWidth: 0, justifyContent: compact ? 'center' : 'flex-start' }}>
        <Box aria-hidden sx={{ width: 30, height: 30, borderRadius: 1.5, flexShrink: 0, display: 'grid', placeItems: 'center', bgcolor: scopeId ? clientColor(scopeId) : 'primary.main', color: '#fff', fontWeight: 700, fontSize: 13 }}>
          {title.trim().charAt(0).toUpperCase() || '?'}
        </Box>
        {!compact && (
          <Box sx={{ minWidth: 0, flex: 1 }}>
            <Typography variant="subtitle2" noWrap>{title}</Typography>
            <Typography variant="caption" color="text.secondary" noWrap component="div" sx={{ textTransform: 'capitalize' }}>{sub}</Typography>
          </Box>
        )}
      </Stack>
    </Box>
  );
}

function UserMenu() {
  const { me, signOut } = useAuth();
  const navigate = useNavigate();
  const { mode, setMode } = useColorScheme();
  const [anchor, setAnchor] = useState<HTMLElement | null>(null);
  const close = useCallback(() => setAnchor(null), []);
  if (!me) return null;
  return (
    <>
      <Tooltip title="Account">
        <IconButton onClick={(e) => setAnchor(e.currentTarget)} aria-label="Account menu" aria-haspopup="menu" sx={{ p: 0.5 }}>
          <UserAvatar name={me.user.name} seed={me.user.id} size={28} />
        </IconButton>
      </Tooltip>
      <Menu anchorEl={anchor} open={Boolean(anchor)} onClose={close} anchorOrigin={{ vertical: 'bottom', horizontal: 'right' }} transformOrigin={{ vertical: 'top', horizontal: 'right' }} slotProps={{ paper: { sx: { minWidth: 250 } } }}>
        <Box sx={{ px: 2, py: 1.25 }}>
          <Typography variant="subtitle2" noWrap>{me.user.name}</Typography>
          <Typography variant="caption" color="text.secondary" noWrap component="div">{me.user.email}</Typography>
        </Box>
        <Divider />
        <MenuItem onClick={() => { close(); navigate('/settings/profile'); }}>Profile & security</MenuItem>
        <Box sx={{ px: 2, py: 1 }}>
          <Typography variant="caption" color="text.secondary" component="div" sx={{ mb: 0.75 }}>Appearance</Typography>
          <ToggleButtonGroup exclusive size="small" fullWidth value={mode ?? 'system'} onChange={(_, v) => v && setMode(v)} aria-label="Colour theme">
            <ToggleButton value="light" aria-label="Light"><LightModeOutlinedIcon fontSize="small" /></ToggleButton>
            <ToggleButton value="system" aria-label="System"><SettingsBrightnessOutlinedIcon fontSize="small" /></ToggleButton>
            <ToggleButton value="dark" aria-label="Dark"><DarkModeOutlinedIcon fontSize="small" /></ToggleButton>
          </ToggleButtonGroup>
        </Box>
        <Divider />
        <MenuItem onClick={async () => { close(); await signOut(); navigate('/sign-in', { replace: true }); }}>
          <LogoutRoundedIcon fontSize="small" sx={{ mr: 1.25, color: 'text.secondary' }} />
          Sign out
        </MenuItem>
      </Menu>
    </>
  );
}
