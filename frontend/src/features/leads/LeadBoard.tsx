import { useState } from 'react';
import { Link as RouterLink } from 'react-router-dom';
import { Box, Button, Chip, IconButton, ListItemText, Menu, MenuItem, Paper, Skeleton, Stack, Tooltip, Typography } from '@mui/material';
import MoreHorizRoundedIcon from '@mui/icons-material/MoreHorizRounded';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { pipelinesApi, type BoardStage, type Lead, type Pipeline } from '@/api/crm';
import { UserAvatar } from '@/ui/display';
import { ErrorState } from '@/ui/states';
import { FollowupText, formatMoney, ScoreBadge, SlaChip, useDirectory } from './shared';
import { useLeadMover } from './useLeadMover';
import { kindOfStatus } from './ReasonDialog';

/**
 * Kanban board. Drag a card to another column (or use the card menu — the keyboard and screen-reader path).
 * The move goes through the same rules as everywhere else; the card jumps immediately and snaps back if the server says no.
 */
export function LeadBoard({ pipeline, ownerId, q }: { pipeline: Pipeline; ownerId?: string; q?: string }) {
  const qc = useQueryClient();
  const key = ['pipelines', 'board', pipeline.id, ownerId ?? '', q ?? ''];
  const board = useQuery({ queryKey: key, queryFn: () => pipelinesApi.board(pipeline.id, { ownerId, q, perStage: 20 }), placeholderData: (p) => p });
  const forecast = useQuery({ queryKey: ['pipelines', 'forecast', pipeline.id], queryFn: () => pipelinesApi.forecast(pipeline.id) });
  const directory = useDirectory();
  const owners = new Map((directory.data ?? []).map((m) => [m.userId, m.name]));
  const mover = useLeadMover();
  const [dragId, setDragId] = useState<string | null>(null);
  const [over, setOver] = useState<string | null>(null);

  const moveCard = async (lead: Lead, target: BoardStage) => {
    if (lead.stageId === target.id) return;
    const before = qc.getQueryData<{ stages: BoardStage[] }>(key);
    // optimistic: take the card out of its column and put it at the top of the target
    qc.setQueryData<{ stages: BoardStage[] }>(key, (d) =>
      d && {
        stages: d.stages.map((s) =>
          s.id === lead.stageId ? { ...s, count: s.count - 1, value: s.value - (lead.estimatedValue ?? 0), leads: s.leads.filter((l) => l.id !== lead.id) }
          : s.id === target.id ? { ...s, count: s.count + 1, value: s.value + (lead.estimatedValue ?? 0), leads: [{ ...lead, stageId: target.id, stageName: target.name, stageColor: target.color }, ...s.leads] }
          : s
        ),
      }
    );
    const ok = await mover.move(lead, { stageId: target.id, name: target.name, kind: kindOfStatus(target.mapsToStatus) });
    if (!ok && before) qc.setQueryData(key, before);
    void qc.invalidateQueries({ queryKey: ['pipelines', 'forecast'] });
  };

  if (board.isError) return <ErrorState error={board.error} onRetry={() => void board.refetch()} />;
  if (!board.data) {
    return (
      <Stack direction="row" spacing={1.5} sx={{ overflow: 'hidden' }}>
        {Array.from({ length: 5 }, (_, i) => <Skeleton key={i} variant="rounded" width={280} height={360} sx={{ flexShrink: 0 }} />)}
      </Stack>
    );
  }
  const f = forecast.data;
  return (
    <Box>
      {f && (
        <Stack direction="row" spacing={4} sx={{ mb: 2, flexWrap: 'wrap', rowGap: 1 }} aria-label="Pipeline forecast">
          {[['Open leads', f.open.count.toLocaleString()], ['Open value', formatMoney(f.open.value)], ['Weighted forecast', formatMoney(f.open.weighted)], ['Won deal value', `${formatMoney(f.won.dealValue)} · ${f.won.count.toLocaleString()} won${f.won.withoutDealValue ? ` (${f.won.withoutDealValue.toLocaleString()} without a recorded sale)` : ''}`]].map(([label, value]) => (
            <Box key={label}>
              <Typography variant="caption" color="text.secondary">{label}</Typography>
              <Typography variant="h4" component="p" sx={{ fontVariantNumeric: 'tabular-nums' }}>{value}</Typography>
            </Box>
          ))}
        </Stack>
      )}
      <Box sx={{ display: 'flex', gap: 1.5, overflowX: 'auto', pb: 2, mx: { xs: -2, md: 0 }, px: { xs: 2, md: 0 }, scrollSnapType: { xs: 'x mandatory', md: 'none' } }} role="list" aria-label="Pipeline stages" tabIndex={0}>
        {board.data.stages.map((s) => (
          <Paper
            key={s.id}
            role="listitem"
            aria-label={`${s.name}, ${s.count} leads`}
            variant="outlined"
            onDragOver={(e) => { if (dragId) { e.preventDefault(); setOver(s.id); } }}
            onDragLeave={() => setOver((o) => (o === s.id ? null : o))}
            onDrop={(e) => {
              e.preventDefault();
              setOver(null);
              const lead = board.data.stages.flatMap((x) => x.leads).find((l) => l.id === (e.dataTransfer.getData('text/plain') || dragId));
              if (lead) void moveCard(lead, s);
            }}
            sx={{ width: 286, flexShrink: 0, scrollSnapAlign: 'start', display: 'flex', flexDirection: 'column', maxHeight: 'calc(100dvh - 330px)', minHeight: 200, bgcolor: over === s.id ? 'surface.selected' : 'surface.sunken', borderColor: over === s.id ? 'primary.main' : 'divider', transition: 'background-color 150ms, border-color 150ms' }}
          >
            <Box sx={{ p: 1.5, pb: 1 }}>
              <Stack direction="row" spacing={1} sx={{ alignItems: 'center' }}>
                <Box sx={{ width: 10, height: 10, borderRadius: '50%', bgcolor: s.color }} aria-hidden />
                <Typography variant="subtitle2" sx={{ flex: 1 }} noWrap>{s.name}</Typography>
                <Chip size="small" label={s.count} sx={{ fontVariantNumeric: 'tabular-nums' }} />
              </Stack>
              <Typography variant="caption" color="text.secondary">{formatMoney(s.value)}{!s.isClosed && s.probability > 0 ? ` · ${s.probability}% likely` : ''}</Typography>
            </Box>
            <Stack spacing={1} sx={{ p: 1, pt: 0.5, overflowY: 'auto', flex: 1 }}>
              {s.leads.map((l) => (
                <Card key={l.id} lead={l} owner={l.ownerId ? owners.get(l.ownerId) : undefined} stages={board.data.stages} dragging={dragId === l.id} onDragStart={() => setDragId(l.id)} onDragEnd={() => { setDragId(null); setOver(null); }} onMove={(t) => void moveCard(l, t)} />
              ))}
              {s.count > s.leads.length && (
                <Button size="small" component={RouterLink} to={`/leads?stage=${s.id}&state=${s.isClosed ? 'closed' : 'open'}`}>+{s.count - s.leads.length} more in the list</Button>
              )}
              {s.count === 0 && <Typography variant="caption" color="text.disabled" sx={{ textAlign: 'center', py: 3 }}>Drop a lead here</Typography>}
            </Stack>
          </Paper>
        ))}
      </Box>
      {mover.dialog}
    </Box>
  );
}

