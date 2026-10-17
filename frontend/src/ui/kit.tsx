import type { ReactNode } from 'react';
import { Box, Paper, Stack, Tooltip, Typography, type SxProps, type Theme } from '@mui/material';
import { Link as RouterLink } from 'react-router-dom';
import { clientColor } from '@/features/agency/shared';

/**
 * The small shared pieces every screen is built from: a compact "when", soft status pills, the client chip, a plain card.
 * One vocabulary on every screen is what makes the product feel like one thing.
 */

const MIN = 60_000;
const HOUR = 3_600_000;
const DAY = 86_400_000;

/** "just now", "5m ago", "3h ago", "Yesterday", "4d ago", "12 Oct"; future: "in 5m", "in 3h", "Tomorrow", "in 4d". Short on purpose. */
export function shortWhen(d: Date, now = new Date()): string {
  const diff = now.getTime() - d.getTime();
  const abs = Math.abs(diff);
  const past = diff >= 0;
  if (abs < 45_000) return 'just now';
  if (abs < HOUR) return past ? `${Math.max(1, Math.round(abs / MIN))}m ago` : `in ${Math.max(1, Math.round(abs / MIN))}m`;
  if (abs < DAY) return past ? `${Math.round(abs / HOUR)}h ago` : `in ${Math.round(abs / HOUR)}h`;
  const startOf = (x: Date) => new Date(x.getFullYear(), x.getMonth(), x.getDate()).getTime();
  const days = Math.round((startOf(now) - startOf(d)) / DAY);
  if (days === 1) return 'Yesterday';
  if (days === -1) return 'Tomorrow';
  if (Math.abs(days) < 7) return past ? `${days}d ago` : `in ${-days}d`;
  return d.toLocaleDateString(undefined, { day: 'numeric', month: 'short', ...(d.getFullYear() !== now.getFullYear() ? { year: 'numeric' } : {}) });
}

/** A time that fits in a table cell, with the exact moment on hover. `due` turns a past time red (follow-ups, deadlines). */
export function When({ value, fallback = '—', due = false, sx }: { value: string | Date | null | undefined; fallback?: string; due?: boolean; sx?: SxProps<Theme> }) {
  if (!value) return <Box component="span" sx={{ color: 'text.disabled', ...(sx as object) }}>{fallback}</Box>;
  const d = typeof value === 'string' ? new Date(value) : value;
  const overdue = due && d.getTime() < Date.now();
  return (
    <Tooltip title={d.toLocaleString()}>
      <Box component="time" dateTime={d.toISOString()} sx={{ color: overdue ? 'error.main' : 'text.secondary', fontWeight: overdue ? 600 : 400, whiteSpace: 'nowrap', ...(sx as object) }}>
        {shortWhen(d)}
      </Box>
    </Tooltip>
  );
}

export type Tone = 'neutral' | 'accent' | 'success' | 'warning' | 'danger' | 'info';
const MAIN: Record<Exclude<Tone, 'neutral' | 'accent'>, 'success' | 'warning' | 'error' | 'info'> = { success: 'success', warning: 'warning', danger: 'error', info: 'info' };

/** A soft, tinted status label. Colour carries meaning (never decoration); the text always says it too. */
export function Pill({ children, tone = 'neutral', icon, onClick, title, ariaLabel, sx }: { children: ReactNode; tone?: Tone; icon?: ReactNode; onClick?: (e: React.MouseEvent) => void; title?: string; ariaLabel?: string; sx?: SxProps<Theme> }) {
  const content = (
    <Box
      component={onClick ? 'button' : 'span'}
      type={onClick ? 'button' : undefined}
      onClick={onClick}
      aria-label={ariaLabel}
      sx={[
        (t: Theme) => {
          const v = t.vars!.palette;
          const base =
            tone === 'neutral' ? { bg: v.neutralTone.bg, fg: v.neutralTone.fg } : tone === 'accent' ? { bg: v.accentTone.bg, fg: v.accentTone.fg } : { bg: `rgba(${v[MAIN[tone]].mainChannel} / 0.13)`, fg: v[MAIN[tone]].dark };
          return {
            all: onClick ? 'unset' : undefined,
            boxSizing: 'border-box',
            display: 'inline-flex',
            alignItems: 'center',
            gap: 0.6,
            height: 22,
            px: 1,
            borderRadius: '6px',
            bgcolor: base.bg,
            color: base.fg,
            fontSize: '0.75rem',
            fontWeight: 600,
            lineHeight: 1,
            whiteSpace: 'nowrap',
            cursor: onClick ? 'pointer' : undefined,
            '&:hover': onClick ? { filter: 'brightness(0.96)' } : undefined,
            '&:focus-visible': { outline: `2px solid ${v.primary.main}`, outlineOffset: 1 },
            ...(tone !== 'neutral' && tone !== 'accent' ? t.applyStyles('dark', { color: v[MAIN[tone]].light }) : {}),
          };
        },
        ...(Array.isArray(sx) ? sx : sx ? [sx] : []),
      ]}
    >
      {icon}
      {children}
    </Box>
  );
  return title ? <Tooltip title={title}>{content}</Tooltip> : content;
}

