import type { ReactElement } from 'react';
import SpaceDashboardOutlinedIcon from '@mui/icons-material/SpaceDashboardOutlined';
import PeopleAltOutlinedIcon from '@mui/icons-material/PeopleAltOutlined';
import ContactsOutlinedIcon from '@mui/icons-material/ContactsOutlined';
import ChecklistRoundedIcon from '@mui/icons-material/ChecklistRounded';
import ApartmentOutlinedIcon from '@mui/icons-material/ApartmentOutlined';
import InsightsOutlinedIcon from '@mui/icons-material/InsightsOutlined';
import GroupsOutlinedIcon from '@mui/icons-material/GroupsOutlined';
import SettingsOutlinedIcon from '@mui/icons-material/SettingsOutlined';
import SpeedOutlinedIcon from '@mui/icons-material/SpeedOutlined';
import GridViewRoundedIcon from '@mui/icons-material/GridViewRounded';
import { useAuth } from '@/features/auth/AuthProvider';

export interface NavItem {
  to: string;
  label: string;
  icon: ReactElement;
  /** Permission from the server matrix. Omitted = visible to every signed-in user. */
  permission?: string;
  keywords?: string;
  /** Only for agency owners/admins. */
  adminOnly?: boolean;
  /** Path prefixes that keep this item highlighted (a section has several pages). */
  matches?: string[];
  /** Highlight only on this exact path (a section whose siblings live under the same prefix). */
  exact?: boolean;
}
export interface NavGroup {
  label: string;
  items: NavItem[];
}
export type NavMode = 'agency' | 'client';

/** Pages that live inside a section: shown as tabs or a sub-menu, never as extra sidebar entries. */
export interface SubPage {
  to: string;
  label: string;
  permission?: string;
  keywords?: string;
}

export const REPORT_PAGES: SubPage[] = [
  { to: '/reports', label: 'Reports', permission: 'dashboard:read', keywords: 'dashboard analytics attribution funnel performance numbers sources campaigns spend team charts' },
];
export const REAL_ESTATE_PAGES: SubPage[] = [
  { to: '/properties', label: 'Properties', permission: 'properties:read', keywords: 'units listings inventory' },
  { to: '/projects', label: 'Projects', permission: 'projects:read', keywords: 'developments' },
  { to: '/property-matching', label: 'Matching', permission: 'matching:use', keywords: 'recommend match' },
  { to: '/deals', label: 'Offers and bookings', permission: 'reservations:read', keywords: 'reservations sales deals sold booked won offers' },
];
export const SETTINGS_PAGES: Array<SubPage & { group: 'Client' | 'Leads' | 'Connect' | 'Admin' }> = [
  { group: 'Client', to: '/settings/client', label: 'Details', keywords: 'name industry contact archive' },
  { group: 'Client', to: '/settings/team', label: 'Team', keywords: 'members assign employees roles callers' },
  { group: 'Leads', to: '/settings/custom-fields', label: 'Lead fields', permission: 'custom_fields:read', keywords: 'custom fields questions details columns' },
  { group: 'Leads', to: '/settings/pipelines', label: 'Pipeline stages', permission: 'pipeline:manage', keywords: 'stages rules process funnel' },
  { group: 'Leads', to: '/settings/workspace', label: 'Lead handling', permission: 'tenant:update', keywords: 'time zone currency assignment reply deadline sla' },
  { group: 'Leads', to: '/settings/automation', label: 'Automation', permission: 'automation:read', keywords: 'rules workflow follow up' },
  { group: 'Connect', to: '/integrations/lead-sources', label: 'Lead sources', permission: 'integrations:read', keywords: 'meta facebook instagram google ads website form webhook capture' },
  { group: 'Connect', to: '/integrations/webhooks', label: 'Send data out', permission: 'integrations:read', keywords: 'webhook push notify zapier outgoing events signed' },
  { group: 'Connect', to: '/settings/api-keys', label: 'API keys', permission: 'api_keys:manage', keywords: 'developer tokens integration' },
  { group: 'Admin', to: '/operations', label: 'Monitor', permission: 'operations:read', keywords: 'health status logs errors queues audit trail' },
];

