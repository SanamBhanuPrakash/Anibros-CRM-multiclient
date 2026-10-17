import { useEffect, useMemo, useState } from 'react';
import { Link as RouterLink } from 'react-router-dom';
import { Alert, Box, Button, Chip, InputAdornment, List, ListItemButton, MenuItem, Paper, Stack, TextField, Typography } from '@mui/material';
import SearchRoundedIcon from '@mui/icons-material/SearchRounded';
import CheckRoundedIcon from '@mui/icons-material/CheckRounded';
import AutoAwesomeOutlinedIcon from '@mui/icons-material/AutoAwesomeOutlined';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { leadsApi, type Lead } from '@/api/crm';
import { shortlistApi } from '@/api/deals';
import { propertiesApi } from '@/api/inventory';
import { matchingApi, type Match, type MatchCriteria } from '@/api/matching';
import { useAuth } from '@/features/auth/AuthProvider';
import { problemText } from '@/features/deals/shared';
import { formatMoney, StageChip } from '@/features/leads/shared';
import { TYPE_LABEL } from '@/features/inventory/shared';
import { PageHeader } from '@/ui/PageHeader';
import { Panel } from '@/ui/Panel';
import { EmptyState, ErrorState, SkeletonRows } from '@/ui/states';
import { useToast } from '@/ui/toast';

const TYPES = Object.keys(TYPE_LABEL);
const useDebounced = <T,>(v: T, ms: number) => {
  const [d, setD] = useState(v);
  useEffect(() => { const t = setTimeout(() => setD(v), ms); return () => clearTimeout(t); }, [v, ms]);
  return d;
};
const num = (s: string): number | undefined => (s.trim() !== '' && Number.isFinite(Number(s)) && Number(s) >= 0 ? Number(s) : undefined);

/**
 * Property matching: suggest the available properties that fit a buyer's own recorded requirements (budget, area, bedrooms, type) and say
 * why. The criteria start from what the lead records and can be adjusted here without changing the lead. Nothing is invented: a lead
 * with no requirements gets an unnarrowed list and a note saying so; a workspace with nothing available gets an explanation, never a fake match.
 */