export function ClientDot({ id, size = 8 }: { id: string; size?: number }) {
  return <Box component="span" aria-hidden sx={{ width: size, height: size, borderRadius: '50%', bgcolor: clientColor(id), flexShrink: 0, display: 'inline-block' }} />;
}

/** The client a record belongs to: a coloured dot and the name. Clickable when it should take you somewhere. */
export function ClientChip({ id, name, onClick }: { id: string; name: string; onClick?: (e: React.MouseEvent) => void }) {
  return (
    <Box
      component={onClick ? 'button' : 'span'}
      type={onClick ? 'button' : undefined}
      onClick={onClick}
      sx={{ all: onClick ? 'unset' : undefined, boxSizing: 'border-box', display: 'inline-flex', alignItems: 'center', gap: 0.75, maxWidth: '100%', color: 'text.primary', typography: 'body2', cursor: onClick ? 'pointer' : undefined, '&:hover': onClick ? { color: 'primary.main' } : undefined, '&:focus-visible': { outline: '2px solid', outlineColor: 'primary.main', outlineOffset: 2, borderRadius: 1 } }}
    >
      <ClientDot id={id} />
      <Box component="span" sx={{ overflow: 'hidden', textOverflow: 'ellipsis', whiteSpace: 'nowrap' }}>{name}</Box>
    </Box>
  );
}

/** The one container for grouped content: white, thin border, optional heading row. */
export function Card({ title, subtitle, actions, children, pad = 2, sx, id }: { title?: ReactNode; subtitle?: ReactNode; actions?: ReactNode; children: ReactNode; pad?: number; sx?: SxProps<Theme>; id?: string }) {
  return (
    <Paper variant="outlined" component="section" aria-labelledby={title && id ? `${id}-title` : undefined} sx={[{ borderRadius: 2.5, minWidth: 0, overflow: 'hidden' }, ...(Array.isArray(sx) ? sx : sx ? [sx] : [])]}>
      {(title || actions) && (
        <Stack direction="row" sx={{ px: Math.max(pad, 2), pt: 1.5, pb: 1.25, alignItems: 'center', justifyContent: 'space-between', gap: 1 }}>
          <Box sx={{ minWidth: 0 }}>
            {title && <Typography id={id ? `${id}-title` : undefined} variant="h5" component="h2">{title}</Typography>}
            {subtitle && <Typography variant="caption" color="text.secondary" component="div">{subtitle}</Typography>}
          </Box>
          {actions}
        </Stack>
      )}
      <Box sx={{ px: pad, pb: pad, pt: title || actions ? 1.25 : pad }}>{children}</Box>
    </Paper>
  );
}

/** A label and a value on one line (details panels). */
export function Fact({ label, children }: { label: string; children: ReactNode }) {
  return (
    <Box sx={{ display: 'flex', justifyContent: 'space-between', gap: 2, py: 0.75, alignItems: 'baseline', minWidth: 0 }}>
      <Typography variant="body2" color="text.secondary" sx={{ flexShrink: 0 }}>{label}</Typography>
      <Typography variant="body2" component="div" sx={{ textAlign: 'right', minWidth: 0, wordBreak: 'break-word' }}>{children}</Typography>
    </Box>
  );
}

