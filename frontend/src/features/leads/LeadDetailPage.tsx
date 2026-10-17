import { useEffect, useMemo, useRef, useState, type ReactElement } from 'react';
import { Link as RouterLink, useNavigate, useParams } from 'react-router-dom';
import { Alert, Autocomplete, Box, Button, Chip, Dialog, DialogActions, DialogContent, DialogTitle, InputAdornment, Link as MuiLink, ListItemIcon, ListItemText, Menu, MenuItem, Stack, TextField, Tooltip, Typography } from '@mui/material';
import CallOutlinedIcon from '@mui/icons-material/CallOutlined';
import WhatsAppIcon from '@mui/icons-material/WhatsApp';
import SmsOutlinedIcon from '@mui/icons-material/SmsOutlined';
import InstagramIcon from '@mui/icons-material/Instagram';
import PersonAddAlt1RoundedIcon from '@mui/icons-material/PersonAddAlt1Rounded';
import CloseRoundedIcon from '@mui/icons-material/CloseRounded';
import MailOutlineRoundedIcon from '@mui/icons-material/MailOutlineRounded';
import EventOutlinedIcon from '@mui/icons-material/EventOutlined';
import StickyNote2OutlinedIcon from '@mui/icons-material/StickyNote2Outlined';
import SwapHorizRoundedIcon from '@mui/icons-material/SwapHorizRounded';
import EditOutlinedIcon from '@mui/icons-material/EditOutlined';
import MoreHorizRoundedIcon from '@mui/icons-material/MoreHorizRounded';
import ArchiveOutlinedIcon from '@mui/icons-material/ArchiveOutlined';
import ChecklistRoundedIcon from '@mui/icons-material/ChecklistRounded';
import CheckRoundedIcon from '@mui/icons-material/CheckRounded';
import ArrowDownwardRoundedIcon from '@mui/icons-material/ArrowDownwardRounded';
import ArrowUpwardRoundedIcon from '@mui/icons-material/ArrowUpwardRounded';
import { useInfiniteQuery, useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { ApiProblem } from '@/api/client';
import { leadsApi, type Activity, type LeadDetail, type Stage } from '@/api/crm';
import { useAuth } from '@/features/auth/AuthProvider';
import { ActivityForm, type LogType } from '@/features/agency/ActivityForm';
import { OUTCOME_LABEL, channelSummary } from '@/features/agency/shared';
import { UserAvatar } from '@/ui/display';
import { PageHeader } from '@/ui/PageHeader';
import { ErrorState, SkeletonRows } from '@/ui/states';
import { useConfirm } from '@/ui/confirm';
import { useToast } from '@/ui/toast';
import { Card, ClientChip, Fact, Pill, When, type Tone } from '@/ui/kit';
import { ScoreBadge, SlaChip, SOURCE_LABEL, STATUS_LABEL, formatMoney, usePipelines, useDirectory } from './shared';
import { parseMoney } from './NewLeadDialog';
import { LeadTasks } from '@/features/tasks/LeadTasks';
import { LeadVisits } from '@/features/viewings/LeadVisits';
import { LeadDeals } from '@/features/deals/LeadDeals';
import { NextStepBanner } from './NextStepBanner';
import { LeadCustomFields } from './LeadCustomFields';
import { useLeadMover } from './useLeadMover';

const TYPE_ICON: Record<string, ReactElement> = {
  note: <StickyNote2OutlinedIcon fontSize="small" />,
  call: <CallOutlinedIcon fontSize="small" />,
  whatsapp: <WhatsAppIcon fontSize="small" />,
  sms: <SmsOutlinedIcon fontSize="small" />,
  email: <MailOutlineRoundedIcon fontSize="small" />,
  instagram: <InstagramIcon fontSize="small" />,
  meeting: <EventOutlinedIcon fontSize="small" />,
  stage_change: <SwapHorizRoundedIcon fontSize="small" />,
  task_created: <ChecklistRoundedIcon fontSize="small" />,
  task_completed: <ChecklistRoundedIcon fontSize="small" />,
};
const TYPE_LABEL: Record<string, string> = { note: 'Note', call: 'Call', whatsapp: 'WhatsApp', sms: 'SMS', email: 'E-mail', instagram: 'Instagram', meeting: 'Meeting', stage_change: 'Stage change', viewing: 'Visit', task_created: 'Task', task_completed: 'Task done' };
const OUTCOME_TONE: Record<string, Tone> = { connected: 'success', interested: 'success', appointment_booked: 'success', replied: 'success', not_interested: 'danger', wrong_number: 'danger' };
const QUICK: Array<{ type: LogType; label: string; icon: ReactElement }> = [
  { type: 'call', label: 'Call', icon: <CallOutlinedIcon fontSize="small" /> },
  { type: 'whatsapp', label: 'WhatsApp', icon: <WhatsAppIcon fontSize="small" /> },
  { type: 'sms', label: 'SMS', icon: <SmsOutlinedIcon fontSize="small" /> },
  { type: 'email', label: 'E-mail', icon: <MailOutlineRoundedIcon fontSize="small" /> },
  { type: 'instagram', label: 'Instagram', icon: <InstagramIcon fontSize="small" /> },
  { type: 'meeting', label: 'Meeting', icon: <EventOutlinedIcon fontSize="small" /> },
  { type: 'note', label: 'Note', icon: <StickyNote2OutlinedIcon fontSize="small" /> },
];

export function LeadDetailPage() {
  const { id = '' } = useParams();
  const nav = useNavigate();
  const qc = useQueryClient();
  const toast = useToast();
  const confirm = useConfirm();
  const { can, scope, me, tenant } = useAuth();
  const directory = useDirectory();
  const q = useQuery({ queryKey: ['leads', 'detail', id], queryFn: () => leadsApi.get(id) });
  const mover = useLeadMover();
  const [editOpen, setEditOpen] = useState(false);
  const [menu, setMenu] = useState<HTMLElement | null>(null);
  const [assignAnchor, setAssignAnchor] = useState<HTMLElement | null>(null);
  const [compose, setCompose] = useState<{ type: LogType; n: number }>({ type: 'call', n: 0 });
  const composerRef = useRef<HTMLDivElement>(null);
  const realEstate = tenant?.industry === 'real_estate';

  const assign = useMutation({
    mutationFn: (ownerId: string | null) => leadsApi.assign(id, ownerId),
    onSuccess: (l) => { void qc.invalidateQueries({ queryKey: ['leads'] }); toast.success(l.ownerId ? 'Lead reassigned' : 'Lead unassigned'); },
    onError: (e) => toast.error(e instanceof ApiProblem ? e.detail : 'Couldn’t reassign the lead'),
  });
  const archive = useMutation({
    mutationFn: () => leadsApi.archive(id),
    onSuccess: () => {
      void qc.invalidateQueries({ queryKey: ['leads'] });
      nav('/leads');
      toast.success('Lead archived', { label: 'Undo', onClick: () => void leadsApi.restore(id).then(() => qc.invalidateQueries({ queryKey: ['leads'] })) });
    },
    onError: (e) => toast.error(e instanceof ApiProblem ? e.detail : 'Couldn’t archive the lead'),
  });

  if (q.isPending) return <><PageHeader title="Lead" crumbs={[{ label: 'Leads', to: '/leads' }, { label: '…' }]} /><SkeletonRows rows={6} /></>;
  if (q.isError) {
    const gone = q.error instanceof ApiProblem && q.error.status === 404;
    return (
      <>
        <PageHeader title={gone ? 'Lead not found' : 'Lead'} crumbs={[{ label: 'Leads', to: '/leads' }, { label: 'Lead' }]} />
        <ErrorState error={q.error} onRetry={() => void q.refetch()} title={gone ? 'This lead doesn’t exist, or you don’t have access to it' : 'We couldn’t load this lead'} />
      </>
    );
  }
  const lead = q.data;
  const owner = lead.ownerId ? directory.data?.find((m) => m.userId === lead.ownerId) : undefined;
  const ownerName = lead.ownerId ? (owner?.name ?? '…') : 'Not assigned';
  const mayEdit = scope('leads:update') === 'all' || (scope('leads:update') === 'own' && lead.ownerId === me?.user.id);
  // Someone added as a handler (the WhatsApp person, the one who does the visit) can log their work without owning the lead.
  const isHandler = lead.handlers.some((h) => h.userId === me?.user.id);
  const mayLog = (mayEdit || isHandler) && can('activities:create');
  const myChannels = tenant?.workChannels ?? null;
  const quick = QUICK.filter((a) => a.type === 'note' || !myChannels || myChannels.includes(a.type));
  const closed = lead.closedAt !== null;
  const startCompose = (type: LogType) => {
    setCompose((c) => ({ type, n: c.n + 1 }));
    setTimeout(() => composerRef.current?.scrollIntoView({ behavior: 'smooth', block: 'nearest' }), 30);
  };

  return (
    <>
      <PageHeader crumbs={[{ label: 'Leads', to: '/leads' }, { label: lead.contactName ?? 'Lead' }]} title={lead.contactName ?? 'Unnamed lead'} meta={<ScoreBadge lead={lead} size="medium" />}
        description={
          <Stack direction="row" spacing={1.5} sx={{ alignItems: 'center', flexWrap: 'wrap', rowGap: 0.5 }}>
            {lead.contactPhone && <MuiLink href={`tel:${lead.contactPhone}`} color="inherit" underline="hover">{lead.contactPhone}</MuiLink>}
            {lead.contactEmail && <MuiLink href={`mailto:${lead.contactEmail}`} color="inherit" underline="hover">{lead.contactEmail}</MuiLink>}
            {tenant && <ClientChip id={tenant.id} name={tenant.name} />}
            <span>Added <When value={lead.createdAt} sx={{ color: 'inherit' }} /></span>
          </Stack>
        }
        actions={
          <>
            {lead.contactId && <Button component={RouterLink} to={`/contacts?c=${lead.contactId}`} size="small" color="inherit" variant="outlined">Contact</Button>}
            {mayEdit && <Button size="small" variant="outlined" color="inherit" startIcon={<EditOutlinedIcon />} onClick={() => setEditOpen(true)}>Edit</Button>}
            {can('leads:delete') && (
              <>
                <Button size="small" variant="outlined" color="inherit" aria-label="More actions" onClick={(e) => setMenu(e.currentTarget)} sx={{ minWidth: 34, px: 0 }}><MoreHorizRoundedIcon fontSize="small" /></Button>
                <Menu anchorEl={menu} open={Boolean(menu)} onClose={() => setMenu(null)}>
                  <MenuItem sx={{ color: 'error.main' }} onClick={async () => { setMenu(null); if (await confirm({ title: 'Archive this lead?', body: 'It disappears from lists and boards. You can undo this right after.', confirmLabel: 'Archive', destructive: true })) archive.mutate(); }}>
                    <ListItemIcon><ArchiveOutlinedIcon fontSize="small" color="error" /></ListItemIcon><ListItemText>Archive lead</ListItemText>
                  </MenuItem>
                </Menu>
              </>
            )}
          </>
        }
      />

      {/* Where it is in the process, and the one-click way to move it */}
      <Card pad={1.5} sx={{ mb: 2.5 }}>
        <Stack direction="row" spacing={1.5} sx={{ alignItems: 'center', flexWrap: 'wrap', rowGap: 1, mb: 1.5 }}>
          <Pill tone={lead.status === 'won' ? 'success' : lead.status === 'lost' || lead.status === 'disqualified' ? 'neutral' : 'accent'}>{STATUS_LABEL[lead.status]}</Pill>
          <SlaChip sla={lead.sla} />
          <Box sx={{ flex: 1 }} />
          {mayLog && (
            <Stack direction="row" spacing={0.75} sx={{ flexWrap: 'wrap', rowGap: 0.75 }} role="group" aria-label="Log activity">
              {quick.map((a) => (
                <Button key={a.type} size="small" variant="outlined" color="inherit" startIcon={a.icon} onClick={() => startCompose(a.type)}>{a.label}</Button>
              ))}
            </Stack>
          )}
        </Stack>
        <StageBar lead={lead} editable={mayEdit} moving={mover.isMoving} onMove={(t) => void mover.move(lead, t)} />
        {mayEdit && !closed && lead.transitions.some((t) => t.requiredFields.length) && (
          <Typography variant="caption" color="text.secondary" component="p" sx={{ mt: 1 }}>Some moves need details first; you’ll be asked.</Typography>
        )}
      </Card>

      {(lead.status === 'lost' || lead.status === 'disqualified') && (
        <Alert severity="info" sx={{ mb: 2.5 }}>{STATUS_LABEL[lead.status]}{lead.lossReason ? `: ${lead.lossReason}` : ''}. You can reopen this lead from the stage bar.</Alert>
      )}

      <Box sx={{ display: 'grid', gap: 2.5, gridTemplateColumns: { xs: '1fr', lg: 'minmax(0, 1fr) 360px' }, alignItems: 'start' }}>
        <Stack spacing={2.5} sx={{ minWidth: 0 }}>
          {mayLog && tenant && (
            <Box ref={composerRef}>
              <Card title="Log activity" subtitle="Call, message, meeting or note. One click on how it went is enough.">
                <ActivityForm key={compose.n} clientId={tenant.id} lead={{ id: lead.id, name: lead.contactName, phone: lead.contactPhone, email: lead.contactEmail }} initialType={compose.type} autoFocus={compose.n > 0} />
              </Card>
            </Box>
          )}
          <Timeline leadId={id} />
        </Stack>

        <Stack spacing={2.5} sx={{ minWidth: 0 }}>
          {lead.nextStep && <NextStepBanner step={lead.nextStep} />}

          <Card title="Details">
            <Fact label="Owner">
              <Stack direction="row" spacing={0.75} sx={{ alignItems: 'center', justifyContent: 'flex-end' }}>
                {lead.ownerId && <UserAvatar name={ownerName} seed={lead.ownerId} size={20} />}
                <span>{ownerName}</span>
                {!lead.ownerId && can('leads:claim') && <MuiLink component="button" type="button" onClick={() => assign.mutate(me?.user.id ?? null)} sx={{ typography: 'body2' }}>Take it</MuiLink>}
                {can('leads:assign') && <MuiLink component="button" type="button" onClick={(e: React.MouseEvent<HTMLElement>) => setAssignAnchor(e.currentTarget)} sx={{ typography: 'body2' }}>{lead.ownerId ? 'Change' : 'Assign'}</MuiLink>}
              </Stack>
            </Fact>
            <Fact label="Source">{SOURCE_LABEL[lead.source]}{lead.campaign ? ` · ${lead.campaign}` : ''}</Fact>
            <Fact label="Next follow-up"><When value={lead.nextFollowupAt} due sx={{ color: 'inherit' }} /></Fact>
            <Fact label="Last activity"><When value={lead.lastActivityAt} fallback="None yet" sx={{ color: 'inherit' }} /></Fact>
            {(realEstate || lead.estimatedValue) && <Fact label={realEstate ? 'Deal value' : 'Value'}>{formatMoney(lead.estimatedValue, lead.currency)}</Fact>}
            {realEstate && (
              <>
                <Fact label="Budget">{lead.budgetMin || lead.budgetMax ? `${formatMoney(lead.budgetMin, lead.currency)} – ${formatMoney(lead.budgetMax, lead.currency)}` : '—'}</Fact>
                <Fact label="Bedrooms">{lead.bedroomsMin ? `${lead.bedroomsMin}+` : '—'}</Fact>
                <Fact label="Locations">{lead.locations.join(', ') || '—'}</Fact>
              </>
            )}
            {lead.tags.length > 0 && <Stack direction="row" sx={{ flexWrap: 'wrap', gap: 0.5, mt: 1 }}>{lead.tags.map((t) => <Pill key={t}>{t}</Pill>)}</Stack>}
          </Card>

          {(lead.handlers.length > 0 || can('leads:assign')) && <Handlers lead={lead} canManage={can('leads:assign')} />}
          <LeadCustomFields lead={lead} canEdit={mayEdit} />
          <LeadTasks leadId={lead.id} leadName={lead.contactName ?? 'this lead'} />
          {realEstate && <LeadVisits lead={{ id: lead.id, name: lead.contactName ?? 'this lead', closed }} canBook={mayEdit} />}
          {realEstate && <LeadDeals lead={{ id: lead.id, name: lead.contactName ?? 'this lead', closed }} canEdit={mayEdit} />}
          <History lead={lead} />
        </Stack>
      </Box>

      <Menu anchorEl={assignAnchor} open={Boolean(assignAnchor)} onClose={() => setAssignAnchor(null)}>
        <MenuItem onClick={() => { setAssignAnchor(null); assign.mutate(null); }}><em>Not assigned</em></MenuItem>
        {(directory.data ?? []).map((m) => <MenuItem key={m.userId} selected={m.userId === lead.ownerId} onClick={() => { setAssignAnchor(null); assign.mutate(m.userId); }}>{m.name}</MenuItem>)}
      </Menu>
      <EditLeadDialog open={editOpen} lead={lead} realEstate={realEstate} onClose={() => setEditOpen(false)} />
      {mover.dialog}
    </>
  );
}

/** The whole path as one bar: where the lead is, what came before, and a click on any allowed stage moves it there. */
function StageBar({ lead, editable, moving, onMove }: { lead: LeadDetail; editable: boolean; moving: boolean; onMove: (t: { stageId: string; name: string; kind: LeadDetail['transitions'][number]['reasonKind'] }) => void }) {
  const pipelines = usePipelines();
  const [reopen, setReopen] = useState<HTMLElement | null>(null);
  const stages: Stage[] = useMemo(() => (pipelines.data?.find((p) => p.id === lead.pipelineId)?.stages ?? []).filter((s) => !s.isClosed || s.mapsToStatus === 'won').sort((a, b) => a.orderIndex - b.orderIndex), [pipelines.data, lead.pipelineId]);
  const allowed = new Map(lead.transitions.map((t) => [t.stageId, t]));
  const currentIndex = stages.findIndex((s) => s.id === lead.stageId);
  const ending = lead.transitions.filter((t) => t.closes && t.status !== 'won');
  const closed = lead.closedAt !== null;

  if (closed) {
    return (
      <Stack direction="row" spacing={1.5} sx={{ alignItems: 'center', flexWrap: 'wrap', rowGap: 1 }}>
        <Typography variant="body2" color="text.secondary">This lead is closed as <b>{lead.stageName}</b>.</Typography>
        {editable && lead.transitions.length > 0 && (
          <>
            <Button size="small" variant="outlined" onClick={(e) => setReopen(e.currentTarget)}>Reopen as…</Button>
            <Menu anchorEl={reopen} open={Boolean(reopen)} onClose={() => setReopen(null)}>
              {lead.transitions.map((t) => <MenuItem key={t.stageId} onClick={() => { setReopen(null); onMove({ stageId: t.stageId, name: t.name, kind: t.reasonKind }); }}><Box component="span" sx={{ width: 8, height: 8, borderRadius: '50%', bgcolor: t.color, mr: 1.25 }} />{t.name}</MenuItem>)}
            </Menu>
          </>
        )}
      </Stack>
    );
  }

  return (
    <Stack direction="row" spacing={1.5} sx={{ alignItems: 'center' }}>
      <Box role="list" aria-label="Stages" sx={{ display: 'flex', flex: 1, minWidth: 0, overflowX: 'auto', pb: 0.25 }}>
        {stages.map((s, i) => {
          const current = s.id === lead.stageId;
          const past = currentIndex >= 0 && i < currentIndex;
          const t = allowed.get(s.id);
          const canClick = editable && !current && Boolean(t) && !moving;
          return (
            <Tooltip key={s.id} title={current ? 'Current stage' : t ? `Move to “${s.name}”` : editable ? 'This pipeline does not allow that move from here' : ''}>
              <Box
                component="button"
                type="button"
                role="listitem"
                aria-current={current ? 'step' : undefined}
                disabled={!canClick}
                onClick={() => t && onMove({ stageId: s.id, name: s.name, kind: t.reasonKind })}
                sx={(th) => ({
                  all: 'unset',
                  boxSizing: 'border-box',
                  flex: '1 0 auto',
                  minWidth: 96,
                  height: 34,
                  px: 1.5,
                  display: 'flex',
                  alignItems: 'center',
                  justifyContent: 'center',
                  gap: 0.75,
                  typography: 'body2',
                  fontWeight: current ? 600 : 500,
                  whiteSpace: 'nowrap',
                  border: 1,
                  borderColor: current ? 'primary.main' : 'divider',
                  ml: i === 0 ? 0 : '-1px',
                  borderRadius: i === 0 ? '8px 0 0 8px' : i === stages.length - 1 ? '0 8px 8px 0' : 0,
                  bgcolor: current ? 'surface.selected' : past ? 'surface.sunken' : 'background.paper',
                  color: current ? 'primary.main' : past ? 'text.primary' : 'text.secondary',
                  position: 'relative',
                  zIndex: current ? 1 : 0,
                  cursor: canClick ? 'pointer' : 'default',
                  opacity: !current && !past && !t ? 0.55 : 1,
                  '&:hover': canClick ? { bgcolor: 'surface.hover', color: 'text.primary' } : undefined,
                  '&:focus-visible': { outline: `2px solid ${th.vars!.palette.primary.main}`, outlineOffset: 1, zIndex: 2 },
                })}
              >
                {past && <CheckRoundedIcon sx={{ fontSize: 14 }} />}
                {s.name}
              </Box>
            </Tooltip>
          );
        })}
      </Box>
      {editable && ending.length > 0 && (
        <Stack direction="row" spacing={0.75} sx={{ flexShrink: 0 }}>
          {ending.map((t) => <Button key={t.stageId} size="small" variant="outlined" color="inherit" disabled={moving} onClick={() => onMove({ stageId: t.stageId, name: t.name, kind: t.reasonKind })}>{t.name}</Button>)}
        </Stack>
      )}
    </Stack>
  );
}

function Timeline({ leadId }: { leadId: string }) {
  const q = useInfiniteQuery({
    queryKey: ['leads', 'activities', leadId],
    queryFn: ({ pageParam }) => leadsApi.activities(leadId, pageParam),
    initialPageParam: undefined as string | undefined,
    getNextPageParam: (last) => last.page?.next ?? undefined,
  });
  const items = useMemo(() => q.data?.pages.flatMap((p) => p.data) ?? [], [q.data]);
  return (
    <Card title="Activity" subtitle="Everything done on this lead, newest first" id="timeline">
      {q.isPending ? <SkeletonRows rows={4} /> : q.isError ? <ErrorState error={q.error} onRetry={() => void q.refetch()} /> : items.length === 0 ? (
        <Typography variant="body2" color="text.secondary">Nothing yet. Calls, messages, meetings and notes you log appear here.</Typography>
      ) : (
        <Stack spacing={0}>
          {items.map((a) => <TimelineItem key={a.id} a={a} />)}
          {q.hasNextPage && <Button onClick={() => void q.fetchNextPage()} disabled={q.isFetchingNextPage} sx={{ alignSelf: 'flex-start', mt: 1 }}>{q.isFetchingNextPage ? 'Loading…' : 'Show older activity'}</Button>}
        </Stack>
      )}
    </Card>
  );
}

function TimelineItem({ a }: { a: Activity }) {
  const system = a.type === 'stage_change' || a.type === 'task_created' || a.type === 'task_completed' || a.type === 'status_change' || a.metadata.event === 'created' || a.metadata.event === 'assigned' || a.metadata.event === 'import';
  const outcome = a.outcome ?? (typeof a.metadata.outcome === 'string' ? a.metadata.outcome : null);
  const mins = a.durationMinutes ?? (typeof a.metadata.durationMinutes === 'number' ? a.metadata.durationMinutes : null);
  if (system) {
    return (
      <Stack direction="row" spacing={1.25} sx={{ py: 0.9, alignItems: 'center', color: 'text.secondary' }}>
        <Box sx={{ width: 28, display: 'grid', placeItems: 'center', flexShrink: 0 }}>{a.type === 'stage_change' ? <SwapHorizRoundedIcon sx={{ fontSize: 16 }} /> : <Box sx={{ width: 6, height: 6, borderRadius: '50%', bgcolor: 'text.disabled' }} />}</Box>
        <Typography variant="body2" color="text.secondary" sx={{ flex: 1, minWidth: 0 }}>{a.description}{a.userName ? ` · ${a.userName}` : ''}</Typography>
        <Typography variant="caption" color="text.secondary" sx={{ flexShrink: 0 }}><When value={a.createdAt} /></Typography>
      </Stack>
    );
  }
  return (
    <Stack direction="row" spacing={1.25} sx={{ py: 1.25, borderTop: 1, borderColor: 'divider', '&:first-of-type': { borderTop: 0 } }}>
      <Box aria-hidden sx={{ width: 28, height: 28, borderRadius: '50%', flexShrink: 0, display: 'grid', placeItems: 'center', bgcolor: 'accentTone.bg', color: 'accentTone.fg' }}>{TYPE_ICON[a.type] ?? TYPE_ICON.note}</Box>
      <Box sx={{ minWidth: 0, flex: 1 }}>
        <Stack direction="row" spacing={0.75} sx={{ alignItems: 'center', flexWrap: 'wrap', rowGap: 0.5 }}>
          <Typography variant="subtitle2">{a.metadata.event === 'inbound_message' ? (typeof a.metadata.turns === 'number' ? 'Chat with the visitor' : 'Their message') : (TYPE_LABEL[a.type] ?? a.type)}</Typography>
          {a.direction && <Pill icon={a.direction === 'inbound' ? <ArrowDownwardRoundedIcon sx={{ fontSize: 12 }} /> : <ArrowUpwardRoundedIcon sx={{ fontSize: 12 }} />}>{a.direction === 'inbound' ? 'They reached out' : 'We reached out'}</Pill>}
          {a.metadata.delivery === 'queued' && <Pill tone="info" title="Waiting to be sent">Sending…</Pill>}
          {a.metadata.delivery === 'sent' && <Pill tone="success" title="Handed to the mail server or WhatsApp">Sent</Pill>}
          {a.metadata.delivery === 'failed' && <Pill tone="danger" title={typeof a.metadata.error === 'string' ? a.metadata.error : undefined}>Not delivered</Pill>}
          {outcome && <Pill tone={OUTCOME_TONE[outcome] ?? 'neutral'}>{OUTCOME_LABEL[outcome] ?? outcome.replace(/_/g, ' ')}</Pill>}
          {mins ? <Typography variant="caption" color="text.secondary">{mins} min</Typography> : null}
          <Box sx={{ flex: 1 }} />
          <Typography variant="caption" color="text.secondary"><When value={a.createdAt} /></Typography>
        </Stack>
        <Typography variant="body2" sx={{ whiteSpace: 'pre-wrap', wordBreak: 'break-word', mt: 0.25 }}>{a.description}</Typography>
        {a.metadata.delivery === 'failed' && typeof a.metadata.error === 'string' && <Typography variant="caption" sx={{ color: 'error.main', display: 'block' }}>{a.metadata.error}</Typography>}
        <Typography variant="caption" color="text.secondary">{a.userName ?? 'System'}</Typography>
      </Box>
    </Stack>
  );
}

function History({ lead }: { lead: LeadDetail }) {
  const [all, setAll] = useState(false);
  const rows = all ? lead.history : lead.history.slice(0, 4);
  return (
    <Card title="Stage history">
      <Stack spacing={1}>
        {rows.map((h) => (
          <Box key={h.id}>
            <Typography variant="body2">{h.fromStage ? <>{h.fromStage} → <b>{h.toStage}</b></> : <>Started in <b>{h.toStage}</b></>}</Typography>
            <Typography variant="caption" color="text.secondary">{h.changedBy ?? 'System'} · <When value={h.at} sx={{ color: 'inherit' }} />{h.reason ? ` · ${h.reason}` : ''}</Typography>
          </Box>
        ))}
      </Stack>
      {lead.history.length > 4 && <Button size="small" onClick={() => setAll(!all)} sx={{ mt: 1 }}>{all ? 'Show less' : `Show all ${lead.history.length}`}</Button>}
    </Card>
  );
}

function EditLeadDialog({ open, lead, realEstate, onClose }: { open: boolean; lead: LeadDetail; realEstate: boolean; onClose: () => void }) {
  const qc = useQueryClient();
  const toast = useToast();
  const [v, setV] = useState({ value: '', budgetMin: '', budgetMax: '', beds: '', campaign: '', followup: '', locations: [] as string[], tags: [] as string[] });
  const [error, setError] = useState<string | null>(null);
  const symbol = useMemo(() => new Intl.NumberFormat(undefined, { style: 'currency', currency: lead.currency }).formatToParts(0).find((p) => p.type === 'currency')?.value ?? lead.currency, [lead.currency]);
  useEffect(() => {
    if (open) {
      setError(null);
      setV({
        value: lead.estimatedValue?.toString() ?? '', budgetMin: lead.budgetMin?.toString() ?? '', budgetMax: lead.budgetMax?.toString() ?? '', beds: lead.bedroomsMin?.toString() ?? '', campaign: lead.campaign ?? '',
        followup: lead.nextFollowupAt ? new Date(new Date(lead.nextFollowupAt).getTime() - new Date().getTimezoneOffset() * 60_000).toISOString().slice(0, 16) : '', locations: lead.locations, tags: lead.tags,
      });
    }
  }, [open, lead]);
  const save = useMutation({
    mutationFn: () => {
      const m = (s: string) => { const n = parseMoney(s); if (Number.isNaN(n)) throw new ApiProblem(400, { detail: 'One of the amounts isn’t a number. Try 85,000 or 1.2 L.' }); return n ?? null; };
      return leadsApi.update(lead.id, lead.version, {
        estimatedValue: m(v.value), campaign: v.campaign.trim() || null, nextFollowupAt: v.followup ? new Date(v.followup).toISOString() : null, tags: v.tags,
        ...(realEstate ? { budgetMin: m(v.budgetMin), budgetMax: m(v.budgetMax), bedroomsMin: v.beds ? Number(v.beds) : null, locations: v.locations } : {}),
      });
    },
    onSuccess: () => { void qc.invalidateQueries({ queryKey: ['leads'] }); toast.success('Lead updated'); onClose(); },
    onError: (e) => setError(e instanceof ApiProblem ? (e.status === 412 ? 'Someone else changed this lead while you were editing. Close this and reopen it to see their changes.' : (e.errors[0]?.message ?? e.detail)) : 'Couldn’t save. Please try again.'),
  });
  const money = (label: string, key: 'value' | 'budgetMin' | 'budgetMax') => (
    <TextField label={label} fullWidth value={v[key]} onChange={(e) => setV({ ...v, [key]: e.target.value })} slotProps={{ input: { startAdornment: <InputAdornment position="start">{symbol}</InputAdornment> } }} />
  );
  return (
    <Dialog open={open} onClose={save.isPending ? undefined : onClose} fullWidth maxWidth="sm" aria-labelledby="edit-lead-title">
      <DialogTitle id="edit-lead-title">Edit lead</DialogTitle>
      <DialogContent>
        <Stack component="form" id="edit-lead-form" spacing={2} sx={{ pt: 0.5 }} onSubmit={(e) => { e.preventDefault(); setError(null); save.mutate(); }}>
          {error && <Alert severity="error">{error}</Alert>}
          {money(realEstate ? 'Deal value' : 'Value', 'value')}
          {realEstate && <Stack direction={{ xs: 'column', sm: 'row' }} spacing={2}>{money('Budget from', 'budgetMin')}{money('Budget up to', 'budgetMax')}</Stack>}
          <Stack direction={{ xs: 'column', sm: 'row' }} spacing={2}>
            {realEstate && <TextField label="Bedrooms (at least)" type="number" fullWidth value={v.beds} onChange={(e) => setV({ ...v, beds: e.target.value })} slotProps={{ htmlInput: { min: 0, max: 20 } }} />}
            <TextField label="Campaign" fullWidth value={v.campaign} onChange={(e) => setV({ ...v, campaign: e.target.value })} />
          </Stack>
          <TextField label="Next follow-up" type="datetime-local" fullWidth value={v.followup} onChange={(e) => setV({ ...v, followup: e.target.value })} slotProps={{ inputLabel: { shrink: true } }} />
          {realEstate && <Autocomplete multiple freeSolo options={[] as string[]} value={v.locations} onChange={(_, x) => setV({ ...v, locations: x })} renderValue={(x, p) => x.map((o, i) => <Chip size="small" label={o} {...p({ index: i })} key={o} />)} renderInput={(p) => <TextField {...p} label="Preferred locations" helperText="Type and press Enter" />} />}
          <Autocomplete multiple freeSolo options={[] as string[]} value={v.tags} onChange={(_, x) => setV({ ...v, tags: x })} renderValue={(x, p) => x.map((o, i) => <Chip size="small" label={o} {...p({ index: i })} key={o} />)} renderInput={(p) => <TextField {...p} label="Tags" helperText="Type and press Enter" />} />
        </Stack>
      </DialogContent>
      <DialogActions>
        <Button onClick={onClose} disabled={save.isPending}>Cancel</Button>
        <Button type="submit" form="edit-lead-form" variant="contained" disabled={save.isPending}>{save.isPending ? 'Saving…' : 'Save changes'}</Button>
      </DialogActions>
    </Dialog>
  );
}

/** Who else works on this lead. The owner stays accountable; a handler can log the kind of work they are set up for (see the client's team). */
function Handlers({ lead, canManage }: { lead: LeadDetail; canManage: boolean }) {
  const qc = useQueryClient();
  const toast = useToast();
  const directory = useDirectory();
  const [anchor, setAnchor] = useState<HTMLElement | null>(null);
  const done = () => void qc.invalidateQueries({ queryKey: ['leads'] });
  const fail = (e: unknown) => toast.error(e instanceof ApiProblem ? e.detail : 'That didn’t work. Please try again.');
  const add = useMutation({ mutationFn: (userId: string) => leadsApi.addHandler(lead.id, userId), onSuccess: () => { done(); toast.success('Added to this lead'); }, onError: fail });
  const remove = useMutation({ mutationFn: (userId: string) => leadsApi.removeHandler(lead.id, userId), onSuccess: done, onError: fail });
  const taken = new Set([lead.ownerId, ...lead.handlers.map((h) => h.userId)]);
  const choices = (directory.data ?? []).filter((m) => !taken.has(m.userId) && ['sales_agent', 'sales_manager', 'tenant_admin', 'tenant_owner'].includes(m.role));
  return (
    <Card title="Working on this lead" subtitle="The owner stays accountable. Others you add can log their calls and messages." actions={canManage && !lead.closedAt && <Button size="small" startIcon={<PersonAddAlt1RoundedIcon />} onClick={(e) => setAnchor(e.currentTarget)} disabled={choices.length === 0}>Add</Button>}>
      {lead.handlers.length === 0 ? (
        <Typography variant="body2" color="text.secondary">Just the owner. Add someone when a different person should call, message or do the visit.</Typography>
      ) : (
        <Stack spacing={0.75}>
          {lead.handlers.map((h) => (
            <Stack key={h.userId} direction="row" spacing={1} sx={{ alignItems: 'center' }}>
              <UserAvatar name={h.name} seed={h.userId} size={24} />
              <Typography variant="body2" sx={{ flex: 1, minWidth: 0 }} noWrap>{h.name}</Typography>
              <Pill>{channelSummary(h.workChannels)}</Pill>
              {canManage && <Tooltip title="Take off this lead"><Box component="button" type="button" aria-label={`Take ${h.name} off this lead`} onClick={() => remove.mutate(h.userId)} sx={{ all: 'unset', cursor: 'pointer', display: 'flex', p: 0.25, borderRadius: 1, color: 'text.secondary', '&:hover': { color: 'error.main' } }}><CloseRoundedIcon sx={{ fontSize: 16 }} /></Box></Tooltip>}
            </Stack>
          ))}
        </Stack>
      )}
      <Menu anchorEl={anchor} open={Boolean(anchor)} onClose={() => setAnchor(null)} slotProps={{ paper: { sx: { minWidth: 220, maxHeight: 320 } } }}>
        <Typography variant="overline" color="text.secondary" sx={{ px: 2 }}>Add to this lead</Typography>
        {choices.map((m) => <MenuItem key={m.userId} onClick={() => { setAnchor(null); add.mutate(m.userId); }}>{m.name}</MenuItem>)}
      </Menu>
    </Card>
  );
}