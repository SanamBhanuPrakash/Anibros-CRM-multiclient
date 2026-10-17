import { useMemo, useState } from 'react';
import { Box, Button, Stack, ToggleButton, ToggleButtonGroup, Typography } from '@mui/material';
import { Link as RouterLink, useNavigate } from 'react-router-dom';
import type { FlowStageKey, LeadFlow } from '@/api/analytics';
import { Card } from '@/ui/kit';
import { Delta, Donut, FlowRows, FlowSankey, Heatmap, Ring, Sparkline, SpeedBars, STAGE_COLORS, sourceColor, usePal } from '@/ui/viz';

/**
 * The composed infographic cards shared by the client Overview and the Reports page. Each one answers a single question in one look and
 * links through to the exact leads behind what it shows.
 */

/** The pipeline's own stages vary by industry; these plain groups do not. */
export const GROUP_STATUS: Record<FlowStageKey, string> = {
  new: 'open',
  talking: 'contacted',
  qualified: 'qualified',
  booked: 'viewing_scheduled,negotiation',
  won: 'won',
  closed: 'lost,disqualified',
};

export const minutesText = (m: number | null | undefined) => (m === null || m === undefined ? '—' : m < 1 ? '<1 min' : m < 60 ? `${Math.round(m)} min` : m < 1440 ? `${(m / 60).toFixed(1)} h` : `${(m / 1440).toFixed(1)} d`);

/** A link to the leads list for one slice of the picture (a source, a stage group, or both) in the period. */
export function leadsUrl(sel: { source?: string; stage?: FlowStageKey }, range: { from: string; to: string }) {
  const p = new URLSearchParams({ state: 'all', from: range.from, to: range.to });
  if (sel.source) p.set('source', sel.source);
  if (sel.stage) p.set('status', GROUP_STATUS[sel.stage]);
  return `/leads?${p.toString()}`;
}

const dayName = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
const hour12 = (h: number) => `${h % 12 === 0 ? 12 : h % 12}${h < 12 ? 'am' : 'pm'}`;

/** One big number with its trend: the headline of the page. */
export function PulseCard({ flow, previousTotal, extra }: { flow: LeadFlow; previousTotal?: number | null; extra?: React.ReactNode }) {
  const pal = usePal();
  const won = flow.stages.find((s) => s.key === 'won')?.total ?? 0;
  const closed = flow.stages.find((s) => s.key === 'closed')?.total ?? 0;
  const decided = won + closed;
  return (
    <Card pad={2.25} sx={{ height: '100%' }}>
      <Typography variant="eyebrow" color="text.secondary">New leads · {flow.range.days} days</Typography>
      <Stack direction="row" spacing={1.25} sx={{ alignItems: 'baseline', mt: 0.5 }}>
        <Typography component="div" sx={{ fontSize: '2.75rem', fontWeight: 650, letterSpacing: '-0.03em', lineHeight: 1, fontVariantNumeric: 'tabular-nums' }}>{flow.total.toLocaleString()}</Typography>
        <Delta current={flow.total} previous={previousTotal ?? null} />
      </Stack>
      <Box sx={{ mt: 1.5, mx: -0.5 }}>
        <Sparkline values={flow.daily.map((d) => d.leads)} color={pal.primary.main} height={110} label={`Leads per day: ${flow.daily.map((d) => d.leads).join(', ')}`} />
      </Box>
      <Box sx={{ display: 'grid', gridTemplateColumns: 'repeat(3, 1fr)', mt: 1.5, pt: 1.5, borderTop: 1, borderColor: 'divider', gap: 1 }}>
        <MiniStat label="Won" value={won.toLocaleString()} />
        <MiniStat label="Win rate" value={decided ? `${Math.round((won / decided) * 100)}%` : '—'} hint={decided ? `${won} of ${decided} decided` : 'nothing decided yet'} />
        <MiniStat label="Median reply" value={minutesText(flow.replyTimes.median)} />
      </Box>
      {flow.sources.length > 0 && (
        <Box sx={{ mt: 2, pt: 2, borderTop: 1, borderColor: 'divider' }}>
          <Typography variant="eyebrow" color="text.secondary" component="div" sx={{ mb: 1.25 }}>By source</Typography>
          <Donut size={92} slices={flow.sources.slice(0, 5).map((x) => ({ key: x.source, label: x.label, value: x.total, color: sourceColor(x.source) }))} center={<Typography variant="h3" component="div">{flow.total}</Typography>} />
        </Box>
      )}
      {extra}
    </Card>
  );
}