export function MatchingPage() {
  const { can } = useAuth();
  const toast = useToast();
  const qc = useQueryClient();
  const [search, setSearch] = useState('');
  const term = useDebounced(search.trim(), 300);
  const leads = useQuery({ queryKey: ['leads', 'matching-picker', term], queryFn: async () => (await leadsApi.list({ q: term || undefined, state: 'open' })).data, placeholderData: (p) => p });
  const [leadId, setLeadId] = useState<string | null>(null);
  const lead: Lead | undefined = leads.data?.find((l) => l.id === leadId) ?? (leadId ? undefined : leads.data?.[0]);
  useEffect(() => { if (!leadId && leads.data?.[0]) setLeadId(leads.data[0].id); }, [leads.data, leadId]);

  // criteria, started from the lead and editable
  const [budgetMin, setBudgetMin] = useState('');
  const [budgetMax, setBudgetMax] = useState('');
  const [location, setLocation] = useState('');
  const [bedrooms, setBedrooms] = useState('');
  const [ptype, setPtype] = useState('');
  useEffect(() => {
    if (!lead) return;
    setBudgetMin(lead.budgetMin ? String(lead.budgetMin) : '');
    setBudgetMax(lead.budgetMax ? String(lead.budgetMax) : '');
    setLocation(lead.locations[0] ?? '');
    setBedrooms(lead.bedroomsMin ? String(lead.bedroomsMin) : '');
    setPtype(lead.propertyTypes[0] ?? '');
  }, [lead?.id]); // eslint-disable-line react-hooks/exhaustive-deps

  const criteria = useDebounced<MatchCriteria>({ budgetMin: num(budgetMin), budgetMax: num(budgetMax), location: location.trim() || undefined, bedrooms: num(bedrooms), propertyType: ptype || undefined, listingType: lead?.listingType ?? undefined }, 350);
  const none = !criteria.budgetMin && !criteria.budgetMax && !criteria.location && !criteria.bedrooms && !criteria.propertyType;
  const matches = useQuery({ queryKey: ['matching', lead?.id, criteria], queryFn: () => matchingApi.match(criteria), enabled: Boolean(lead && can('matching:use')) });
  const stock = useQuery({ queryKey: ['properties', 'summary'], queryFn: propertiesApi.summary, enabled: matches.isSuccess && matches.data.length === 0 });
  const shortlist = useQuery({ queryKey: ['shortlist', lead?.id], queryFn: () => shortlistApi.list(lead!.id), enabled: Boolean(lead) });
  const saved = useMemo(() => new Set((shortlist.data ?? []).map((i) => i.propertyId)), [shortlist.data]);

  const save = useMutation({
    mutationFn: (m: Match) => shortlistApi.add({ leadId: lead!.id, propertyId: m.property.id, source: 'matching', matchScore: m.score }),
    onSuccess: () => { void qc.invalidateQueries({ queryKey: ['shortlist'] }); toast.success('Saved to their shortlist'); },
    onError: (e) => toast.error(problemText(e, 'Couldn’t save to the shortlist')),
  });

  return (
    <>
      <PageHeader title="Property matching" description="Suggests available properties for a buyer from their budget, area, bedrooms and type. Rule-based: every score lists the reasons behind it." />
      <Box sx={{ display: 'grid', gap: 2.5, gridTemplateColumns: { xs: 'minmax(0, 1fr)', md: 'minmax(260px, 340px) minmax(0, 1fr)' }, alignItems: 'start' }}>
        {/* the buyer */}
        <Stack spacing={1.5} sx={{ minWidth: 0 }}>
          <TextField
            placeholder="Search open leads"
            value={search}
            onChange={(e) => setSearch(e.target.value)}
            slotProps={{ input: { startAdornment: <InputAdornment position="start"><SearchRoundedIcon fontSize="small" /></InputAdornment> }, htmlInput: { 'aria-label': 'Search open leads', maxLength: 100 } }}
          />
          {leads.isPending ? <SkeletonRows rows={4} height={56} /> : leads.isError ? <ErrorState error={leads.error} onRetry={() => void leads.refetch()} /> : leads.data.length === 0 ? (
            <Paper variant="outlined" sx={{ p: 2, borderRadius: 2 }}>
              <Typography variant="body2" sx={{ mb: 1 }}>{term ? `No open lead matches “${term}”.` : 'There are no open leads yet.'}</Typography>
              {!term && <Button size="small" component={RouterLink} to="/leads">Go to Leads</Button>}
            </Paper>
          ) : (
            <Paper variant="outlined" sx={{ borderRadius: 2, overflow: 'hidden' }}>
              <List role="listbox" aria-label="Open leads" disablePadding sx={{ maxHeight: { md: 560 }, overflow: 'auto' }}>
                {leads.data.map((l) => (
                  <ListItemButton key={l.id} role="option" aria-selected={l.id === lead?.id} selected={l.id === lead?.id} onClick={() => setLeadId(l.id)} sx={{ borderRadius: 0, alignItems: 'flex-start', gap: 1, borderBottom: 1, borderColor: 'divider', '&:last-of-type': { borderBottom: 0 } }}>
                    <Box sx={{ minWidth: 0, flex: 1 }}>
                      <Typography variant="body2" sx={{ fontWeight: 600 }} noWrap>{l.contactName ?? 'Unnamed lead'}</Typography>
                      <Typography variant="caption" color="text.secondary" component="div">
                        {l.budgetMax ? `Budget up to ${formatMoney(l.budgetMax, l.currency)}` : l.estimatedValue ? `Estimated ${formatMoney(l.estimatedValue, l.currency)}` : 'No budget recorded'}
                      </Typography>
                    </Box>
                    <StageChip name={l.stageName} color={l.stageColor} />
                  </ListItemButton>
                ))}
              </List>
            </Paper>
          )}
        </Stack>

        {/* the suggestions */}
        <Stack spacing={2} sx={{ minWidth: 0 }}>
          {!can('matching:use') ? (
            <EmptyState icon={<AutoAwesomeOutlinedIcon />} title="Matching is not available for your role" description="Ask an administrator if you need it." />
          ) : !lead ? (
            <EmptyState icon={<AutoAwesomeOutlinedIcon />} title="Choose a lead to see matches" description="Pick a buyer on the left. Suggestions come from the budget, area, bedrooms and property type recorded on their lead." />
          ) : (
            <>
              <Panel title={`Matching for ${lead.contactName ?? 'this lead'}`} note={<>Starts from what the lead records; change a field to explore without changing the lead. <Typography component={RouterLink} to={`/leads/${lead.id}`} variant="caption" sx={{ color: 'primary.main', textDecoration: 'underline' }}>Open the lead</Typography></>}>
                <Box sx={{ display: 'grid', gap: 1.5, gridTemplateColumns: { xs: 'repeat(2, minmax(0, 1fr))', lg: 'repeat(5, minmax(0, 1fr))' } }}>
                  <TextField label="Budget from" value={budgetMin} onChange={(e) => setBudgetMin(e.target.value)} slotProps={{ htmlInput: { inputMode: 'numeric' } }} />
                  <TextField label="Budget up to" value={budgetMax} onChange={(e) => setBudgetMax(e.target.value)} slotProps={{ htmlInput: { inputMode: 'numeric' } }} />
                  <TextField label="Area" value={location} onChange={(e) => setLocation(e.target.value)} />
                  <TextField select label="Bedrooms" value={bedrooms} onChange={(e) => setBedrooms(e.target.value)}>
                    <MenuItem value="">Any</MenuItem>
                    {[1, 2, 3, 4, 5].map((n) => <MenuItem key={n} value={String(n)}>{n}{n === 5 ? '+' : ''}</MenuItem>)}
                  </TextField>
                  <TextField select label="Property type" value={ptype} onChange={(e) => setPtype(e.target.value)}>
                    <MenuItem value="">Any</MenuItem>
                    {TYPES.map((t) => <MenuItem key={t} value={t}>{TYPE_LABEL[t as keyof typeof TYPE_LABEL]}</MenuItem>)}
                  </TextField>
                </Box>
                {none && <Alert severity="info" sx={{ mt: 1.5 }}>This lead has no budget, area, bedrooms or type recorded, so nothing narrows the list and scores are neutral. Add the buyer’s requirements on the lead (or type them above) for sharper suggestions.</Alert>}
              </Panel>

              {matches.isPending ? <SkeletonRows rows={3} height={110} /> : matches.isError ? <ErrorState error={matches.error} onRetry={() => void matches.refetch()} /> : matches.data.length === 0 ? (
                <EmptyState
                  icon={<AutoAwesomeOutlinedIcon />}
                  title={stock.data && stock.data.available.count > 0 ? 'No available property fits these requirements' : 'No property is available right now'}
                  description={stock.data && stock.data.available.count > 0 ? `${stock.data.available.count} ${stock.data.available.count === 1 ? 'property is' : 'properties are'} available, but none fits. Widen the budget, remove the area or choose “Any” for bedrooms and type.` : 'Matching only suggests properties that are available. Properties that are reserved, sold, rented or off the market are never suggested.'}
                  action={<Button component={RouterLink} to="/properties" variant="outlined">Open Properties</Button>}
                />
              ) : (
                <Stack spacing={1.25} component="ul" sx={{ listStyle: 'none', m: 0, p: 0 }} aria-label="Suggested properties">
                  {matches.data.map((m) => {
                    const strong = m.score >= 80;
                    const good = m.score >= 60;
                    const onList = saved.has(m.property.id);
                    return (
                      <Paper component="li" key={m.property.id} variant="outlined" sx={{ p: 2, borderRadius: 2, display: 'grid', gridTemplateColumns: { xs: '1fr', sm: '124px minmax(0, 1fr)' }, gap: 2 }}>
                        <Box sx={{ textAlign: { sm: 'center' }, display: 'flex', flexDirection: { xs: 'row', sm: 'column' }, alignItems: { xs: 'center', sm: 'center' }, gap: 1 }}>
                          <Typography variant="h3" component="p" sx={{ fontVariantNumeric: 'tabular-nums' }}>{m.score}%</Typography>
                          <Chip size="small" label={strong ? 'Strong match' : good ? 'Good match' : 'Partial match'} color={strong ? 'success' : good ? 'primary' : 'default'} variant={strong || good ? 'filled' : 'outlined'} />
                        </Box>
                        <Box sx={{ minWidth: 0 }}>
                          <Stack direction="row" sx={{ justifyContent: 'space-between', gap: 2, flexWrap: 'wrap' }}>
                            <Box sx={{ minWidth: 0 }}>
                              <Typography variant="h5" component="h3"><Typography component={RouterLink} to={`/properties/${m.property.id}`} variant="inherit" sx={{ color: 'text.primary', textDecoration: 'none', '&:hover': { textDecoration: 'underline' } }}>{m.property.title}</Typography></Typography>
                              <Typography variant="body2" color="text.secondary">{[m.property.location, m.property.bedrooms ? `${m.property.bedrooms} bd` : null, TYPE_LABEL[m.property.propertyType as keyof typeof TYPE_LABEL]].filter(Boolean).join(' · ')}</Typography>
                            </Box>
                            <Typography variant="h4" component="p" sx={{ fontVariantNumeric: 'tabular-nums' }}>{formatMoney(m.property.price, m.property.currency)}</Typography>
                          </Stack>
                          {m.matchReasons.length > 0 ? (
                            <Stack component="ul" spacing={0.25} sx={{ listStyle: 'none', m: 0, p: 0, mt: 1 }}>
                              {m.matchReasons.map((r) => (
                                <Stack component="li" key={r} direction="row" spacing={0.75} sx={{ alignItems: 'flex-start', color: 'success.main' }}>
                                  <CheckRoundedIcon sx={{ fontSize: 16, mt: 0.2 }} aria-hidden />
                                  <Typography variant="body2" sx={{ color: 'text.primary' }}>{r}</Typography>
                                </Stack>
                              ))}
                            </Stack>
                          ) : <Typography variant="body2" color="text.secondary" sx={{ mt: 1 }}>No requirement matched; shown because it is available.</Typography>}
                          <Stack direction="row" spacing={1} sx={{ mt: 1.5, flexWrap: 'wrap', rowGap: 1 }}>
                            {can('leads:update') && (onList ? <Chip size="small" icon={<CheckRoundedIcon />} label="On their shortlist" variant="outlined" /> : <Button size="small" variant="outlined" disabled={save.isPending} onClick={() => save.mutate(m)}>Save to their shortlist</Button>)}
                            <Button size="small" component={RouterLink} to={`/properties/${m.property.id}`}>View property</Button>
                          </Stack>
                        </Box>
                      </Paper>
                    );
                  })}
                </Stack>
              )}
            </>
          )}
        </Stack>
      </Box>
    </>
  );
}
