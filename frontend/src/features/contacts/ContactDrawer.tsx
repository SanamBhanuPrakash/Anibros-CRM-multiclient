import { useState } from 'react';
import {
  Autocomplete, Box, Button, Chip, CircularProgress, Dialog, DialogActions, DialogContent, DialogContentText, DialogTitle, Divider, Drawer, IconButton,
  ListItemIcon, ListItemText, Menu, MenuItem, Skeleton, Stack, Switch, TextField, Tooltip, Typography,
} from '@mui/material';
import CloseRoundedIcon from '@mui/icons-material/CloseRounded';
import EditOutlinedIcon from '@mui/icons-material/EditOutlined';
import MoreHorizRoundedIcon from '@mui/icons-material/MoreHorizRounded';
import CallMergeRoundedIcon from '@mui/icons-material/CallMergeRounded';
import ArchiveOutlinedIcon from '@mui/icons-material/ArchiveOutlined';
import MailOutlineRoundedIcon from '@mui/icons-material/MailOutlineRounded';
import PhoneOutlinedIcon from '@mui/icons-material/PhoneOutlined';
import BusinessOutlinedIcon from '@mui/icons-material/BusinessOutlined';
import PlaceOutlinedIcon from '@mui/icons-material/PlaceOutlined';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { ApiProblem } from '@/api/client';
import { contactsApi, membersApi, type ConsentChannel, type ConsentState, type Contact } from '@/api/endpoints';
import { useAuth } from '@/features/auth/AuthProvider';
import { CopyButton, RelativeTime, UserAvatar } from '@/ui/display';
import { ErrorState } from '@/ui/states';
import { useConfirm } from '@/ui/confirm';
import { useToast } from '@/ui/toast';
import { ContactFormDialog } from './ContactFormDialog';

const CHANNELS: Array<{ id: ConsentChannel; label: string; help: string }> = [
  { id: 'whatsapp', label: 'WhatsApp', help: 'Messages and templates' },
  { id: 'sms', label: 'SMS', help: 'Text messages' },
  { id: 'email', label: 'Email', help: 'Marketing and follow-ups' },
  { id: 'phone_call', label: 'Phone calls', help: 'Outbound calls' },
];

export function ContactDrawer({ contactId, onClose, onOpenOther }: { contactId: string | null; onClose: () => void; onOpenOther: (id: string) => void }) {
  const q = useQuery({ queryKey: ['contacts', 'detail', contactId], queryFn: () => contactsApi.get(contactId!), enabled: Boolean(contactId) });
  return (
    <Drawer anchor="right" open={Boolean(contactId)} onClose={onClose} slotProps={{ paper: { sx: { width: { xs: '100%', sm: 460 }, maxWidth: '100%' }, 'aria-label': 'Contact details' } }}>
      {q.isPending && contactId && (
        <Stack spacing={2} sx={{ p: 3 }} aria-busy="true">
          <Skeleton variant="rounded" height={48} />
          <Skeleton variant="rounded" height={120} />
          <Skeleton variant="rounded" height={160} />
        </Stack>
      )}
      {q.isError && (
        <Stack spacing={2} sx={{ p: 3 }}>
          <ErrorState error={q.error} onRetry={() => void q.refetch()} title={q.error instanceof ApiProblem && q.error.status === 404 ? 'This contact no longer exists' : 'We couldn’t load this contact'} />
          <Button onClick={onClose}>Close</Button>
        </Stack>
      )}
      {q.data && <DrawerBody contact={q.data} onClose={onClose} onOpenOther={onOpenOther} />}
    </Drawer>
  );
}

