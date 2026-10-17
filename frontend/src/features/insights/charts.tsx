import { useEffect, useRef, useState, type ReactNode } from 'react';
import { Box, Stack, Typography, useTheme } from '@mui/material';

/** Width of an element, kept up to date (charts draw to the width they are given instead of stretching). */
function useWidth() {
  const ref = useRef<HTMLDivElement>(null);
  const [w, setW] = useState(0);
  useEffect(() => {
    const el = ref.current;
    if (!el) return;
    setW(el.clientWidth);
    const ro = new ResizeObserver(([e]) => e && setW(Math.floor(e.contentRect.width)));
    ro.observe(el);
    return () => ro.disconnect();
  }, []);
  return [ref, w] as const;
}

const niceMax = (v: number) => {
  if (v <= 4) return 4;
  const p = 10 ** Math.floor(Math.log10(v));
  const n = v / p;
  return (n <= 1 ? 1 : n <= 2 ? 2 : n <= 5 ? 5 : 10) * p;
};

export interface TrendPoint {
  label: string;
  leads: number;
  sales: number;
}

/**
 * Leads per day (or week, or month) as bars, with won deals as a line over them. Reads colours from the theme; hover or focus a day to read it.
 * The numbers are also in the summary text, so the picture is never the only way to get them.
 */
export function TrendChart({ points, salesLabel = 'Won', height = 230 }: { points: TrendPoint[]; salesLabel?: string; height?: number }) {
  const theme = useTheme();
  const pal = (theme.vars ?? theme).palette;
  const [ref, w] = useWidth();
  const [hover, setHover] = useState<number | null>(null);
  const pad = { l: 34, r: 6, t: 10, b: 24 };
  const innerW = Math.max(0, w - pad.l - pad.r);
  const innerH = height - pad.t - pad.b;
  const max = niceMax(Math.max(1, ...points.map((p) => p.leads), ...points.map((p) => p.sales)));
  const slot = points.length ? innerW / points.length : 0;
  const y = (v: number) => pad.t + innerH - (v / max) * innerH;
  const every = Math.max(1, Math.ceil(points.length / Math.max(1, Math.floor(innerW / 64))));
  const hasSales = points.some((p) => p.sales > 0);
  const line = points.map((p, i) => `${i ? 'L' : 'M'}${(pad.l + slot * i + slot / 2).toFixed(1)} ${y(p.sales).toFixed(1)}`).join(' ');
  const total = points.reduce((n, p) => n + p.leads, 0);
  const h = hover !== null ? points[hover] : null;

  return (
    <Box ref={ref} sx={{ position: 'relative', width: '100%', color: 'text.secondary' }} role="img" aria-label={`Leads over time: ${total} in total${hasSales ? `, ${points.reduce((n, p) => n + p.sales, 0)} ${salesLabel.toLowerCase()}` : ''}`}>
      {w > 0 && (
        <svg width={w} height={height} style={{ display: 'block' }} onMouseLeave={() => setHover(null)}>
          {[0, 0.5, 1].map((f) => (
            <g key={f}>
              <line x1={pad.l} x2={w - pad.r} y1={y(max * f)} y2={y(max * f)} stroke={pal.divider} strokeDasharray={f === 0 ? undefined : '3 4'} />
              <text x={pad.l - 8} y={y(max * f) + 4} textAnchor="end" fontSize="11" fill="currentColor">{Math.round(max * f)}</text>
            </g>
          ))}
          {points.map((p, i) => {
            const bh = Math.max(p.leads ? 2 : 0, (p.leads / max) * innerH);
            const bw = Math.max(2, Math.min(34, slot * 0.66));
            const x = pad.l + slot * i + (slot - bw) / 2;
            return (
              <g key={i}>
                <rect x={x} y={pad.t + innerH - bh} width={bw} height={bh} rx={Math.min(4, bw / 2)} fill={pal.primary.main} opacity={hover === null || hover === i ? 1 : 0.45} />
                {i % every === 0 && <text x={pad.l + slot * i + slot / 2} y={height - 6} textAnchor="middle" fontSize="11" fill="currentColor">{p.label}</text>}
                <rect x={pad.l + slot * i} y={pad.t} width={slot} height={innerH} fill="transparent" tabIndex={0} aria-label={`${p.label}: ${p.leads} leads${p.sales ? `, ${p.sales} ${salesLabel.toLowerCase()}` : ''}`} onMouseEnter={() => setHover(i)} onFocus={() => setHover(i)} onBlur={() => setHover(null)} style={{ outline: 'none' }} />
              </g>
            );
          })}
          {hasSales && (
            <>
              <path d={line} fill="none" stroke={pal.success.main} strokeWidth={2} strokeLinejoin="round" />
              {points.map((p, i) => p.sales > 0 && <circle key={i} cx={pad.l + slot * i + slot / 2} cy={y(p.sales)} r={3.5} fill={pal.background.paper} stroke={pal.success.main} strokeWidth={2} />)}
            </>
          )}
        </svg>
      )}
      {h && hover !== null && (
        <Box sx={{ position: 'absolute', top: 0, left: Math.min(Math.max(pad.l + slot * hover + slot / 2 - 60, 0), Math.max(0, w - 130)), width: 120, p: 1, borderRadius: 1.5, bgcolor: 'background.paper', border: 1, borderColor: 'divider', boxShadow: 2, pointerEvents: 'none', color: 'text.primary' }}>
          <Typography variant="caption" color="text.secondary" component="div">{h.label}</Typography>
          <Typography variant="body2" sx={{ fontWeight: 600 }}>{h.leads} lead{h.leads === 1 ? '' : 's'}</Typography>
          {hasSales && <Typography variant="caption" sx={{ color: 'success.main' }}>{h.sales} {salesLabel.toLowerCase()}</Typography>}
        </Box>
      )}
    </Box>
  );
}

