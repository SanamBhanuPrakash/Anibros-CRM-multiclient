import { useState, type ReactNode } from 'react';
import { Box, Button, ListItemText, Menu, MenuItem, Stack, Typography } from '@mui/material';
import FacebookRoundedIcon from '@mui/icons-material/FacebookRounded';
import GoogleIcon from '@mui/icons-material/Google';
import LanguageRoundedIcon from '@mui/icons-material/LanguageRounded';
import HandshakeOutlinedIcon from '@mui/icons-material/HandshakeOutlined';
import DirectionsWalkRoundedIcon from '@mui/icons-material/DirectionsWalkRounded';
import StorefrontOutlinedIcon from '@mui/icons-material/StorefrontOutlined';
import EditNoteRoundedIcon from '@mui/icons-material/EditNoteRounded';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { ApiProblem } from '@/api/client';
import { leadsApi } from '@/api/crm';
import type { Role } from '@/api/endpoints';
import { useAuth } from '@/features/auth/AuthProvider';
import { UserAvatar } from '@/ui/display';
import { useToast } from '@/ui/toast';
import { SOURCE_NAME } from './shared';

/** Where a lead came from, as a small tag with a recognisable icon (Meta, Google, website…), so a caller sees the channel at a glance. */
const SOURCE_ICON: Record<string, ReactNode> = {
  meta_ads: <FacebookRoundedIcon sx={{ color: '#1877F2' }} />,
  google_ads: <GoogleIcon sx={{ color: '#EA4335' }} />,
  website: <LanguageRoundedIcon sx={{ color: 'primary.main' }} />,
  referral: <HandshakeOutlinedIcon sx={{ color: 'success.main' }} />,
  walk_in: <DirectionsWalkRoundedIcon sx={{ color: 'warning.main' }} />,
  portal: <StorefrontOutlinedIcon sx={{ color: 'info.main' }} />,
  direct: <EditNoteRoundedIcon sx={{ color: 'text.secondary' }} />,
  other: <EditNoteRoundedIcon sx={{ color: 'text.secondary' }} />,
};
export const SOURCE_TEXT: Record<string, string> = { meta_ads: 'Meta ads', google_ads: 'Google ads', website: 'Website', referral: 'Referral', walk_in: 'Walk-in', portal: 'Portal', direct: 'Added by hand', other: 'Other' };

export function SourceTag({ source, campaign }: { source: string; campaign?: string | null }) {
  return (
    <Stack direction="row" spacing={0.75} sx={{ alignItems: 'center', minWidth: 0 }}>
      <Box sx={{ display: 'flex', '& svg': { fontSize: 18 } }} aria-hidden>{SOURCE_ICON[source] ?? SOURCE_ICON.other}</Box>
      <Box sx={{ minWidth: 0 }}>
        <Typography variant="body2" noWrap>{SOURCE_TEXT[source] ?? SOURCE_NAME(source)}</Typography>
        {campaign && <Typography variant="caption" color="text.secondary" noWrap component="div" sx={{ maxWidth: 170 }} title={campaign}>{campaign}</Typography>}
      </Box>
    </Stack>
  );
}

/** What a role may do about who owns a lead (the server decides; this only shows the right button). */
export const canAssignAs = (role: Role | null | undefined) => role === 'tenant_owner' || role === 'tenant_admin' || role === 'sales_manager';
export const canClaimAs = (role: Role | null | undefined) => canAssignAs(role) || role === 'sales_agent';

/**
 * The owner of a lead, with the next sensible action: nobody owns it → "Take it" for anyone who may claim, "Assign…" for team leads and admins;
 * somebody owns it → their name, and managers can hand it to someone else. Works inside any client (for the agency-wide list).
 */
