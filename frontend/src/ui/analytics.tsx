import { useMemo, type ReactNode } from 'react';
import { Link as RouterLink, useSearchParams } from 'react-router-dom';
import { Box, FormControlLabel, IconButton, Paper, Stack, Switch, TextField, ToggleButton, ToggleButtonGroup, Tooltip, Typography } from '@mui/material';
import HelpOutlineRoundedIcon from '@mui/icons-material/HelpOutlineRounded';
import ArrowOutwardRoundedIcon from '@mui/icons-material/ArrowOutwardRounded';
import { addDays, format } from 'date-fns';
import type { Theme } from '@mui/material/styles';
import { MetricHelp } from './Panel';

export { Panel, MetricHelp } from './Panel';

/** The theme's CSS-variable palette (follows the colour scheme), falling back to the plain palette outside a CSS-variables provider. */
const vars = (t: Theme) => (t.vars ?? t).palette;

/**
 * Shared building blocks for the analytical screens (Dashboard, Reports, Attribution). They read colours only from the theme's
 * semantic tokens, so both colour schemes are handled in one place and no analytical screen carries its own palette.
 */

// ── date range ────────────────────────────────────────────────────────────────────────────────────

export interface RangeInfo {
  from: string;
  to: string;
  days: number;
  timezone: string;
}
const iso = (d: Date) => format(d, 'yyyy-MM-dd');
const parse = (s: string) => new Date(`${s}T00:00:00`);
export const dayLabel = (s: string) => format(parse(s), 'd MMM');

/** The range lives in the URL (`from`, `to`, `includeTest`), so a link, a refresh or the back button keep the same view. */
export function useRangeParams() {
  const [params, setParams] = useSearchParams();
  const set = (patch: Record<string, string | undefined>) =>
    setParams(
      (p) => {
        const n = new URLSearchParams(p);
        for (const [k, v] of Object.entries(patch)) {
          if (v === undefined) n.delete(k);
          else n.set(k, v);
        }
        return n;
      },
      { replace: true }
    );
  return { params, set, from: params.get('from') ?? undefined, to: params.get('to') ?? undefined, includeTest: params.get('includeTest') === 'true' };
}

const PRESETS = [
  { id: '7', label: '7 days' },
  { id: '30', label: '30 days' },
  { id: '90', label: '90 days' },
  { id: 'month', label: 'This month' },
  { id: 'year', label: 'Year to date' },
] as const;

/** Presets, exact dates and (optionally) the test-lead switch. "Today" is the end of the range the server last answered with, i.e. workspace time. */
export function RangeBar({ range, includeTest, set, showTest = true, children }: { range?: RangeInfo; includeTest: boolean; set: (p: Record<string, string | undefined>) => void; showTest?: boolean; children?: ReactNode }) {
  const today = range?.to ?? iso(new Date());
  const active = useMemo(() => {
    if (!range) return null;
    const end = parse(range.to);
    if (range.to !== today) return 'custom';
    if (range.from === iso(new Date(end.getFullYear(), end.getMonth(), 1))) return 'month';
    if (range.from === iso(new Date(end.getFullYear(), 0, 1))) return 'year';
    return [7, 30, 90].includes(range.days) ? String(range.days) : 'custom';
  }, [range, today]);
  const preset = (id: string) => {
    const end = parse(today);
    if (id === 'month') return set({ from: iso(new Date(end.getFullYear(), end.getMonth(), 1)), to: today });
    if (id === 'year') return set({ from: iso(new Date(end.getFullYear(), 0, 1)), to: today });
    set({ from: iso(addDays(end, -(Number(id) - 1))), to: today });
  };
  return (
    <Stack direction="row" sx={{ mb: 2.5, alignItems: 'center', flexWrap: 'wrap', columnGap: 1.5, rowGap: 1.25 }} role="group" aria-label="Period">
      <ToggleButtonGroup size="small" exclusive value={active} aria-label="Quick periods" onChange={(_, v: string | null) => v && v !== 'custom' && preset(v)}>
        {PRESETS.map((p) => <ToggleButton key={p.id} value={p.id}>{p.label}</ToggleButton>)}
        {active === 'custom' && <ToggleButton value="custom" disabled>Custom</ToggleButton>}
      </ToggleButtonGroup>
      <TextField type="date" label="From" value={range?.from ?? ''} onChange={(e) => e.target.value && set({ from: e.target.value, to: range?.to })} slotProps={{ inputLabel: { shrink: true }, htmlInput: { max: range?.to } }} sx={{ width: 156 }} />
      <TextField type="date" label="To" value={range?.to ?? ''} onChange={(e) => e.target.value && set({ to: e.target.value, from: range?.from })} slotProps={{ inputLabel: { shrink: true }, htmlInput: { min: range?.from } }} sx={{ width: 156 }} />
      {children}
      <Box sx={{ flex: 1 }} />
      {showTest && (
        <Tooltip title="Leads tagged “test” (from “Send test lead”) are left out of business numbers unless you switch this on." describeChild>
          <FormControlLabel control={<Switch size="small" checked={includeTest} onChange={(_, v) => set({ includeTest: v ? 'true' : undefined })} />} label="Include test leads" slotProps={{ typography: { variant: 'body2' } }} />
        </Tooltip>
      )}
    </Stack>
  );
}

