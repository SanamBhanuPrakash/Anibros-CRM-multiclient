import { useEffect, useMemo, useRef, useState, type ReactElement } from 'react';
import { Alert, Box, Button, Chip, Stack, TextField, ToggleButton, ToggleButtonGroup, Typography } from '@mui/material';
import CallOutlinedIcon from '@mui/icons-material/CallOutlined';
import WhatsAppIcon from '@mui/icons-material/WhatsApp';
import SmsOutlinedIcon from '@mui/icons-material/SmsOutlined';
import InstagramIcon from '@mui/icons-material/Instagram';
import MailOutlineRoundedIcon from '@mui/icons-material/MailOutlineRounded';
import EventOutlinedIcon from '@mui/icons-material/EventOutlined';
import StickyNote2OutlinedIcon from '@mui/icons-material/StickyNote2Outlined';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { ApiProblem } from '@/api/client';
import { clientWorkApi, type LogActivityInput } from '@/api/agency';
import { messagingApi } from '@/api/outbound';
import { useAuth } from '@/features/auth/AuthProvider';
import { useToast } from '@/ui/toast';

export type LogType = LogActivityInput['type'];

export const ACTIVITY_TYPES: Array<{ value: LogType; label: string; icon: ReactElement; hint: string }> = [
  { value: 'call', label: 'Call', icon: <CallOutlinedIcon fontSize="small" />, hint: 'What was discussed? Next step…' },
  { value: 'whatsapp', label: 'WhatsApp', icon: <WhatsAppIcon fontSize="small" />, hint: 'What did you send, or what did they say?' },
  { value: 'sms', label: 'SMS', icon: <SmsOutlinedIcon fontSize="small" />, hint: 'The message, or their reply…' },
  { value: 'email', label: 'E-mail', icon: <MailOutlineRoundedIcon fontSize="small" />, hint: 'Summarise the e-mail…' },
  { value: 'instagram', label: 'Instagram', icon: <InstagramIcon fontSize="small" />, hint: 'What did you send, or what did they say?' },
  { value: 'meeting', label: 'Meeting', icon: <EventOutlinedIcon fontSize="small" />, hint: 'Who met, what was agreed…' },
  { value: 'note', label: 'Note', icon: <StickyNote2OutlinedIcon fontSize="small" />, hint: 'A note for the team…' },
];
const HAS_DIRECTION: LogType[] = ['call', 'whatsapp', 'sms', 'email', 'instagram'];
const HAS_DURATION: LogType[] = ['call', 'meeting'];

type FollowUp = 'none' | 'tomorrow' | 'in3' | 'week' | 'custom';
const at10 = (daysAhead: number) => {
  const d = new Date();
  d.setDate(d.getDate() + daysAhead);
  d.setHours(10, 0, 0, 0);
  return d.toISOString();
};
const digits = (s?: string | null) => (s ?? '').replace(/\D/g, '');

export interface LogTarget {
  id: string;
  name: string | null;
  phone?: string | null;
  /** Needed only to offer "send it from here" for e-mail. */
  email?: string | null;
}

/**
 * Record what you did on a lead: a call, a WhatsApp, an SMS, an e-mail, a meeting or a note.
 * One click on an outcome is enough to save; everything else is optional. Works for a lead of any client you belong to,
 * on the lead page (inline) and from lists (in a pop-up).
 */