export function OwnerCell({ lead, role, onChanged, compact }: { lead: { id: string; clientId: string; ownerId: string | null; ownerName: string | null }; role: Role | null | undefined; onChanged?: () => void; compact?: boolean }) {
  const { me } = useAuth();
  const toast = useToast();
  const qc = useQueryClient();
  const [anchor, setAnchor] = useState<HTMLElement | null>(null);
  const mayAssign = canAssignAs(role);
  const mayClaim = canClaimAs(role);
  const directory = useQuery({ queryKey: ['lead-owners', lead.clientId], queryFn: () => leadsApi.directory(lead.clientId), enabled: Boolean(anchor), staleTime: 60_000 });

  const done = () => {
    void qc.invalidateQueries({ queryKey: ['agency'] });
    void qc.invalidateQueries({ queryKey: ['leads'] });
    onChanged?.();
  };
  const fail = (e: unknown) => {
    toast.error(e instanceof ApiProblem ? e.detail : 'That didn’t work. Please try again.');
    done(); // somebody may have taken it a moment earlier: show how it stands now
  };
  const claim = useMutation({ mutationFn: () => leadsApi.claim(lead.id, lead.clientId), onSuccess: () => { toast.success('It’s yours now'); done(); }, onError: fail });
  const assign = useMutation({ mutationFn: (ownerId: string | null) => leadsApi.assign(lead.id, ownerId, lead.clientId), onSuccess: (l) => { toast.success(l.ownerId ? 'Lead assigned' : 'Lead unassigned'); done(); }, onError: fail });

  const menu = mayAssign && (
    <Menu anchorEl={anchor} open={Boolean(anchor)} onClose={() => setAnchor(null)} slotProps={{ paper: { sx: { minWidth: 220, maxHeight: 320 } } }}>
      <Typography variant="overline" color="text.secondary" sx={{ px: 2 }}>Assign to</Typography>
      {directory.isPending && <MenuItem disabled>Loading…</MenuItem>}
      {(directory.data ?? []).map((m) => (
        <MenuItem key={m.userId} selected={m.userId === lead.ownerId} onClick={() => { setAnchor(null); if (m.userId !== lead.ownerId) assign.mutate(m.userId); }}>
          <ListItemText primary={`${m.name}${m.userId === me?.user.id ? ' (you)' : ''}`} />
        </MenuItem>
      ))}
      {lead.ownerId && <MenuItem onClick={() => { setAnchor(null); assign.mutate(null); }} sx={{ color: 'text.secondary' }}>Unassign</MenuItem>}
    </Menu>
  );

  if (lead.ownerId) {
    const name = lead.ownerId === me?.user.id ? 'You' : (lead.ownerName ?? '…');
    const body = (
      <Stack direction="row" spacing={0.75} sx={{ alignItems: 'center' }}>
        <UserAvatar name={lead.ownerName ?? '?'} seed={lead.ownerId} size={22} />
        {!compact && <Typography variant="body2" noWrap>{name}</Typography>}
      </Stack>
    );
    return mayAssign ? (
      <>
        <Box component="button" type="button" title="Change owner" onClick={(e: React.MouseEvent<HTMLElement>) => { e.stopPropagation(); setAnchor(e.currentTarget); }} sx={{ all: 'unset', cursor: 'pointer', borderRadius: 1, px: 0.5, mx: -0.5, '&:hover': { bgcolor: 'action.hover' }, '&:focus-visible': { outline: '2px solid', outlineColor: 'primary.main' } }}>{body}</Box>
        {menu}
      </>
    ) : body;
  }
  return (
    <Stack direction="row" spacing={0.5} sx={{ alignItems: 'center' }} onClick={(e) => e.stopPropagation()}>
      {mayClaim && <Button size="small" variant="contained" disableElevation disabled={claim.isPending} onClick={() => claim.mutate()} sx={{ py: 0.15, px: 1, minHeight: 26, fontSize: '0.75rem' }}>{claim.isPending ? 'Taking…' : 'Take it'}</Button>}
      {mayAssign && <Button size="small" color="inherit" onClick={(e) => setAnchor(e.currentTarget)} sx={{ py: 0.15, px: 1, minHeight: 26, fontSize: '0.75rem' }}>Assign…</Button>}
      {!mayClaim && !mayAssign && <Typography variant="body2" color="warning.main">Not assigned</Typography>}
      {menu}
    </Stack>
  );
}