// ── tiles, panels, bars ───────────────────────────────────────────────────────────────────────────

/**
 * One headline figure. The label wraps (it is never cut off), the whole tile is a link when `to` is given, and the help button sits
 * outside that link so there is no interactive element inside another.
 */
export function StatTile({ to, label, value, sub, tone, help, size = 'md' }: { to?: string; label: string; value: ReactNode; sub?: ReactNode; tone?: 'error' | 'success' | 'warning'; help?: ReactNode; size?: 'md' | 'lg' }) {
  const body = (
    <>
      <Typography variant="body2" color="text.secondary" sx={{ fontWeight: 500, pr: help ? 3 : 0 }}>{label}</Typography>
      <Typography variant="h2" component="p" sx={{ mt: 0.25, fontSize: size === 'lg' ? '2rem' : '1.625rem', fontVariantNumeric: 'tabular-nums', lineHeight: 1.15, color: tone ? `${tone}.main` : 'text.primary' }}>{value}</Typography>
      {sub && <Typography variant="caption" color="text.secondary" component="div" sx={{ mt: 0.5, lineHeight: 1.35 }}>{sub}</Typography>}
    </>
  );
  const common = { variant: 'outlined' as const, sx: { p: 1.75, borderRadius: 2, minWidth: 0, height: '100%', display: 'block', color: 'inherit', textDecoration: 'none' } };
  return (
    <Box sx={{ position: 'relative', minWidth: 0 }}>
      {to ? (
        <Paper {...common} component={RouterLink} to={to} sx={{ ...common.sx, transition: 'border-color 0.15s', '&:hover, &:focus-visible': { borderColor: 'primary.main' } }}>
          {body}
        </Paper>
      ) : (
        <Paper {...common}>{body}</Paper>
      )}
      {to && <ArrowOutwardRoundedIcon aria-hidden sx={{ position: 'absolute', right: 10, bottom: 10, fontSize: 14, color: 'text.disabled', pointerEvents: 'none' }} />}
      {help && <Box sx={{ position: 'absolute', top: 8, right: 8 }}><MetricHelp label={label}>{help}</MetricHelp></Box>}
    </Box>
  );
}

export interface BarRow {
  key: string;
  label: string;
  value: number;
  /** Text shown for the value (defaults to the number). */
  display?: string;
  note?: string;
  to?: string;
  /** Theme chart colour index 1–6 or a palette path such as 'success.main'. */
  color?: number | string;
}

