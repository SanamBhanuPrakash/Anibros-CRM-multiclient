import { useState } from 'react';
import { Box, Button, Stack, Typography } from '@mui/material';
import { formatDistanceToNowStrict } from 'date-fns';
import type { NextStep } from '@/api/crm';
import { VisitDialog } from '@/features/viewings/VisitDialog';

/** "What do I do now?" for this lead. The server decides; the text about time is worked out here so it never goes stale on screen. */
export function NextStepBanner({ step, onPlan }: { step: NextStep; onPlan?: () => void }) {
  const [visit, setVisit] = useState<string | null>(null);
  const when = step.dueAt ? (() => {
    const d = new Date(step.dueAt);
    const dist = formatDistanceToNowStrict(d);
    return d.getTime() < Date.now() ? `${dist} overdue` : `in ${dist}`;
  })() : null;
  const tone = step.urgent ? 'warning' : step.kind === 'plan' ? 'info' : 'success';
  const action =
    step.kind === 'record_visit' && step.viewingId ? { label: 'Record visit', run: () => setVisit(step.viewingId!) }
    : step.kind === 'visit' && step.viewingId ? { label: 'Open visit', run: () => setVisit(step.viewingId!) }
    : step.kind === 'plan' && onPlan ? { label: 'Add a follow-up', run: onPlan }
    : null;
  return (
    <>
      <Box
        role="region"
        aria-label="Next step"
        sx={{ p: 1.5, borderRadius: 2.5, border: 1, borderColor: 'divider', borderLeft: 4, borderLeftColor: `${tone}.main`, bgcolor: 'background.paper' }}
      >
        <Stack direction="row" spacing={1.5} sx={{ alignItems: 'flex-start', justifyContent: 'space-between' }}>
          <Box sx={{ minWidth: 0 }}>
            <Typography variant="overline" color="text.secondary" component="div">Next step</Typography>
            <Typography variant="subtitle2">{step.title}{when ? ` · ${when}` : ''}</Typography>
            <Typography variant="body2" color="text.secondary">{step.detail}</Typography>
          </Box>
          {action && <Button size="small" variant="outlined" onClick={action.run} sx={{ flexShrink: 0 }}>{action.label}</Button>}
        </Stack>
      </Box>
      <VisitDialog visitId={visit} onClose={() => setVisit(null)} />
    </>
  );
}
