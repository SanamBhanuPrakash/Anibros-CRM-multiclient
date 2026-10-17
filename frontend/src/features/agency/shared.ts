import { useQuery } from '@tanstack/react-query';
import { ApiProblem } from '@/api/client';
import { agencyApi, type ClientRole } from '@/api/agency';
import type { Role } from '@/api/endpoints';

/** What an employee can be inside a client, in words a team lead understands. */
export const CLIENT_ROLE_OPTIONS: Array<{ value: ClientRole; label: string; hint: string }> = [
  { value: 'sales_agent', label: 'Caller', hint: 'Sees and works only the leads assigned to them' },
  { value: 'sales_manager', label: 'Team lead', hint: 'Sees and works all of this client’s leads and can assign them' },
  { value: 'marketing_manager', label: 'Marketing manager', hint: 'Lead sources, spend and reports' },
  { value: 'marketing_analyst', label: 'Analyst', hint: 'Reports and downloads, read-only' },
  { value: 'executive_readonly', label: 'View only', hint: 'Can look at everything, change nothing' },
];

export const roleName = (role: Role | null | undefined): string => {
  if (!role) return '';
  if (role === 'tenant_owner') return 'Agency owner';
  if (role === 'tenant_admin') return 'Agency admin';
  return CLIENT_ROLE_OPTIONS.find((o) => o.value === role)?.label ?? role;
};

/** A calm, stable colour per client so tabs and chips are recognisable at a glance. Each keeps white text at 4.5:1 or better. */
const COLORS = ['#4F46E5', '#0F766E', '#B45309', '#BE185D', '#1D4ED8', '#7C3AED', '#15803D', '#C2410C', '#0E7490', '#9F1239'];
export const clientColor = (id: string) => COLORS[[...id].reduce((a, c) => a + c.charCodeAt(0), 0) % COLORS.length]!;

/** Roles that may download lead data (mirrors the server's `leads:export`). The server decides; this only hides a button that would fail. */
export const canExportAs = (role: Role | null | undefined) => role === 'tenant_owner' || role === 'tenant_admin' || role === 'sales_manager' || role === 'marketing_manager' || role === 'marketing_analyst';

export const OUTCOME_LABEL: Record<string, string> = {
  connected: 'Spoke to them',
  no_answer: 'No answer',
  busy: 'Busy / call back',
  voicemail: 'Left a voicemail',
  wrong_number: 'Wrong number',
  interested: 'Interested',
  not_interested: 'Not interested',
  appointment_booked: 'Appointment booked',
  sent: 'Message sent',
  replied: 'They replied',
};

export const SOURCE_NAME = (s: string) => s.replace(/_/g, ' ').replace(/^./, (c) => c.toUpperCase());

/** Save a file the API generates (cookie session + same origin). Errors come back as readable messages, not a blank download. */
export async function downloadFile(url: string): Promise<string> {
  const res = await fetch(url, { credentials: 'include', headers: { Accept: '*/*' } });
  if (!res.ok) {
    let body: Record<string, unknown> = {};
    try {
      body = await res.json();
    } catch {
      /* not JSON */
    }
    throw new ApiProblem(res.status, body);
  }
  const blob = await res.blob();
  const name = /filename="([^"]+)"/.exec(res.headers.get('content-disposition') ?? '')?.[1] ?? 'download';
  const a = document.createElement('a');
  a.href = URL.createObjectURL(blob);
  a.download = name;
  document.body.appendChild(a);
  a.click();
  a.remove();
  setTimeout(() => URL.revokeObjectURL(a.href), 10_000);
  return name;
}

export const useAgencyOptions = () => useQuery({ queryKey: ['agency', 'options'], queryFn: agencyApi.options, staleTime: Infinity });
export const useAgencyClients = (status: 'active' | 'archived' | 'all' = 'active') =>
  useQuery({ queryKey: ['agency', 'clients', status], queryFn: () => agencyApi.clients(status), refetchInterval: 60_000, staleTime: 15_000 });
export const useAgencyOverview = () => useQuery({ queryKey: ['agency', 'overview'], queryFn: agencyApi.overview, refetchInterval: 60_000, staleTime: 15_000 });
export const useAgencyStaff = (enabled = true) => useQuery({ queryKey: ['agency', 'staff'], queryFn: agencyApi.staff, enabled, staleTime: 15_000 });

/** What an employee can be asked to handle in a client (their "work types"), in words a team lead uses. */
export const WORK_CHANNEL_OPTIONS: Array<{ value: string; label: string; short: string }> = [
  { value: 'call', label: 'Phone calls', short: 'Calls' },
  { value: 'whatsapp', label: 'WhatsApp', short: 'WhatsApp' },
  { value: 'sms', label: 'Text messages', short: 'Texts' },
  { value: 'email', label: 'E-mail', short: 'E-mail' },
  { value: 'instagram', label: 'Instagram messages', short: 'Instagram' },
  { value: 'meeting', label: 'Visits and meetings', short: 'Visits' },
];
/** "Everything", "Calls only", "WhatsApp and Instagram". */
export const channelSummary = (channels: string[] | null | undefined): string => {
  if (!channels || channels.length === 0 || channels.length >= WORK_CHANNEL_OPTIONS.length) return 'Everything';
  const names = channels.map((c) => WORK_CHANNEL_OPTIONS.find((o) => o.value === c)?.short ?? c);
  return names.length === 1 ? `${names[0]} only` : `${names.slice(0, -1).join(', ')} and ${names[names.length - 1]}`;
};