function MiniStat({ label, value, hint }: { label: string; value: string; hint?: string }) {
  return (
    <Box sx={{ minWidth: 0 }}>
      <Typography variant="caption" color="text.secondary" component="div" noWrap>{label}</Typography>
      <Typography variant="h3" component="div" sx={{ fontVariantNumeric: 'tabular-nums' }} noWrap>{value}</Typography>
      {hint && <Typography variant="caption" color="text.disabled" component="div" noWrap>{hint}</Typography>}
    </Box>
  );
}

const VIEW_KEY = 'anibrosdesign.flow-view';
const savedView = (): 'flow' | 'rows' => {
  try {
    return localStorage.getItem(VIEW_KEY) === 'rows' ? 'rows' : 'flow';
  } catch {
    return 'flow';
  }
};

/** Sources on the left, where those leads are now on the right. */
export function LeadFlowCard({ flow, actions }: { flow: LeadFlow; actions?: React.ReactNode }) {
  const nav = useNavigate();
  const [view, setView] = useState<'flow' | 'rows'>(savedView);
  const left = useMemo(() => flow.sources.map((s) => ({ key: s.source, label: s.label, total: s.total, color: sourceColor(s.source) })), [flow.sources]);
  const right = useMemo(() => flow.stages.map((s) => ({ key: s.key, label: s.label, total: s.total, color: STAGE_COLORS[s.key] ?? '#94A3B8' })), [flow.stages]);
  const links = useMemo(() => flow.links.map((l) => ({ from: l.source, to: l.stage, count: l.count })), [flow.links]);
  const best = useMemo(() => {
    // The source with the best share of won leads, but only when it has enough leads for the share to mean something.
    const rows = flow.sources.filter((s) => s.total >= 5).map((s) => ({ s, won: flow.links.find((l) => l.source === s.source && l.stage === 'won')?.count ?? 0 })).filter((r) => r.won > 0).sort((a, b) => b.won / b.s.total - a.won / a.s.total);
    return rows[0];
  }, [flow]);
  const choose = (_: unknown, v: 'flow' | 'rows' | null) => {
    if (!v) return;
    setView(v);
    try {
      localStorage.setItem(VIEW_KEY, v);
    } catch {
      /* the choice just is not remembered */
    }
  };
  const open = (sel: { from?: string; to?: string }) => nav(leadsUrl({ source: sel.from, stage: sel.to as FlowStageKey | undefined }, flow.range));
  const hint = view === 'flow' ? 'Each ribbon is as wide as the number of leads. Click one to see them.' : 'Each bar splits a source’s leads by where they are now. Click a part to see them.';
  return (
    <Card
      title="Where leads come from, and where they are now"
      subtitle={best ? `${best.s.label} converts best: ${best.won} of ${best.s.total} won. ${view === 'flow' ? 'Click a ribbon to see them.' : 'Click a part to see them.'}` : hint}
      actions={
        <Stack direction="row" spacing={1} sx={{ alignItems: 'center' }}>
          <ToggleButtonGroup exclusive size="small" value={view} onChange={choose} aria-label="Picture style" sx={{ '& .MuiToggleButton-root': { px: 1.25, py: 0.25, fontSize: '0.75rem', textTransform: 'none' } }}>
            <ToggleButton value="flow">Flow</ToggleButton>
            <ToggleButton value="rows">By source</ToggleButton>
          </ToggleButtonGroup>
          {actions}
        </Stack>
      }
    >
      {view === 'flow' ? (
        <FlowSankey left={left} right={right} links={links} height={Math.max(270, Math.min(420, 70 + left.length * 56))} onSelect={open} />
      ) : (
        <FlowRows left={left} right={right} links={links} onSelect={open} />
      )}
    </Card>
  );
}

