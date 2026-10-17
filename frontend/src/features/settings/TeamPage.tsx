import { useState } from 'react';
import {
  Alert, Box, Button, Chip, Dialog, DialogActions, DialogContent, DialogTitle, IconButton, ListItemIcon, ListItemText, Menu, MenuItem,
  Paper, Select, Stack, Table, TableBody, TableCell, TableContainer, TableHead, TableRow, TextField, Tooltip, Typography,
} from '@mui/material';
import MoreHorizRoundedIcon from '@mui/icons-material/MoreHorizRounded';
import PersonAddAltRoundedIcon from '@mui/icons-material/PersonAddAltRounded';
import PersonOffOutlinedIcon from '@mui/icons-material/PersonOffOutlined';
import PauseCircleOutlineRoundedIcon from '@mui/icons-material/PauseCircleOutlineRounded';
import PlayCircleOutlineRoundedIcon from '@mui/icons-material/PlayCircleOutlineRounded';
import GroupsOutlinedIcon from '@mui/icons-material/GroupsOutlined';
import ShieldOutlinedIcon from '@mui/icons-material/ShieldOutlined';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { ApiProblem } from '@/api/client';
import { membersApi, ROLE_LABEL, type Invitation, type Member, type Role } from '@/api/endpoints';
import { useAuth } from '@/features/auth/AuthProvider';
import { RelativeTime, RoleChip, UserAvatar } from '@/ui/display';
import { PageHeader } from '@/ui/PageHeader';
import { EmptyState, QueryBoundary } from '@/ui/states';
import { useConfirm } from '@/ui/confirm';
import { useToast } from '@/ui/toast';

const ALL_ROLES = Object.keys(ROLE_LABEL) as Role[];
const ROLE_HELP: Record<Role, string> = {
  tenant_owner: 'Full control, including billing-level settings and owners.',
  tenant_admin: 'Manages people, settings and integrations. Cannot manage owners.',
  sales_manager: 'Sees the whole team’s leads, assigns work, manages inventory.',
  sales_agent: 'Works their own leads, tasks and viewings.',
  marketing_manager: 'Runs integrations, automations and campaign reporting.',
  marketing_analyst: 'Read-only access to marketing data and reports.',
  executive_readonly: 'Read-only dashboards and reports.',
  auditor: 'Read-only audit trail and operations. No business data.',
};

export function TeamPage() {
  const { me, can, role } = useAuth();
  const canInvite = can('members:invite');
  const [inviteOpen, setInviteOpen] = useState(false);
  const q = useQuery({ queryKey: ['members'], queryFn: membersApi.list });
  const assignable = role === 'tenant_owner' ? ALL_ROLES : ALL_ROLES.filter((r) => r !== 'tenant_owner');

  return (
    <>
      <PageHeader
        title="Team"
        description="Invite people, set what they can do, and remove access the moment it’s no longer needed."
        actions={canInvite && <Button variant="contained" startIcon={<PersonAddAltRoundedIcon />} onClick={() => setInviteOpen(true)}>Invite someone</Button>}
      />
      <QueryBoundary query={q} isEmpty={(d) => d.members.length === 0} empty={<EmptyState icon={<GroupsOutlinedIcon />} title="Just you so far" description="Invite teammates to share leads, tasks and viewings." />}>
        {({ members, invitations }) => (
          <Stack spacing={4}>
            <MembersTable members={members} meId={me!.user.id} assignable={assignable} canManage={can('members:update')} actorIsOwner={role === 'tenant_owner'} />
            {invitations.length > 0 && <InvitationsTable invitations={invitations} canManage={canInvite} />}
          </Stack>
        )}
      </QueryBoundary>
      <InviteDialog open={inviteOpen} onClose={() => setInviteOpen(false)} roles={assignable} />
    </>
  );
}

