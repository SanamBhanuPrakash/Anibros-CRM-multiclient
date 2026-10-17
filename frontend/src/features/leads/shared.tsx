import { Box, Popover, Stack, Tooltip, Typography } from '@mui/material';
import { useQuery } from '@tanstack/react-query';
import { useState } from 'react';
import { membersApi } from '@/api/endpoints';
import { pipelinesApi, type Lead, type LeadSource, type LeadStatus, type SlaState } from '@/api/crm';
import { Pill, When, type Tone } from '@/ui/kit';

export const SOURCE_LABEL: Record<LeadSource, string> = {
  website: 'Website',
  referral: 'Referral',
  portal: 'Portal',
  direct: 'Direct',
  meta_ads: 'Meta ads',
  google_ads: 'Google ads',
  walk_in: 'Walk-in',
  other: 'Other',
};
export const STATUS_LABEL: Record<LeadStatus, string> = {
  open: 'Open',
  contacted: 'Contacted',
  qualified: 'Qualified',
  viewing_scheduled: 'Viewing scheduled',
  negotiation: 'Negotiation',
  won: 'Won',
  lost: 'Lost',
  disqualified: 'Not a fit',
};
export const FIELD_LABEL: Record<string, string> = {
  ownerId: 'an owner',
  estimatedValue: 'an estimated value',
  budgetMax: 'a maximum budget',
  phone: 'a phone number',
  email: 'an email address',
  nextFollowupAt: 'a next follow-up date',
};

const trim = (n: number) => String(Math.round(n * 100) / 100);

/** ₹1.25 Cr / ₹85 L / ₹42,000 for rupees; compact Intl formatting for everything else. */
export function formatMoney(value: number | null | undefined, currency = 'INR'): string {
  if (value === null || value === undefined) return '—';
  if (currency === 'INR') {
    if (value >= 1e7) return `₹${trim(value / 1e7)} Cr`;
    if (value >= 1e5) return `₹${trim(value / 1e5)} L`;
    return `₹${value.toLocaleString('en-IN')}`;
  }
  return new Intl.NumberFormat(undefined, { style: 'currency', currency, notation: 'compact', maximumFractionDigits: 1 }).format(value);
}

/** The default pipeline (and every other) — cached for the whole session, refreshed by realtime `pipeline.*` events. */
export function usePipelines() {
  return useQuery({ queryKey: ['pipelines'], queryFn: pipelinesApi.list, staleTime: 60_000 });
}
export function useDirectory() {
  return useQuery({ queryKey: ['members', 'directory'], queryFn: membersApi.directory, staleTime: 5 * 60_000 });
}

const scoreTone = (s: number): Tone => (s >= 70 ? 'success' : s >= 40 ? 'warning' : 'neutral');

/** Score with its reasons one click away: the number is never a black box. */
export function ScoreBadge({ lead, size = 'small' }: { lead: Pick<Lead, 'leadScore' | 'scoreBreakdown'>; size?: 'small' | 'medium' }) {
  const [anchor, setAnchor] = useState<HTMLElement | null>(null);
  return (
    <>
      <Pill
        tone={scoreTone(lead.leadScore)}
        onClick={(e) => {
          e.stopPropagation();
          setAnchor(e.currentTarget as HTMLElement);
        }}
        ariaLabel={`Lead score ${lead.leadScore}. Show why`}
        title="Why this score?"
        sx={{ minWidth: size === 'medium' ? 40 : 30, justifyContent: 'center', fontVariantNumeric: 'tabular-nums', height: size === 'medium' ? 26 : 22 }}
      >
        {lead.leadScore}
      </Pill>
      <Popover open={Boolean(anchor)} anchorEl={anchor} onClose={() => setAnchor(null)} onClick={(e) => e.stopPropagation()}>
        <Box sx={{ p: 2, width: 280 }}>
          <Typography variant="subtitle2">Why {lead.leadScore}?</Typography>
          <Stack spacing={0.75} sx={{ mt: 1 }}>
            {lead.scoreBreakdown.map((f) => (
              <Stack key={f.factor + f.note} direction="row" spacing={1} sx={{ justifyContent: 'space-between' }}>
                <Typography variant="body2" color="text.secondary" sx={{ textTransform: 'capitalize' }}>
                  {f.factor}
                  <Typography component="span" variant="caption" sx={{ display: "block" }}>{f.note}</Typography>
                </Typography>
                <Typography variant="body2" sx={{ fontVariantNumeric: 'tabular-nums', color: f.points < 0 ? 'error.main' : 'text.primary' }}>
                  {f.points > 0 ? '+' : ''}
                  {f.points}
                </Typography>
              </Stack>
            ))}
            {lead.scoreBreakdown.length === 0 && <Typography variant="body2" color="text.secondary">Nothing known about this lead yet.</Typography>}
          </Stack>
        </Box>
      </Popover>
    </>
  );
}

const SLA_COPY: Record<Exclude<SlaState, 'none' | 'met'>, { label: string; tone: Tone }> = {
  ok: { label: 'Reply due', tone: 'neutral' },
  at_risk: { label: 'Reply due soon', tone: 'warning' },
  breached: { label: 'Reply overdue', tone: 'danger' },
};
/** First-response clock; shows nothing once the lead has been answered or closed. */
export function SlaChip({ sla, quiet = false }: { sla: Lead['sla']; quiet?: boolean }) {
  if (sla.state === 'none' || sla.state === 'met') return null;
  if (quiet && sla.state === 'ok') return null; // lists and boards only call out leads that need attention
  const c = SLA_COPY[sla.state];
  return <Pill tone={c.tone} title={sla.dueAt ? `First reply due ${new Date(sla.dueAt).toLocaleString()}` : undefined}>{c.label}</Pill>;
}

/** The stage a lead is in: a dot in the stage's colour and its name, nothing boxed. */
export function StageChip({ name, color }: { name: string; color: string }) {
  return (
    <Box component="span" sx={{ display: 'inline-flex', alignItems: 'center', gap: 0.75, typography: 'body2', whiteSpace: 'nowrap' }}>
      <Box component="span" aria-hidden sx={{ width: 8, height: 8, borderRadius: '50%', bgcolor: color, flexShrink: 0 }} />
      {name}
    </Box>
  );
}

export function FollowupText({ at }: { at: string | null }) {
  return <Typography variant="body2" component="span"><When value={at} due /></Typography>;
}