/** When leads arrive across the week: the hours someone has to be ready. */
export function ArrivalCard({ flow }: { flow: LeadFlow }) {
  const peak = flow.arrivals.peak;
  const weekday = flow.arrivals.grid.slice(0, 5).flat().reduce((a, b) => a + b, 0);
  const sub = peak && flow.arrivals.total >= 5 ? `Busiest: ${dayName[peak.day]} around ${hour12(peak.hour)} (${peak.count} lead${peak.count === 1 ? '' : 's'})` : 'Fills in as leads arrive';
  return (
    <Card title="When leads arrive" subtitle={sub}>
      <Heatmap grid={flow.arrivals.grid} />
      {flow.arrivals.total >= 5 && (
        <Typography variant="caption" color="text.secondary" component="p" sx={{ mt: 1 }}>
          {Math.round((weekday / flow.arrivals.total) * 100)}% arrive Monday to Friday. Have someone ready to answer in the darker hours.
        </Typography>
      )}
    </Card>
  );
}

/** How fast people were answered, and how many were not answered at all. */
export function ReplySpeedCard({ flow, onTimeRate }: { flow: LeadFlow; onTimeRate?: number | null }) {
  const r = flow.replyTimes;
  const quick = r.buckets.filter((b) => ['m5', 'm15', 'm30'].includes(b.key)).reduce((n, b) => n + b.count, 0);
  const totalLeads = r.answered + r.unanswered;
  const share = onTimeRate ?? (totalLeads ? quick / totalLeads : null);
  return (
    <Card title="How fast you answer" subtitle={r.median !== null ? `Typical first reply: ${minutesText(r.median)}` : 'No replies logged in this period yet'}>
      <Stack direction="row" spacing={2.5} sx={{ alignItems: 'center', mb: 2 }}>
        <Ring value={share} size={84} stroke={9} color="#10B981" label={share === null ? 'No data' : `${Math.round(share * 100)}% answered within 30 minutes`}>
          <Box>
            <Typography variant="h3" component="div" sx={{ lineHeight: 1 }}>{share === null ? '—' : `${Math.round(share * 100)}%`}</Typography>
            <Typography variant="caption" color="text.secondary" sx={{ fontSize: '0.625rem' }}>{onTimeRate !== undefined && onTimeRate !== null ? 'on time' : '< 30 min'}</Typography>
          </Box>
        </Ring>
        <Typography variant="body2" color="text.secondary" sx={{ flex: 1 }}>
          {r.unanswered > 0 ? <><b style={{ color: 'inherit' }}>{r.unanswered}</b> lead{r.unanswered === 1 ? ' has' : 's have'} had no reply yet.</> : 'Every lead in this period has had a reply.'} Leads answered within minutes are far more likely to become customers.
        </Typography>
      </Stack>
      <SpeedBars buckets={r.buckets} unanswered={r.unanswered} height={118} />
    </Card>
  );
}

/** A heading row used above groups of infographic cards. */
export function SectionLabel({ children, to, action }: { children: React.ReactNode; to?: string; action?: string }) {
  return (
    <Stack direction="row" sx={{ alignItems: 'baseline', justifyContent: 'space-between', mb: 1, mt: 0.5 }}>
      <Typography variant="eyebrow" color="text.secondary">{children}</Typography>
      {to && action && <Button size="small" color="inherit" component={RouterLink} to={to} sx={{ mr: -1 }}>{action}</Button>}
    </Stack>
  );
}
