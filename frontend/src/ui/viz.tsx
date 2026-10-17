import { useEffect, useId, useMemo, useRef, useState, type FocusEvent as ReactFocusEvent, type MouseEvent as ReactMouseEvent, type ReactNode } from 'react';
import { Box, Stack, Typography, useTheme } from '@mui/material';

/**
 * The visual vocabulary for every infographic: small hand-built SVG components that draw to the width they are given, read colours from the
 * theme (so dark mode follows), carry a text alternative, and let you hover or focus to read the exact number. No charting library: each
 * picture is exactly as complicated as the question it answers.
 */

/** Width of an element, kept up to date (charts draw to the width they are given instead of stretching). */
export function useWidth() {
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

export const usePal = () => {
  const t = useTheme();
  return (t.vars ?? t).palette;
};

/** Where a lead came from keeps one colour on every screen. */
export const SOURCE_COLORS: Record<string, string> = {
  meta_ads: '#1877F2',
  facebook: '#1877F2',
  instagram: '#D6249F',
  google_ads: '#F5A524',
  website: '#6366F1',
  whatsapp: '#25B866',
  referral: '#0EA5A4',
  walk_in: '#F97316',
  phone: '#64748B',
  email: '#8B5CF6',
  other: '#94A3B8',
};
export const sourceColor = (s: string) => SOURCE_COLORS[s] ?? '#94A3B8';

/** Where a lead is now, cool to warm to done. */
export const STAGE_COLORS: Record<string, string> = {
  new: '#94A3B8',
  talking: '#38BDF8',
  qualified: '#8B5CF6',
  booked: '#F59E0B',
  won: '#10B981',
  closed: '#FB7185',
};

const niceMax = (v: number) => {
  if (v <= 4) return 4;
  const p = 10 ** Math.floor(Math.log10(v));
  const n = v / p;
  return (n <= 1 ? 1 : n <= 2 ? 2 : n <= 5 ? 5 : 10) * p;
};

/** A smooth line through points (Catmull-Rom to cubic Bézier). */
function smoothPath(pts: Array<[number, number]>): string {
  if (pts.length === 0) return '';
  if (pts.length === 1) return `M${pts[0]![0]} ${pts[0]![1]}`;
  let d = `M${pts[0]![0].toFixed(1)} ${pts[0]![1].toFixed(1)}`;
  for (let i = 0; i < pts.length - 1; i++) {
    const p0 = pts[i - 1] ?? pts[i]!;
    const p1 = pts[i]!;
    const p2 = pts[i + 1]!;
    const p3 = pts[i + 2] ?? p2;
    const c1: [number, number] = [p1[0] + (p2[0] - p0[0]) / 6, p1[1] + (p2[1] - p0[1]) / 6];
    const c2: [number, number] = [p2[0] - (p3[0] - p1[0]) / 6, p2[1] - (p3[1] - p1[1]) / 6];
    d += ` C${c1[0].toFixed(1)} ${c1[1].toFixed(1)}, ${c2[0].toFixed(1)} ${c2[1].toFixed(1)}, ${p2[0].toFixed(1)} ${p2[1].toFixed(1)}`;
  }
  return d;
}

// ── Sparkline ────────────────────────────────────────────────────────────────────────────────────────────

/** A tiny trend with no axes: the direction of something at a glance. The last point is marked. */
export function Sparkline({ values, color, height = 36, width, label }: { values: number[]; color?: string; height?: number; width?: number; label?: string }) {
  const pal = usePal();
  const [ref, w0] = useWidth();
  const w = width ?? w0;
  const c = color ?? pal.primary.main;
  const gid = useMemo(() => `sp${Math.random().toString(36).slice(2, 8)}`, []);
  const max = Math.max(1, ...values);
  const pad = 3;
  const pts: Array<[number, number]> = values.map((v, i) => [pad + (values.length > 1 ? (i / (values.length - 1)) * (w - pad * 2) : (w - pad * 2) / 2), pad + (1 - v / max) * (height - pad * 2)]);
  const line = smoothPath(pts);
  const last = pts[pts.length - 1];
  return (
    <Box ref={width ? undefined : ref} sx={{ width: width ?? '100%', height, lineHeight: 0 }} role="img" aria-label={label ?? `Trend: ${values.join(', ')}`}>
      {w > 0 && values.length > 0 && (
        <svg width={w} height={height} style={{ display: 'block', overflow: 'visible' }}>
          <defs>
            <linearGradient id={gid} x1="0" x2="0" y1="0" y2="1">
              <stop offset="0" stopColor={c} stopOpacity="0.28" />
              <stop offset="1" stopColor={c} stopOpacity="0" />
            </linearGradient>
          </defs>
          <path d={`${line} L${pts[pts.length - 1]![0].toFixed(1)} ${height} L${pts[0]![0].toFixed(1)} ${height} Z`} fill={`url(#${gid})`} />
          <path d={line} fill="none" stroke={c} strokeWidth={1.75} strokeLinecap="round" strokeLinejoin="round" />
          {last && <circle cx={last[0]} cy={last[1]} r={3} fill={pal.background.paper} stroke={c} strokeWidth={2} />}
        </svg>
      )}
    </Box>
  );
}

// ── Ring ─────────────────────────────────────────────────────────────────────────────────────────────────

/** A progress ring with a number in the middle: one share of one whole (on time, win rate, capacity). */
export function Ring({ value, size = 76, stroke = 8, color, children, label }: { value: number | null; size?: number; stroke?: number; color?: string; children?: ReactNode; label?: string }) {
  const pal = usePal();
  const v = value === null ? 0 : Math.max(0, Math.min(1, value));
  const r = (size - stroke) / 2;
  const circ = 2 * Math.PI * r;
  return (
    <Box sx={{ position: 'relative', width: size, height: size, flexShrink: 0 }} role="img" aria-label={label ?? (value === null ? 'No data' : `${Math.round(v * 100)}%`)}>
      <svg width={size} height={size} style={{ transform: 'rotate(-90deg)', display: 'block' }}>
        <circle cx={size / 2} cy={size / 2} r={r} fill="none" stroke={pal.divider} strokeWidth={stroke} />
        {v > 0 && <circle cx={size / 2} cy={size / 2} r={r} fill="none" stroke={color ?? pal.primary.main} strokeWidth={stroke} strokeLinecap="round" strokeDasharray={`${circ * v} ${circ}`} style={{ transition: 'stroke-dasharray 500ms cubic-bezier(0.2,0.8,0.2,1)' }} />}
      </svg>
      <Box sx={{ position: 'absolute', inset: 0, display: 'grid', placeItems: 'center', textAlign: 'center', lineHeight: 1.1 }}>{children}</Box>
    </Box>
  );
}

// ── Donut ────────────────────────────────────────────────────────────────────────────────────────────────

export interface Slice {
  key: string;
  label: string;
  value: number;
  color: string;
}
/** How a total divides, with the total in the middle. Hovering a slice or a legend row highlights the pair. */
export function Donut({ slices, size = 150, center, onSelect }: { slices: Slice[]; size?: number; center?: ReactNode; onSelect?: (key: string) => void }) {
  const pal = usePal();
  const [hover, setHover] = useState<string | null>(null);
  const total = slices.reduce((n, s) => n + s.value, 0);
  const stroke = Math.round(size * 0.14);
  const r = (size - stroke) / 2;
  const circ = 2 * Math.PI * r;
  let acc = 0;
  return (
    <Stack direction="row" spacing={2.5} sx={{ alignItems: 'center', flexWrap: 'wrap', rowGap: 2 }}>
      <Box sx={{ position: 'relative', width: size, height: size, flexShrink: 0 }} role="img" aria-label={slices.map((s) => `${s.label}: ${s.value}`).join(', ')}>
        <svg width={size} height={size} style={{ transform: 'rotate(-90deg)', display: 'block' }}>
          <circle cx={size / 2} cy={size / 2} r={r} fill="none" stroke={pal.divider} strokeWidth={stroke} />
          {total > 0 &&
            slices.filter((s) => s.value > 0).map((s) => {
              const len = (s.value / total) * circ;
              const gap = slices.filter((x) => x.value > 0).length > 1 ? 2.5 : 0;
              const el = (
                <circle
                  key={s.key}
                  cx={size / 2}
                  cy={size / 2}
                  r={r}
                  fill="none"
                  stroke={s.color}
                  strokeWidth={hover === s.key ? stroke + 3 : stroke}
                  strokeDasharray={`${Math.max(0, len - gap)} ${circ}`}
                  strokeDashoffset={-acc}
                  opacity={hover && hover !== s.key ? 0.35 : 1}
                  style={{ transition: 'opacity 120ms, stroke-width 120ms', cursor: onSelect ? 'pointer' : undefined }}
                  onMouseEnter={() => setHover(s.key)}
                  onMouseLeave={() => setHover(null)}
                  onClick={() => onSelect?.(s.key)}
                />
              );
              acc += len;
              return el;
            })}
        </svg>
        <Box sx={{ position: 'absolute', inset: 0, display: 'grid', placeItems: 'center', textAlign: 'center' }}>{center}</Box>
      </Box>
      <Stack spacing={0.75} sx={{ minWidth: 150, flex: 1 }}>
        {slices.map((s) => (
          <Stack
            key={s.key}
            direction="row"
            spacing={1}
            onMouseEnter={() => setHover(s.key)}
            onMouseLeave={() => setHover(null)}
            onClick={() => onSelect?.(s.key)}
            sx={{ alignItems: 'center', cursor: onSelect ? 'pointer' : undefined, opacity: hover && hover !== s.key ? 0.5 : 1, transition: 'opacity 120ms' }}
          >
            <Box sx={{ width: 10, height: 10, borderRadius: '3px', bgcolor: s.color, flexShrink: 0 }} />
            <Typography variant="body2" noWrap sx={{ flex: 1 }}>{s.label}</Typography>
            <Typography variant="body2" sx={{ fontWeight: 600 }}>{s.value.toLocaleString()}</Typography>
            <Typography variant="caption" color="text.secondary" sx={{ width: 34, textAlign: 'right' }}>{total ? `${Math.round((s.value / total) * 100)}%` : '—'}</Typography>
          </Stack>
        ))}
      </Stack>
    </Stack>
  );
}

// ── Lead flow (Sankey) ───────────────────────────────────────────────────────────────────────────────────

export interface FlowNode {
  key: string;
  label: string;
  total: number;
  color: string;
}
export interface FlowLink {
  from: string;
  to: string;
  count: number;
}
type Pick = { from?: string; to?: string };

const pct = (n: number, of: number) => (of > 0 ? Math.round((n / of) * 100) : 0);
const plural = (n: number) => `${n.toLocaleString()} lead${n === 1 ? '' : 's'}`;

/**
 * Where leads came from (left) and where they are now (right), joined by ribbons as wide as the number of leads. Each ribbon fades from the
 * colour of its source into the colour of where those leads ended up, so "which source actually becomes customers" reads in one look. Hover or
 * focus a ribbon or a name to light up its path and read the exact numbers; click (or press Enter) to open exactly those leads.
 */
export function FlowSankey({ left, right, links, height = 300, onSelect }: { left: FlowNode[]; right: FlowNode[]; links: FlowLink[]; height?: number; onSelect?: (sel: Pick) => void }) {
  const pal = usePal();
  const uid = useId().replace(/:/g, '');
  const [ref, w] = useWidth();
  const [hover, setHover] = useState<Pick | null>(null);
  const [tip, setTip] = useState<{ x: number; y: number; title: string; lines: string[] } | null>(null);
  const total = left.reduce((n, l) => n + l.total, 0);
  const narrow = w > 0 && w < 520;
  const labelW = narrow ? 84 : 132;
  const labelR = narrow ? 84 : 150;
  const nodeW = 14;
  const gap = 12;
  const top = 26; // room for the column headings
  const bottom = 4;

  const layout = useMemo(() => {
    const L = left.filter((n) => n.total > 0);
    const R = right.filter((n) => n.total > 0);
    const innerH = height - top - bottom;
    const usable = (cnt: number) => innerH - gap * Math.max(0, cnt - 1);
    const scale = total > 0 ? Math.min(usable(L.length), usable(R.length)) / total : 0;
    const place = (nodes: FlowNode[]) => {
      let y = top;
      return new Map(nodes.map((n) => { const h = Math.max(4, n.total * scale); const r = { y, h }; y += h + gap; return [n.key, r] as const; }));
    };
    const lp = place(L);
    const rp = place(R);
    // Ribbons leave and arrive in a fixed order, so they never cross needlessly.
    const lOff = new Map<string, number>();
    const rOff = new Map<string, number>();
    const rank = new Map(R.map((n, i) => [n.key, i]));
    const ribbons = [...links]
      .filter((l) => l.count > 0 && lp.has(l.from) && rp.has(l.to))
      .sort((a, b) => L.findIndex((n) => n.key === a.from) - L.findIndex((n) => n.key === b.from) || (rank.get(a.to) ?? 0) - (rank.get(b.to) ?? 0))
      .map((l) => {
        const h = Math.max(1.5, l.count * scale);
        const y0 = lp.get(l.from)!.y + (lOff.get(l.from) ?? 0);
        lOff.set(l.from, (lOff.get(l.from) ?? 0) + h);
        return { ...l, h, y0 };
      });
    const byRight = [...ribbons].sort((a, b) => (rank.get(a.to) ?? 0) - (rank.get(b.to) ?? 0) || L.findIndex((n) => n.key === a.from) - L.findIndex((n) => n.key === b.from));
    const withY = new Map<string, number>();
    for (const r of byRight) { const k = `${r.from}>${r.to}`; withY.set(k, rp.get(r.to)!.y + (rOff.get(r.to) ?? 0)); rOff.set(r.to, (rOff.get(r.to) ?? 0) + r.h); }
    return { L, R, lp, rp, ribbons: ribbons.map((r) => ({ ...r, y1: withY.get(`${r.from}>${r.to}`)! })) };
  }, [left, right, links, height, total]);

  if (!total) return <Typography variant="body2" color="text.secondary">No leads in this period yet.</Typography>;
  const x0 = labelW + nodeW;
  const x1 = Math.max(x0 + 40, w - labelR - nodeW);
  const mid = (x0 + x1) / 2;
  const active = (r: { from: string; to: string }) => !hover || ((hover.from ? hover.from === r.from : true) && (hover.to ? hover.to === r.to : true));
  const name = (list: FlowNode[], key: string) => list.find((n) => n.key === key)?.label ?? key;
  const colorOf = (list: FlowNode[], key: string) => list.find((n) => n.key === key)?.color ?? '#94A3B8';
  const short = (s: string) => (narrow && s.length > 11 ? `${s.slice(0, 10)}…` : s);

  const showTip = (e: ReactMouseEvent | ReactFocusEvent, title: string, lines: string[]) => {
    const box = ref.current?.getBoundingClientRect();
    if (!box) return;
    const at = 'clientX' in e ? { x: e.clientX, y: e.clientY } : { x: box.left + box.width / 2, y: box.top + 40 };
    setTip({ x: at.x - box.left, y: at.y - box.top, title, lines });
  };
  const clear = () => { setHover(null); setTip(null); };

  return (
    <Box
      ref={ref}
      sx={{ width: '100%', color: 'text.primary', position: 'relative', '& .flow-ribbon': { animation: 'flowIn 520ms ease both' }, '@keyframes flowIn': { from: { opacity: 0 }, to: {} } }}
      role="img"
      aria-label={`Lead flow: ${left.map((l) => `${l.label} ${l.total}`).join(', ')}. Now: ${right.map((r) => `${r.label} ${r.total}`).join(', ')}.`}
    >
      {w > 0 && (
        <svg width={w} height={height} style={{ display: 'block', overflow: 'visible' }} onMouseLeave={clear}>
          <defs>
            {layout.ribbons.map((r) => (
              <linearGradient key={`${r.from}>${r.to}`} id={`${uid}-${r.from}-${r.to}`} x1="0" x2="1" y1="0" y2="0">
                <stop offset="0" stopColor={colorOf(left, r.from)} />
                <stop offset="1" stopColor={colorOf(right, r.to)} />
              </linearGradient>
            ))}
          </defs>
          <text x={labelW + nodeW / 2} y={11} textAnchor="end" fontSize="10" fontWeight="700" letterSpacing="0.08em" fill={pal.text.secondary} style={{ textTransform: 'uppercase' }}>{narrow ? 'From' : 'Came from'}</text>
          <text x={x1 + nodeW / 2} y={11} textAnchor="start" fontSize="10" fontWeight="700" letterSpacing="0.08em" fill={pal.text.secondary} style={{ textTransform: 'uppercase' }}>Now</text>

          {layout.ribbons.map((r) => {
            const ra = active(r);
            const from = name(left, r.from);
            const to = name(right, r.to);
            const lines = [plural(r.count), `${pct(r.count, left.find((n) => n.key === r.from)?.total ?? 0)}% of ${from}`];
            const midY = (r.y0 + r.y1) / 2 + r.h / 2;
            return (
              <g key={`${r.from}>${r.to}`} className="flow-ribbon">
                <path
                  d={`M${x0} ${r.y0} C${mid} ${r.y0}, ${mid} ${r.y1}, ${x1} ${r.y1} L${x1} ${r.y1 + r.h} C${mid} ${r.y1 + r.h}, ${mid} ${r.y0 + r.h}, ${x0} ${r.y0 + r.h} Z`}
                  fill={`url(#${uid}-${r.from}-${r.to})`}
                  opacity={hover ? (ra ? 0.8 : 0.08) : 0.42}
                  style={{ transition: 'opacity 160ms', cursor: onSelect ? 'pointer' : undefined, outline: 'none' }}
                  tabIndex={onSelect ? 0 : undefined}
                  role={onSelect ? 'link' : undefined}
                  aria-label={`${from} to ${to}: ${plural(r.count)}`}
                  onMouseEnter={(e) => { setHover({ from: r.from, to: r.to }); showTip(e, `${from} → ${to}`, lines); }}
                  onMouseMove={(e) => showTip(e, `${from} → ${to}`, lines)}
                  onFocus={(e) => { setHover({ from: r.from, to: r.to }); showTip(e, `${from} → ${to}`, lines); }}
                  onBlur={clear}
                  onKeyDown={(e) => { if (e.key === 'Enter') onSelect?.({ from: r.from, to: r.to }); }}
                  onClick={() => onSelect?.({ from: r.from, to: r.to })}
                />
                {r.h >= 17 && x1 - x0 > 120 && (
                  <text x={mid} y={midY} textAnchor="middle" dominantBaseline="central" fontSize="11" fontWeight="700" fill="#fff" opacity={hover && !ra ? 0 : 0.95} pointerEvents="none" style={{ paintOrder: 'stroke', stroke: 'rgba(15,23,42,0.35)', strokeWidth: 2.5, transition: 'opacity 160ms' }}>{r.count}</text>
                )}
              </g>
            );
          })}

          {layout.L.map((n) => {
            const p = layout.lp.get(n.key)!;
            const dim = hover && hover.from && hover.from !== n.key;
            const won = links.find((l) => l.from === n.key && l.to === 'won')?.count ?? 0;
            return (
              <g
                key={n.key}
                style={{ cursor: onSelect ? 'pointer' : undefined, transition: 'opacity 160ms' }}
                opacity={dim ? 0.35 : 1}
                onMouseEnter={(e) => { setHover({ from: n.key }); showTip(e, n.label, [plural(n.total), `${pct(n.total, total)}% of all leads`, ...(won ? [`${won} won (${pct(won, n.total)}%)`] : [])]); }}
                onMouseMove={(e) => showTip(e, n.label, [plural(n.total), `${pct(n.total, total)}% of all leads`, ...(won ? [`${won} won (${pct(won, n.total)}%)`] : [])])}
                onClick={() => onSelect?.({ from: n.key })}
              >
                <rect x={labelW} y={p.y} width={nodeW} height={p.h} rx={4} fill={n.color} />
                <text x={labelW - 10} y={p.y + p.h / 2 - (p.h > 28 ? 7 : 0)} textAnchor="end" dominantBaseline="middle" fontSize="12.5" fontWeight="650" fill="currentColor">{short(n.label)}</text>
                {p.h > 28 && <text x={labelW - 10} y={p.y + p.h / 2 + 8} textAnchor="end" dominantBaseline="middle" fontSize="11" fill={pal.text.secondary}>{n.total.toLocaleString()} · {pct(n.total, total)}%</text>}
              </g>
            );
          })}
          {layout.R.map((n) => {
            const p = layout.rp.get(n.key)!;
            const dim = hover && hover.to && hover.to !== n.key;
            return (
              <g
                key={n.key}
                style={{ cursor: onSelect ? 'pointer' : undefined, transition: 'opacity 160ms' }}
                opacity={dim ? 0.35 : 1}
                onMouseEnter={(e) => { setHover({ to: n.key }); showTip(e, n.label, [plural(n.total), `${pct(n.total, total)}% of all leads`]); }}
                onMouseMove={(e) => showTip(e, n.label, [plural(n.total), `${pct(n.total, total)}% of all leads`])}
                onClick={() => onSelect?.({ to: n.key })}
              >
                <rect x={x1} y={p.y} width={nodeW} height={p.h} rx={4} fill={n.color} />
                <text x={x1 + nodeW + 10} y={p.y + p.h / 2 - (p.h > 28 ? 7 : 0)} dominantBaseline="middle" fontSize="12.5" fontWeight="650" fill="currentColor">{short(n.label)}</text>
                {p.h > 28 && <text x={x1 + nodeW + 10} y={p.y + p.h / 2 + 8} dominantBaseline="middle" fontSize="11" fill={pal.text.secondary}>{n.total.toLocaleString()} · {pct(n.total, total)}%</text>}
              </g>
            );
          })}
        </svg>
      )}
      {tip && (
        <Box
          role="status"
          sx={{ position: 'absolute', left: Math.min(Math.max(8, tip.x + 14), Math.max(8, w - 190)), top: Math.max(0, tip.y - 56), pointerEvents: 'none', zIndex: 2, minWidth: 140, px: 1.25, py: 0.9, borderRadius: 1.5, bgcolor: 'background.paper', boxShadow: '0 8px 24px rgba(15,23,42,.18), 0 0 0 1px rgba(15,23,42,.08)' }}
        >
          <Typography variant="caption" sx={{ display: 'block', fontWeight: 700, color: 'text.primary' }}>{tip.title}</Typography>
          {tip.lines.map((l) => <Typography key={l} variant="caption" color="text.secondary" sx={{ display: 'block' }}>{l}</Typography>)}
        </Box>
      )}
    </Box>
  );
}

/**
 * The same numbers, one row per source: each bar is split by where those leads are now, with the share that won on the right. Easier than
 * the ribbons when the question is "which source is worth the money", and it reads well on a phone.
 */
export function FlowRows({ left, right, links, onSelect }: { left: FlowNode[]; right: FlowNode[]; links: FlowLink[]; onSelect?: (sel: Pick) => void }) {
  const pal = usePal();
  const rows = left.filter((n) => n.total > 0);
  const stages = right.filter((n) => n.total > 0);
  const [hover, setHover] = useState<string | null>(null);
  if (!rows.length) return <Typography variant="body2" color="text.secondary">No leads in this period yet.</Typography>;
  const count = (from: string, to: string) => links.find((l) => l.from === from && l.to === to)?.count ?? 0;
  return (
    <Box role="group" aria-label="Where each source's leads are now">
      <Stack direction="row" spacing={2} sx={{ flexWrap: 'wrap', rowGap: 0.5, mb: 1.5 }}>
        {stages.map((s) => (
          <Stack key={s.key} direction="row" spacing={0.75} sx={{ alignItems: 'center', opacity: hover && hover !== s.key ? 0.4 : 1, transition: 'opacity 140ms' }}>
            <Box aria-hidden sx={{ width: 10, height: 10, borderRadius: '3px', bgcolor: s.color }} />
            <Typography variant="caption" color="text.secondary">{s.label}</Typography>
          </Stack>
        ))}
      </Stack>
      <Stack spacing={1.25}>
        {rows.map((r) => {
          const won = count(r.key, 'won');
          return (
            <Box key={r.key} sx={{ display: 'grid', gridTemplateColumns: { xs: '84px 1fr 44px', sm: '132px 1fr 56px' }, columnGap: 1.5, alignItems: 'center' }}>
              <Box sx={{ minWidth: 0 }}>
                <Stack direction="row" spacing={0.75} sx={{ alignItems: 'center' }}>
                  <Box aria-hidden sx={{ width: 8, height: 8, borderRadius: '50%', bgcolor: r.color, flexShrink: 0 }} />
                  <Typography variant="body2" noWrap sx={{ fontWeight: 600 }} title={r.label}>{r.label}</Typography>
                </Stack>
                <Typography variant="caption" color="text.secondary" sx={{ pl: 2 }}>{r.total.toLocaleString()} lead{r.total === 1 ? '' : 's'}</Typography>
              </Box>
              <Box sx={{ display: 'flex', height: 26, borderRadius: 1.5, overflow: 'hidden', bgcolor: 'action.hover', gap: '2px' }}>
                {stages.map((s) => {
                  const c = count(r.key, s.key);
                  if (!c) return null;
                  const share = (c / r.total) * 100;
                  return (
                    <Box
                      key={s.key}
                      component={onSelect ? 'button' : 'div'}
                      type={onSelect ? 'button' : undefined}
                      onClick={() => onSelect?.({ from: r.key, to: s.key })}
                      onMouseEnter={() => setHover(s.key)}
                      onMouseLeave={() => setHover(null)}
                      title={`${r.label} · ${s.label}: ${c} (${Math.round(share)}%)`}
                      aria-label={`${r.label}, ${s.label}: ${c} leads, ${Math.round(share)} percent`}
                      sx={{ width: `${share}%`, minWidth: 4, border: 0, p: 0, m: 0, bgcolor: s.color, color: '#fff', font: 'inherit', fontSize: 11, fontWeight: 700, cursor: onSelect ? 'pointer' : 'default', opacity: hover && hover !== s.key ? 0.35 : 1, transition: 'opacity 140ms, filter 140ms', '&:hover': { filter: 'brightness(1.08)' }, '&:focus-visible': { outline: `2px solid ${pal.primary.main}`, outlineOffset: -2 } }}
                    >
                      {share >= 11 ? c : ''}
                    </Box>
                  );
                })}
              </Box>
              <Box sx={{ textAlign: 'right' }}>
                <Typography variant="body2" sx={{ fontWeight: 700, color: won ? STAGE_COLORS.won : 'text.disabled' }}>{pct(won, r.total)}%</Typography>
                <Typography variant="caption" color="text.secondary" sx={{ fontSize: '0.625rem' }}>won</Typography>
              </Box>
            </Box>
          );
        })}
      </Stack>
    </Box>
  );
}

// ── Heatmap ──────────────────────────────────────────────────────────────────────────────────────────────

const DAYS = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
const hourLabel = (h: number) => (h === 0 ? '12am' : h === 12 ? '12pm' : h < 12 ? `${h}am` : `${h - 12}pm`);
/**
 * When things happen across a week: seven rows (Monday first), one cell per hour, darker is more. Used for "when do leads arrive", which tells
 * a client when someone must be ready to answer.
 */
export function Heatmap({ grid, unit = 'lead', color }: { grid: number[][]; unit?: string; color?: string }) {
  const pal = usePal();
  const [ref, w] = useWidth();
  const [hover, setHover] = useState<{ d: number; h: number } | null>(null);
  const max = Math.max(1, ...grid.flat());
  const labelW = 34;
  const top = 18;
  const cell = Math.max(8, Math.floor((w - labelW) / 24));
  const ch = Math.min(26, Math.max(14, Math.round(cell * 0.9)));
  const c = color ?? pal.primary.main;
  const plural = (n: number) => `${n} ${unit}${n === 1 ? '' : 's'}`;
  return (
    <Box ref={ref} sx={{ width: '100%', position: 'relative', color: 'text.secondary' }} role="img" aria-label={`When ${unit}s arrive across the week. Busiest: ${(() => { let b = { d: 0, h: 0, n: -1 }; grid.forEach((row, d) => row.forEach((n, h) => { if (n > b.n) b = { d, h, n }; })); return `${DAYS[b.d]} ${hourLabel(b.h)} with ${plural(b.n)}`; })()}`}>
      {w > 0 && (
        <svg width={w} height={top + 7 * (ch + 3)} style={{ display: 'block' }} onMouseLeave={() => setHover(null)}>
          {[0, 3, 6, 9, 12, 15, 18, 21].map((h) => <text key={h} x={labelW + h * cell + cell / 2} y={11} textAnchor="middle" fontSize="10" fill="currentColor">{hourLabel(h)}</text>)}
          {grid.map((row, d) => (
            <g key={d}>
              <text x={labelW - 8} y={top + d * (ch + 3) + ch / 2 + 4} textAnchor="end" fontSize="11" fill="currentColor">{DAYS[d]}</text>
              {row.map((n, h) => (
                <rect
                  key={h}
                  x={labelW + h * cell + 1}
                  y={top + d * (ch + 3)}
                  width={cell - 2}
                  height={ch}
                  rx={4}
                  fill={n === 0 ? pal.divider : c}
                  fillOpacity={n === 0 ? 0.55 : 0.14 + 0.86 * Math.sqrt(n / max)}
                  stroke={hover && hover.d === d && hover.h === h ? pal.text.primary : 'none'}
                  strokeWidth={1.5}
                  tabIndex={n > 0 ? 0 : -1}
                  aria-label={`${DAYS[d]} ${hourLabel(h)}: ${plural(n)}`}
                  onMouseEnter={() => setHover({ d, h })}
                  onFocus={() => setHover({ d, h })}
                  onBlur={() => setHover(null)}
                  style={{ outline: 'none' }}
                />
              ))}
            </g>
          ))}
        </svg>
      )}
      {hover && (
        <Box sx={{ position: 'absolute', top: top + hover.d * (ch + 3) - 34, left: Math.min(Math.max(labelW + hover.h * cell - 40, 0), Math.max(0, w - 130)), px: 1, py: 0.5, borderRadius: 1.5, bgcolor: 'text.primary', color: 'background.paper', pointerEvents: 'none', whiteSpace: 'nowrap', boxShadow: 3 }}>
          <Typography variant="caption" sx={{ fontWeight: 600, color: 'inherit' }}>{DAYS[hover.d]} {hourLabel(hover.h)} · {plural(grid[hover.d]![hover.h]!)}</Typography>
        </Box>
      )}
    </Box>
  );
}

// ── Reply speed ──────────────────────────────────────────────────────────────────────────────────────────

/** How fast people were answered: one bar per speed band, green when quick and warm to red when slow. */
export function SpeedBars({ buckets, unanswered, height = 150 }: { buckets: Array<{ key: string; label: string; count: number }>; unanswered?: number; height?: number }) {
  const pal = usePal();
  const [hover, setHover] = useState<string | null>(null);
  const all = [...buckets, ...(unanswered ? [{ key: 'none', label: 'No reply yet', count: unanswered }] : [])];
  const max = Math.max(1, ...all.map((b) => b.count));
  const ramp = ['#10B981', '#34D399', '#84CC16', '#FACC15', '#FB923C', '#F87171', '#DC2626', '#94A3B8'];
  return (
    <Box role="img" aria-label={all.map((b) => `${b.label}: ${b.count}`).join(', ')}>
      <Box sx={{ display: 'grid', gridTemplateColumns: `repeat(${all.length}, 1fr)`, gap: 0.75, alignItems: 'end', height }}>
        {all.map((b, i) => (
          <Box key={b.key} onMouseEnter={() => setHover(b.key)} onMouseLeave={() => setHover(null)} sx={{ display: 'flex', flexDirection: 'column', justifyContent: 'flex-end', height: '100%', alignItems: 'center', gap: 0.5 }}>
            <Typography variant="caption" sx={{ fontWeight: 600, opacity: b.count ? 1 : 0.35 }}>{b.count}</Typography>
            <Box sx={{ width: '100%', maxWidth: 44, height: `${Math.max(b.count ? 4 : 2, (b.count / max) * (height - 22))}px`, borderRadius: '6px 6px 3px 3px', bgcolor: b.key === 'none' ? pal.text.disabled : ramp[Math.min(i, ramp.length - 2)], opacity: hover && hover !== b.key ? 0.4 : b.count ? 1 : 0.3, transition: 'opacity 120ms, height 300ms' }} />
          </Box>
        ))}
      </Box>
      <Box sx={{ display: 'grid', gridTemplateColumns: `repeat(${all.length}, 1fr)`, gap: 0.75, mt: 0.75 }}>
        {all.map((b) => <Typography key={b.key} variant="caption" color="text.secondary" sx={{ textAlign: 'center', lineHeight: 1.2, fontSize: '0.6875rem' }}>{b.label}</Typography>)}
      </Box>
    </Box>
  );
}

// ── Funnel ───────────────────────────────────────────────────────────────────────────────────────────────

export interface FunnelStepVm {
  key: string;
  label: string;
  count: number;
  ofPrevious: number | null;
  to?: string;
}
/**
 * The journey from first contact to a sale as a tapering shape. Each step is as wide as its share of the first; the band between two steps
 * narrows by exactly what was lost, so the biggest drop is the steepest edge.
 */
export function FunnelShape({ steps, color }: { steps: FunnelStepVm[]; color?: string }) {
  const pal = usePal();
  const [ref, w] = useWidth();
  const top = Math.max(1, steps[0]?.count ?? 1);
  const rowH = 58;
  const head = 18;
  const bar = 22;
  const c = color ?? pal.primary.main;
  const widthOf = (n: number) => Math.max(n > 0 ? 8 : 0, (n / top) * w);
  return (
    <Box ref={ref} sx={{ width: '100%', color: 'text.primary' }} role="img" aria-label={steps.map((s) => `${s.label}: ${s.count}`).join(', ')}>
      {w > 0 && (
        <svg width={w} height={steps.length * rowH - (rowH - head - bar)} style={{ display: 'block' }}>
          {steps.map((s, i) => {
            const y = i * rowH;
            const by = y + head;
            const bw = widthOf(s.count);
            const x = (w - bw) / 2;
            const next = steps[i + 1];
            const nw = next ? widthOf(next.count) : 0;
            const nx = (w - nw) / 2;
            const drop = next && s.count > 0 ? Math.round((1 - next.count / s.count) * 100) : null;
            return (
              <g key={s.key}>
                {next && <path d={`M${x} ${by + bar} L${x + bw} ${by + bar} L${nx + nw} ${y + rowH + head} L${nx} ${y + rowH + head} Z`} fill={c} opacity={0.1} />}
                <rect x={x} y={by} width={bw} height={bar} rx={7} fill={c} opacity={1 - i * 0.14} />
                <text x={0} y={y + 12} fontSize="12.5" fontWeight="500" fill="currentColor">{s.label}</text>
                <text x={w} y={y + 12} textAnchor="end" fontSize="13" fontWeight="700" fill="currentColor">
                  {s.count.toLocaleString()}
                  {i > 0 && s.ofPrevious !== null && <tspan fontSize="11" fontWeight="400" fill={pal.text.secondary}> · {Math.round(s.ofPrevious * 100)}% of previous</tspan>}
                </text>
                {drop !== null && drop > 0 && bw > 0 && <text x={w / 2} y={by + bar + 15} textAnchor="middle" fontSize="10.5" fontWeight="600" fill={drop >= 50 ? pal.error.main : pal.text.secondary}>−{drop}%</text>}
              </g>
            );
          })}
        </svg>
      )}
    </Box>
  );
}

// ── Treemap ──────────────────────────────────────────────────────────────────────────────────────────────

export interface TreeItem {
  key: string;
  value: number;
  [extra: string]: unknown;
}
export interface Rect<T extends TreeItem = TreeItem> {
  item: T;
  x: number;
  y: number;
  w: number;
  h: number;
}
/** Squarified treemap layout: tiles as close to square as possible, area proportional to value. Pure, so it is easy to test. */
export function squarify<T extends TreeItem>(items: T[], width: number, height: number): Array<Rect<T>> {
  const list = items.filter((i) => i.value > 0).sort((a, b) => b.value - a.value);
  const total = list.reduce((n, i) => n + i.value, 0);
  const out: Array<Rect<T>> = [];
  if (!total || width <= 0 || height <= 0) return out;
  let x = 0;
  let y = 0;
  let w = width;
  let h = height;
  let rest = list.map((i) => ({ item: i, area: (i.value / total) * width * height }));
  const worst = (row: number[], side: number) => {
    const s = row.reduce((a, b) => a + b, 0);
    const mx = Math.max(...row);
    const mn = Math.min(...row);
    return Math.max((side * side * mx) / (s * s), (s * s) / (side * side * mn));
  };
  while (rest.length) {
    const side = Math.min(w, h);
    let row = [rest[0]!];
    let i = 1;
    while (i < rest.length && worst([...row, rest[i]!].map((r) => r.area), side) <= worst(row.map((r) => r.area), side)) {
      row = [...row, rest[i]!];
      i++;
    }
    const sum = row.reduce((a, b) => a + b.area, 0);
    if (w >= h) {
      const colW = sum / h;
      let yy = y;
      for (const r of row) { const rh = r.area / colW; out.push({ item: r.item, x, y: yy, w: colW, h: rh }); yy += rh; }
      x += colW;
      w -= colW;
    } else {
      const rowH = sum / w;
      let xx = x;
      for (const r of row) { const rw = r.area / rowH; out.push({ item: r.item, x: xx, y, w: rw, h: rowH }); xx += rw; }
      y += rowH;
      h -= rowH;
    }
    rest = rest.slice(row.length);
  }
  return out;
}

// ── Stacked proportion bar ───────────────────────────────────────────────────────────────────────────────

/** One bar split into labelled parts (a person's calls, messages and visits). Parts too small to label stay unlabelled but keep a tooltip. */
export function SplitBar({ parts, height = 10 }: { parts: Array<{ key: string; label: string; value: number; color: string }>; height?: number }) {
  const total = parts.reduce((n, p) => n + p.value, 0);
  return (
    <Box role="img" aria-label={parts.map((p) => `${p.label}: ${p.value}`).join(', ')} sx={{ display: 'flex', height, borderRadius: height, overflow: 'hidden', gap: '2px', bgcolor: total ? 'transparent' : 'action.hover' }}>
      {parts.filter((p) => p.value > 0).map((p) => <Box key={p.key} title={`${p.label}: ${p.value}`} sx={{ flex: p.value, minWidth: 4, bgcolor: p.color, borderRadius: 1 }} />)}
    </Box>
  );
}

/** "▲ 12%" against the period before; `invert` when down is good (reply time, cost per lead). */
export function Delta({ current, previous, invert = false }: { current: number | null; previous: number | null; invert?: boolean }) {
  if (current === null || previous === null || (previous === 0 && current === 0)) return null;
  if (previous === 0) return <Typography component="span" variant="caption" sx={{ fontWeight: 700, color: 'success.main', bgcolor: 'rgba(22,138,90,0.1)', px: 0.75, py: 0.125, borderRadius: 1 }}>new</Typography>;
  const diff = current - previous;
  if (diff === 0) return null;
  const good = invert ? diff < 0 : diff > 0;
  return (
    <Typography component="span" variant="caption" sx={{ fontWeight: 700, color: good ? 'success.main' : 'error.main', bgcolor: good ? 'rgba(22,138,90,0.1)' : 'rgba(217,45,32,0.09)', px: 0.75, py: 0.125, borderRadius: 1, whiteSpace: 'nowrap' }}>
      {diff > 0 ? '↑' : '↓'} {Math.round((Math.abs(diff) / previous) * 100)}%
    </Typography>
  );
}

export { niceMax, smoothPath };
