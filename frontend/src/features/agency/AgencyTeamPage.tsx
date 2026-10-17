import { useEffect, useState } from 'react';
import { Alert, Box, Button, Chip, Dialog, DialogActions, DialogContent, DialogTitle, IconButton, ListItemText, Menu, MenuItem, Paper, Stack, Table, TableBody, TableCell, TableContainer, TableHead, TableRow, TextField, Typography } from '@mui/material';
import PersonAddAlt1RoundedIcon from '@mui/icons-material/PersonAddAlt1Rounded';
import MoreVertRoundedIcon from '@mui/icons-material/MoreVertRounded';
import GroupsOutlinedIcon from '@mui/icons-material/GroupsOutlined';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { Navigate } from 'react-router-dom';
import { ApiProblem } from '@/api/client';
import { agencyApi, type ClientRole, type StaffMember } from '@/api/agency';
import { useAuth } from '@/features/auth/AuthProvider';
import { PageHeader } from '@/ui/PageHeader';
import { EmptyState, ErrorState, SkeletonRows } from '@/ui/states';
import { RelativeTime, UserAvatar } from '@/ui/display';
import { useConfirm } from '@/ui/confirm';
import { useToast } from '@/ui/toast';
import { CLIENT_ROLE_OPTIONS, clientColor, roleName, useAgencyClients, useAgencyStaff } from './shared';

