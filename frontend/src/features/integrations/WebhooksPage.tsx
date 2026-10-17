import { useMemo, useState } from 'react';
import {
  Alert, Box, Button, Checkbox, Chip, Dialog, DialogActions, DialogContent, DialogContentText, DialogTitle, FormControlLabel, IconButton, Menu, MenuItem, Paper,
  Stack, Table, TableBody, TableCell, TableContainer, TableHead, TableRow, TextField, Tooltip, Typography,
} from '@mui/material';
import AddRoundedIcon from '@mui/icons-material/AddRounded';
import MoreVertRoundedIcon from '@mui/icons-material/MoreVertRounded';
import SendRoundedIcon from '@mui/icons-material/SendRounded';
import WebhookRoundedIcon from '@mui/icons-material/WebhookRounded';
import { useInfiniteQuery, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { ApiProblem } from '@/api/client';
import { webhooksApi, type Webhook, type WebhookDeliveryStatus, type WebhookEvent } from '@/api/outbound';
import { useAuth } from '@/features/auth/AuthProvider';
import { CopyButton } from '@/ui/display';
import { PageHeader } from '@/ui/PageHeader';
import { EmptyState, ErrorState, SkeletonRows } from '@/ui/states';
import { useConfirm } from '@/ui/confirm';
import { useToast } from '@/ui/toast';
import { Card, Pill, When } from '@/ui/kit';
import { downloadText, webhookHandoff } from './handoff';

const PRESETS: Array<{ label: string; hint: string; events: WebhookEvent[] }> = [
  { label: 'Send me every new lead', hint: 'Just lead.created', events: ['lead.created'] },
  { label: 'Follow the whole journey', hint: 'New, assigned, moved and won', events: ['lead.created', 'lead.assigned', 'lead.stage_changed'] },
  { label: 'Everything the team does', hint: 'Plus calls, messages and tasks', events: ['lead.created', 'lead.assigned', 'lead.stage_changed', 'lead.updated', 'activity.created', 'task.created', 'task.completed'] },
];
const EVENT_LABEL: Record<WebhookEvent, string> = {
  'lead.created': 'New lead', 'lead.assigned': 'Lead assigned', 'lead.stage_changed': 'Stage changed', 'lead.updated': 'Lead edited', 'lead.deleted': 'Lead archived',
  'activity.created': 'Call or message logged', 'task.created': 'Task created', 'task.completed': 'Task done', 'contact.created': 'Contact added',
};

/** "Send data out": a signed message to the client's own system the moment something happens. The mirror image of Lead sources. */
export function WebhooksPage() {
  const { tenant } = useAuth();
  const list = useQuery({ queryKey: ['webhooks'], queryFn: webhooksApi.list, refetchInterval: 30_000 });
  const [editing, setEditing] = useState<{ webhook: Webhook | null; preset?: WebhookEvent[] } | null>(null);
  const [revealed, setRevealed] = useState<{ webhook: Webhook; secret: string } | null>(null);
  const [filter, setFilter] = useState<string>('');
  return (
    <>
      <PageHeader
        title="Send data out"
        description={`Tell another system the moment something happens for ${tenant?.name ?? 'this client'}. Each webhook sends signed messages to an address you choose.`}
        actions={<Button variant="contained" startIcon={<AddRoundedIcon />} onClick={() => setEditing({ webhook: null })}>Add webhook</Button>}
      />
      {list.isPending ? (
        <SkeletonRows rows={3} height={110} />
      ) : list.isError ? (
        <ErrorState error={list.error} onRetry={() => void list.refetch()} />
      ) : list.data.length === 0 ? (
        <Card pad={3}>
          <EmptyState
            icon={<WebhookRoundedIcon />}
            title="Nothing is sent out yet"
            description="Give us an address and pick what to tell it. It is how a CRM, a spreadsheet, Zapier or your own software finds out about new leads without asking."
          />
          <Box sx={{ display: 'grid', gap: 1.5, gridTemplateColumns: { xs: '1fr', md: 'repeat(3, 1fr)' }, mt: 1 }}>
            {PRESETS.map((p) => (
              <Paper key={p.label} variant="outlined" onClick={() => setEditing({ webhook: null, preset: p.events })} sx={{ p: 2, cursor: 'pointer', '&:hover': { bgcolor: 'surface.hover' } }}>
                <Typography variant="subtitle2">{p.label}</Typography>
                <Typography variant="caption" color="text.secondary">{p.hint}</Typography>
              </Paper>
            ))}
          </Box>
        </Card>
      ) : (
        <Stack spacing={2}>
          {list.data.map((w) => <WebhookCard key={w.id} w={w} onEdit={() => setEditing({ webhook: w })} onSecret={(s) => setRevealed({ webhook: w, secret: s })} onFilter={() => setFilter(w.id)} />)}
          <Deliveries hooks={list.data} endpointId={filter} setEndpointId={setFilter} />
        </Stack>
      )}
      <EditDialog state={editing} onClose={() => setEditing(null)} onCreated={(webhook, secret) => { setEditing(null); setRevealed({ webhook, secret }); }} />
      <SecretDialog revealed={revealed} clientName={tenant?.name ?? 'the client'} onClose={() => setRevealed(null)} />
    </>
  );
}

function statusPill(w: Webhook) {
  if (w.status === 'disabled') return <Pill tone="danger" title={w.disabledReason ?? undefined}>Switched off</Pill>;
  if (w.status === 'paused') return <Pill>Paused</Pill>;
  if (w.stats.failed > 0 && w.stats.delivered === 0) return <Pill tone="warning">Failing</Pill>;
  return <Pill tone="success">Live</Pill>;
}

function WebhookCard({ w, onEdit, onSecret, onFilter }: { w: Webhook; onEdit: () => void; onSecret: (s: string) => void; onFilter: () => void }) {
  const qc = useQueryClient();
  const toast = useToast();
  const confirm = useConfirm();
  const [menu, setMenu] = useState<HTMLElement | null>(null);
  const refresh = () => void qc.invalidateQueries({ queryKey: ['webhooks'] });
  const fail = (e: unknown) => toast.error(e instanceof ApiProblem ? e.detail : 'That didn’t work. Please try again.');
  const test = useMutation({
    mutationFn: () => webhooksApi.test(w.id),
    onSuccess: (r) => { refresh(); void qc.invalidateQueries({ queryKey: ['webhooks', 'deliveries'] }); if (r.ok) toast.success(`The address answered ${r.status} in ${r.ms} ms. All good.`);
      else toast.error(r.error ?? 'The address did not accept the test message.');
    },
    onError: fail,
  });
  const toggle = useMutation({ mutationFn: () => webhooksApi.update(w.id, { status: w.status === 'active' ? 'paused' : 'active' }), onSuccess: refresh, onError: fail });
  const rotate = useMutation({ mutationFn: () => webhooksApi.rotate(w.id), onSuccess: (r) => { refresh(); onSecret(r.secret); }, onError: fail });
  const remove = useMutation({ mutationFn: () => webhooksApi.remove(w.id), onSuccess: () => { refresh(); toast.success('Webhook deleted'); }, onError: fail });
  return (
    <Card pad={2.25}>
      <Stack direction="row" spacing={2} sx={{ alignItems: 'flex-start', justifyContent: 'space-between' }}>
        <Box sx={{ minWidth: 0 }}>
          <Stack direction="row" spacing={1} sx={{ alignItems: 'center', flexWrap: 'wrap', rowGap: 0.5 }}>
            <Typography variant="h4" component="h2">{w.name}</Typography>
            {statusPill(w)}
          </Stack>
          <Typography variant="mono" color="text.secondary" sx={{ display: 'block', mt: 0.25, wordBreak: 'break-all' }}>{w.url}</Typography>
        </Box>
        <Stack direction="row" spacing={0.75} sx={{ flexShrink: 0 }}>
          <Button size="small" variant="outlined" color="inherit" startIcon={<SendRoundedIcon />} disabled={test.isPending} onClick={() => test.mutate()}>{test.isPending ? 'Sending…' : 'Send test'}</Button>
          <Tooltip title="More"><IconButton size="small" aria-label={`More for ${w.name}`} onClick={(e) => setMenu(e.currentTarget)}><MoreVertRoundedIcon fontSize="small" /></IconButton></Tooltip>
        </Stack>
      </Stack>
      {w.status === 'disabled' && <Alert severity="warning" sx={{ mt: 1.5 }} action={<Button color="inherit" size="small" onClick={() => toggle.mutate()}>Turn back on</Button>}>{w.disabledReason ?? 'This webhook was switched off after repeated failures.'}</Alert>}
      <Stack direction="row" sx={{ flexWrap: 'wrap', gap: 0.5, mt: 1.5 }}>
        {w.events.map((e) => <Chip key={e} size="small" variant="outlined" label={EVENT_LABEL[e] ?? e} />)}
      </Stack>
      <Box sx={{ display: 'flex', gap: 3, mt: 1.75, pt: 1.5, borderTop: 1, borderColor: 'divider', flexWrap: 'wrap' }}>
        <Fig label="Sent (7 days)" value={w.stats.delivered} />
        <Fig label="Failed" value={w.stats.failed} tone={w.stats.failed ? 'warning' : undefined} onClick={w.stats.failed ? onFilter : undefined} />
        <Fig label="Waiting" value={w.stats.pending} />
        <Box>
          <Typography variant="caption" color="text.secondary" component="div">Last message</Typography>
          <Typography variant="body2" component="div"><When value={w.lastDeliveryAt} fallback="None yet" />{w.lastStatus ? <Typography component="span" variant="caption" color="text.secondary"> · answered {w.lastStatus}</Typography> : null}</Typography>
        </Box>
      </Box>
      {w.lastError && w.status !== 'disabled' && <Typography variant="caption" sx={{ color: 'warning.dark', display: 'block', mt: 1 }}>Last problem: {w.lastError}</Typography>}
      <Menu anchorEl={menu} open={Boolean(menu)} onClose={() => setMenu(null)}>
        <MenuItem onClick={() => { setMenu(null); onEdit(); }}>Edit</MenuItem>
        {w.status !== 'disabled' && <MenuItem onClick={() => { setMenu(null); toggle.mutate(); }}>{w.status === 'active' ? 'Pause' : 'Resume'}</MenuItem>}
        <MenuItem onClick={async () => { setMenu(null); if (await confirm({ title: 'Replace the signing secret?', body: 'The old secret stops working at once. Whoever receives these messages must update it.', confirmLabel: 'Replace secret' })) rotate.mutate(); }}>Replace signing secret…</MenuItem>
        <MenuItem sx={{ color: 'error.main' }} onClick={async () => { setMenu(null); if (await confirm({ title: `Delete “${w.name}”?`, body: 'Messages stop immediately. The history stays for a while.', confirmLabel: 'Delete', destructive: true })) remove.mutate(); }}>Delete…</MenuItem>
      </Menu>
    </Card>
  );
}

function Fig({ label, value, tone, onClick }: { label: string; value: number; tone?: 'warning'; onClick?: () => void }) {
  return (
    <Box onClick={onClick} sx={{ cursor: onClick ? 'pointer' : undefined }}>
      <Typography variant="caption" color="text.secondary" component="div">{label}</Typography>
      <Typography variant="h3" component="div" sx={{ color: tone === 'warning' ? 'warning.dark' : 'text.primary', fontVariantNumeric: 'tabular-nums' }}>{value.toLocaleString()}</Typography>
    </Box>
  );
}

const DELIVERY_TONE: Record<WebhookDeliveryStatus, 'success' | 'warning' | 'danger' | 'neutral'> = { delivered: 'success', pending: 'neutral', failed: 'warning', dead: 'danger', skipped: 'neutral' };
const DELIVERY_LABEL: Record<WebhookDeliveryStatus, string> = { delivered: 'Delivered', pending: 'Waiting', failed: 'Retrying', dead: 'Gave up', skipped: 'Skipped' };

function Deliveries({ hooks, endpointId, setEndpointId }: { hooks: Webhook[]; endpointId: string; setEndpointId: (v: string) => void }) {
  const qc = useQueryClient();
  const toast = useToast();
  const [status, setStatus] = useState<WebhookDeliveryStatus | ''>('');
  const filters = { ...(status ? { status } : {}), ...(endpointId ? { endpointId } : {}) };
  const log = useInfiniteQuery({
    queryKey: ['webhooks', 'deliveries', filters],
    queryFn: ({ pageParam }) => webhooksApi.deliveries(filters, pageParam),
    initialPageParam: undefined as string | undefined,
    getNextPageParam: (l) => l.page?.next ?? undefined,
    placeholderData: (p) => p,
    refetchInterval: 15_000,
  });
  const rows = useMemo(() => log.data?.pages.flatMap((p) => p.data) ?? [], [log.data]);
  const retry = useMutation({
    mutationFn: webhooksApi.retry,
    onSuccess: () => { void qc.invalidateQueries({ queryKey: ['webhooks'] }); toast.success('Back in the queue. It will be sent in a moment.'); },
    onError: (e) => toast.error(e instanceof ApiProblem ? e.detail : 'That didn’t work'),
  });
  return (
    <Card title="Messages sent" subtitle="Everything we sent or tried to send, newest first" pad={0}
      actions={
        <Stack direction="row" spacing={1}>
          <TextField select size="small" fullWidth={false} value={endpointId} onChange={(e) => setEndpointId(e.target.value)} sx={{ minWidth: 170 }} slotProps={{ select: { displayEmpty: true }, htmlInput: { 'aria-label': 'Webhook' } }}>
            <MenuItem value="">All webhooks</MenuItem>
            {hooks.map((h) => <MenuItem key={h.id} value={h.id}>{h.name}</MenuItem>)}
          </TextField>
          <TextField select size="small" fullWidth={false} value={status} onChange={(e) => setStatus(e.target.value as WebhookDeliveryStatus | '')} sx={{ minWidth: 150 }} slotProps={{ select: { displayEmpty: true }, htmlInput: { 'aria-label': 'Outcome' } }}>
            <MenuItem value="">Any outcome</MenuItem>
            {(Object.keys(DELIVERY_LABEL) as WebhookDeliveryStatus[]).map((s) => <MenuItem key={s} value={s}>{DELIVERY_LABEL[s]}</MenuItem>)}
          </TextField>
        </Stack>
      }>
      {log.isPending ? <Box sx={{ p: 2 }}><SkeletonRows rows={3} height={32} /></Box> : rows.length === 0 ? (
        <Typography variant="body2" color="text.secondary" sx={{ p: 2 }}>Nothing yet. Press “Send test” on a webhook to see how this works.</Typography>
      ) : (
        <TableContainer>
          <Table size="small" aria-label="Messages sent">
            <TableHead>
              <TableRow>
                <TableCell>When</TableCell>
                <TableCell>Webhook</TableCell>
                <TableCell>Event</TableCell>
                <TableCell>Outcome</TableCell>
                <TableCell align="right">Answer</TableCell>
                <TableCell padding="checkbox" />
              </TableRow>
            </TableHead>
            <TableBody>
              {rows.map((d) => (
                <TableRow key={d.id} hover>
                  <TableCell><When value={d.createdAt} /></TableCell>
                  <TableCell>{d.endpointName}</TableCell>
                  <TableCell>{d.isTest ? <Pill>Test</Pill> : <Typography variant="mono">{d.event}</Typography>}</TableCell>
                  <TableCell>
                    <Tooltip title={d.error ?? ''}><span><Pill tone={DELIVERY_TONE[d.status]}>{DELIVERY_LABEL[d.status]}{d.attempts > 1 ? ` · ${d.attempts} tries` : ''}</Pill></span></Tooltip>
                    {d.error && d.status !== 'delivered' && <Typography variant="caption" color="text.secondary" component="div" sx={{ maxWidth: 360 }} noWrap>{d.error}</Typography>}
                  </TableCell>
                  <TableCell align="right">{d.responseStatus ? `${d.responseStatus}${d.responseMs !== null ? ` · ${d.responseMs} ms` : ''}` : '—'}</TableCell>
                  <TableCell padding="checkbox">{(d.status === 'failed' || d.status === 'dead') && !d.isTest && <Button size="small" onClick={() => retry.mutate(d.id)}>Send again</Button>}</TableCell>
                </TableRow>
              ))}
            </TableBody>
          </Table>
        </TableContainer>
      )}
      {log.hasNextPage && <Box sx={{ p: 1.5, textAlign: 'center' }}><Button size="small" color="inherit" onClick={() => void log.fetchNextPage()} disabled={log.isFetchingNextPage}>{log.isFetchingNextPage ? 'Loading…' : 'Show more'}</Button></Box>}
    </Card>
  );
}

function EditDialog({ state, onClose, onCreated }: { state: { webhook: Webhook | null; preset?: WebhookEvent[] } | null; onClose: () => void; onCreated: (w: Webhook, secret: string) => void }) {
  const qc = useQueryClient();
  const toast = useToast();
  const events = useQuery({ queryKey: ['webhooks', 'events'], queryFn: webhooksApi.events, enabled: Boolean(state), staleTime: 600_000 });
  const [name, setName] = useState('');
  const [url, setUrl] = useState('');
  const [picked, setPicked] = useState<WebhookEvent[]>([]);
  const [error, setError] = useState<string | null>(null);
  const [seeded, setSeeded] = useState<string | null>(null);
  const key = state ? (state.webhook?.id ?? `new:${(state.preset ?? []).join(',')}`) : null;
  if (key !== seeded) {
    setSeeded(key);
    if (state) {
      setName(state.webhook?.name ?? '');
      setUrl(state.webhook?.url ?? '');
      setPicked(state.webhook?.events ?? state.preset ?? ['lead.created']);
      setError(null);
    }
  }
  const save = useMutation({
    mutationFn: async () => {
      if (state?.webhook) return { webhook: await webhooksApi.update(state.webhook.id, { name: name.trim(), url: url.trim(), events: picked }), secret: null as string | null };
      return webhooksApi.create({ name: name.trim(), url: url.trim(), events: picked });
    },
    onSuccess: (r) => {
      void qc.invalidateQueries({ queryKey: ['webhooks'] });
      if (r.secret) onCreated(r.webhook, r.secret);
      else { toast.success('Saved'); onClose(); }
    },
    onError: (e) => setError(e instanceof ApiProblem ? (e.errors[0]?.message ?? e.detail) : 'That didn’t work. Please try again.'),
  });
  const toggle = (e: WebhookEvent) => setPicked((p) => (p.includes(e) ? p.filter((x) => x !== e) : [...p, e]));
  return (
    <Dialog open={Boolean(state)} onClose={onClose} maxWidth="sm" aria-labelledby="wh-title">
      <DialogTitle id="wh-title">{state?.webhook ? 'Edit webhook' : 'Add a webhook'}</DialogTitle>
      <DialogContent>
        <Stack spacing={2.25}>
          {error && <Alert severity="error">{error}</Alert>}
          <TextField label="Name" autoFocus value={name} onChange={(e) => setName(e.target.value)} placeholder="e.g. Clinic booking system" helperText="Only you see this." />
          <TextField label="Address to send to" value={url} onChange={(e) => setUrl(e.target.value)} placeholder="https://example.com/hooks/crm" helperText="A public https address that accepts POST. Private and internal addresses are refused." slotProps={{ htmlInput: { inputMode: 'url', autoCapitalize: 'off', spellCheck: false } }} />
          <Box>
            <Typography variant="subtitle2" sx={{ mb: 0.5 }}>What should we tell it about?</Typography>
            {!state?.webhook && (
              <Stack direction="row" sx={{ flexWrap: 'wrap', gap: 0.75, mb: 1 }}>
                {PRESETS.map((p) => <Chip key={p.label} size="small" variant="outlined" label={p.label} onClick={() => setPicked(p.events)} />)}
              </Stack>
            )}
            <Stack>
              {(events.data ?? []).map((e) => (
                <FormControlLabel key={e.event} control={<Checkbox size="small" checked={picked.includes(e.event)} onChange={() => toggle(e.event)} />} label={<><Typography variant="body2" component="span" sx={{ fontWeight: 500 }}>{EVENT_LABEL[e.event] ?? e.event}</Typography> <Typography variant="caption" color="text.secondary" component="span">{e.description}</Typography></>} />
              ))}
            </Stack>
          </Box>
        </Stack>
      </DialogContent>
      <DialogActions>
        <Button onClick={onClose}>Cancel</Button>
        <Button variant="contained" disabled={!name.trim() || !url.trim() || picked.length === 0 || save.isPending} onClick={() => (setError(null), save.mutate())}>{save.isPending ? 'Saving…' : state?.webhook ? 'Save changes' : 'Add webhook'}</Button>
      </DialogActions>
    </Dialog>
  );
}

function SecretDialog({ revealed, clientName, onClose }: { revealed: { webhook: Webhook; secret: string } | null; clientName: string; onClose: () => void }) {
  const toast = useToast();
  const [ok, setOk] = useState(false);
  const handoff = () => (revealed ? webhookHandoff(revealed.webhook, revealed.secret, clientName) : '');
  const close = () => { setOk(false); onClose(); };
  return (
    <Dialog open={Boolean(revealed)} onClose={(_, r) => { if (r !== 'backdropClick') close(); }} aria-labelledby="whs-title">
      <DialogTitle id="whs-title">Copy the signing secret</DialogTitle>
      <DialogContent>
        <Stack spacing={2}>
          <DialogContentText>Whoever receives the messages uses this secret to prove each one came from us. It is shown once.</DialogContentText>
          <Box sx={{ display: 'flex', alignItems: 'center', gap: 1, p: 1.5, bgcolor: 'surface.sunken', borderRadius: 2, border: 1, borderColor: 'divider' }}>
            <Typography variant="mono" sx={{ flex: 1, wordBreak: 'break-all', userSelect: 'all' }}>{revealed?.secret}</Typography>
            <CopyButton value={revealed?.secret ?? ''} label="Copy signing secret" />
          </Box>
          <Typography variant="body2" color="text.secondary">Send the developer the instructions below: they include copy-paste code (Node, Python, PHP) that checks the signature.</Typography>
          <FormControlLabel control={<Checkbox checked={ok} onChange={(e) => setOk(e.target.checked)} />} label="I’ve copied the secret somewhere safe" />
        </Stack>
      </DialogContent>
      <DialogActions sx={{ flexWrap: 'wrap', gap: 1 }}>
        <Button onClick={async () => { try { await navigator.clipboard.writeText(handoff()); toast.success('Instructions copied, including the secret. Send them over a private channel.'); } catch { toast.error('Copying is blocked here. Use Download instead.'); } }}>Copy instructions</Button>
        <Button onClick={() => downloadText('webhook-instructions.md', handoff())}>Download</Button>
        <Box sx={{ flex: 1 }} />
        <Button variant="contained" disabled={!ok} onClick={close}>Done</Button>
      </DialogActions>
    </Dialog>
  );
}
