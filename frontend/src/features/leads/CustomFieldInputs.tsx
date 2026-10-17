import { Autocomplete, Chip, MenuItem, TextField } from '@mui/material';
import { useQuery } from '@tanstack/react-query';
import { customFieldsApi, type CustomField } from '@/api/customFields';

/** The open client's visible lead fields (cached; changes appear within a minute or on the next visit). */
export function useLeadFields() {
  return useQuery({ queryKey: ['custom-fields'], queryFn: () => customFieldsApi.list(), staleTime: 60_000 });
}

const isBlank = (v: unknown) => v === undefined || v === null || v === '' || (Array.isArray(v) && v.length === 0);

/** Readable text for a saved value: option names instead of codes, Yes/No for booleans. */
export function showFieldValue(f: CustomField, v: unknown): string {
  if (isBlank(v)) return '—';
  const label = (x: unknown) => f.options.find((o) => o.value === x)?.label ?? String(x);
  if (f.type === 'boolean') return v === true ? 'Yes' : v === false ? 'No' : String(v);
  if (f.type === 'select') return label(v);
  if (f.type === 'multi_select') return Array.isArray(v) ? v.map(label).join(', ') : String(v);
  if (f.type === 'number' && typeof v === 'number') return v.toLocaleString();
  if (f.type === 'date' && typeof v === 'string') return new Date(`${v}T00:00:00`).toLocaleDateString();
  return String(v);
}

/** What to send: when creating, only what was filled in; when editing, a blank field is sent as null so it is cleared. */
export function buildCustomFields(fields: CustomField[], values: Record<string, unknown>, mode: 'create' | 'edit'): Record<string, unknown> {
  const out: Record<string, unknown> = {};
  for (const f of fields) {
    const v = values[f.key];
    if (isBlank(v)) {
      if (mode === 'edit') out[f.key] = null;
    } else out[f.key] = v;
  }
  return out;
}

/** One input per visible field, by type. Values use the stored shape (booleans as true/false, dropdowns as option values). */
export function CustomFieldInputs({ fields, values, onChange, errors = {} }: { fields: CustomField[]; values: Record<string, unknown>; onChange: (key: string, value: unknown) => void; errors?: Record<string, string> }) {
  return (
    <>
      {fields.filter((f) => f.visible).map((f) => {
        const v = values[f.key];
        const error = errors[f.key];
        const common = { label: f.label, required: f.required, fullWidth: true, error: Boolean(error), helperText: error ?? f.helpText ?? undefined } as const;
        switch (f.type) {
          case 'number':
            return <TextField key={f.key} {...common} type="number" value={v === undefined || v === null ? '' : String(v)} onChange={(e) => onChange(f.key, e.target.value === '' ? undefined : Number(e.target.value))} />;
          case 'date':
            return <TextField key={f.key} {...common} type="date" value={typeof v === 'string' ? v : ''} onChange={(e) => onChange(f.key, e.target.value || undefined)} slotProps={{ inputLabel: { shrink: true } }} />;
          case 'boolean':
            return (
              <TextField key={f.key} {...common} select value={v === true ? 'true' : v === false ? 'false' : ''} onChange={(e) => onChange(f.key, e.target.value === '' ? undefined : e.target.value === 'true')} slotProps={{ select: { displayEmpty: true }, inputLabel: { shrink: true } }}>
                <MenuItem value=""><em>Not answered</em></MenuItem>
                <MenuItem value="true">Yes</MenuItem>
                <MenuItem value="false">No</MenuItem>
              </TextField>
            );
          case 'select':
            return (
              <TextField key={f.key} {...common} select value={typeof v === 'string' ? v : ''} onChange={(e) => onChange(f.key, e.target.value || undefined)} slotProps={{ select: { displayEmpty: true }, inputLabel: { shrink: true } }}>
                <MenuItem value=""><em>Not answered</em></MenuItem>
                {f.options.map((o) => <MenuItem key={o.value} value={o.value}>{o.label}</MenuItem>)}
              </TextField>
            );
          case 'multi_select': {
            const chosen = Array.isArray(v) ? (v as string[]) : [];
            return (
              <Autocomplete
                key={f.key}
                multiple
                options={f.options.map((o) => o.value)}
                getOptionLabel={(value) => f.options.find((o) => o.value === value)?.label ?? value}
                value={chosen}
                onChange={(_, next) => onChange(f.key, next.length ? next : undefined)}
                renderValue={(items, getItemProps) => items.map((value, i) => <Chip size="small" label={f.options.find((o) => o.value === value)?.label ?? value} {...getItemProps({ index: i })} key={value} />)}
                renderInput={(p) => <TextField {...p} {...common} />}
              />
            );
          }
          case 'url':
            return <TextField key={f.key} {...common} type="url" value={typeof v === 'string' ? v : ''} onChange={(e) => onChange(f.key, e.target.value || undefined)} placeholder="https://" />;
          case 'email':
            return <TextField key={f.key} {...common} type="email" value={typeof v === 'string' ? v : ''} onChange={(e) => onChange(f.key, e.target.value || undefined)} />;
          default:
            return <TextField key={f.key} {...common} value={typeof v === 'string' ? v : v === undefined || v === null ? '' : String(v)} onChange={(e) => onChange(f.key, e.target.value || undefined)} slotProps={{ htmlInput: { maxLength: 500 } }} />;
        }
      })}
    </>
  );
}
