import { Chip, Stack } from '@mui/material';
import { WORK_CHANNEL_OPTIONS } from './shared';

/**
 * What a person handles in a client: everything (the default), or a short list, so "only WhatsApp", "only calls" or "calls and visits" is one
 * or two clicks. A person set up for WhatsApp only can log WhatsApp (and notes), nothing else, and the server enforces it.
 */
export function ChannelPicker({ value, onChange, disabled, label = 'What they handle' }: { value: string[] | null; onChange: (next: string[] | null) => void; disabled?: boolean; label?: string }) {
  const everything = !value || value.length === 0 || value.length >= WORK_CHANNEL_OPTIONS.length;
  const toggle = (c: string) => {
    const current = everything ? [] : value!;
    const next = current.includes(c) ? current.filter((x) => x !== c) : [...current, c];
    onChange(next.length === 0 || next.length >= WORK_CHANNEL_OPTIONS.length ? null : next);
  };
  return (
    <Stack direction="row" role="group" aria-label={label} sx={{ flexWrap: 'wrap', gap: 0.5 }}>
      <Chip size="small" label="Everything" disabled={disabled} color={everything ? 'primary' : 'default'} variant={everything ? 'filled' : 'outlined'} onClick={() => onChange(null)} aria-pressed={everything} />
      {WORK_CHANNEL_OPTIONS.map((o) => {
        const on = !everything && value!.includes(o.value);
        return <Chip key={o.value} size="small" label={o.short} disabled={disabled} color={on ? 'primary' : 'default'} variant={on ? 'filled' : 'outlined'} onClick={() => toggle(o.value)} aria-pressed={on} />;
      })}
    </Stack>
  );
}
