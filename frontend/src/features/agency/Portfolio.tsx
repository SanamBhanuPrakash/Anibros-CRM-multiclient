import { useMemo } from 'react';
import { Box, Stack, Tooltip, Typography } from '@mui/material';
import { useNavigate } from 'react-router-dom';
import type { Client } from '@/api/agency';
import { openClient } from '@/app/scope';
import { Card } from '@/ui/kit';
import { squarify, useWidth, type TreeItem } from '@/ui/viz';
import { clientColor } from './shared';

type Tile = TreeItem & { client: Client };

/**
 * Your clients as a map: each tile is as big as the client's open leads, so the biggest accounts are the biggest tiles. An amber corner means
 * leads are waiting for a reply past the deadline. A click opens that client. It is the fastest way to see where the work is.
 */
export function ClientTreemap({ clients, height = 330 }: { clients: Client[]; height?: number }) {
  const nav = useNavigate();
  const [ref, w] = useWidth();
  const tiles = useMemo(
    () =>
      squarify<Tile>(
        clients.filter((c) => c.status === 'active').map((c) => ({ key: c.id, client: c, value: Math.max(1, c.stats?.openLeads ?? 0) + 0.4 * Math.max(1, ...clients.map((x) => x.stats?.openLeads ?? 0)) })),
        Math.max(0, w),
        height
      ),
    [clients, w, height]
  );
  const gap = 4;
  return (
    <Box ref={ref} sx={{ position: 'relative', width: '100%', height }} role="list" aria-label="Clients sized by open leads">
      {tiles.map(({ item, x, y, w: tw, h: th }) => {
        const c = item.client;
        const s = c.stats;
        const waiting = s?.slaBreached ?? 0;
        const big = tw > 150 && th > 96;
        const mid = tw > 96 && th > 64;
        return (
          <Tooltip key={item.key} title={`${c.name}: ${s?.openLeads ?? 0} open${waiting ? `, ${waiting} waiting for a reply` : ''}${s?.unassigned ? `, ${s.unassigned} not assigned` : ''}`} disableInteractive>
            <Box
              role="listitem"
              tabIndex={0}
              onClick={() => { openClient(c.id); nav('/overview'); }}
              onKeyDown={(e) => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); openClient(c.id); nav('/overview'); } }}
              sx={{
                position: 'absolute',
                left: x + gap / 2,
                top: y + gap / 2,
                width: Math.max(0, tw - gap),
                height: Math.max(0, th - gap),
                borderRadius: big ? 3 : 2,
                p: big ? 1.75 : 1,
                color: '#fff',
                cursor: 'pointer',
                overflow: 'hidden',
                background: `linear-gradient(155deg, ${clientColor(c.id)} 0%, color-mix(in srgb, ${clientColor(c.id)} 72%, #000) 100%)`,
                transition: 'transform 140ms cubic-bezier(0.2,0.8,0.2,1), box-shadow 140ms',
                '&:hover': { transform: 'translateY(-2px)', boxShadow: 4 },
                '&:focus-visible': { outline: '3px solid', outlineColor: 'text.primary', outlineOffset: 2 },
                display: 'flex',
                flexDirection: 'column',
                justifyContent: 'space-between',
              }}
            >
              {waiting > 0 && <Box aria-hidden sx={{ position: 'absolute', top: 0, right: 0, width: 0, height: 0, borderStyle: 'solid', borderWidth: big ? '0 34px 34px 0' : '0 22px 22px 0', borderColor: 'transparent #FBBF24 transparent transparent' }} />}
              <Box sx={{ minWidth: 0 }}>
                <Typography noWrap sx={{ fontWeight: 650, fontSize: big ? '0.9375rem' : '0.8125rem', color: 'inherit', pr: waiting > 0 ? 3 : 0, letterSpacing: '-0.005em' }}>{c.name}</Typography>
                {big && <Typography noWrap sx={{ fontSize: '0.75rem', opacity: 0.8, color: 'inherit', textTransform: 'capitalize' }}>{c.industry ? c.industry.replace(/_/g, ' ') : 'Client'}</Typography>}
              </Box>
              {mid && (
                <Stack direction="row" spacing={1} sx={{ alignItems: 'baseline' }}>
                  <Typography sx={{ fontSize: big ? '2rem' : '1.375rem', fontWeight: 650, lineHeight: 1, letterSpacing: '-0.03em', color: 'inherit', fontVariantNumeric: 'tabular-nums' }}>{s?.openLeads ?? 0}</Typography>
                  <Typography sx={{ fontSize: '0.75rem', opacity: 0.85, color: 'inherit' }}>open</Typography>
                  {big && waiting > 0 && <Typography sx={{ fontSize: '0.75rem', color: '#FDE68A', fontWeight: 600, ml: 'auto !important' }}>{waiting} waiting</Typography>}
                </Stack>
              )}
            </Box>
          </Tooltip>
        );
      })}
    </Box>
  );
}

/** Which clients need attention most: waiting for a reply and not assigned, as paired bars. Hidden when everything is calm. */
export function AttentionByClient({ clients }: { clients: Client[] }) {
  const nav = useNavigate();
  const rows = clients
    .filter((c) => c.status === 'active' && c.stats && (c.stats.slaBreached > 0 || c.stats.unassigned > 0))
    .sort((a, b) => b.stats!.slaBreached * 2 + b.stats!.unassigned - (a.stats!.slaBreached * 2 + a.stats!.unassigned))
    .slice(0, 6);
  const max = Math.max(1, ...rows.map((c) => Math.max(c.stats!.slaBreached, c.stats!.unassigned)));
  return (
    <Card title="Where to look first" subtitle={rows.length ? 'Leads waiting for a reply and leads nobody owns' : 'Nothing is waiting on anyone'} sx={{ height: '100%' }}>
      {rows.length === 0 ? (
        <Typography variant="body2" color="text.secondary">Every open lead has an owner and a reply. Nice.</Typography>
      ) : (
        <Stack spacing={1.5}>
          {rows.map((c) => (
            <Box key={c.id} onClick={() => { openClient(c.id); nav('/leads?sla=breached'); }} sx={{ cursor: 'pointer', borderRadius: 2, mx: -1, px: 1, py: 0.5, '&:hover': { bgcolor: 'action.hover' } }}>
              <Typography variant="body2" noWrap sx={{ fontWeight: 500, mb: 0.5 }}>{c.name}</Typography>
              {([['Waiting', c.stats!.slaBreached, '#F59E0B'], ['Unassigned', c.stats!.unassigned, '#94A3B8']] as const).map(([label, v, color]) => (
                <Stack key={label} direction="row" spacing={1} sx={{ alignItems: 'center', height: 14 }}>
                  <Typography variant="caption" color="text.secondary" sx={{ width: 70, fontSize: '0.6875rem' }}>{label}</Typography>
                  <Box sx={{ flex: 1, height: 6, borderRadius: 3, bgcolor: 'action.hover', overflow: 'hidden' }}>
                    <Box sx={{ height: '100%', width: `${(v / max) * 100}%`, bgcolor: color, borderRadius: 3 }} />
                  </Box>
                  <Typography variant="caption" sx={{ width: 20, textAlign: 'right', fontWeight: 650 }}>{v}</Typography>
                </Stack>
              ))}
            </Box>
          ))}
        </Stack>
      )}
    </Card>
  );
}
