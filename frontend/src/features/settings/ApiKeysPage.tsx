import { fontStacks } from '@/theme/theme';
import { useMemo, useState, type ReactNode } from 'react';
import {
  Alert, Autocomplete, Box, Button, Checkbox, Chip, Collapse, Dialog, DialogActions, DialogContent, DialogContentText, DialogTitle, FormControlLabel,
  MenuItem, Paper, Stack, Table, TableBody, TableCell, TableContainer, TableHead, TableRow, TextField, Typography,
} from '@mui/material';
import KeyOutlinedIcon from '@mui/icons-material/KeyOutlined';
import AddRoundedIcon from '@mui/icons-material/AddRounded';
import CallReceivedRoundedIcon from '@mui/icons-material/CallReceivedRounded';
import CallMadeRoundedIcon from '@mui/icons-material/CallMadeRounded';
import NotificationsActiveOutlinedIcon from '@mui/icons-material/NotificationsActiveOutlined';
import CheckRoundedIcon from '@mui/icons-material/CheckRounded';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { Link as RouterLink } from 'react-router-dom';
import { ApiProblem } from '@/api/client';
import { apiKeysApi, ROLE_LABEL, type ApiKey, type Role } from '@/api/endpoints';
import { keyTemplatesApi, type KeyTemplate } from '@/api/outbound';
import { CopyButton, RelativeTime, RoleChip } from '@/ui/display';
import { PageHeader } from '@/ui/PageHeader';
import { EmptyState, QueryBoundary } from '@/ui/states';
import { useConfirm } from '@/ui/confirm';
import { useToast } from '@/ui/toast';
import { useAuth } from '@/features/auth/AuthProvider';
import { apiKeyHandoff, downloadText } from '@/features/integrations/handoff';

/** What a template's scopes mean in everyday words (the raw scope names stay available under "Customise"). */
const CAN: Record<string, string> = {
  'leads:create': 'add leads',
  'contacts:create': 'add contacts',
  'leads:read': 'read leads',
  'contacts:read': 'read contacts',
  'activities:read': 'read calls and messages',
  'dashboard:read': 'read the summary numbers',
  'reports:read': 'read reports',
  'attribution:read': 'read where leads came from',
};
const plain = (scopes: string[]) => scopes.map((s) => CAN[s] ?? s);

export function ApiKeysPage() {
  const q = useQuery({ queryKey: ['api-keys'], queryFn: apiKeysApi.list });
  const templates = useQuery({ queryKey: ['api-keys', 'templates'], queryFn: keyTemplatesApi.list, retry: false });
  const [dialog, setDialog] = useState<{ preset: KeyTemplate | null } | null>(null);
  const [revealed, setRevealed] = useState<(ApiKey & { key: string }) | null>(null);
  return (
    <>
      <PageHeader
        title="API keys"
        description="Let other systems (website forms, Zapier, a spreadsheet, your own code) talk to this client. Start from a template: each one is limited to exactly its job."
        actions={<>
          <Button href="/api/docs" target="_blank" rel="noreferrer">API reference</Button>
          <Button variant="outlined" color="inherit" startIcon={<AddRoundedIcon />} onClick={() => setDialog({ preset: null })}>Custom key</Button>
        </>}
      />

      <Box sx={{ display: 'grid', gap: 2, gridTemplateColumns: { xs: '1fr', md: 'repeat(3, minmax(0, 1fr))' }, mb: 3 }}>
        {(templates.data ?? []).map((t) => (
          <TemplateCard
            key={t.id}
            icon={t.direction === 'in' ? <CallReceivedRoundedIcon /> : <CallMadeRoundedIcon />}
            tag={t.direction === 'in' ? 'Into the CRM' : 'Out of the CRM'}
            title={t.title}
            body={t.description}
            facts={[`Can: ${plain(t.scopes).join(', ')}`, `Lasts: ${t.expiresInDays ? `${t.expiresInDays} days` : 'until you revoke it'}`]}
            action={<Button variant="contained" size="small" onClick={() => setDialog({ preset: t })}>Create this key</Button>}
          />
        ))}
        <TemplateCard
          icon={<NotificationsActiveOutlinedIcon />}
          tag="CRM tells another system"
          title="Get notified when things happen"
          body="The CRM sends a signed message to your address the moment a lead arrives, moves stage or is won. No key to hand out and nothing to poll."
          facts={['Can: send new-lead, stage and activity events', 'Needs: an address that accepts POST']}
          action={<Button variant="outlined" size="small" component={RouterLink} to="/integrations/webhooks">Set up a webhook</Button>}
        />
      </Box>

      <Typography variant="eyebrow" color="text.secondary" component="div" sx={{ mb: 1 }}>Your keys</Typography>
      <QueryBoundary
        query={q}
        isEmpty={(d) => d.length === 0}
        empty={<EmptyState icon={<KeyOutlinedIcon />} title="No API keys yet" description="Pick a template above to create your first key. You’ll see the key once, so store it in a secrets manager." />}
      >
        {(keys) => <KeysTable keys={keys} />}
      </QueryBoundary>
      <CreateKeyDialog open={Boolean(dialog)} preset={dialog?.preset ?? null} onClose={() => setDialog(null)} onCreated={setRevealed} />
      <RevealDialog created={revealed} onClose={() => setRevealed(null)} />
    </>
  );
}