function DrawerBody({ contact: c, onClose, onOpenOther }: { contact: Contact; onClose: () => void; onOpenOther: (id: string) => void }) {
  const qc = useQueryClient();
  const toast = useToast();
  const confirm = useConfirm();
  const { can, scope, me } = useAuth();
  // Agents may only edit what they own; the API enforces it, the UI just doesn't offer a dead end.
  const canEdit = scope('contacts:update') === 'all' || (scope('contacts:update') === 'own' && c.ownerId === me?.user.id);
  const [editing, setEditing] = useState(false);
  const [menu, setMenu] = useState<HTMLElement | null>(null);
  const [mergeOpen, setMergeOpen] = useState(false);
  const owner = useQuery({ queryKey: ['members', 'directory'], queryFn: membersApi.directory, staleTime: 5 * 60_000 });
  const ownerName = owner.data?.find((m) => m.userId === c.ownerId)?.name;

  const archive = useMutation({
    mutationFn: () => contactsApi.remove(c.id),
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: ['contacts'] });
      onClose();
      toast.success(`${c.displayName} archived`, {
        label: 'Undo',
        onClick: () => void contactsApi.restore(c.id).then(() => qc.invalidateQueries({ queryKey: ['contacts'] })).catch((e) => toast.error(e instanceof ApiProblem ? e.detail : 'Couldn’t restore the contact')),
      });
    },
    onError: (e) => toast.error(e instanceof ApiProblem ? e.detail : 'Couldn’t archive the contact'),
  });

  return (
    <>
      <Box component="header" sx={{ p: 2.5, pb: 2, display: 'flex', gap: 1.5, alignItems: 'flex-start' }}>
        <UserAvatar name={c.displayName} seed={c.id} size={44} />
        <Box sx={{ flex: 1, minWidth: 0 }}>
          <Typography variant="h3" component="h2" sx={{ wordBreak: 'break-word' }}>{c.displayName}</Typography>
          {(c.jobTitle || c.company) && <Typography variant="body2" color="text.secondary">{[c.jobTitle, c.company].filter(Boolean).join(' · ')}</Typography>}
        </Box>
        {canEdit && <Tooltip title="Edit"><IconButton aria-label="Edit contact" onClick={() => setEditing(true)}><EditOutlinedIcon fontSize="small" /></IconButton></Tooltip>}
        {(can('contacts:merge') || can('contacts:delete')) && (
          <>
            <IconButton aria-label="More actions" onClick={(e) => setMenu(e.currentTarget)}><MoreHorizRoundedIcon fontSize="small" /></IconButton>
            <Menu anchorEl={menu} open={Boolean(menu)} onClose={() => setMenu(null)}>
              {can('contacts:merge') && (
                <MenuItem onClick={() => { setMenu(null); setMergeOpen(true); }}>
                  <ListItemIcon><CallMergeRoundedIcon fontSize="small" /></ListItemIcon>
                  <ListItemText>Merge duplicates into this contact…</ListItemText>
                </MenuItem>
              )}
              {can('contacts:delete') && (
                <MenuItem
                  sx={{ color: 'error.main' }}
                  onClick={async () => {
                    setMenu(null);
                    if (await confirm({ title: `Archive ${c.displayName}?`, body: 'They disappear from lists, and their email and phone number can be used for a new contact. You can undo this right after, or restore them later.', confirmLabel: 'Archive', destructive: true })) archive.mutate();
                  }}
                >
                  <ListItemIcon><ArchiveOutlinedIcon fontSize="small" color="error" /></ListItemIcon>
                  <ListItemText>Archive</ListItemText>
                </MenuItem>
              )}
            </Menu>
          </>
        )}
        <IconButton aria-label="Close" edge="end" onClick={onClose}><CloseRoundedIcon /></IconButton>
      </Box>
      <Divider />
      <Box sx={{ p: 2.5, overflowY: 'auto' }}>
        <Stack spacing={2.5}>
          <Stack spacing={1.25}>
            {[c.email, ...c.alternateEmails].filter(Boolean).map((v, i) => (
              <Row key={v} icon={<MailOutlineRoundedIcon fontSize="small" />} label={i === 0 ? 'Email' : 'Other email'} value={v!} href={`mailto:${v}`} />
            ))}
            {[c.phone, ...c.alternatePhones].filter(Boolean).map((v, i) => (
              <Row key={v} icon={<PhoneOutlinedIcon fontSize="small" />} label={i === 0 ? 'Phone' : 'Other phone'} value={v!} href={`tel:${v}`} />
            ))}
            {c.company && <Row icon={<BusinessOutlinedIcon fontSize="small" />} label="Company" value={c.company} />}
            {c.city && <Row icon={<PlaceOutlinedIcon fontSize="small" />} label="City" value={c.city} />}
            {!c.email && !c.phone && <Typography variant="body2" color="text.secondary">No email or phone yet. Add one so this person can be reached and de-duplicated.</Typography>}
          </Stack>

          {c.tags.length > 0 && <Stack direction="row" sx={{ flexWrap: 'wrap', gap: 0.75 }}>{c.tags.map((t) => <Chip key={t} size="small" label={t} variant="outlined" />)}</Stack>}

          <Box component="dl" sx={{ m: 0, display: 'grid', gridTemplateColumns: 'auto 1fr', columnGap: 3, rowGap: 0.75, '& dt': { color: 'text.secondary', typography: 'body2' }, '& dd': { m: 0, typography: 'body2' } }}>
            <dt>Owner</dt><dd>{ownerName ?? (c.ownerId ? '…' : 'Unassigned')}</dd>
            <dt>Leads</dt><dd>{c.leadCount}</dd>
            <dt>Added</dt><dd><RelativeTime value={c.createdAt} /></dd>
            <dt>Updated</dt><dd><RelativeTime value={c.updatedAt} /></dd>
          </Box>

          <Divider />
          <ConsentPanel contactId={c.id} canEdit={canEdit} />
        </Stack>
      </Box>

      <ContactFormDialog open={editing} contact={c} onClose={() => setEditing(false)} onSaved={() => { setEditing(false); void qc.invalidateQueries({ queryKey: ['contacts'] }); }} onOpenExisting={onOpenOther} />
      <MergeDialog open={mergeOpen} survivor={c} onClose={() => setMergeOpen(false)} />
    </>
  );
}