function MembersTable({ members, meId, assignable, canManage, actorIsOwner }: { members: Member[]; meId: string; assignable: Role[]; canManage: boolean; actorIsOwner: boolean }) {
  const qc = useQueryClient();
  const toast = useToast();
  const confirm = useConfirm();
  const [menu, setMenu] = useState<{ el: HTMLElement; m: Member } | null>(null);
  const fail = (e: unknown) => toast.error(e instanceof ApiProblem ? e.detail : 'That didn’t work. Please try again.');
  const done = (msg: string) => () => { void qc.invalidateQueries({ queryKey: ['members'] }); toast.success(msg); };
  const update = useMutation({ mutationFn: (v: { id: string; patch: { role?: Role; status?: 'active' | 'suspended' } }) => membersApi.update(v.id, v.patch), onError: fail });
  const remove = useMutation({ mutationFn: (id: string) => membersApi.remove(id), onSuccess: done('Member removed'), onError: fail });

  return (
    <Box>
      <Typography variant="h4" component="h2" sx={{ mb: 1.5 }}>Members <Typography component="span" color="text.secondary" variant="body2">({members.length})</Typography></Typography>
      <TableContainer component={Paper} variant="outlined" sx={{ borderRadius: 2.5 }}>
        <Table size="small" aria-label="Workspace members">
          <TableHead>
            <TableRow>
              <TableCell>Person</TableCell>
              <TableCell>Role</TableCell>
              <TableCell sx={{ display: { xs: 'none', md: 'table-cell' } }}>Last active</TableCell>
              <TableCell sx={{ display: { xs: 'none', md: 'table-cell' } }}>2-step</TableCell>
              <TableCell align="right" sx={{ width: 56 }}><span style={{ position: 'absolute', left: -9999 }}>Actions</span></TableCell>
            </TableRow>
          </TableHead>
          <TableBody>
            {members.map((m) => {
              const isMe = m.userId === meId;
              const protectedOwner = m.role === 'tenant_owner' && !actorIsOwner;
              return (
                <TableRow key={m.membershipId} hover sx={{ opacity: m.status === 'suspended' ? 0.6 : 1 }}>
                  <TableCell>
                    <Stack direction="row" spacing={1.5} sx={{ alignItems: 'center' }}>
                      <UserAvatar name={m.name} seed={m.userId} />
                      <Box sx={{ minWidth: 0 }}>
                        <Typography variant="subtitle2" noWrap>{m.name} {isMe && <Chip size="small" label="You" sx={{ ml: 0.5 }} />} {m.status === 'suspended' && <Chip size="small" color="warning" label="Suspended" sx={{ ml: 0.5 }} />}</Typography>
                        <Typography variant="caption" color="text.secondary" noWrap component="div">{m.email}</Typography>
                      </Box>
                    </Stack>
                  </TableCell>
                  <TableCell>
                    {canManage && !protectedOwner ? (
                      <Select
                        size="small"
                        variant="standard"
                        disableUnderline
                        value={m.role}
                        onChange={(e) => update.mutate({ id: m.membershipId, patch: { role: e.target.value as Role } }, { onSuccess: done(`${m.name} is now ${ROLE_LABEL[e.target.value as Role]}`) })}
                        inputProps={{ 'aria-label': `Role for ${m.name}` }}
                        sx={{ fontSize: '0.8125rem', fontWeight: 500 }}
                      >
                        {assignable.concat(assignable.includes(m.role) ? [] : [m.role]).map((r) => <MenuItem key={r} value={r} disabled={!assignable.includes(r)}>{ROLE_LABEL[r]}</MenuItem>)}
                      </Select>
                    ) : (
                      <RoleChip role={m.role} />
                    )}
                  </TableCell>
                  <TableCell sx={{ display: { xs: 'none', md: 'table-cell' }, color: 'text.secondary' }}><RelativeTime value={m.lastSeenAt} fallback="Never" /></TableCell>
                  <TableCell sx={{ display: { xs: 'none', md: 'table-cell' } }}>
                    {m.mfaEnabled ? <Tooltip title="Two-step verification is on"><ShieldOutlinedIcon fontSize="small" color="success" /></Tooltip> : <Typography variant="caption" color="text.disabled">Off</Typography>}
                  </TableCell>
                  <TableCell align="right">
                    {canManage && !protectedOwner && (
                      <IconButton size="small" aria-label={`Actions for ${m.name}`} onClick={(e) => setMenu({ el: e.currentTarget, m })}>
                        <MoreHorizRoundedIcon fontSize="small" />
                      </IconButton>
                    )}
                  </TableCell>
                </TableRow>
              );
            })}
          </TableBody>
        </Table>
      </TableContainer>
      <Menu anchorEl={menu?.el} open={Boolean(menu)} onClose={() => setMenu(null)}>
        {menu && (
          <MenuItem onClick={() => { const m = menu.m; setMenu(null); const next = m.status === 'active' ? 'suspended' : 'active'; update.mutate({ id: m.membershipId, patch: { status: next } }, { onSuccess: done(next === 'suspended' ? `${m.name} suspended` : `${m.name} reactivated`) }); }}>
            <ListItemIcon>{menu.m.status === 'active' ? <PauseCircleOutlineRoundedIcon fontSize="small" /> : <PlayCircleOutlineRoundedIcon fontSize="small" />}</ListItemIcon>
            <ListItemText>{menu.m.status === 'active' ? 'Suspend access' : 'Reactivate'}</ListItemText>
          </MenuItem>
        )}
        {menu && menu.m.userId !== meId && (
          <MenuItem
            sx={{ color: 'error.main' }}
            onClick={async () => {
              const m = menu.m;
              setMenu(null);
              if (await confirm({ title: `Remove ${m.name}?`, body: 'They lose access to this workspace immediately. Their past activity stays on record.', confirmLabel: 'Remove', destructive: true })) remove.mutate(m.membershipId);
            }}
          >
            <ListItemIcon><PersonOffOutlinedIcon fontSize="small" color="error" /></ListItemIcon>
            <ListItemText>Remove from workspace</ListItemText>
          </MenuItem>
        )}
      </Menu>
    </Box>
  );
}

