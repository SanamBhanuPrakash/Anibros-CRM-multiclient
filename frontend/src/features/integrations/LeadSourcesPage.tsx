import { useMemo, useState } from 'react';
import { Link as RouterLink, useSearchParams } from 'react-router-dom';
import { Alert, Badge, Box, Button, Card, CardContent, Chip, IconButton, Menu, MenuItem, Stack, Switch, Tab, Table, TableBody, TableCell, TableContainer, TableHead, TableRow, Tabs, TextField, Tooltip, Typography, Paper, ListItemIcon, ListItemText } from '@mui/material';
import AddRoundedIcon from '@mui/icons-material/AddRounded';
import MoreVertRoundedIcon from '@mui/icons-material/MoreVertRounded';
import SettingsOutlinedIcon from '@mui/icons-material/SettingsOutlined';
import MenuBookOutlinedIcon from '@mui/icons-material/MenuBookOutlined';
import SendRoundedIcon from '@mui/icons-material/SendRounded';
import VpnKeyOutlinedIcon from '@mui/icons-material/VpnKeyOutlined';
import DeleteOutlineRoundedIcon from '@mui/icons-material/DeleteOutlineRounded';
import InboxOutlinedIcon from '@mui/icons-material/InboxOutlined';
import { useInfiniteQuery, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { ApiProblem } from '@/api/client';
import { leadSourcesApi, type DeliveryStatus, type LeadSourceEndpoint } from '@/api/inbound';
import { useAuth } from '@/features/auth/AuthProvider';
import { CopyButton, RelativeTime } from '@/ui/display';
import { PageHeader } from '@/ui/PageHeader';
import { EmptyState, ErrorState, SkeletonRows } from '@/ui/states';
import { useConfirm } from '@/ui/confirm';
import { useToast } from '@/ui/toast';
import ContentCopyRoundedIcon from '@mui/icons-material/ContentCopyRounded';
import { Pill } from '@/ui/kit';
import { leadSourceHandoff } from './handoff';
import { SetupGuide } from './SetupGuide';
import { WhatsAppCard } from './WhatsAppCard';
import { SourceDialog } from './SourceDialog';
import { DELIVERY_LABEL, DeliveryChip, PROVIDER_INFO } from './sourceMeta';

const STATUS_FILTERS: DeliveryStatus[] = ['needs_review', 'failed', 'created', 'duplicate', 'received', 'ignored'];

export function LeadSourcesPage() {
  const { can, tenant } = useAuth();
  const manage = can('integrations:manage');
  const [params, setParams] = useSearchParams();
  const tab = params.get('tab') === 'log' ? 'log' : 'sources';
  const qc = useQueryClient();
  const toast = useToast();
  const confirm = useConfirm();
  const sources = useQuery({ queryKey: ['lead-sources'], queryFn: leadSourcesApi.list });
  const [dialog, setDialog] = useState<{ source?: LeadSourceEndpoint } | null>(null);
  const [guide, setGuide] = useState<{ source: LeadSourceEndpoint; secret: string | null } | null>(null);
  const [menu, setMenu] = useState<{ el: HTMLElement; source: LeadSourceEndpoint } | null>(null);

  const attention = useMemo(() => (sources.data ?? []).reduce((n, s) => n + s.stats.needs_review + s.stats.failed, 0), [sources.data]);
  const refresh = () => void qc.invalidateQueries({ queryKey: ['lead-sources'] });

  const toggle = useMutation({
    mutationFn: (s: LeadSourceEndpoint) => leadSourcesApi.update(s.id, { status: s.status === 'active' ? 'paused' : 'active' }),
    onSuccess: (s) => { refresh(); toast.success(s.status === 'active' ? `${s.name} is receiving again` : `${s.name} is paused. Senders will retry later.`); },
    onError: (e) => toast.error(e instanceof ApiProblem ? e.detail : 'Couldn’t change the status'),
  });
  const test = useMutation({
    mutationFn: (s: LeadSourceEndpoint) => leadSourcesApi.test(s.id),
    onSuccess: () => { refresh(); toast.success('Test lead sent. It should appear in Leads in a few seconds.', { label: 'Open Leads', onClick: () => window.location.assign('/leads?tag=test') }); },
    onError: (e) => toast.error(e instanceof ApiProblem ? e.detail : 'Couldn’t send the test lead'),
  });
  const rotate = useMutation({
    mutationFn: (s: LeadSourceEndpoint) => leadSourcesApi.rotate(s.id),
    onSuccess: (r) => { refresh(); setGuide({ source: r.endpoint, secret: r.secret }); },
    onError: (e) => toast.error(e instanceof ApiProblem ? e.detail : 'Couldn’t rotate the secret'),
  });
  const remove = useMutation({
    mutationFn: (s: LeadSourceEndpoint) => leadSourcesApi.remove(s.id),
    onSuccess: () => { refresh(); toast.success('Source deleted. Its address no longer accepts leads.'); },
    onError: (e) => toast.error(e instanceof ApiProblem ? e.detail : 'Couldn’t delete the source'),
  });

  return (
    <>
      <PageHeader
        title="Lead sources"
        description="Where your leads come from. Connect Meta, Google and your website so every enquiry lands in Leads on its own, with the campaign it came from."
        actions={manage && <Button variant="contained" startIcon={<AddRoundedIcon />} onClick={() => setDialog({})}>Add source</Button>}
      />
      {attention > 0 && (
        <Alert severity="warning" sx={{ mb: 2 }} action={<Button color="inherit" size="small" component={RouterLink} to="/integrations/lead-sources?tab=log">Review</Button>}>
          {attention} {attention === 1 ? 'delivery needs' : 'deliveries need'} your attention in the last 7 days. A lead may be waiting.
        </Alert>
      )}
      {manage && (
        <Tabs value={tab} onChange={(_, v) => setParams(v === 'log' ? { tab: 'log' } : {}, { replace: true })} aria-label="Lead sources sections" sx={{ mb: 2.5 }}>
          <Tab value="sources" label="Sources" />
          <Tab value="log" label={<Badge color="warning" badgeContent={attention} max={99} sx={{ pr: 1.5, '& .MuiBadge-badge': { right: 0, top: 6 } }}>Delivery log</Badge>} />
        </Tabs>
      )}

      {tab === 'log' && manage ? (
        <DeliveryLog sources={sources.data ?? []} />
      ) : sources.isPending ? (
        <SkeletonRows rows={3} height={150} />
      ) : sources.isError ? (
        <ErrorState error={sources.error} onRetry={() => void sources.refetch()} />
      ) : sources.data.length === 0 ? (
        <EmptyState icon={<InboxOutlinedIcon />} title="No lead sources yet" description="Add the places leads come from. Each one gets its own private address, so a lead can only arrive from a sender you have set up. Nothing is ever guessed." action={manage ? <Button variant="contained" onClick={() => setDialog({})}>Add your first source</Button> : undefined} />
      ) : (
        <Stack spacing={2}>
          {sources.data.map((s) => (
            <Card key={s.id} variant="outlined" sx={{ borderRadius: 3, opacity: s.status === 'paused' ? 0.8 : 1 }}>
              <CardContent>
                <Stack direction={{ xs: 'column', md: 'row' }} spacing={2} sx={{ justifyContent: 'space-between' }}>
                  <Stack direction="row" spacing={1.5} sx={{ minWidth: 0, alignItems: 'flex-start' }}>
                    <Box sx={{ color: 'primary.main', display: 'flex', mt: 0.25 }}>{PROVIDER_INFO[s.provider].icon}</Box>
                    <Box sx={{ minWidth: 0 }}>
                      <Stack direction="row" spacing={1} sx={{ alignItems: 'center', flexWrap: 'wrap' }}>
                        <Typography variant="h4" component="h2">{s.name}</Typography>
                        <Chip size="small" variant="outlined" label={PROVIDER_INFO[s.provider].label} />
                        {s.status === 'paused' ? <Pill tone="warning">Paused</Pill> : s.lastReceivedAt && Date.now() - new Date(s.lastReceivedAt).getTime() < 7 * 86_400_000 ? <Pill tone="success">Live</Pill> : <Pill>{s.lastReceivedAt ? 'Quiet for over a week' : 'Waiting for the first lead'}</Pill>}
                        {s.provider === 'meta' && !s.hasAccessToken && <Chip size="small" color="warning" label="Needs page access token" />}
                      </Stack>
                      <Stack direction="row" sx={{ alignItems: 'center', gap: 0.5, mt: 0.5, minWidth: 0 }}>
                        <Typography variant="body2" color="text.secondary" noWrap sx={{ fontFamily: 'ui-monospace, Menlo, monospace', fontSize: 12.5 }}>{s.url}</Typography>
                        <CopyButton value={s.url} label="Copy address" />
                      </Stack>
                      <Typography variant="caption" color="text.secondary">
                        {s.lastReceivedAt ? <>Last delivery <RelativeTime value={s.lastReceivedAt} /> · {s.receivedCount.toLocaleString()} received in total</> : 'Nothing received yet'}
                      </Typography>
                    </Box>
                  </Stack>
                  {manage && (
                    <Stack direction="row" spacing={0.5} sx={{ alignItems: 'center', flexShrink: 0 }}>
                      <Tooltip title={s.status === 'active' ? 'Receiving. Switch off to pause.' : 'Paused. Switch on to receive.'}>
                        <Switch checked={s.status === 'active'} onChange={() => toggle.mutate(s)} disabled={toggle.isPending} slotProps={{ input: { 'aria-label': `${s.name} receiving` } }} />
                      </Tooltip>
                      <Button size="small" startIcon={<SendRoundedIcon />} onClick={() => test.mutate(s)} disabled={test.isPending || s.status !== 'active'}>Send test lead</Button>
                      <Button size="small" startIcon={<MenuBookOutlinedIcon />} onClick={() => setGuide({ source: s, secret: null })}>Setup guide</Button>
                      <Tooltip title="Copies everything the client or their developer needs: the address, how to prove who they are, what we read and what each answer means">
                        <Button size="small" startIcon={<ContentCopyRoundedIcon />} onClick={async () => { try { await navigator.clipboard.writeText(leadSourceHandoff(s, null, tenant?.name ?? 'the client')); toast.success('Instructions copied. Paste them into an e-mail or chat to the client.'); } catch { toast.error('Copying is blocked here. Open the setup guide and use Download.'); } }}>Copy for client</Button>
                      </Tooltip>
                      <IconButton aria-label={`More actions for ${s.name}`} onClick={(e) => setMenu({ el: e.currentTarget, source: s })}><MoreVertRoundedIcon /></IconButton>
                    </Stack>
                  )}
                </Stack>
                <Stack direction="row" spacing={1} sx={{ mt: 2, flexWrap: 'wrap', rowGap: 1 }} aria-label="Last 7 days">
                  <Chip size="small" color="success" variant="outlined" label={`${s.stats.created} created`} />
                  <Chip size="small" variant="outlined" label={`${s.stats.duplicate} added to existing leads`} />
                  {s.stats.received > 0 && <Chip size="small" color="info" label={`${s.stats.received} waiting`} />}
                  {s.stats.needs_review > 0 && <Chip size="small" color="warning" label={`${s.stats.needs_review} need attention`} component={RouterLink} to={`/integrations/lead-sources?tab=log`} clickable />}
                  {s.stats.failed > 0 && <Chip size="small" color="error" label={`${s.stats.failed} failed`} />}
                  <Typography variant="caption" color="text.secondary" sx={{ alignSelf: 'center', ml: 0.5 }}>last 7 days</Typography>
                </Stack>
              </CardContent>
            </Card>
          ))}
        </Stack>
      )}

      <Menu anchorEl={menu?.el} open={Boolean(menu)} onClose={() => setMenu(null)}>
        <MenuItem onClick={() => { const s = menu!.source; setMenu(null); setDialog({ source: s }); }}><ListItemIcon><SettingsOutlinedIcon fontSize="small" /></ListItemIcon><ListItemText>Settings</ListItemText></MenuItem>
        {(menu?.source.provider === 'google' || menu?.source.provider === 'generic') && (
          <MenuItem onClick={async () => { const s = menu!.source; setMenu(null); if (await confirm({ title: 'Rotate the secret?', body: 'The current secret stops working immediately. Update it wherever you pasted it, or deliveries will be refused.', confirmLabel: 'Rotate' })) rotate.mutate(s); }}>
            <ListItemIcon><VpnKeyOutlinedIcon fontSize="small" /></ListItemIcon><ListItemText>Rotate secret</ListItemText>
          </MenuItem>
        )}
        {can('integrations:delete') && (
          <MenuItem sx={{ color: 'error.main' }} onClick={async () => { const s = menu!.source; setMenu(null); if (await confirm({ title: `Delete ${s.name}?`, body: 'Its address stops accepting leads right away. Leads already created stay. This can’t be undone.', confirmLabel: 'Delete', destructive: true })) remove.mutate(s); }}>
            <ListItemIcon><DeleteOutlineRoundedIcon fontSize="small" color="error" /></ListItemIcon><ListItemText>Delete source</ListItemText>
          </MenuItem>
        )}
      </Menu>

      <SourceDialog
        open={Boolean(dialog)}
        source={dialog?.source}
        onClose={() => setDialog(null)}
        onSaved={(endpoint, secret, created) => { setDialog(null); if (created) setGuide({ source: endpoint, secret }); }}
      />
      <SetupGuide open={Boolean(guide)} source={guide?.source ?? null} secret={guide?.secret ?? null} onClose={() => setGuide(null)} onTest={() => guide && test.mutate(guide.source)} />
      {tab === 'sources' && <Box sx={{ mt: 3 }}><WhatsAppCard canManage={manage} /></Box>}
    </>
  );
}

function DeliveryLog({ sources }: { sources: LeadSourceEndpoint[] }) {
  const qc = useQueryClient();
  const toast = useToast();
  const [status, setStatus] = useState<DeliveryStatus | ''>('');
  const [endpointId, setEndpointId] = useState('');
  const filters = { ...(status ? { status } : {}), ...(endpointId ? { endpointId } : {}) };
  const list = useInfiniteQuery({
    queryKey: ['lead-sources', 'events', filters],
    queryFn: ({ pageParam }) => leadSourcesApi.events(filters, pageParam),
    initialPageParam: undefined as string | undefined,
    getNextPageParam: (l) => l.page?.next ?? undefined,
    placeholderData: (p) => p,
  });
  const rows = useMemo(() => list.data?.pages.flatMap((p) => p.data) ?? [], [list.data]);
  const act = useMutation({
    mutationFn: ({ id, kind }: { id: string; kind: 'retry' | 'ignore' }) => (kind === 'retry' ? leadSourcesApi.retry(id) : leadSourcesApi.ignore(id)),
    onSuccess: (_r, v) => { void qc.invalidateQueries({ queryKey: ['lead-sources'] }); toast.success(v.kind === 'retry' ? 'Back in the queue. It will be processed in a moment.' : 'Marked as ignored'); },
    onError: (e) => toast.error(e instanceof ApiProblem ? e.detail : 'That didn’t work'),
  });

  return (
    <>
      <Stack direction="row" spacing={1.5} sx={{ mb: 2, flexWrap: 'wrap', rowGap: 1.5 }}>
        <TextField select size="small" fullWidth={false} value={status} onChange={(e) => setStatus(e.target.value as DeliveryStatus | '')} sx={{ minWidth: 200 }} slotProps={{ select: { displayEmpty: true }, htmlInput: { 'aria-label': 'Outcome' } }}>
          <MenuItem value="">Every outcome</MenuItem>
          {STATUS_FILTERS.map((s) => <MenuItem key={s} value={s}>{DELIVERY_LABEL[s]}</MenuItem>)}
        </TextField>
        <TextField select size="small" fullWidth={false} value={endpointId} onChange={(e) => setEndpointId(e.target.value)} sx={{ minWidth: 200 }} slotProps={{ select: { displayEmpty: true }, htmlInput: { 'aria-label': 'Source' } }}>
          <MenuItem value="">Every source</MenuItem>
          {sources.map((s) => <MenuItem key={s.id} value={s.id}>{s.name}</MenuItem>)}
        </TextField>
      </Stack>
      {list.isPending ? <SkeletonRows rows={6} /> : list.isError ? <ErrorState error={list.error} onRetry={() => void list.refetch()} /> : rows.length === 0 ? (
        <EmptyState icon={<InboxOutlinedIcon />} title="No deliveries yet" description={status || endpointId ? 'Nothing matches these filters.' : 'When Meta, Google or your website send a lead, each delivery is listed here with what happened to it.'} />
      ) : (
        <>
          <TableContainer component={Paper} variant="outlined" sx={{ borderRadius: 2.5 }}>
            <Table size="small" aria-label="Deliveries">
              <TableHead>
                <TableRow><TableCell>Received</TableCell><TableCell>Source</TableCell><TableCell>Person</TableCell><TableCell>What happened</TableCell><TableCell align="right" /></TableRow>
              </TableHead>
              <TableBody>
                {rows.map((d) => (
                  <TableRow key={d.id} hover>
                    <TableCell sx={{ whiteSpace: 'nowrap' }}><RelativeTime value={d.receivedAt} /></TableCell>
                    <TableCell>{d.endpointName}{d.isTest && <Chip size="small" label="test" sx={{ ml: 0.75 }} />}</TableCell>
                    <TableCell>{d.leadId ? <Typography component={RouterLink} to={`/leads/${d.leadId}`} variant="body2" sx={{ color: 'primary.main' }}>{d.who || 'Open lead'}</Typography> : d.who || '—'}</TableCell>
                    <TableCell sx={{ maxWidth: 420 }}>
                      <DeliveryChip status={d.status} />
                      {d.reason && <Typography variant="body2" color="text.secondary" sx={{ mt: 0.5 }}>{d.reason}</Typography>}
                    </TableCell>
                    <TableCell align="right" sx={{ whiteSpace: 'nowrap' }}>
                      {(d.status === 'needs_review' || d.status === 'failed') && (
                        <>
                          <Button size="small" onClick={() => act.mutate({ id: d.id, kind: 'retry' })} disabled={act.isPending}>Retry</Button>
                          <Button size="small" color="inherit" onClick={() => act.mutate({ id: d.id, kind: 'ignore' })} disabled={act.isPending}>Ignore</Button>
                        </>
                      )}
                    </TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          </TableContainer>
          {list.hasNextPage && <Box sx={{ mt: 2, textAlign: 'center' }}><Button onClick={() => void list.fetchNextPage()} disabled={list.isFetchingNextPage}>{list.isFetchingNextPage ? 'Loading…' : 'Load more'}</Button></Box>}
        </>
      )}
    </>
  );
}
