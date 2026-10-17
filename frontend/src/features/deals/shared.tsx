import { useState } from 'react';
import { Autocomplete, Chip, TextField, type ChipProps } from '@mui/material';
import { useQuery, type QueryClient } from '@tanstack/react-query';
import { ApiProblem } from '@/api/client';
import { propertiesApi, type Property } from '@/api/inventory';
import type { Interest, Reservation, ReservationStatus } from '@/api/deals';

export const DEAL_STATUS_LABEL: Record<ReservationStatus, string> = { offered: 'Offer', reserved: 'Reserved', completed: 'Sold', closed: 'Closed' };
const DEAL_COLOR: Record<ReservationStatus, ChipProps['color']> = { offered: 'info', reserved: 'warning', completed: 'success', closed: 'default' };

export function DealStatusChip({ deal }: { deal: Pick<Reservation, 'status' | 'property'> }) {
  const rental = deal.property.listingType !== 'sale';
  const label = deal.status === 'completed' ? (rental ? 'Rented' : 'Sold') : DEAL_STATUS_LABEL[deal.status];
  return <Chip size="small" label={label} color={DEAL_COLOR[deal.status]} variant={deal.status === 'closed' ? 'outlined' : 'filled'} />;
}

export const INTEREST_LABEL: Record<Interest['status'], string> = { shortlisted: 'Shortlisted', interested: 'Interested', rejected: 'Not for them' };

/** Everything that can change when a deal moves. Called after every deal action. */
export function invalidateDeals(qc: QueryClient) {
  for (const k of ['deals', 'shortlist', 'leads', 'properties', 'home', 'dashboard', 'pipelines', 'tasks']) void qc.invalidateQueries({ queryKey: [k] });
}

/** The first thing the server said was wrong, in its own words. */
export const problemText = (e: unknown, fallback: string) => (e instanceof ApiProblem ? (e.errors[0]?.message ?? e.detail ?? fallback) : fallback);

/** A positive amount from a text box; null while it is empty or not a number. */
export function parseAmount(v: string): number | null {
  const n = Number(v.replace(/[, ]/g, ''));
  return v.trim() !== '' && Number.isFinite(n) && n >= 0 ? n : null;
}

export interface UnitOption {
  id: string;
  label: string;
  price: number;
  status: string;
}

/** Search the workspace's properties. `only` narrows the statuses offered (the server enforces the real rules and explains refusals). */
export function UnitPicker({ value, onChange, only, label = 'Property', helperText }: { value: UnitOption | null; onChange: (v: UnitOption | null) => void; only?: string[]; label?: string; helperText?: string }) {
  const [q, setQ] = useState('');
  const units = useQuery({
    queryKey: ['properties', 'picker', q, only?.join(',')],
    queryFn: async () =>
      (await propertiesApi.list({ q: q || undefined }))
        .data.filter((p: Property) => !only || only.includes(p.status))
        .map((p: Property): UnitOption => ({ id: p.id, label: `${p.title}${p.unitNumber ? ` · Unit ${p.unitNumber}` : ''}`, price: p.price, status: p.status })),
  });
  return (
    <Autocomplete
      options={units.data ?? []}
      loading={units.isFetching}
      value={value}
      onChange={(_, v) => onChange(v)}
      inputValue={q}
      onInputChange={(_, v, reason) => reason !== 'reset' && setQ(v)}
      filterOptions={(o) => o}
      isOptionEqualToValue={(a, b) => a.id === b.id}
      getOptionLabel={(o) => o.label}
      noOptionsText="No properties match"
      renderInput={(p) => <TextField {...p} label={label} placeholder="Search properties" helperText={helperText} />}
    />
  );
}