function TemplateCard({ icon, tag, title, body, facts, action }: { icon: ReactNode; tag: string; title: string; body: string; facts: string[]; action: ReactNode }) {
  return (
    <Paper variant="outlined" sx={{ p: 2.25, display: 'flex', flexDirection: 'column', gap: 1.25 }}>
      <Stack direction="row" spacing={1.25} sx={{ alignItems: 'center' }}>
        <Box sx={{ width: 34, height: 34, borderRadius: 2.5, display: 'grid', placeItems: 'center', bgcolor: 'accentTone.bg', color: 'accentTone.fg', '& svg': { fontSize: 19 } }}>{icon}</Box>
        <Typography variant="eyebrow" color="text.secondary">{tag}</Typography>
      </Stack>
      <Box>
        <Typography variant="h4" component="h3">{title}</Typography>
        <Typography variant="body2" color="text.secondary" sx={{ mt: 0.5 }}>{body}</Typography>
      </Box>
      <Stack spacing={0.25}>
        {facts.map((f) => <Typography key={f} variant="caption" color="text.secondary" sx={{ display: 'flex', gap: 0.75 }}><CheckRoundedIcon sx={{ fontSize: 14, mt: '2px', color: 'success.main' }} />{f}</Typography>)}
      </Stack>
      <Box sx={{ mt: 'auto', pt: 0.5 }}>{action}</Box>
    </Paper>
  );
}

function KeysTable({ keys }: { keys: ApiKey[] }) {
  const qc = useQueryClient();
  const toast = useToast();
  const confirm = useConfirm();
  const revoke = useMutation({
    mutationFn: apiKeysApi.revoke,
    onSuccess: () => { void qc.invalidateQueries({ queryKey: ['api-keys'] }); toast.success('Key revoked. It stops working immediately.'); },
    onError: (e) => toast.error(e instanceof ApiProblem ? e.detail : 'Couldn’t revoke the key'),
  });
  return (
    <TableContainer component={Paper} variant="outlined">
      <Table size="small" aria-label="API keys">
        <TableHead>
          <TableRow>
            <TableCell>Name</TableCell>
            <TableCell>Key</TableCell>
            <TableCell sx={{ display: { xs: 'none', md: 'table-cell' } }}>Permissions</TableCell>
            <TableCell sx={{ display: { xs: 'none', md: 'table-cell' } }}>Last used</TableCell>
            <TableCell align="right" />
          </TableRow>
        </TableHead>
        <TableBody>
          {keys.map((k) => {
            const expired = k.expiresAt && new Date(k.expiresAt) < new Date();
            const dead = Boolean(k.revokedAt) || Boolean(expired);
            return (
              <TableRow key={k.id} hover sx={{ opacity: dead ? 0.55 : 1 }}>
                <TableCell>
                  <Typography variant="subtitle2">{k.name}</Typography>
                  <Stack direction="row" spacing={0.75} sx={{ mt: 0.25, alignItems: 'center' }}>
                    <RoleChip role={k.role} />
                    {k.revokedAt && <Chip size="small" color="error" variant="outlined" label="Revoked" />}
                    {!k.revokedAt && expired && <Chip size="small" color="warning" variant="outlined" label="Expired" />}
                    {!dead && k.expiresAt && <Typography variant="caption" color="text.secondary">expires <RelativeTime value={k.expiresAt} /></Typography>}
                  </Stack>
                </TableCell>
                <TableCell><Typography variant="mono">crm_live_{k.prefix}_••••</Typography></TableCell>
                <TableCell sx={{ display: { xs: 'none', md: 'table-cell' }, maxWidth: 280 }}>
                  <Typography variant="caption" color="text.secondary">{plain(k.scopes).slice(0, 3).join(', ')}{k.scopes.length > 3 ? ` +${k.scopes.length - 3} more` : ''}</Typography>
                </TableCell>
                <TableCell sx={{ display: { xs: 'none', md: 'table-cell' }, color: 'text.secondary' }}>
                  <RelativeTime value={k.lastUsedAt} fallback="Never" />
                  {k.lastUsedIp && <Typography variant="caption" component="div">{k.lastUsedIp}</Typography>}
                </TableCell>
                <TableCell align="right">
                  {!k.revokedAt && (
                    <Button size="small" color="error" onClick={async () => { if (await confirm({ title: `Revoke “${k.name}”?`, body: 'Anything using this key will start receiving 401 errors immediately. This can’t be undone.', confirmLabel: 'Revoke key', destructive: true })) revoke.mutate(k.id); }}>
                      Revoke
                    </Button>
                  )}
                </TableCell>
              </TableRow>
            );
          })}
        </TableBody>
      </Table>
    </TableContainer>
  );
}