/** The agency's employees: who they are, which clients each one works on, and in what role. Agency admins only. */
export function AgencyTeamPage() {
  const { isAgencyAdmin, isAgencyOwner, me } = useAuth();
  const qc = useQueryClient();
  const toast = useToast();
  const confirm = useConfirm();
  const staff = useAgencyStaff(isAgencyAdmin);
  const clients = useAgencyClients('active');
  const [inviting, setInviting] = useState(false);
  const [assigning, setAssigning] = useState<StaffMember | null>(null);
  const [menu, setMenu] = useState<{ el: HTMLElement; m: StaffMember } | null>(null);

  const refresh = () => void qc.invalidateQueries({ queryKey: ['agency'] });
  const fail = (e: unknown) => toast.error(e instanceof ApiProblem ? e.detail : 'That didn’t work. Please try again.');
  const unassign = useMutation({
    mutationFn: ({ clientId, userId }: { clientId: string; userId: string }) => agencyApi.unassign(clientId, userId),
    onSuccess: (r) => { refresh(); toast.success(r.leadsUnassigned ? `Removed. ${r.leadsUnassigned} open lead${r.leadsUnassigned === 1 ? ' is' : 's are'} now not assigned.` : 'Removed'); },
    onError: fail,
  });
  const setRole = useMutation({
    mutationFn: ({ userId, role }: { userId: string; role: 'admin' | 'staff' }) => agencyApi.setStaffRole(userId, role),
    onSuccess: (_, v) => { refresh(); toast.success(v.role === 'admin' ? 'Now an agency admin' : 'Now a regular employee'); },
    onError: fail,
  });
  const remove = useMutation({
    mutationFn: (userId: string) => agencyApi.removeStaff(userId),
    onSuccess: (r) => { refresh(); toast.success(`Removed from the agency and ${r.clientsLeft} client${r.clientsLeft === 1 ? '' : 's'}. ${r.leadsUnassigned} open lead${r.leadsUnassigned === 1 ? '' : 's'} unassigned.`); },
    onError: fail,
  });

  if (!isAgencyAdmin) return <Navigate to="/agency" replace />;
  const list = staff.data?.staff ?? [];

  return (
    <>
      <PageHeader
        title="Team"
        description="Your employees work across clients. Assign each person to the clients they work on, and choose what they can do in each."
        actions={<Button variant="contained" startIcon={<PersonAddAlt1RoundedIcon />} onClick={() => setInviting(true)}>Invite employee</Button>}
      />

      {staff.isPending ? (
        <SkeletonRows rows={5} height={60} />
      ) : staff.isError ? (
        <ErrorState error={staff.error} onRetry={() => void staff.refetch()} />
      ) : (
        <>
          <TableContainer component={Paper} variant="outlined" sx={{ borderRadius: 2 }}>
            <Table aria-label="Employees">
              <TableHead>
                <TableRow>
                  <TableCell>Employee</TableCell>
                  <TableCell>Role</TableCell>
                  <TableCell>Works on</TableCell>
                  <TableCell padding="checkbox"><span style={{ position: 'absolute', left: -9999 }}>Actions</span></TableCell>
                </TableRow>
              </TableHead>
              <TableBody>
                {list.map((m) => {
                  const admin = m.agencyRole !== 'staff';
                  return (
                    <TableRow key={m.userId} sx={{ '&:last-child td': { borderBottom: 0 }, verticalAlign: 'top' }}>
                      <TableCell>
                        <Stack direction="row" spacing={1.25} sx={{ alignItems: 'center' }}>
                          <UserAvatar name={m.name} seed={m.userId} size={32} />
                          <Box sx={{ minWidth: 0 }}>
                            <Typography variant="subtitle2" noWrap>{m.name}{m.userId === me?.user.id ? ' (you)' : ''}</Typography>
                            <Typography variant="caption" color="text.secondary" noWrap component="div">{m.email}</Typography>
                          </Box>
                        </Stack>
                      </TableCell>
                      <TableCell><Chip size="small" color={admin ? 'primary' : 'default'} variant={admin ? 'filled' : 'outlined'} label={m.agencyRole === 'owner' ? 'Owner' : m.agencyRole === 'admin' ? 'Admin' : 'Employee'} /></TableCell>
                      <TableCell>
                        {admin ? (
                          <Typography variant="body2" color="text.secondary">Every client</Typography>
                        ) : m.clients.length === 0 ? (
                          <Typography variant="body2" color="warning.main">Not assigned to any client yet</Typography>
                        ) : (
                          <Stack direction="row" sx={{ flexWrap: 'wrap', gap: 0.75 }}>
                            {m.clients.map((c) => (
                              <Chip key={c.clientId} size="small" variant="outlined" label={`${c.clientName} · ${roleName(c.role)}`}
                                icon={<Box component="span" sx={{ width: 8, height: 8, borderRadius: '50%', bgcolor: clientColor(c.clientId), ml: '8px !important' }} aria-hidden />}
                                onDelete={async () => {
                                  if (await confirm({ title: `Take ${m.name} off ${c.clientName}?`, body: 'They lose access to this client straight away. Their open leads there become “not assigned” so nobody is left waiting.', confirmLabel: 'Take off', destructive: true })) unassign.mutate({ clientId: c.clientId, userId: m.userId });
                                }}
                                deleteIcon={<span aria-label={`Take ${m.name} off ${c.clientName}`} role="img" style={{ fontSize: 14 }}>✕</span>}
                              />
                            ))}
                          </Stack>
                        )}
                      </TableCell>
                      <TableCell padding="checkbox">
                        {!admin || (isAgencyOwner && m.agencyRole === 'admin') ? <IconButton size="small" aria-label={`More for ${m.name}`} onClick={(e) => setMenu({ el: e.currentTarget, m })}><MoreVertRoundedIcon fontSize="small" /></IconButton> : null}
                      </TableCell>
                    </TableRow>
                  );
                })}
              </TableBody>
            </Table>
          </TableContainer>

          {list.length <= 1 && (
            <Paper variant="outlined" sx={{ mt: 2, borderRadius: 2 }}>
              <EmptyState icon={<GroupsOutlinedIcon />} title="Invite your first employee" description="They get an e-mail to set a password. After that, assign them to the clients they work on." action={<Button variant="contained" onClick={() => setInviting(true)}>Invite employee</Button>} />
            </Paper>
          )}

          {(staff.data?.invitations.length ?? 0) > 0 && (
            <Box sx={{ mt: 3 }}>
              <Typography variant="h5" component="h2" sx={{ mb: 1 }}>Waiting to join</Typography>
              <Paper variant="outlined" sx={{ borderRadius: 2 }}>
                {staff.data!.invitations.map((i) => (
                  <Stack key={i.id} direction="row" sx={{ px: 2, py: 1.25, justifyContent: 'space-between', borderBottom: 1, borderColor: 'divider', '&:last-child': { borderBottom: 0 } }}>
                    <Typography variant="body2">{i.email}</Typography>
                    <Typography variant="caption" color="text.secondary">Invited <RelativeTime value={i.createdAt} /> · expires <RelativeTime value={i.expiresAt} /></Typography>
                  </Stack>
                ))}
              </Paper>
            </Box>
          )}
        </>
      )}

      <Menu anchorEl={menu?.el} open={Boolean(menu)} onClose={() => setMenu(null)}>
        {menu && menu.m.agencyRole === 'staff' && [
          <MenuItem key="assign" onClick={() => { setAssigning(menu.m); setMenu(null); }}>Assign to a client…</MenuItem>,
          ...(isAgencyOwner ? [<MenuItem key="admin" onClick={async () => { const m = menu.m; setMenu(null); if (await confirm({ title: `Make ${m.name} an agency admin?`, body: 'Admins see every client and every lead, and can add clients and manage the team.', confirmLabel: 'Make admin' })) setRole.mutate({ userId: m.userId, role: 'admin' }); }}>Make agency admin…</MenuItem>] : []),
          <MenuItem key="remove" sx={{ color: 'error.main' }} onClick={async () => { const m = menu.m; setMenu(null); if (await confirm({ title: `Remove ${m.name} from the agency?`, body: 'They lose access to every client straight away and their open leads become “not assigned”. Everything they logged stays.', confirmLabel: 'Remove', destructive: true })) remove.mutate(m.userId); }}>Remove from agency…</MenuItem>,
        ]}
        {menu && menu.m.agencyRole === 'admin' && [
          <MenuItem key="demote" onClick={async () => { const m = menu.m; setMenu(null); if (await confirm({ title: `Make ${m.name} a regular employee?`, body: 'They lose access to every client until you assign them again.', confirmLabel: 'Make employee', destructive: true })) setRole.mutate({ userId: m.userId, role: 'staff' }); }}>
            <ListItemText primary="Make regular employee…" />
          </MenuItem>,
        ]}
      </Menu>

      <InviteDialog open={inviting} onClose={() => setInviting(false)} onDone={refresh} />
      <AssignDialog member={assigning} clients={(clients.data ?? []).filter((c) => !assigning?.clients.some((x) => x.clientId === c.id))} onClose={() => setAssigning(null)} onDone={refresh} />
    </>
  );
}