export function ActivityForm({ clientId, lead, initialType = 'call', onDone, onCancel, autoFocus = false }: { clientId: string; lead: LogTarget; initialType?: LogType; onDone?: () => void; onCancel?: () => void; autoFocus?: boolean }) {
  const qc = useQueryClient();
  const toast = useToast();
  // A specialist (only WhatsApp, only calls…) sees only the kinds of work they handle for THIS client; notes are for everyone.
  const { me } = useAuth();
  const allowed = me?.tenants.find((t) => t.id === clientId)?.workChannels ?? null;
  const types = ACTIVITY_TYPES.filter((t) => t.value === 'note' || !allowed || allowed.includes(t.value));
  const startType: LogType = types.some((t) => t.value === initialType) ? initialType : types[0]!.value;
  const [type, setType] = useState<LogType>(startType);
  const [direction, setDirection] = useState<'outbound' | 'inbound'>('outbound');
  const [outcome, setOutcome] = useState('');
  const [minutes, setMinutes] = useState('');
  const [text, setText] = useState('');
  const [followUp, setFollowUp] = useState<FollowUp>('none');
  const [custom, setCustom] = useState('');
  const [error, setError] = useState<string | null>(null);
  const [mode, setMode] = useState<'log' | 'send'>('log');
  const [subject, setSubject] = useState('');
  const notes = useRef<HTMLTextAreaElement>(null);

  // A new lead or a different starting type (the quick buttons on the lead page) starts a fresh form.
  useEffect(() => {
    setType(startType);
    setDirection('outbound');
    setOutcome('');
    setMinutes('');
    setText('');
    setFollowUp('none');
    setCustom('');
    setError(null);
    setMode('log');
    setSubject('');
    if (autoFocus) setTimeout(() => notes.current?.focus(), 50);
  }, [startType, lead.id, autoFocus]);

  const options = useQuery({ queryKey: ['activity-options'], queryFn: () => clientWorkApi.options(clientId), staleTime: Infinity });
  const outcomes = useMemo(() => (options.data?.outcomes ?? []).filter((o) => o.forTypes.includes(type)), [options.data, type]);
  const typeMeta = types.find((t) => t.value === type) ?? types[0]!;
  const phone = digits(lead.phone);
  // Sending from the CRM: e-mail always works (the lead needs an address); WhatsApp needs the client's own number to be connected.
  const wa = useQuery({ queryKey: ['messaging', 'whatsapp', clientId], queryFn: () => messagingApi.whatsapp(clientId), enabled: type === 'whatsapp', staleTime: 60_000, retry: false });
  const canSend = (type === 'email' && Boolean(lead.email ?? true)) || (type === 'whatsapp' && Boolean(wa.data?.connected && phone));
  const sending = mode === 'send' && canSend && direction === 'outbound';

  const save = useMutation({
    mutationFn: () => {
      if (sending) {
        if (!text.trim()) throw new ApiProblem(400, { detail: 'Write the message first' });
        if (type === 'email' && !subject.trim()) throw new ApiProblem(400, { detail: 'Add a subject' });
        return messagingApi.send({ leadId: lead.id, channel: type as 'email' | 'whatsapp', subject: type === 'email' ? subject.trim() : undefined, body: text.trim() }, clientId);
      }
      const label = outcomes.find((o) => o.code === outcome)?.label;
      const input: LogActivityInput = { type, description: text.trim() || `${typeMeta.label}${label ? `: ${label}` : ''}` };
      if (HAS_DIRECTION.includes(type)) input.direction = direction;
      if (outcome) input.outcome = outcome;
      if (HAS_DURATION.includes(type) && minutes.trim() !== '') {
        const n = Number(minutes);
        if (!Number.isInteger(n) || n < 0 || n > 1440) throw new ApiProblem(400, { detail: 'Duration is a whole number of minutes (0–1440)' });
        input.durationMinutes = n;
      }
      if (followUp === 'tomorrow') input.nextFollowupAt = at10(1);
      else if (followUp === 'in3') input.nextFollowupAt = at10(3);
      else if (followUp === 'week') input.nextFollowupAt = at10(7);
      else if (followUp === 'custom') {
        if (!custom) throw new ApiProblem(400, { detail: 'Pick the follow-up date and time' });
        input.nextFollowupAt = new Date(custom).toISOString();
      }
      return clientWorkApi.log(clientId, lead.id, input);
    },
    onSuccess: () => {
      for (const key of ['agency', 'leads', 'activities', 'home', 'tasks']) void qc.invalidateQueries({ queryKey: [key] });
      toast.success(sending ? `${typeMeta.label} is on its way` : `${typeMeta.label} logged${followUp !== 'none' ? ' · follow-up set' : ''}`);
      setSubject('');
      setOutcome('');
      setMinutes('');
      setText('');
      setFollowUp('none');
      setCustom('');
      onDone?.();
    },
    onError: (e) => setError(e instanceof ApiProblem ? (e.errors[0]?.message ?? e.detail) : 'Couldn’t save that. Please try again.'),
  });
  const submit = () => {
    setError(null);
    save.mutate();
  };

  return (
    <Stack component="form" spacing={1.75} onSubmit={(e) => { e.preventDefault(); submit(); }}>
      {error && <Alert severity="error">{error}</Alert>}

      <Box role="radiogroup" aria-label="What did you do?" sx={{ display: 'grid', gridTemplateColumns: 'repeat(auto-fill, minmax(104px, 1fr))', gap: 0.75 }}>
        {types.map((t) => (
          <ToggleButton
            key={t.value}
            value={t.value}
            size="small"
            role="radio"
            aria-checked={type === t.value}
            selected={type === t.value}
            onChange={() => { setType(t.value); setOutcome(''); }}
            sx={{ gap: 0.75, py: 0.75, whiteSpace: 'nowrap', borderRadius: '8px !important', border: '1px solid', borderColor: 'divider' }}
          >
            {t.icon} {t.label}
          </ToggleButton>
        ))}
      </Box>

      {allowed && <Typography variant="caption" color="text.secondary" component="div" sx={{ mt: -0.75 }}>You handle {allowed.map((c) => ACTIVITY_TYPES.find((t) => t.value === c)?.label ?? c).join(', ')} for this client.</Typography>}

      {(phone && (type === 'call' || type === 'whatsapp' || type === 'sms')) || HAS_DIRECTION.includes(type) ? (
        <Stack direction="row" spacing={1} sx={{ alignItems: 'center', flexWrap: 'wrap', rowGap: 1 }}>
          {phone && type === 'call' && <Button size="small" variant="outlined" component="a" href={`tel:+${phone}`} startIcon={<CallOutlinedIcon />}>Dial {lead.phone}</Button>}
          {phone && type === 'whatsapp' && <Button size="small" variant="outlined" component="a" href={`https://wa.me/${phone}`} target="_blank" rel="noopener noreferrer" startIcon={<WhatsAppIcon />}>Open WhatsApp</Button>}
          {phone && type === 'sms' && <Button size="small" variant="outlined" component="a" href={`sms:+${phone}`} startIcon={<SmsOutlinedIcon />}>Open messages</Button>}
          {HAS_DIRECTION.includes(type) && (
            <ToggleButtonGroup exclusive size="small" value={direction} onChange={(_, v) => v && setDirection(v)} aria-label="Who reached out?">
              <ToggleButton value="outbound">I reached out</ToggleButton>
              <ToggleButton value="inbound">They reached out</ToggleButton>
            </ToggleButtonGroup>
          )}
        </Stack>
      ) : null}

      {(type === 'email' || type === 'whatsapp') && direction === 'outbound' && (
        <Stack direction="row" spacing={1.5} sx={{ alignItems: 'center', flexWrap: 'wrap', rowGap: 0.75 }}>
          <ToggleButtonGroup exclusive size="small" value={sending ? 'send' : 'log'} onChange={(_, v) => v && setMode(v)} aria-label="How was it sent?">
            <ToggleButton value="log">I sent it myself</ToggleButton>
            <ToggleButton value="send" disabled={!canSend}>Send from here</ToggleButton>
          </ToggleButtonGroup>
          {!canSend && <Typography variant="caption" color="text.secondary">{type === 'whatsapp' ? (wa.data?.connected === false ? 'Connect this client’s WhatsApp number in Settings → Lead sources to send from here.' : !phone ? 'This lead has no phone number.' : '') : ''}</Typography>}
          {sending && <Typography variant="caption" color="text.secondary">{type === 'email' ? 'Goes out under the client’s name; replies come to you.' : 'Goes from the client’s own WhatsApp number.'}</Typography>}
        </Stack>
      )}

      {sending && type === 'email' && <TextField label="Subject" value={subject} onChange={(e) => setSubject(e.target.value)} slotProps={{ htmlInput: { maxLength: 200 } }} />}

      {outcomes.length > 0 && !sending && (
        <Box>
          <Typography variant="caption" color="text.secondary" component="div" sx={{ mb: 0.5 }}>How did it go?</Typography>
          <Stack direction="row" sx={{ flexWrap: 'wrap', gap: 0.75 }} role="radiogroup" aria-label="Outcome">
            {outcomes.map((o) => (
              <Chip key={o.code} label={o.label} role="radio" aria-checked={outcome === o.code} color={outcome === o.code ? 'primary' : 'default'} variant={outcome === o.code ? 'filled' : 'outlined'} onClick={() => setOutcome(outcome === o.code ? '' : o.code)} sx={{ height: 28 }} />
            ))}
          </Stack>
        </Box>
      )}

      <Stack direction="row" spacing={1.5} sx={{ alignItems: 'flex-start' }}>
        <TextField
          inputRef={notes}
          multiline
          minRows={2}
          maxRows={6}
          placeholder={sending ? 'Write your message…' : `${typeMeta.hint} (optional)`}
          value={text}
          onChange={(e) => setText(e.target.value)}
          slotProps={{ htmlInput: { maxLength: 4000, 'aria-label': 'Notes' } }}
          onKeyDown={(e) => {
            if ((e.metaKey || e.ctrlKey) && e.key === 'Enter') {
              e.preventDefault();
              submit();
            }
          }}
        />
        {HAS_DURATION.includes(type) && !sending && <TextField label="Minutes" type="number" value={minutes} onChange={(e) => setMinutes(e.target.value)} slotProps={{ htmlInput: { min: 0, max: 1440 }, inputLabel: { shrink: true } }} sx={{ width: 110, flexShrink: 0 }} />}
      </Stack>

      <Box>
        <Typography variant="caption" color="text.secondary" component="div" sx={{ mb: 0.5 }}>Follow up</Typography>
        <Stack direction="row" sx={{ flexWrap: 'wrap', gap: 0.75, alignItems: 'center' }} role="radiogroup" aria-label="Follow-up">
          {([['none', 'None'], ['tomorrow', 'Tomorrow'], ['in3', 'In 3 days'], ['week', 'Next week'], ['custom', 'Pick a time…']] as Array<[FollowUp, string]>).map(([v, label]) => (
            <Chip key={v} label={label} role="radio" aria-checked={followUp === v} color={followUp === v ? 'primary' : 'default'} variant={followUp === v ? 'filled' : 'outlined'} onClick={() => setFollowUp(v)} sx={{ height: 28 }} />
          ))}
          {followUp === 'custom' && <TextField size="small" type="datetime-local" value={custom} onChange={(e) => setCustom(e.target.value)} sx={{ width: 220 }} slotProps={{ htmlInput: { 'aria-label': 'Follow-up time' } }} />}
        </Stack>
      </Box>

      <Stack direction="row" spacing={1} sx={{ alignItems: 'center', justifyContent: 'space-between', pt: 0.5 }}>
        <Typography variant="caption" color="text.secondary" sx={{ pr: 1 }}>
          {type === 'note' ? 'Notes don’t count as a reply.' : direction === 'inbound' && HAS_DIRECTION.includes(type) ? 'Recorded, but not counted as your reply.' : 'Counts as your reply to the lead.'}
        </Typography>
        <Stack direction="row" spacing={1} sx={{ flexShrink: 0 }}>
          {onCancel && <Button onClick={onCancel} disabled={save.isPending}>Cancel</Button>}
          <Button type="submit" variant="contained" disabled={save.isPending}>{save.isPending ? (sending ? 'Sending…' : 'Saving…') : sending ? `Send ${type === 'email' ? 'e-mail' : 'WhatsApp'}` : `Save ${['call', 'meeting', 'note'].includes(type) ? typeMeta.label.toLowerCase() : typeMeta.label}`}</Button>
        </Stack>
      </Stack>
    </Stack>
  );
}
