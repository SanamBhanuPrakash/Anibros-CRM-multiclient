import { useEffect, useState } from 'react';
import { Alert, Autocomplete, Box, Button, Card, CardActionArea, Chip, Dialog, DialogActions, DialogContent, DialogTitle, MenuItem, Stack, TextField, Typography } from '@mui/material';
import { useMutation, useQueryClient } from '@tanstack/react-query';
import { ApiProblem } from '@/api/client';
import { leadSourcesApi, type CreateSourceInput, type LeadSourceEndpoint, type Provider, type SourceConfig } from '@/api/inbound';
import { SOURCE_LABEL, useDirectory, usePipelines } from '@/features/leads/shared';
import { useToast } from '@/ui/toast';
import { PROVIDER_INFO } from './sourceMeta';

const PROVIDERS: Provider[] = ['meta', 'google', 'website', 'generic'];
const CUSTOM_SOURCES = ['website', 'portal', 'referral', 'direct', 'walk_in', 'other'];

interface Form {
  name: string;
  appSecret: string;
  pageAccessToken: string;
  campaign: string;
  tags: string[];
  source: string;
  pipelineId: string;
  stageId: string;
  ownerId: string;
  allowedOrigins: string[];
  fieldMap: string; // "their_field = our_field" per line
}
const empty: Form = { name: '', appSecret: '', pageAccessToken: '', campaign: '', tags: [], source: '', pipelineId: '', stageId: '', ownerId: '', allowedOrigins: [], fieldMap: '' };

const fromEndpoint = (e: LeadSourceEndpoint): Form => ({
  ...empty,
  name: e.name,
  campaign: e.config.campaign ?? '',
  tags: e.config.tags ?? [],
  source: e.config.source ?? '',
  pipelineId: e.config.pipelineId ?? '',
  stageId: e.config.stageId ?? '',
  ownerId: e.config.ownerId ?? '',
  allowedOrigins: e.config.allowedOrigins ?? [],
  fieldMap: Object.entries(e.config.fieldMap ?? {}).map(([k, v]) => `${k} = ${v}`).join('\n'),
});

function parseFieldMap(text: string): Record<string, string> | undefined {
  const out: Record<string, string> = {};
  for (const line of text.split('\n')) {
    const [k, v] = line.split('=').map((s) => s.trim());
    if (k && v) out[k] = v;
  }
  return Object.keys(out).length ? out : undefined;
}