const AGENCY: NavGroup[] = [
  {
    label: 'Agency',
    items: [
      { to: '/agency', label: 'Clients', exact: true, icon: <GridViewRoundedIcon />, keywords: 'add client workspaces overview all clients' },
      { to: '/agency/leads', label: 'All leads', icon: <PeopleAltOutlinedIcon />, keywords: 'every client leads download excel csv export filter' },
      { to: '/agency/reports', label: 'Reports', icon: <InsightsOutlinedIcon />, keywords: 'compare clients side by side leads won spend performance numbers' },
      { to: '/agency/activity', label: 'Activity', icon: <SpeedOutlinedIcon />, keywords: 'calls messages who did what today performance team' },
      { to: '/agency/team', label: 'Team', icon: <GroupsOutlinedIcon />, adminOnly: true, keywords: 'employees staff invite assign clients roles' },
    ],
  },
];

const CLIENT: NavItem[] = [
  { to: '/overview', label: 'Overview', icon: <SpaceDashboardOutlinedIcon />, keywords: 'today my day get started follow-ups summary', matches: ['/overview'] },
  { to: '/leads', label: 'Leads', icon: <PeopleAltOutlinedIcon />, permission: 'leads:read', keywords: 'inbox prospects calls log pipeline board', matches: ['/leads'] },
  { to: '/tasks', label: 'Tasks', icon: <ChecklistRoundedIcon />, permission: 'tasks:read', keywords: 'todo follow-up appointments schedule calendar week', matches: ['/tasks'] },
  { to: '/contacts', label: 'Contacts', icon: <ContactsOutlinedIcon />, permission: 'contacts:read', keywords: 'people', matches: ['/contacts'] },
  { to: '/reports', label: 'Reports', icon: <InsightsOutlinedIcon />, permission: 'dashboard:read', keywords: 'analytics dashboard attribution funnel numbers spend', matches: ['/reports', '/dashboard', '/attribution'] },
  { to: '/properties', label: 'Properties', icon: <ApartmentOutlinedIcon />, permission: 'properties:read', keywords: 'units listings projects matching offers real estate', matches: ['/properties', '/projects', '/property-matching', '/deals'] },
  { to: '/settings/client', label: 'Settings', icon: <SettingsOutlinedIcon />, keywords: 'setup client details team lead fields sources api keys pipeline', matches: ['/settings', '/integrations', '/operations'] },
];

export interface NavOptions {
  mode: NavMode;
  can: (permission: string) => boolean;
  isAgencyAdmin: boolean;
  /** The properties area is only for real-estate clients. */
  realEstate: boolean;
}

export function navFor({ mode, can, isAgencyAdmin, realEstate }: NavOptions): NavGroup[] {
  if (mode === 'agency') {
    return AGENCY.map((g) => ({ ...g, items: g.items.filter((i) => !i.adminOnly || isAgencyAdmin) })).filter((g) => g.items.length > 0);
  }
  const items = CLIENT.filter((i) => (!i.permission || can(i.permission)) && (realEstate || i.to !== '/properties'));
  return [{ label: 'Client', items }];
}

/** Every page the command palette can jump to (including pages inside sections). */
export function searchablePages(opts: NavOptions): Array<{ to: string; label: string; section: string; icon?: ReactElement; keywords?: string }> {
  const out: Array<{ to: string; label: string; section: string; icon?: ReactElement; keywords?: string }> = [];
  for (const g of navFor(opts)) for (const i of g.items) out.push({ to: i.to, label: i.label, section: g.label, icon: i.icon, keywords: i.keywords });
  if (opts.mode === 'client') {
    for (const p of REPORT_PAGES) if (!p.permission || opts.can(p.permission)) out.push({ to: p.to, label: p.label, section: 'Reports', keywords: p.keywords });
    if (opts.realEstate) for (const p of REAL_ESTATE_PAGES) if (!p.permission || opts.can(p.permission)) out.push({ to: p.to, label: p.label, section: 'Properties', keywords: p.keywords });
    for (const p of SETTINGS_PAGES) if (!p.permission || opts.can(p.permission)) out.push({ to: p.to, label: p.label, section: 'Settings', keywords: p.keywords });
  }
  out.push({ to: '/settings/profile', label: 'Profile & security', section: 'Account', keywords: 'password mfa sessions account' });
  return out;
}

export function useNavOptions(): NavOptions {
  const { can, scopeId, isAgencyAdmin, tenant } = useAuth();
  return { mode: scopeId ? 'client' : 'agency', can, isAgencyAdmin, realEstate: tenant?.industry === 'real_estate' };
}

/** The navigation for what is on screen right now. */
export function useNavGroups(): NavGroup[] {
  return navFor(useNavOptions());
}
