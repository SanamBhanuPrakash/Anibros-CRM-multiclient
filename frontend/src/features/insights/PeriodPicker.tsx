import { useState } from 'react';
import { Button, Checkbox, FormControlLabel, MenuItem, Popover, Stack, TextField, Tooltip } from '@mui/material';
import ExpandMoreRoundedIcon from '@mui/icons-material/ExpandMoreRounded';
import EventRoundedIcon from '@mui/icons-material/EventRounded';
import { addDays, format } from 'date-fns';

const iso = (d: Date) => format(d, 'yyyy-MM-dd');
const PRESETS: Array<{ id: string; label: string }> = [
  { id: '7', label: 'Last 7 days' },
  { id: '30', label: 'Last 30 days' },
  { id: '90', label: 'Last 90 days' },
  { id: 'month', label: 'This month' },
  { id: 'year', label: 'This year' },
];

/** One button for "which days": quick periods, exact dates and the test-lead switch. The choice lives in the address (from, to, includeTest). */
export function PeriodPicker({ range, includeTest, set, showTest = true }: { range?: { from: string; to: string; days: number }; includeTest: boolean; set: (p: Record<string, string | undefined>) => void; showTest?: boolean }) {
  const [anchor, setAnchor] = useState<HTMLElement | null>(null);
  const end = new Date(`${range?.to ?? iso(new Date())}T00:00:00`);
  const active = (() => {
    if (!range) return '30';
    if (range.from === iso(new Date(end.getFullYear(), end.getMonth(), 1))) return 'month';
    if (range.from === iso(new Date(end.getFullYear(), 0, 1))) return 'year';
    return [7, 30, 90].includes(range.days) ? String(range.days) : 'custom';
  })();
  const pick = (id: string) => {
    if (id === 'month') set({ from: iso(new Date(end.getFullYear(), end.getMonth(), 1)), to: iso(end) });
    else if (id === 'year') set({ from: iso(new Date(end.getFullYear(), 0, 1)), to: iso(end) });
    else set({ from: iso(addDays(end, -(Number(id) - 1))), to: iso(end) });
    setAnchor(null);
  };
  return (
    <>
      <Button size="small" variant="outlined" color="inherit" startIcon={<EventRoundedIcon />} endIcon={<ExpandMoreRoundedIcon />} onClick={(e) => setAnchor(e.currentTarget)} aria-haspopup="dialog">{PRESETS.find((p) => p.id === active)?.label ?? 'Custom period'}</Button>
      <Popover open={Boolean(anchor)} anchorEl={anchor} onClose={() => setAnchor(null)} anchorOrigin={{ vertical: 'bottom', horizontal: 'right' }} transformOrigin={{ vertical: 'top', horizontal: 'right' }}>
        <Stack sx={{ p: 1.5, width: 280 }} spacing={1.5}>
          <Stack>
            {PRESETS.map((p) => <MenuItem key={p.id} selected={p.id === active} onClick={() => pick(p.id)} sx={{ borderRadius: 1 }}>{p.label}</MenuItem>)}
          </Stack>
          <Stack direction="row" spacing={1}>
            <TextField type="date" label="From" value={range?.from ?? ''} onChange={(e) => e.target.value && set({ from: e.target.value, to: range?.to })} slotProps={{ inputLabel: { shrink: true }, htmlInput: { max: range?.to } }} />
            <TextField type="date" label="To" value={range?.to ?? ''} onChange={(e) => e.target.value && set({ to: e.target.value, from: range?.from })} slotProps={{ inputLabel: { shrink: true }, htmlInput: { min: range?.from } }} />
          </Stack>
          {showTest && (
            <Tooltip title="Leads tagged “test” are left out unless you switch this on." describeChild>
              <FormControlLabel control={<Checkbox size="small" checked={includeTest} onChange={(_, v) => set({ includeTest: v ? 'true' : undefined })} />} label="Include test leads" slotProps={{ typography: { variant: 'body2' } }} />
            </Tooltip>
          )}
        </Stack>
      </Popover>
    </>
  );
}