function Row({ icon, label, value, href }: { icon: React.ReactNode; label: string; value: string; href?: string }) {
  return (
    <Stack direction="row" spacing={1.5} sx={{ alignItems: 'center' }}>
      <Box sx={{ color: 'text.secondary', display: 'flex' }} aria-hidden>{icon}</Box>
      <Box sx={{ flex: 1, minWidth: 0 }}>
        <Typography variant="caption" color="text.secondary" component="div">{label}</Typography>
        <Typography variant="body2" component={href ? 'a' : 'div'} href={href} sx={{ color: 'text.primary', textDecoration: 'none', '&:hover': href ? { textDecoration: 'underline' } : undefined, wordBreak: 'break-all' }}>{value}</Typography>
      </Box>
      <CopyButton value={value} label={`Copy ${label.toLowerCase()}`} />
    </Stack>
  );
}

function ConsentPanel({ contactId, canEdit }: { contactId: string; canEdit: boolean }) {
  const qc = useQueryClient();
  const toast = useToast();
  const record = useMutation({
    mutationFn: (v: { channel: ConsentChannel; grant: boolean }) => contactsApi.recordConsent(contactId, { channel: v.channel, action: v.grant ? 'grant' : 'revoke', reason: v.grant ? undefined : 'Withdrawn in the CRM' }),
    // Optimistic: the switch moves immediately; the server's answer replaces it, a failure puts it back.
    onMutate: async (v) => {
      const key = ['contacts', 'consents', contactId];
      // Must be awaited: a cancelled in-flight refetch restores its pre-fetch data asynchronously and would otherwise
      // overwrite the optimistic value we set below.
      await qc.cancelQueries({ queryKey: key });
      const prev = qc.getQueryData<ConsentState>(key);
      if (prev) qc.setQueryData<ConsentState>(key, { ...prev, current: prev.current.map((c) => (c.channel === v.channel ? { ...c, granted: v.grant, source: v.grant ? 'manual' : c.source, since: v.grant ? new Date().toISOString() : c.since } : c)) });
      return { prev };
    },
    onSuccess: (data) => qc.setQueryData(['contacts', 'consents', contactId], data),
    onError: (e, _v, ctx) => {
      if (ctx?.prev) qc.setQueryData(['contacts', 'consents', contactId], ctx.prev);
      toast.error(e instanceof ApiProblem ? e.detail : 'Couldn’t update consent');
    },
  });
  // Paused while a toggle is in flight: a realtime-triggered refetch that read the old state must not overwrite the optimistic one.
  const q = useQuery({ queryKey: ['contacts', 'consents', contactId], queryFn: () => contactsApi.consents(contactId), enabled: !record.isPending });
  return (
    <Box component="section" aria-labelledby="consent-title">
      <Typography id="consent-title" variant="h5" component="h3">Communication consent</Typography>
      <Typography variant="body2" color="text.secondary" sx={{ mb: 0.5 }}>Has this person said it is OK to contact them on each channel? Switch one on when they agreed (they ticked a box, or said yes on a call). Switch it off if they ask you to stop: the CRM then will not send them messages on that channel.</Typography>
      <Typography variant="caption" color="text.secondary" component="p" sx={{ mb: 1 }}>Off with no history just means nobody has recorded it yet: replying to their own enquiry is fine. Every change is saved with who made it and when.</Typography>
      {q.isPending && <Skeleton variant="rounded" height={120} />}
      {q.isError && <ErrorState error={q.error} onRetry={() => void q.refetch()} />}
      {q.data && (
        <Stack divider={<Divider flexItem />}>
          {CHANNELS.map((ch) => {
            const state = q.data.current.find((s) => s.channel === ch.id);
            return (
              <Stack key={ch.id} direction="row" sx={{ alignItems: 'center', py: 0.75 }}>
                <Box sx={{ flex: 1 }}>
                  <Typography variant="subtitle2" id={`consent-${ch.id}`}>{ch.label}</Typography>
                  <Typography variant="caption" color="text.secondary">{state?.granted ? <>Agreed via {state.source} · <RelativeTime value={state.since} /></> : ch.help}</Typography>
                </Box>
                {record.isPending && record.variables?.channel === ch.id && <CircularProgress size={16} sx={{ mr: 1 }} />}
                <Switch edge="end" checked={Boolean(state?.granted)} disabled={!canEdit || (record.isPending && record.variables?.channel === ch.id)} onChange={(_, grant) => record.mutate({ channel: ch.id, grant })} slotProps={{ input: { 'aria-labelledby': `consent-${ch.id}` } }} />
              </Stack>
            );
          })}
        </Stack>
      )}
    </Box>
  );
}