/** One number that matters. Give it `to` and it becomes a link to exactly the records it counts. */
export function Stat({ label, value, hint, tone, to }: { label: string; value: ReactNode; hint?: ReactNode; tone?: 'warning' | 'danger'; to?: string }) {
  const body = (
    <>
      <Typography variant="caption" color="text.secondary" component="div" noWrap>{label}</Typography>
      <Typography variant="display" component="div" sx={{ color: tone && Number(value) > 0 ? `${tone === 'danger' ? 'error' : 'warning'}.main` : 'text.primary', mt: 0.25 }}>{value}</Typography>
      {hint && <Typography variant="caption" color="text.secondary" component="div" noWrap>{hint}</Typography>}
    </>
  );
  const sx = { p: 1.75, borderRadius: 2.5, minWidth: 0, display: 'block', color: 'inherit', textDecoration: 'none', transition: 'border-color 120ms', ...(to ? { '&:hover': { borderColor: 'text.disabled' }, '&:focus-visible': { outline: '2px solid', outlineColor: 'primary.main', outlineOffset: 1 } } : {}) };
  return to ? <Paper variant="outlined" component={RouterLink} to={to} sx={sx}>{body}</Paper> : <Paper variant="outlined" sx={sx}>{body}</Paper>;
}
/** Quick views as a row of tabs (Open · Mine · Needs reply …): the fastest way to a list people use every day. */
export function ViewTabs<T extends string>({ items, value, onChange, label }: { items: Array<{ id: T; label: string }>; value: T | null; onChange: (id: T) => void; label: string }) {
  return (
    <Stack direction="row" role="tablist" aria-label={label} sx={{ gap: 0.5, mb: 1.5, borderBottom: 1, borderColor: 'divider', overflowX: 'auto' }}>
      {items.map((i) => {
        const active = i.id === value;
        return (
          <Box key={i.id} component="button" type="button" role="tab" aria-selected={active} onClick={() => onChange(i.id)}
            sx={{ all: 'unset', boxSizing: 'border-box', cursor: 'pointer', px: 1.5, py: 1, mb: '-1px', whiteSpace: 'nowrap', typography: 'body2', fontWeight: active ? 600 : 500, color: active ? 'primary.main' : 'text.secondary', borderBottom: 2, borderColor: active ? 'primary.main' : 'transparent', '&:hover': { color: active ? 'primary.main' : 'text.primary' }, '&:focus-visible': { outline: '2px solid', outlineColor: 'primary.main', outlineOffset: -2, borderRadius: 1 } }}>
            {i.label}
          </Box>
        );
      })}
    </Stack>
  );
}
/** One segment of a "right now" strip: a big number that links to exactly the records it counts. */
export function NowTile({ label, value, hint, to, tone }: { label: string; value: number | string; hint?: ReactNode; to?: string; tone?: 'warning' | 'success' }) {
  const hot = tone === 'warning' && Number(value) > 0;
  const inner = (
    <>
      <Stack direction="row" spacing={0.75} sx={{ alignItems: 'center' }}>
        <Box aria-hidden sx={{ width: 7, height: 7, borderRadius: '50%', bgcolor: hot ? 'warning.main' : tone === 'success' ? 'success.main' : 'text.disabled' }} />
        <Typography variant="caption" color="text.secondary" noWrap>{label}</Typography>
      </Stack>
      <Typography component="div" sx={{ fontSize: '1.875rem', fontWeight: 650, letterSpacing: '-0.025em', lineHeight: 1.15, mt: 0.5, color: hot ? 'warning.dark' : 'text.primary', fontVariantNumeric: 'tabular-nums' }}>{typeof value === 'number' ? value.toLocaleString() : value}</Typography>
      {hint && <Typography variant="caption" color="text.secondary" component="div" noWrap>{hint}</Typography>}
    </>
  );
  const sx = { p: 2, display: 'block', color: 'inherit', textDecoration: 'none', minWidth: 0, transition: 'background-color 120ms', ...(to ? { '&:hover': { bgcolor: 'surface.hover' }, '&:focus-visible': { outline: '2px solid', outlineColor: 'primary.main', outlineOffset: -2 } } : {}) };
  return to ? <Box component={RouterLink} to={to} sx={sx}>{inner}</Box> : <Box sx={sx}>{inner}</Box>;
}

/** Several NowTiles in one card, divided by hairlines. */
export function NowStrip({ children, columns = 4, sx }: { children: ReactNode; columns?: number; sx?: SxProps<Theme> }) {
  return (
    <Card pad={0} sx={sx}>
      <Box sx={{ display: 'grid', gridTemplateColumns: { xs: 'repeat(2, 1fr)', md: `repeat(${columns}, 1fr)` }, '& > *': { borderRight: { md: 1 }, borderColor: 'divider' }, '& > *:last-child': { borderRight: 0 } }}>{children}</Box>
    </Card>
  );
}
