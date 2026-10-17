import { Autocomplete, Box, Button, Chip, IconButton, MenuItem, Stack, TextField, Typography } from '@mui/material';
import AddRoundedIcon from '@mui/icons-material/AddRounded';
import CloseRoundedIcon from '@mui/icons-material/CloseRounded';
import { useDirectory } from '@/features/leads/shared';
import { SOURCE_TEXT } from '@/features/agency/LeadRowParts';

export interface RoutingRule {
  /** A lead source, or `all` for "every lead". */
  source: string;
  userIds: string[];
}

const SOURCE_CHOICES = ['meta_ads', 'google_ads', 'website', 'referral', 'portal', 'direct', 'walk_in', 'other'];
const WORKS_LEADS = ['sales_agent', 'sales_manager', 'tenant_admin', 'tenant_owner'];

/**
 * "Send every lead to Divya" (one person handles everything), plus optional exceptions per source: "Google leads go to Ravi and Arjun".
 * With several people on a rule the least busy one gets each new lead. A source rule always wins over the "every lead" rule.
 */
export function RoutingEditor({ rules, onChange, disabled }: { rules: RoutingRule[]; onChange: (r: RoutingRule[]) => void; disabled: boolean }) {
  const directory = useDirectory();
  const people = (directory.data ?? []).filter((m) => WORKS_LEADS.includes(m.role));
  const name = (id: string) => people.find((p) => p.userId === id)?.name ?? 'Someone';
  const everything = rules.find((r) => r.source === 'all');
  const bySource = rules.filter((r) => r.source !== 'all');
  const free = SOURCE_CHOICES.filter((s) => !bySource.some((r) => r.source === s));
  const setAll = (userIds: string[]) => onChange(userIds.length ? [{ source: 'all', userIds }, ...bySource] : bySource);
  const setSource = (i: number, patch: Partial<RoutingRule>) => onChange([...(everything ? [everything] : []), ...bySource.map((r, k) => (k === i ? { ...r, ...patch } : r))]);
  const removeSource = (i: number) => onChange([...(everything ? [everything] : []), ...bySource.filter((_, k) => k !== i)]);
  const addSource = () => onChange([...(everything ? [everything] : []), ...bySource, { source: free[0]!, userIds: [] }]);

  const picker = (value: string[], set: (v: string[]) => void, label: string, placeholder: string) => (
    <Autocomplete
      multiple
      size="small"
      fullWidth
      options={people.map((p) => p.userId)}
      getOptionLabel={name}
      value={value}
      disabled={disabled}
      onChange={(_, v) => set(v)}
      renderValue={(v, getItemProps) => v.map((id, k) => <Chip size="small" label={name(id)} {...getItemProps({ index: k })} key={id} />)}
      renderInput={(p) => <TextField {...p} label={label} placeholder={value.length ? '' : placeholder} />}
    />
  );

  return (
    <Stack spacing={2}>
      <Box sx={{ p: 1.75, borderRadius: 2, bgcolor: 'surface.sunken' }}>
        <Typography variant="subtitle2">One person (or a small group) handles every lead</Typography>
        <Typography variant="body2" color="text.secondary" sx={{ mb: 1.25 }}>
          Every new lead, from any source and also the ones your team adds by hand, goes to them. With more than one person the least busy gets each lead.
        </Typography>
        {picker(everything?.userIds ?? [], setAll, 'Send every lead to', 'Choose a person')}
      </Box>

      <Box>
        <Typography variant="subtitle2" sx={{ mb: 0.5 }}>Exceptions by source</Typography>
        <Typography variant="body2" color="text.secondary" sx={{ mb: 1.25 }}>
          {everything ? 'These win over the rule above, e.g. “Google leads go to Ravi”.' : 'Leads from a source go to the people you choose; everything else follows the setting above.'}
        </Typography>
        <Stack spacing={1.5}>
          {bySource.map((r, i) => (
            <Stack key={r.source} spacing={1.25} sx={{ p: 1.5, border: 1, borderColor: 'divider', borderRadius: 2 }}>
              <Stack direction="row" spacing={1} sx={{ alignItems: 'center' }}>
                <TextField select size="small" label="When a lead comes from" value={r.source} onChange={(e) => setSource(i, { source: e.target.value })} disabled={disabled} sx={{ flex: 1 }}>
                  {[r.source, ...free].map((s) => <MenuItem key={s} value={s}>{SOURCE_TEXT[s] ?? s}</MenuItem>)}
                </TextField>
                <IconButton size="small" aria-label={`Remove the rule for ${SOURCE_TEXT[r.source] ?? r.source}`} disabled={disabled} onClick={() => removeSource(i)}><CloseRoundedIcon fontSize="small" /></IconButton>
              </Stack>
              {picker(r.userIds, (v) => setSource(i, { userIds: v }), 'Send it to', 'Choose people')}
            </Stack>
          ))}
          <Box><Button size="small" startIcon={<AddRoundedIcon />} disabled={disabled || free.length === 0} onClick={addSource}>Add an exception</Button></Box>
        </Stack>
      </Box>
    </Stack>
  );
}