/** Create a source (choose the kind, then fill in what that kind needs) or edit one. */
export function SourceDialog({ open, source, onClose, onSaved }: { open: boolean; source?: LeadSourceEndpoint; onClose: () => void; onSaved: (e: LeadSourceEndpoint, secret: string | null, created: boolean) => void }) {
  const qc = useQueryClient();
  const toast = useToast();
  const pipelines = usePipelines();
  const directory = useDirectory();
  const [provider, setProvider] = useState<Provider | null>(null);
  const [f, setF] = useState<Form>(empty);
  const [error, setError] = useState<string | null>(null);
  const [fe, setFe] = useState<Record<string, string>>({});
  const set = <K extends keyof Form>(k: K, v: Form[K]) => setF((x) => ({ ...x, [k]: v }));

  useEffect(() => {
    if (!open) return;
    setError(null);
    setFe({});
    setProvider(source?.provider ?? null);
    setF(source ? fromEndpoint(source) : empty);
  }, [open, source]);

  const stages = pipelines.data?.find((p) => p.id === f.pipelineId)?.stages ?? [];
  const needsSourceLabel = provider === 'website' || provider === 'generic';

  const save = useMutation({
    mutationFn: async () => {
      const config: SourceConfig = {
        ...(f.campaign.trim() ? { campaign: f.campaign.trim() } : {}),
        ...(f.tags.length ? { tags: f.tags } : {}),
        ...(needsSourceLabel && f.source ? { source: f.source } : {}),
        ...(f.pipelineId ? { pipelineId: f.pipelineId } : {}),
        ...(f.pipelineId && f.stageId ? { stageId: f.stageId } : {}),
        ...(f.ownerId ? { ownerId: f.ownerId } : {}),
        ...(provider === 'website' && f.allowedOrigins.length ? { allowedOrigins: f.allowedOrigins } : {}),
        ...(provider === 'generic' && parseFieldMap(f.fieldMap) ? { fieldMap: parseFieldMap(f.fieldMap) } : {}),
      };
      if (source) {
        const updated = await leadSourcesApi.update(source.id, {
          name: f.name.trim(),
          config,
          ...(provider === 'meta' && f.appSecret ? { appSecret: f.appSecret.trim() } : {}),
          ...(provider === 'meta' && f.pageAccessToken ? { pageAccessToken: f.pageAccessToken.trim() } : {}),
        });
        return { endpoint: updated, secret: null as string | null, created: false };
      }
      const input: CreateSourceInput = { provider: provider!, name: f.name.trim(), config, ...(provider === 'meta' ? { appSecret: f.appSecret.trim(), ...(f.pageAccessToken ? { pageAccessToken: f.pageAccessToken.trim() } : {}) } : {}) };
      const r = await leadSourcesApi.create(input);
      return { ...r, created: true };
    },
    onSuccess: (r) => {
      void qc.invalidateQueries({ queryKey: ['lead-sources'] });
      toast.success(r.created ? `${r.endpoint.name} added` : 'Source updated');
      onSaved(r.endpoint, r.secret, r.created);
    },
    onError: (e) => {
      if (!(e instanceof ApiProblem)) return setError('We couldn’t save this source. Please try again.');
      const fields = e.fieldErrors();
      setFe(fields);
      if (!Object.keys(fields).length) setError(e.detail);
    },
  });

  const canSave = Boolean(provider && f.name.trim() && (source || provider !== 'meta' || f.appSecret.trim().length >= 8));

  return (
    <Dialog open={open} onClose={save.isPending ? undefined : onClose} fullWidth maxWidth="sm" scroll="paper" aria-labelledby="source-dialog-title">
      <DialogTitle id="source-dialog-title">{source ? `Settings · ${source.name}` : provider ? `Add ${PROVIDER_INFO[provider].label}` : 'Where do your leads come from?'}</DialogTitle>
      <DialogContent dividers>
        {!provider ? (
          <Box sx={{ display: 'grid', gap: 1.5, gridTemplateColumns: { xs: '1fr', sm: '1fr 1fr' } }}>
            {PROVIDERS.map((p) => (
              <Card key={p} variant="outlined" sx={{ borderRadius: 2.5 }}>
                <CardActionArea onClick={() => setProvider(p)} sx={{ p: 2, height: '100%', alignItems: 'flex-start' }} aria-label={`Add ${PROVIDER_INFO[p].label}`}>
                  <Stack spacing={1}>
                    <Box sx={{ color: 'primary.main', display: 'flex' }}>{PROVIDER_INFO[p].icon}</Box>
                    <Typography variant="subtitle1">{PROVIDER_INFO[p].label}</Typography>
                    <Typography variant="body2" color="text.secondary">{PROVIDER_INFO[p].blurb}</Typography>
                  </Stack>
                </CardActionArea>
              </Card>
            ))}
          </Box>
        ) : (
          <Stack component="form" id="source-form" spacing={2.25} noValidate onSubmit={(e) => { e.preventDefault(); setError(null); setFe({}); save.mutate(); }}>
            {error && <Alert severity="error">{error}</Alert>}
            <TextField label="Name" required autoFocus fullWidth value={f.name} onChange={(e) => set('name', e.target.value)} error={Boolean(fe.name)} helperText={fe.name ?? 'Only you see this, e.g. “Whitefield launch — Facebook”'} slotProps={{ htmlInput: { maxLength: 120 } }} />

            {provider === 'meta' && (
              <>
                <TextField label="Meta app secret" type="password" autoComplete="off" required={!source} fullWidth value={f.appSecret} onChange={(e) => set('appSecret', e.target.value)} error={Boolean(fe.appSecret)}
                  helperText={fe.appSecret ?? (source ? `Stored encrypted (ends in ${source.secretHint ?? '····'}). Paste a new one only to replace it.` : 'Meta for Developers → your app → Settings → Basic → App secret. We use it to check every delivery really comes from Meta.')} />
                <TextField label="Page access token" type="password" autoComplete="off" fullWidth value={f.pageAccessToken} onChange={(e) => set('pageAccessToken', e.target.value)} error={Boolean(fe.pageAccessToken)}
                  helperText={fe.pageAccessToken ?? (source?.hasAccessToken ? 'A token is stored. Paste a new one only to replace it.' : 'Needed to read what the person typed (Meta only sends an id). You can add it later; deliveries wait for it.')} />
              </>
            )}

            {provider === 'website' && (
              <Autocomplete multiple freeSolo options={[] as string[]} value={f.allowedOrigins} onChange={(_, v) => set('allowedOrigins', v)}
                renderValue={(v, getItemProps) => v.map((o, i) => <Chip size="small" label={o} {...getItemProps({ index: i })} key={o} />)}
                renderInput={(p) => <TextField {...p} label="Websites allowed to send leads" error={Boolean(fe['config.allowedOrigins'])} helperText={fe['config.allowedOrigins'] ?? 'e.g. https://www.yourrealty.com — type and press Enter. Leave empty to accept any website.'} />} />
            )}

            <Typography variant="eyebrow" component="p" sx={{ pt: 1 }}>What happens to the lead</Typography>
            <TextField label="Campaign name" fullWidth value={f.campaign} onChange={(e) => set('campaign', e.target.value)} helperText={provider === 'meta' || provider === 'google' ? 'Optional. Leave empty to use the campaign from the ad platform.' : 'Optional label shown on every lead from here.'} slotProps={{ htmlInput: { maxLength: 150 } }} />
            {needsSourceLabel && (
              <TextField select label="Lead source" fullWidth value={f.source} onChange={(e) => set('source', e.target.value)} slotProps={{ select: { displayEmpty: true }, inputLabel: { shrink: true } }}>
                <MenuItem value=""><em>{provider === 'website' ? 'Website' : 'Other'} (default)</em></MenuItem>
                {CUSTOM_SOURCES.map((s) => <MenuItem key={s} value={s}>{SOURCE_LABEL[s as keyof typeof SOURCE_LABEL] ?? s}</MenuItem>)}
              </TextField>
            )}
            <Autocomplete multiple freeSolo options={[] as string[]} value={f.tags} onChange={(_, v) => set('tags', v)} renderValue={(v, getItemProps) => v.map((o, i) => <Chip size="small" label={o} {...getItemProps({ index: i })} key={o} />)} renderInput={(p) => <TextField {...p} label="Tags" helperText="Added to every lead from this source. Type and press Enter." />} />
            <Stack direction={{ xs: 'column', sm: 'row' }} spacing={2}>
              <TextField select label="Pipeline" fullWidth value={f.pipelineId} onChange={(e) => { set('pipelineId', e.target.value); set('stageId', ''); }} slotProps={{ select: { displayEmpty: true }, inputLabel: { shrink: true } }} error={Boolean(fe['config.pipelineId'])} helperText={fe['config.pipelineId']}>
                <MenuItem value=""><em>Default pipeline</em></MenuItem>
                {(pipelines.data ?? []).map((p) => <MenuItem key={p.id} value={p.id}>{p.name}</MenuItem>)}
              </TextField>
              <TextField select label="Starting stage" fullWidth value={f.stageId} onChange={(e) => set('stageId', e.target.value)} disabled={!f.pipelineId} slotProps={{ select: { displayEmpty: true }, inputLabel: { shrink: true } }}>
                <MenuItem value=""><em>First stage</em></MenuItem>
                {stages.filter((s) => !s.isClosed).map((s) => <MenuItem key={s.id} value={s.id}>{s.name}</MenuItem>)}
              </TextField>
            </Stack>
            <TextField select label="Assign to" fullWidth value={f.ownerId} onChange={(e) => set('ownerId', e.target.value)} slotProps={{ select: { displayEmpty: true }, inputLabel: { shrink: true } }} error={Boolean(fe['config.ownerId'])} helperText={fe['config.ownerId'] ?? 'Choose a person, or follow the workspace rule (balanced between your sales team).'}>
              <MenuItem value=""><em>Follow the workspace rule</em></MenuItem>
              {(directory.data ?? []).map((m) => <MenuItem key={m.userId} value={m.userId}>{m.name}</MenuItem>)}
            </TextField>

            {provider === 'generic' && (
              <TextField label="Rename your fields (optional)" multiline minRows={2} maxRows={6} fullWidth value={f.fieldMap} onChange={(e) => set('fieldMap', e.target.value)} placeholder={'mobile_no = phone\nperson = name'} helperText="One per line: your field name = our name (name, email, phone, budget, location…). Common names are understood without this." slotProps={{ htmlInput: { style: { fontFamily: 'ui-monospace, SFMono-Regular, Menlo, monospace', fontSize: 13 } } }} />
            )}
          </Stack>
        )}
      </DialogContent>
      <DialogActions>
        {provider && !source && <Button onClick={() => setProvider(null)} disabled={save.isPending} sx={{ mr: 'auto' }}>Back</Button>}
        <Button onClick={onClose} disabled={save.isPending}>Cancel</Button>
        {provider && <Button type="submit" form="source-form" variant="contained" disabled={!canSave || save.isPending}>{save.isPending ? 'Saving…' : source ? 'Save changes' : 'Add source'}</Button>}
      </DialogActions>
    </Dialog>
  );
}