function CreateKeyDialog({ open, preset, onClose, onCreated }: { open: boolean; preset: KeyTemplate | null; onClose: () => void; onCreated: (k: ApiKey & { key: string }) => void }) {
  const qc = useQueryClient();
  const [name, setName] = useState('');
  const [role, setRole] = useState<Role>('sales_agent');
  const [scopes, setScopes] = useState<string[]>([]);
  const [expires, setExpires] = useState<string>('');
  const [custom, setCustom] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const available = useQuery({ queryKey: ['api-keys', 'scopes'], queryFn: apiKeysApi.scopes, enabled: open && (custom || !preset) });
  const [seeded, setSeeded] = useState<string | null>(null);

  // Start from the template each time the dialog opens for a different one.
  const seedKey = open ? (preset?.id ?? 'custom') : null;
  if (seedKey !== seeded) {
    setSeeded(seedKey);
    if (seedKey) {
      setName(preset?.suggestedName ?? '');
      setRole(preset?.role ?? 'sales_agent');
      setScopes(preset?.scopes ?? []);
      setExpires(preset?.expiresInDays ? String(preset.expiresInDays) : '');
      setCustom(false);
      setError(null);
    }
  }

  const options = useMemo(() => [...(available.data?.scopes ?? [])].sort(), [available.data]);
  const create = useMutation({
    mutationFn: () => apiKeysApi.create({ name: name.trim(), role, scopes, expiresInDays: expires ? Number(expires) : undefined }),
    onSuccess: (k) => { void qc.invalidateQueries({ queryKey: ['api-keys'] }); onClose(); onCreated(k); },
    onError: (e) => setError(e instanceof ApiProblem ? (e.errors[0]?.message ?? e.detail) : 'Couldn’t create the key'),
  });
  const showAdvanced = custom || !preset;

  return (
    <Dialog open={open} onClose={onClose} maxWidth="sm" aria-labelledby="key-title">
      <DialogTitle id="key-title">{preset ? preset.title : 'Create a custom API key'}</DialogTitle>
      <DialogContent>
        <Stack spacing={2.25}>
          {error && <Alert severity="error">{error}</Alert>}
          {preset && <Typography variant="body2" color="text.secondary">{preset.description}</Typography>}
          <TextField label="Name" autoFocus placeholder="e.g. Website contact form" value={name} onChange={(e) => setName(e.target.value)} helperText="Helps you recognise it later." />
          <TextField select label="Expires" value={expires} onChange={(e) => setExpires(e.target.value)} slotProps={{ select: { displayEmpty: true }, inputLabel: { shrink: true } }}>
            <MenuItem value="">Never (until you revoke it)</MenuItem>
            <MenuItem value="30">In 30 days</MenuItem>
            <MenuItem value="90">In 90 days</MenuItem>
            <MenuItem value="365">In 1 year</MenuItem>
          </TextField>
          {preset && !custom && <Button size="small" color="inherit" sx={{ alignSelf: 'flex-start', ml: -1 }} onClick={() => setCustom(true)}>Customise what it can do</Button>}
          <Collapse in={showAdvanced} unmountOnExit>
            <Stack spacing={2.25}>
              <TextField select label="Maximum role" value={role} onChange={(e) => (setRole(e.target.value as Role), setScopes([]))} helperText="A key can never do more than this role, even if you grant more scopes.">
                {(available.data?.roles ?? ['sales_agent']).map((r) => <MenuItem key={r} value={r}>{ROLE_LABEL[r]}</MenuItem>)}
              </TextField>
              <Autocomplete
                multiple
                disableCloseOnSelect
                options={options}
                value={scopes}
                onChange={(_, v) => setScopes(v)}
                renderOption={(props, option, { selected }) => {
                  const { key, ...rest } = props as typeof props & { key: string };
                  return <li key={key} {...rest}><Checkbox size="small" checked={selected} sx={{ mr: 1 }} />{option}</li>;
                }}
                renderInput={(p) => <TextField {...p} label="Scopes" placeholder={scopes.length ? '' : 'Choose what this key may do'} helperText="Grant only what the integration needs." />}
                slotProps={{ chip: { size: 'small' } }}
              />
            </Stack>
          </Collapse>
          {!showAdvanced && <Typography variant="caption" color="text.secondary">It can: {plain(scopes).join(', ')}. Nothing else.</Typography>}
        </Stack>
      </DialogContent>
      <DialogActions>
        <Button onClick={onClose}>Cancel</Button>
        <Button variant="contained" disabled={!name.trim() || scopes.length === 0 || create.isPending} onClick={() => (setError(null), create.mutate())}>{create.isPending ? 'Creating…' : 'Create key'}</Button>
      </DialogActions>
    </Dialog>
  );
}

