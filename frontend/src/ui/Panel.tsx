import type { ReactNode } from 'react';
import { Box, IconButton, Paper, Stack, Tooltip, Typography } from '@mui/material';
import HelpOutlineRoundedIcon from '@mui/icons-material/HelpOutlineRounded';

/** A titled surface: the one container for grouped content on every screen (settings, analytics, detail pages). Reads only theme tokens. */

/** "What does this number mean?" as a real button with an accessible name, never a bare icon. */
export function MetricHelp({ label, children }: { label: string; children: ReactNode }) {
  return (
    <Tooltip title={children} placement="top" arrow enterTouchDelay={0} leaveTouchDelay={6000}>
      <IconButton size="small" aria-label={`What “${label}” means`} sx={{ p: 0.25, color: 'text.secondary' }}>
        <HelpOutlineRoundedIcon sx={{ fontSize: 16 }} />
      </IconButton>
    </Tooltip>
  );
}


export function Panel({ title, note, help, action, children }: { title: string; note?: ReactNode; help?: ReactNode; action?: ReactNode; children: ReactNode }) {
  const id = `p-${title.replace(/\W+/g, '-').toLowerCase()}`;
  return (
    <Paper variant="outlined" component="section" aria-labelledby={id} sx={{ p: 2, borderRadius: 2, minWidth: 0 }}>
      <Stack direction="row" sx={{ alignItems: 'flex-start', justifyContent: 'space-between', gap: 1, mb: 1.25 }}>
        <Box sx={{ minWidth: 0 }}>
          <Stack direction="row" spacing={0.5} sx={{ alignItems: 'center' }}>
            <Typography id={id} variant="h5" component="h2">{title}</Typography>
            {help && <MetricHelp label={title}>{help}</MetricHelp>}
          </Stack>
          {note && <Typography variant="caption" color="text.secondary" component="div" sx={{ mt: 0.25 }}>{note}</Typography>}
        </Box>
        {action}
      </Stack>
      {children}
    </Paper>
  );
}