function InviteDialog({ open, onClose, onDone }: { open: boolean; onClose: () => void; onDone: () => void }) {
  const toast = useToast();
  const [email, setEmail] = useState('');
  const [error, setError] = useState<string | null>(null);
  useEffect(() => { if (open) setError(null); }, [open]);
  const invite = useMutation({
    mutationFn: () => agencyApi.inviteStaff(email.trim()),
    onSuccess: () => { toast.success(`Invitation sent to ${email.trim()}`); setEmail(''); onDone(); onClose(); },
    onError: (e) => setError(e instanceof ApiProblem ? (e.errors[0]?.message ?? e.detail) : 'Couldn’t send the invitation.'),
  });
  return (
    <Dialog open={open} onClose={invite.isPending ? undefined : onClose} fullWidth maxWidth="xs" aria-labelledby="invite-title">
      <DialogTitle id="invite-title">Invite an employee</DialogTitle>
      <DialogContent>
        <Stack component="form" id="invite-form" spacing={2} sx={{ pt: 0.5 }} onSubmit={(e) => { e.preventDefault(); setError(null); if (email.trim()) invite.mutate(); }}>
          {error && <Alert severity="error">{error}</Alert>}
          <TextField autoFocus label="Their e-mail" type="email" required fullWidth value={email} onChange={(e) => setEmail(e.target.value)} helperText="They get a link to set a password. Then assign them to clients." />
        </Stack>
      </DialogContent>
      <DialogActions>
        <Button onClick={onClose} disabled={invite.isPending}>Cancel</Button>
        <Button type="submit" form="invite-form" variant="contained" disabled={invite.isPending || !email.trim()}>{invite.isPending ? 'Sending…' : 'Send invitation'}</Button>
      </DialogActions>
    </Dialog>
  );
}

function AssignDialog({ member, clients, onClose, onDone }: { member: StaffMember | null; clients: Array<{ id: string; name: string }>; onClose: () => void; onDone: () => void }) {
  const toast = useToast();
  const [clientId, setClientId] = useState('');
  const [role, setRole] = useState<ClientRole>('sales_agent');
  const [error, setError] = useState<string | null>(null);
  const assign = useMutation({
    mutationFn: () => agencyApi.assign(clientId, member!.userId, role),
    onSuccess: () => { toast.success(`${member!.name} can now work on this client`); setClientId(''); onDone(); onClose(); },
    onError: (e) => setError(e instanceof ApiProblem ? e.detail : 'Couldn’t assign. Please try again.'),
  });
  const hint = CLIENT_ROLE_OPTIONS.find((o) => o.value === role)?.hint;
  return (
    <Dialog open={Boolean(member)} onClose={assign.isPending ? undefined : onClose} fullWidth maxWidth="xs" aria-labelledby="assign-title">
      <DialogTitle id="assign-title">Assign {member?.name} to a client</DialogTitle>
      <DialogContent>
        <Stack component="form" id="assign-form" spacing={2} sx={{ pt: 0.5 }} onSubmit={(e) => { e.preventDefault(); setError(null); if (clientId) assign.mutate(); }}>
          {error && <Alert severity="error">{error}</Alert>}
          {clients.length === 0 ? <Alert severity="info">{member?.name} already works on every client.</Alert> : (
            <TextField select label="Client" value={clientId} onChange={(e) => setClientId(e.target.value)} autoFocus fullWidth>
              {clients.map((c) => <MenuItem key={c.id} value={c.id}>{c.name}</MenuItem>)}
            </TextField>
          )}
          <TextField select label="What can they do there?" value={role} onChange={(e) => setRole(e.target.value as ClientRole)} helperText={hint} fullWidth>
            {CLIENT_ROLE_OPTIONS.map((o) => <MenuItem key={o.value} value={o.value}>{o.label}</MenuItem>)}
          </TextField>
        </Stack>
      </DialogContent>
      <DialogActions>
        <Button onClick={onClose} disabled={assign.isPending}>Cancel</Button>
        <Button type="submit" form="assign-form" variant="contained" disabled={assign.isPending || !clientId}>{assign.isPending ? 'Assigning…' : 'Assign'}</Button>
      </DialogActions>
    </Dialog>
  );
}