function MergeDialog({ open, survivor, onClose }: { open: boolean; survivor: Contact; onClose: () => void }) {
  const qc = useQueryClient();
  const toast = useToast();
  const [text, setText] = useState('');
  const [picked, setPicked] = useState<Contact[]>([]);
  const [error, setError] = useState<string | null>(null);
  const search = useQuery({
    queryKey: ['contacts', 'merge-search', text],
    queryFn: async () => (await contactsApi.list({ q: text, sort: 'name', order: 'asc' })).data,
    enabled: open && text.trim().length >= 2,
    staleTime: 10_000,
  });
  const options = (search.data ?? []).filter((o) => o.id !== survivor.id);
  const close = () => { setText(''); setPicked([]); setError(null); onClose(); };

  const merge = useMutation({
    mutationFn: () => contactsApi.merge(survivor.id, picked.map((p) => p.id)),
    onSuccess: (r) => {
      void qc.invalidateQueries({ queryKey: ['contacts'] });
      close();
      toast.success(`Merged ${r.merges.length} contact${r.merges.length === 1 ? '' : 's'} into ${survivor.displayName}`, {
        label: 'Undo',
        onClick: () => void Promise.all(r.merges.map((m) => contactsApi.undoMerge(m.id))).then(() => qc.invalidateQueries({ queryKey: ['contacts'] })).catch((e) => toast.error(e instanceof ApiProblem ? e.detail : 'Couldn’t undo the merge')),
      });
    },
    onError: (e) => setError(e instanceof ApiProblem ? e.detail : 'Couldn’t merge these contacts'),
  });

  return (
    <Dialog open={open} onClose={merge.isPending ? undefined : close} fullWidth maxWidth="sm" aria-labelledby="merge-title">
      <DialogTitle id="merge-title">Merge duplicates into {survivor.displayName}</DialogTitle>
      <DialogContent>
        <DialogContentText sx={{ mb: 2 }}>
          {survivor.displayName} stays. Their blank fields are filled from the contacts you pick, and those contacts’ emails, phone numbers, leads and consent records move here. The others are archived. You can undo this for 30 days.
        </DialogContentText>
        <Autocomplete
          multiple
          options={options}
          loading={search.isFetching}
          value={picked}
          onChange={(_, v) => setPicked(v)}
          inputValue={text}
          onInputChange={(_, v, reason) => reason !== 'reset' && setText(v)}
          filterOptions={(o) => o}
          getOptionLabel={(o) => o.displayName}
          isOptionEqualToValue={(a, b) => a.id === b.id}
          noOptionsText={text.trim().length < 2 ? 'Type a name, email or phone number' : 'No matching contacts'}
          renderOption={(props, o) => (
            <li {...props} key={o.id}>
              <Box>
                <Typography variant="body2">{o.displayName}</Typography>
                <Typography variant="caption" color="text.secondary">{[o.email, o.phone, o.company].filter(Boolean).join(' · ') || 'No contact details'}</Typography>
              </Box>
            </li>
          )}
          renderInput={(p) => <TextField {...p} label="Duplicates to merge" autoFocus error={Boolean(error)} helperText={error ?? 'Pick up to 5'} />}
        />
      </DialogContent>
      <DialogActions>
        <Button onClick={close} disabled={merge.isPending}>Cancel</Button>
        <Button variant="contained" disabled={picked.length === 0 || picked.length > 5 || merge.isPending} onClick={() => { setError(null); merge.mutate(); }}>
          {merge.isPending ? 'Merging…' : `Merge ${picked.length || ''} contact${picked.length === 1 ? '' : 's'}`.trim()}
        </Button>
      </DialogActions>
    </Dialog>
  );
}
