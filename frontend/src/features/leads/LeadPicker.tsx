import { useEffect, useState } from 'react';
import { Autocomplete, Box, TextField, Typography } from '@mui/material';
import { useQuery } from '@tanstack/react-query';
import { leadsApi } from '@/api/crm';

export interface PickedLead {
  id: string;
  name: string;
  phone: string | null;
}

/** Find one of this client's leads by name, phone or e-mail. Used wherever something (a task, a visit) should hang off a lead. */
export function LeadPicker({ value, onChange, label = 'Lead', helperText, disabled }: { value: PickedLead | null; onChange: (lead: PickedLead | null) => void; label?: string; helperText?: string; disabled?: boolean }) {
  const [input, setInput] = useState('');
  const [debounced, setDebounced] = useState('');
  useEffect(() => {
    const t = setTimeout(() => setDebounced(input.trim()), 250);
    return () => clearTimeout(t);
  }, [input]);
  const q = useQuery({
    queryKey: ['leads', 'picker', debounced],
    queryFn: async () => (await leadsApi.list({ q: debounced || undefined, state: 'open', sort: 'updated' })).data,
    staleTime: 15_000,
  });
  const options: PickedLead[] = (q.data ?? []).map((l) => ({ id: l.id, name: l.contactName ?? 'Unnamed lead', phone: l.contactPhone }));
  if (value && !options.some((o) => o.id === value.id)) options.unshift(value);
  return (
    <Autocomplete
      value={value}
      onChange={(_, v) => onChange(v)}
      inputValue={input}
      onInputChange={(_, v, reason) => reason !== 'reset' && setInput(v)}
      options={options}
      loading={q.isFetching}
      disabled={disabled}
      filterOptions={(o) => o}
      isOptionEqualToValue={(a, b) => a.id === b.id}
      getOptionLabel={(o) => o.name}
      noOptionsText={debounced ? 'No lead matches' : 'Type a name or phone'}
      renderOption={({ key, ...props }, o) => (
        <Box component="li" key={key} {...props}>
          <Box>
            <Typography variant="body2" sx={{ fontWeight: 600 }}>{o.name}</Typography>
            {o.phone && <Typography variant="caption" color="text.secondary">{o.phone}</Typography>}
          </Box>
        </Box>
      )}
      renderInput={(p) => <TextField {...p} label={label} placeholder="Search leads…" helperText={helperText} />}
    />
  );
}
