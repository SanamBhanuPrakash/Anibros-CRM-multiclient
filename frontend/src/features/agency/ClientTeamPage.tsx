import { useState } from 'react';
import { Alert, Box, Button, Chip, Dialog, DialogActions, DialogContent, DialogTitle, IconButton, MenuItem, Paper, Stack, Table, TableBody, TableCell, TableContainer, TableHead, TableRow, TextField, Tooltip, Typography } from '@mui/material';
import PersonAddAlt1RoundedIcon from '@mui/icons-material/PersonAddAlt1Rounded';
import CloseRoundedIcon from '@mui/icons-material/CloseRounded';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { ApiProblem } from '@/api/client';
import { agencyApi, type ClientRole } from '@/api/agency';
import { membersApi } from '@/api/endpoints';
import { useAuth } from '@/features/auth/AuthProvider';
import { PageHeader } from '@/ui/PageHeader';
import { ErrorState, SkeletonRows } from '@/ui/states';
import { UserAvatar } from '@/ui/display';
import { useConfirm } from '@/ui/confirm';
import { useToast } from '@/ui/toast';
import { CLIENT_ROLE_OPTIONS, channelSummary, roleName, useAgencyStaff } from './shared';
import { ChannelPicker } from './ChannelPicker';

/** Who works on the open client, and what each person can do here. Agency admins change it; everyone else sees the team. */
export function ClientTeamPage() {
  const { scopeId, tenant, isAgencyAdmin } = useAuth();
  const id = scopeId ?? tenant?.id ?? '';
  const qc = useQueryClient();
  const toast = useToast();
  const confirm = useConfirm();
  const [adding, setAdding] = useState(false);
  const team = useQuery({ queryKey: ['agency', 'team', id], queryFn: () => agencyApi.team(id), enabled: isAgencyAdmin && Boolean(id) });
  const directory = useQuery({ queryKey: ['members', 'directory'], queryFn: membersApi.directory, enabled: !isAgencyAdmin });
  const refresh = () => { void qc.invalidateQueries({ queryKey: ['agency'] }); void qc.invalidateQueries({ queryKey: ['members'] }); };
  const fail = (e: unknown) => toast.error(e instanceof ApiProblem ? e.detail : 'That didn’t work. Please try again.');
  const setRole = useMutation({ mutationFn: ({ userId, role }: { userId: string; role: ClientRole }) => agencyApi.assign(id, userId, role), onSuccess: () => { refresh(); toast.success('Role updated'); }, onError: fail });
  const setChannels = useMutation({ mutationFn: ({ userId, role, channels }: { userId: string; role: ClientRole; channels: string[] | null }) => agencyApi.assign(id, userId, role, channels), onSuccess: () => { refresh(); toast.success('Saved'); }, onError: fail });
  const unassign = useMutation({
    mutationFn: (userId: string) => agencyApi.unassign(id, userId),
    onSuccess: (r) => { refresh(); toast.success(r.leadsUnassigned ? `Removed. ${r.leadsUnassigned} open lead${r.leadsUnassigned === 1 ? ' is' : 's are'} now not assigned.` : 'Removed'); },
    onError: fail,
  });

  return (
    <>
      <PageHeader
        title="Client team"
        description={`The people who work on ${tenant?.name ?? 'this client'}. A caller sees only the leads assigned to them; a team lead sees all of this client’s leads.`}
        actions={isAgencyAdmin && <Button variant="contained" startIcon={<PersonAddAlt1RoundedIcon />} onClick={() => setAdding(true)}>Add employee</Button>}
      />
      {!isAgencyAdmin ? (
        directory.isPending ? <SkeletonRows rows={4} /> : directory.isError ? <ErrorState error={directory.error} onRetry={() => void directory.refetch()} /> : (
          <Paper variant="outlined" sx={{ borderRadius: 2 }}>
            {(directory.data ?? []).map((m) => (
              <Stack key={m.userId} direction="row" spacing={1.5} sx={{ px: 2, py: 1.25, alignItems: 'center', borderBottom: 1, borderColor: 'divider', '&:last-child': { borderBottom: 0 } }}>
                <UserAvatar name={m.name} seed={m.userId} size={30} /><Typography variant="body2" sx={{ flex: 1 }}>{m.name}</Typography><Chip size="small" variant="outlined" label={roleName(m.role)} />
              </Stack>
            ))}
          </Paper>
        )
      ) : team.isPending ? (
        <SkeletonRows rows={4} height={56} />
      ) : team.isError ? (
        <ErrorState error={team.error} onRetry={() => void team.refetch()} />
      ) : (
        <TableContainer component={Paper} variant="outlined" sx={{ borderRadius: 2 }}>
          <Table aria-label="Client team">
            <TableHead><TableRow><TableCell>Person</TableCell><TableCell>What they can do here</TableCell><TableCell>What they handle</TableCell><TableCell padding="checkbox"><span style={{ position: 'absolute', left: -9999 }}>Remove</span></TableCell></TableRow></TableHead>
            <TableBody>
              {team.data.map((m) => (
                <TableRow key={m.userId} sx={{ '&:last-child td': { borderBottom: 0 } }}>
                  <TableCell>
                    <Stack direction="row" spacing={1.25} sx={{ alignItems: 'center' }}>
                      <UserAvatar name={m.name} seed={m.userId} size={30} />
                      <Box sx={{ minWidth: 0 }}><Typography variant="subtitle2" noWrap>{m.name}</Typography><Typography variant="caption" color="text.secondary" noWrap component="div">{m.email}</Typography></Box>
                    </Stack>
                  </TableCell>
                  <TableCell>
                    {m.isAgencyAdmin ? (
                      <Chip size="small" color="primary" label={roleName(m.clientRole)} />
                    ) : (
                      <TextField select size="small" value={m.clientRole} onChange={(e) => setRole.mutate({ userId: m.userId, role: e.target.value as ClientRole })} sx={{ width: 220 }} slotProps={{ htmlInput: { 'aria-label': `What ${m.name} can do` } }}>
                        {CLIENT_ROLE_OPTIONS.map((o) => <MenuItem key={o.value} value={o.value}>{o.label}</MenuItem>)}
                      </TextField>
                    )}
                  </TableCell>
                  <TableCell sx={{ minWidth: 300 }}>
                    {m.isAgencyAdmin ? <Typography variant="body2" color="text.secondary">Everything</Typography> : <ChannelPicker value={m.workChannels} onChange={(channels) => setChannels.mutate({ userId: m.userId, role: m.clientRole as ClientRole, channels })} />}
                  </TableCell>
                  <TableCell padding="checkbox">
                    {!m.isAgencyAdmin && (
                      <Tooltip title="Take off this client">
                        <IconButton aria-label={`Take ${m.name} off this client`} onClick={async () => { if (await confirm({ title: `Take ${m.name} off this client?`, body: 'They lose access straight away. Their open leads here become “not assigned”.', confirmLabel: 'Take off', destructive: true })) unassign.mutate(m.userId); }}><CloseRoundedIcon fontSize="small" /></IconButton>
                      </Tooltip>
                    )}
                  </TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        </TableContainer>
      )}
      <AddToClientDialog open={adding} clientId={id} existing={new Set((team.data ?? []).map((m) => m.userId))} onClose={() => setAdding(false)} onDone={refresh} />
    </>
  );
}

function AddToClientDialog({ open, clientId, existing, onClose, onDone }: { open: boolean; clientId: string; existing: Set<string>; onClose: () => void; onDone: () => void }) {
  const toast = useToast();
  const staff = useAgencyStaff(open);
  const [userId, setUserId] = useState('');
  const [role, setRole] = useState<ClientRole>('sales_agent');
  const [channels, setChannels] = useState<string[] | null>(null);
  const [error, setError] = useState<string | null>(null);
  const choices = (staff.data?.staff ?? []).filter((s) => s.agencyRole === 'staff' && !existing.has(s.userId));
  const add = useMutation({
    mutationFn: () => agencyApi.assign(clientId, userId, role, channels),
    onSuccess: () => { toast.success('Added to this client'); setUserId(''); setChannels(null); onDone(); onClose(); },
    onError: (e) => setError(e instanceof ApiProblem ? e.detail : 'Couldn’t add. Please try again.'),
  });
  return (
    <Dialog open={open} onClose={add.isPending ? undefined : onClose} fullWidth maxWidth="xs" aria-labelledby="add-to-client-title">
      <DialogTitle id="add-to-client-title">Add an employee to this client</DialogTitle>
      <DialogContent>
        <Stack component="form" id="add-to-client-form" spacing={2} sx={{ pt: 0.5 }} onSubmit={(e) => { e.preventDefault(); setError(null); if (userId) add.mutate(); }}>
          {error && <Alert severity="error">{error}</Alert>}
          {choices.length === 0 ? <Alert severity="info">Everyone on your team already works on this client. Invite more people under <b>Team</b> on the agency page.</Alert> : (
            <TextField select autoFocus label="Employee" value={userId} onChange={(e) => setUserId(e.target.value)} fullWidth>
              {choices.map((s) => <MenuItem key={s.userId} value={s.userId}>{s.name} <Typography component="span" variant="caption" color="text.secondary" sx={{ ml: 1 }}>{s.email}</Typography></MenuItem>)}
            </TextField>
          )}
          <TextField select label="What can they do here?" value={role} onChange={(e) => setRole(e.target.value as ClientRole)} helperText={CLIENT_ROLE_OPTIONS.find((o) => o.value === role)?.hint} fullWidth>
            {CLIENT_ROLE_OPTIONS.map((o) => <MenuItem key={o.value} value={o.value}>{o.label}</MenuItem>)}
          </TextField>
          <div>
            <Typography variant="body2" color="text.secondary" sx={{ mb: 0.75 }}>What do they handle? <b>{channelSummary(channels)}</b></Typography>
            <ChannelPicker value={channels} onChange={setChannels} />
          </div>
        </Stack>
      </DialogContent>
      <DialogActions>
        <Button onClick={onClose} disabled={add.isPending}>Cancel</Button>
        <Button type="submit" form="add-to-client-form" variant="contained" disabled={add.isPending || !userId}>{add.isPending ? 'Adding…' : 'Add'}</Button>
      </DialogActions>
    </Dialog>
  );
}
