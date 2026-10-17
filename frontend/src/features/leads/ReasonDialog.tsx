import { useEffect, useState } from 'react';
import { Button, Dialog, DialogActions, DialogContent, DialogContentText, DialogTitle, FormControl, FormControlLabel, FormLabel, Radio, RadioGroup, TextField } from '@mui/material';
import { useQuery } from '@tanstack/react-query';
import { pipelinesApi, type ReasonKind } from '@/api/crm';

export interface ReasonResult {
  reasonCode?: string;
  lossReason?: string;
}

/** The fixed reasons for closing a lead without a sale (cached for the whole session). */
export function useReasonCodes() {
  return useQuery({ queryKey: ['pipelines', 'reason-codes'], queryFn: pipelinesApi.reasonCodes, staleTime: 3_600_000 });
}

/** Does moving into this stage demand a reason code? Looked up from the pipelines the screen already loaded. */
export function useReasonCodeRequired(stageId: string | undefined): boolean {
  const pipelines = useQuery({ queryKey: ['pipelines'], queryFn: pipelinesApi.list, staleTime: 60_000 });
  if (!stageId) return false;
  return Boolean(pipelines.data?.find((p) => p.stages.some((s) => s.id === stageId))?.reasonCodesRequired);
}

export const kindOfStatus = (status: string): ReasonKind | null => (status === 'lost' ? 'lost' : status === 'disqualified' ? 'not_a_fit' : null);

/**
 * Why is this lead being closed? A short list of fixed reasons (so reports can count them) plus an optional note.
 * Where the pipeline requires a code, one must be chosen; where it does not, a written reason is still enough.
 * "Other" always needs a note.
 */
export function ReasonDialog({ open, title, subject, targetName, kind, codeRequired, busy, onCancel, onConfirm }: {
  open: boolean;
  title: string;
  subject: string;
  targetName: string;
  kind: ReasonKind;
  codeRequired: boolean;
  busy?: boolean;
  onCancel: () => void;
  onConfirm: (r: ReasonResult) => void;
}) {
  const codes = useReasonCodes();
  const [code, setCode] = useState('');
  const [note, setNote] = useState('');
  useEffect(() => {
    if (open) {
      setCode('');
      setNote('');
    }
  }, [open]);
  const options = codes.data?.[kind] ?? [];
  const noteOk = note.trim().length >= 2;
  const valid = code ? (code === 'other' ? noteOk : true) : !codeRequired && noteOk;
  const word = kind === 'lost' ? 'lost' : 'not a fit';
  return (
    <Dialog open={open} onClose={onCancel} fullWidth maxWidth="xs" aria-labelledby="reason-title">
      <DialogTitle id="reason-title">{title}</DialogTitle>
      <DialogContent>
        <DialogContentText sx={{ mb: 1.5 }}>
          Why is {subject} {word}? This is kept on the lead and used in reports.
        </DialogContentText>
        <FormControl sx={{ mb: 2, width: '100%' }}>
          <FormLabel id="reason-group" sx={{ mb: 0.5 }}>{codeRequired ? 'Reason' : 'Reason (optional if you write one below)'}</FormLabel>
          <RadioGroup aria-labelledby="reason-group" value={code} onChange={(e) => setCode(e.target.value)}>
            {options.map((o) => <FormControlLabel key={o.code} value={o.code} control={<Radio size="small" />} label={o.label} />)}
          </RadioGroup>
        </FormControl>
        <TextField
          label={code === 'other' ? 'Explain (required)' : code ? 'Add a note (optional)' : codeRequired ? 'Add a note (optional)' : 'Or write the reason'}
          fullWidth
          multiline
          minRows={2}
          value={note}
          onChange={(e) => setNote(e.target.value)}
          slotProps={{ htmlInput: { maxLength: 300 } }}
          helperText={!codeRequired && !code ? 'Pick a reason above, or write one here.' : undefined}
        />
      </DialogContent>
      <DialogActions>
        <Button onClick={onCancel}>Cancel</Button>
        <Button variant="contained" disabled={!valid || busy} onClick={() => onConfirm({ reasonCode: code || undefined, lossReason: noteOk ? note.trim() : undefined })}>
          Move to {targetName}
        </Button>
      </DialogActions>
    </Dialog>
  );
}