function Card({ lead, owner, stages, dragging, onDragStart, onDragEnd, onMove }: { lead: Lead; owner?: string; stages: BoardStage[]; dragging: boolean; onDragStart: () => void; onDragEnd: () => void; onMove: (s: BoardStage) => void }) {
  const [menu, setMenu] = useState<HTMLElement | null>(null);
  return (
    <Paper
      draggable
      onDragStart={(e) => { e.dataTransfer.setData('text/plain', lead.id); e.dataTransfer.effectAllowed = 'move'; onDragStart(); }}
      onDragEnd={onDragEnd}
      sx={{ p: 1.25, cursor: 'grab', opacity: dragging ? 0.45 : 1, boxShadow: 1, '&:hover': { boxShadow: 2 }, transition: 'box-shadow 150ms, opacity 150ms' }}
    >
      <Stack direction="row" spacing={0.5} sx={{ alignItems: 'flex-start' }}>
        <Box sx={{ flex: 1, minWidth: 0 }}>
          <Typography component={RouterLink} to={`/leads/${lead.id}`} variant="subtitle2" noWrap sx={{ display: 'block', color: 'text.primary', textDecoration: 'none', '&:hover': { textDecoration: 'underline' } }}>
            {lead.contactName ?? 'Unnamed lead'}
          </Typography>
          <Typography variant="caption" color="text.secondary" noWrap component="div">{[lead.estimatedValue !== null ? formatMoney(lead.estimatedValue, lead.currency) : null, lead.locations[0]].filter(Boolean).join(' · ') || ' '}</Typography>
        </Box>
        <ScoreBadge lead={lead} />
        <IconButton size="small" aria-label={`Move ${lead.contactName ?? 'lead'} to another stage`} onClick={(e) => setMenu(e.currentTarget)} sx={{ mt: -0.25, mr: -0.5 }}>
          <MoreHorizRoundedIcon fontSize="small" />
        </IconButton>
      </Stack>
      <Stack direction="row" spacing={0.75} sx={{ mt: 1, alignItems: 'center', flexWrap: 'wrap', rowGap: 0.5 }}>
        <SlaChip sla={lead.sla} quiet />
        {lead.tags.slice(0, 2).map((t) => <Chip key={t} size="small" variant="outlined" label={t} />)}
        <Box sx={{ flex: 1 }} />
        {lead.nextFollowupAt && <Tooltip title="Next follow-up"><Box component="span"><FollowupText at={lead.nextFollowupAt} /></Box></Tooltip>}
        {owner && <Tooltip title={owner} describeChild><Box component="span"><UserAvatar name={owner} seed={lead.ownerId ?? undefined} size={22} /></Box></Tooltip>}
      </Stack>
      <Menu anchorEl={menu} open={Boolean(menu)} onClose={() => setMenu(null)}>
        {stages.filter((s) => s.id !== lead.stageId).map((s) => (
          <MenuItem key={s.id} onClick={() => { setMenu(null); onMove(s); }}>
            <Box sx={{ width: 8, height: 8, borderRadius: '50%', bgcolor: s.color, mr: 1.25 }} aria-hidden />
            <ListItemText>Move to {s.name}</ListItemText>
          </MenuItem>
        ))}
      </Menu>
    </Paper>
  );
}