export interface FunnelStep {
  key: string;
  label: string;
  count: number;
  /** Share of the step before it (null for the first). */
  ofPrevious: number | null;
  to?: string;
}

/**
 * The journey from first contact to a sale: each step is a bar as long as its share of the first step, with how many carried on from the step
 * before. The point of it is to see where people drop out.
 */
export function FunnelBars({ steps }: { steps: FunnelStep[] }) {
  const top = Math.max(1, steps[0]?.count ?? 1);
  return (
    <Stack spacing={1.25}>
      {steps.map((s, i) => {
        const ratio = s.count / top;
        return (
          <Box key={s.key}>
            <Stack direction="row" sx={{ justifyContent: 'space-between', alignItems: 'baseline', mb: 0.4 }}>
              <Typography variant="body2" sx={{ fontWeight: 500 }}>{s.label}</Typography>
              <Typography variant="body2" sx={{ fontVariantNumeric: 'tabular-nums' }}>
                <b>{s.count.toLocaleString()}</b>
                {s.ofPrevious !== null && i > 0 && <Typography component="span" variant="caption" color="text.secondary"> · {Math.round(s.ofPrevious * 100)}% of the step before</Typography>}
              </Typography>
            </Stack>
            <Box sx={{ height: 14, borderRadius: 7, bgcolor: 'action.hover', overflow: 'hidden' }}>
              <Box sx={{ height: '100%', width: `${Math.max(s.count ? 2 : 0, ratio * 100)}%`, borderRadius: 7, bgcolor: 'primary.main', opacity: 1 - i * 0.14, transition: 'width 300ms' }} />
            </Box>
          </Box>
        );
      })}
    </Stack>
  );
}

/** A coloured "up 20% on the period before" label. */
export function Change({ current, previous, invert = false }: { current: number; previous: number; invert?: boolean }) {
  if (previous === 0 && current === 0) return <Typography variant="caption" color="text.disabled">no change</Typography>;
  if (previous === 0) return <Typography variant="caption" sx={{ color: 'success.main', fontWeight: 600 }}>new</Typography>;
  const diff = current - previous;
  if (diff === 0) return <Typography variant="caption" color="text.secondary">same as before</Typography>;
  const good = invert ? diff < 0 : diff > 0;
  return (
    <Typography variant="caption" sx={{ color: good ? 'success.main' : 'error.main', fontWeight: 600 }}>
      {diff > 0 ? '▲' : '▼'} {Math.round((Math.abs(diff) / previous) * 100)}%
    </Typography>
  );
}

/** One key figure: a big number, what changed, and a one-line note. The card is a link when `to` is given. */
export function Kpi({ label, value, change, note, tone }: { label: string; value: ReactNode; change?: ReactNode; note?: ReactNode; tone?: 'warning' | 'danger' }) {
  return (
    <Box sx={{ p: 1.75, minWidth: 0 }}>
      <Typography variant="caption" color="text.secondary" component="div" noWrap>{label}</Typography>
      <Stack direction="row" spacing={1} sx={{ alignItems: 'baseline', mt: 0.25 }}>
        <Typography variant="display" component="div" sx={{ color: tone ? `${tone === 'danger' ? 'error' : 'warning'}.main` : 'text.primary' }}>{value}</Typography>
        {change}
      </Stack>
      {note && <Typography variant="caption" color="text.secondary" component="div" sx={{ mt: 0.25 }}>{note}</Typography>}
    </Box>
  );
}

/** A single stacked bar showing how a total divides (for example leads by source), with a legend you can read at a glance. */
export function ShareBar({ rows }: { rows: Array<{ key: string; label: string; value: number; color: string; to?: string }> }) {
  const total = rows.reduce((n, r) => n + r.value, 0);
  if (!total) return null;
  return (
    <Box role="img" aria-label={rows.map((r) => `${r.label}: ${r.value}`).join(', ')} sx={{ display: 'flex', height: 10, borderRadius: 5, overflow: 'hidden', gap: '2px' }}>
      {rows.filter((r) => r.value > 0).map((r) => <Box key={r.key} title={`${r.label}: ${r.value}`} sx={{ flex: r.value, minWidth: 4, bgcolor: r.color }} />)}
    </Box>
  );
}