/**
 * A compact ranked list with an inline bar. It sizes itself to the data (one row for one source, ten for ten) instead of drawing a
 * chart canvas, every row is a link when `to` is given, and the figures are real text so nothing depends on the bar.
 */
export function BarList({ rows, max, empty }: { rows: BarRow[]; max?: number; empty?: ReactNode }) {
  if (rows.length === 0) return <Typography variant="body2" color="text.secondary">{empty ?? 'Nothing to show.'}</Typography>;
  const top = max ?? Math.max(...rows.map((r) => r.value), 1);
  return (
    <Stack component="ul" spacing={0.25} sx={{ listStyle: 'none', m: 0, p: 0 }}>
      {rows.map((r) => {
        const content = (
          <Box sx={{ display: 'grid', gridTemplateColumns: 'minmax(96px, 30%) 1fr auto', alignItems: 'center', columnGap: 1.25, py: 0.6, px: 0.75, borderRadius: 1 }}>
            <Typography variant="body2" sx={{ minWidth: 0, overflowWrap: 'anywhere' }}>{r.label}{r.note ? <Typography component="span" variant="caption" color="text.secondary"> · {r.note}</Typography> : null}</Typography>
            <Box aria-hidden sx={{ height: 8, borderRadius: 4, bgcolor: (t) => vars(t).chart.grid, overflow: 'hidden' }}>
              <Box sx={{ height: '100%', width: `${r.value > 0 ? Math.max(2, (r.value / top) * 100) : 0}%`, borderRadius: 4, bgcolor: (t) => (typeof r.color === 'string' ? (r.color.split('.').reduce<any>((o, k) => o?.[k], vars(t)) as string) : vars(t).chart[`s${r.color ?? 1}` as 's1']) }} />
            </Box>
            <Typography variant="body2" sx={{ fontVariantNumeric: 'tabular-nums', fontWeight: 600, textAlign: 'right', minWidth: 28 }}>{r.display ?? r.value.toLocaleString()}</Typography>
          </Box>
        );
        return (
          <li key={r.key}>
            {r.to ? (
              <Box component={RouterLink} to={r.to} sx={{ display: 'block', color: 'inherit', textDecoration: 'none', borderRadius: 1, '&:hover, &:focus-visible': { bgcolor: 'surface.hover' } }}>{content}</Box>
            ) : (
              content
            )}
          </li>
        );
      })}
    </Stack>
  );
}

/** Wraps a chart so assistive technology gets a text summary, and pointer cursors land on the clickable marks. */
export function ChartFrame({ summary, children }: { summary: string; children: ReactNode }) {
  return (
    <Box role="img" aria-label={summary} sx={{ '& .MuiBarChart-element': { cursor: 'pointer' }, '& .MuiPieChart-arc': { cursor: 'pointer' }, '& .MuiLineChart-mark': { cursor: 'pointer' } }}>
      {children}
    </Box>
  );
}

/** Plain-words change versus the previous period; no percentage when there is nothing to compare with. */
export function delta(current: number, previous: number, days: number, fmt: (n: number) => string = (n) => n.toLocaleString()): { text: string; tone: 'success' | 'error' | undefined } {
  const diff = current - previous;
  const window = `the previous ${days} day${days === 1 ? '' : 's'}`;
  if (previous === 0 && current === 0) return { text: `None, same as ${window}`, tone: undefined };
  if (previous === 0) return { text: `▲ ${fmt(current)} (none in ${window})`, tone: 'success' };
  if (diff === 0) return { text: `Same as ${window}`, tone: undefined };
  const pct = Math.round((Math.abs(diff) / previous) * 100);
  return { text: `${diff > 0 ? '▲' : '▼'} ${fmt(Math.abs(diff))} (${diff > 0 ? '+' : '−'}${pct}%) vs ${window}`, tone: diff > 0 ? 'success' : 'error' };
}

export const pct = (v: number | null | undefined) => (v === null || v === undefined ? '—' : `${Math.round(v * 1000) / 10}%`);