function RevealDialog({ created, onClose }: { created: (ApiKey & { key: string }) | null; onClose: () => void }) {
  const [acknowledged, setAcknowledged] = useState(false);
  const { tenant } = useAuth();
  const toast = useToast();
  const handoff = () => (created ? apiKeyHandoff(created, window.location.origin, tenant?.name ?? 'the client') : '');
  return (
    <Dialog open={Boolean(created)} onClose={(_, reason) => { if (reason !== 'backdropClick') { setAcknowledged(false); onClose(); } }} aria-labelledby="reveal-title">
      <DialogTitle id="reveal-title">Copy your API key</DialogTitle>
      <DialogContent>
        <Stack spacing={2}>
          <DialogContentText>This is the only time the full key is shown. Store it in a secrets manager, never in code or chat.</DialogContentText>
          <Box sx={{ display: 'flex', alignItems: 'center', gap: 1, p: 1.5, bgcolor: 'surface.sunken', borderRadius: 2, border: 1, borderColor: 'divider' }}>
            <Typography variant="mono" sx={{ flex: 1, wordBreak: 'break-all', userSelect: 'all' }}>{created?.key}</Typography>
            <CopyButton value={created?.key ?? ''} label="Copy API key" />
          </Box>
          <Typography variant="caption" color="text.secondary" sx={{ fontFamily: fontStacks.mono }}>
            curl -H "Authorization: Bearer {created ? `crm_live_${created.prefix}_…` : ''}" {window.location.origin}/api/v1/members/directory
          </Typography>
          <FormControlLabel control={<Checkbox checked={acknowledged} onChange={(e) => setAcknowledged(e.target.checked)} />} label="I’ve copied the key somewhere safe" />
        </Stack>
      </DialogContent>
      <DialogActions sx={{ flexWrap: 'wrap', gap: 1 }}>
        <Button onClick={async () => { try { await navigator.clipboard.writeText(handoff()); toast.success('Instructions copied, including the key. Send them over a private channel.'); } catch { toast.error('Copying is blocked here. Use Download instead.'); } }}>Copy instructions for the developer</Button>
        <Button onClick={() => downloadText('api-access.md', handoff())}>Download</Button>
        <Box sx={{ flex: 1 }} />
        <Button variant="contained" disabled={!acknowledged} onClick={() => { setAcknowledged(false); onClose(); }}>Done</Button>
      </DialogActions>
    </Dialog>
  );
}