function InvitationsTable({ invitations, canManage }: { invitations: Invitation[]; canManage: boolean }) {
  const qc = useQueryClient();
  const toast = useToast();
  const revoke = useMutation({
    mutationFn: membersApi.revokeInvitation,
    onSuccess: () => { void qc.invalidateQueries({ queryKey: ['members'] }); toast.success('Invitation withdrawn'); },
  });
  return (
    <Box>
      <Typography variant="h4" component="h2" sx={{ mb: 1.5 }}>Pending invitations <Typography component="span" color="text.secondary" variant="body2">({invitations.length})</Typography></Typography>
      <TableContainer component={Paper} variant="outlined" sx={{ borderRadius: 2.5 }}>
        <Table size="small" aria-label="Pending invitations">
          <TableHead><TableRow><TableCell>Email</TableCell><TableCell>Role</TableCell><TableCell>Expires</TableCell><TableCell align="right" /></TableRow></TableHead>
          <TableBody>
            {invitations.map((i) => (
              <TableRow key={i.id} hover>
                <TableCell>{i.email}</TableCell>
                <TableCell><RoleChip role={i.role} /></TableCell>
                <TableCell sx={{ color: 'text.secondary' }}><RelativeTime value={i.expiresAt} /></TableCell>
                <TableCell align="right">{canManage && <Button size="small" color="error" onClick={() => revoke.mutate(i.id)}>Withdraw</Button>}</TableCell>
              </TableRow>
            ))}
          </TableBody>
        </Table>
      </TableContainer>
    </Box>
  );
}

function InviteDialog({ open, onClose, roles }: { open: boolean; onClose: () => void; roles: Role[] }) {
  const qc = useQueryClient();
  const toast = useToast();
  const [email, setEmail] = useState('');
  const [role, setRole] = useState<Role>('sales_agent');
  const [error, setError] = useState<string | null>(null);
  const invite = useMutation({
    mutationFn: () => membersApi.invite(email.trim(), role),
    onSuccess: () => { void qc.invalidateQueries({ queryKey: ['members'] }); toast.success(`Invitation sent to ${email.trim()}`); close(); },
    onError: (e) => setError(e instanceof ApiProblem ? (e.fieldErrors().email ?? e.detail) : 'Couldn’t send the invitation'),
  });
  const close = () => { setEmail(''); setRole('sales_agent'); setError(null); onClose(); };
  const valid = /^[^@\s]+@[^@\s]+\.[^@\s]+$/.test(email.trim());
  return (
    <Dialog open={open} onClose={close} aria-labelledby="invite-title">
      <DialogTitle id="invite-title">Invite someone</DialogTitle>
      <DialogContent>
        <Stack spacing={2} sx={{ pt: 0.5 }} component="form" onSubmit={(e) => { e.preventDefault(); if (valid) invite.mutate(); }} id="invite-form">
          {error && <Alert severity="error">{error}</Alert>}
          <TextField label="Email" type="email" autoFocus value={email} onChange={(e) => setEmail(e.target.value)} autoComplete="off" />
          <TextField select label="Role" value={role} onChange={(e) => setRole(e.target.value as Role)} helperText={ROLE_HELP[role]}>
            {roles.map((r) => <MenuItem key={r} value={r}>{ROLE_LABEL[r]}</MenuItem>)}
          </TextField>
          <Typography variant="caption" color="text.secondary">They’ll get an email with a link that works once and expires in 7 days.</Typography>
        </Stack>
      </DialogContent>
      <DialogActions>
        <Button onClick={close}>Cancel</Button>
        <Button type="submit" form="invite-form" variant="contained" disabled={!valid || invite.isPending}>{invite.isPending ? 'Sending…' : 'Send invitation'}</Button>
      </DialogActions>
    </Dialog>
  );
}
