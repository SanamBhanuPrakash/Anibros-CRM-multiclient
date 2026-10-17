import { useEffect, useState } from 'react';
import { Alert, Box, Button, Chip, Dialog, DialogActions, DialogContent, DialogContentText, DialogTitle, Stack, TextField, Typography } from '@mui/material';
import LaptopMacOutlinedIcon from '@mui/icons-material/LaptopMacOutlined';
import ShieldOutlinedIcon from '@mui/icons-material/ShieldOutlined';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useForm, useWatch } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import { QRCodeSVG } from 'qrcode.react';
import { ApiProblem } from '@/api/client';
import { meApi } from '@/api/endpoints';
import { ME_KEY, useAuth } from '@/features/auth/AuthProvider';
import { PasswordField, PasswordStrength } from '@/ui/fields';
import { CopyButton, RelativeTime } from '@/ui/display';
import { PageHeader, Section } from '@/ui/PageHeader';
import { QueryBoundary } from '@/ui/states';
import { useToast } from '@/ui/toast';
import { useConfirm } from '@/ui/confirm';
import { rhf } from '@/features/auth/AuthLayout';

export function ProfileSecurityPage() {
  return (
    <>
      <PageHeader title="Profile & security" description="Your personal details, how you sign in, and where you’re signed in." />
      <ProfileSection />
      <PasswordSection />
      <MfaSection />
      <SessionsSection />
    </>
  );
}

function ProfileSection() {
  const { me } = useAuth();
  const qc = useQueryClient();
  const toast = useToast();
  const { register, handleSubmit, formState: { errors, isDirty }, reset } = useForm<{ name: string; phone: string; timezone: string }>({
    resolver: zodResolver(z.object({ name: z.string().trim().min(2, 'Enter your name'), phone: z.string().trim().max(30), timezone: z.string().min(1) })),
    defaultValues: { name: me?.user.name ?? '', phone: me?.user.phone ?? '', timezone: me?.user.timezone ?? 'Asia/Kolkata' },
  });
  const save = useMutation({
    mutationFn: (v: { name: string; phone: string; timezone: string }) => meApi.update({ name: v.name, phone: v.phone || null, timezone: v.timezone }),
    onSuccess: async (_d, v) => {
      await qc.invalidateQueries({ queryKey: ME_KEY });
      reset(v);
      toast.success('Profile saved');
    },
    onError: (e) => toast.error(e instanceof ApiProblem ? e.detail : 'Couldn’t save your profile'),
  });
  return (
    <Section title="Profile" description="Shown to teammates on assignments, notes and activity." id="profile">
      <Box component="form" noValidate onSubmit={handleSubmit((v) => save.mutate(v))} sx={{ maxWidth: 460 }}>
        <Stack spacing={2}>
          <TextField label="Full name" autoComplete="name" error={Boolean(errors.name)} helperText={errors.name?.message} {...rhf(register('name'))} />
          <TextField label="Email" value={me?.user.email ?? ''} disabled helperText="Your sign-in address can’t be changed here." />
          <TextField label="Phone" type="tel" autoComplete="tel" {...rhf(register('phone'))} />
          <TextField label="Time zone" helperText="Used for reminders and viewing times. IANA name, e.g. Asia/Kolkata." {...rhf(register('timezone'))} />
          <Box>
            <Button type="submit" variant="contained" disabled={!isDirty || save.isPending}>{save.isPending ? 'Saving…' : 'Save changes'}</Button>
          </Box>
        </Stack>
      </Box>
    </Section>
  );
}

function PasswordSection() {
  const toast = useToast();
  const [error, setError] = useState<string | null>(null);
  const { register, handleSubmit, control, reset, formState: { errors } } = useForm<{ currentPassword: string; newPassword: string }>({
    resolver: zodResolver(z.object({ currentPassword: z.string().min(1, 'Enter your current password'), newPassword: z.string().min(10, 'Use at least 10 characters').max(128) })),
  });
  const next = useWatch({ control, name: 'newPassword' }) ?? '';
  const { me } = useAuth();
  const change = useMutation({
    mutationFn: (v: { currentPassword: string; newPassword: string }) => meApi.changePassword(v.currentPassword, v.newPassword),
    onSuccess: () => { reset(); toast.success('Password changed. Your other sessions were signed out.'); },
    onError: (e) => setError(e instanceof ApiProblem ? (e.fieldErrors().next ?? e.detail) : 'Couldn’t change your password'),
  });
  return (
    <Section title="Password" description="Changing it signs you out everywhere else." id="password">
      <Box component="form" noValidate onSubmit={handleSubmit((v) => (setError(null), change.mutate(v)))} sx={{ maxWidth: 460 }}>
        <Stack spacing={2}>
          {error && <Alert severity="error">{error}</Alert>}
          <PasswordField label="Current password" autoComplete="current-password" error={Boolean(errors.currentPassword)} helperText={errors.currentPassword?.message} {...rhf(register('currentPassword'))} />
          <PasswordField label="New password" autoComplete="new-password" error={Boolean(errors.newPassword)} helperText={errors.newPassword?.message} {...rhf(register('newPassword'))} />
          <PasswordStrength password={next} identity={{ email: me?.user.email, name: me?.user.name }} />
          <Box>
            <Button type="submit" variant="outlined" disabled={change.isPending}>{change.isPending ? 'Updating…' : 'Update password'}</Button>
          </Box>
        </Stack>
      </Box>
    </Section>
  );
}

function MfaSection() {
  const { me } = useAuth();
  const enabled = Boolean(me?.user.mfaEnabled);
  const [setupOpen, setSetupOpen] = useState(false);
  const [disableOpen, setDisableOpen] = useState(false);
  const [codes, setCodes] = useState<string[] | null>(null);

  return (
    <Section title="Two-step verification" description="Add a second step with an authenticator app. Strongly recommended for owners and admins." id="mfa">
      <Stack direction="row" spacing={2} sx={{ alignItems: 'center', flexWrap: 'wrap', rowGap: 1.5 }}>
        <Chip icon={<ShieldOutlinedIcon />} label={enabled ? 'On' : 'Off'} color={enabled ? 'success' : 'default'} variant={enabled ? 'filled' : 'outlined'} />
        {enabled ? (
          <Button color="error" variant="outlined" onClick={() => setDisableOpen(true)}>Turn off</Button>
        ) : (
          <Button variant="contained" onClick={() => setSetupOpen(true)}>Set up authenticator</Button>
        )}
      </Stack>
      <MfaSetupDialog open={setupOpen} onClose={() => setSetupOpen(false)} onCodes={setCodes} />
      <MfaDisableDialog open={disableOpen} onClose={() => setDisableOpen(false)} />
      <RecoveryCodesDialog codes={codes} onClose={() => setCodes(null)} />
    </Section>
  );
}

function MfaSetupDialog({ open, onClose, onCodes }: { open: boolean; onClose: () => void; onCodes: (c: string[]) => void }) {
  const qc = useQueryClient();
  const [secret, setSecret] = useState<{ secret: string; otpauthUri: string } | null>(null);
  const [code, setCode] = useState('');
  const [error, setError] = useState<string | null>(null);
  const begin = useMutation({ mutationFn: meApi.mfaSetup, onSuccess: setSecret, onError: (e) => setError(e instanceof ApiProblem ? e.detail : 'Couldn’t start setup') });
  const confirm = useMutation({
    mutationFn: () => meApi.mfaConfirm(code),
    onSuccess: async (r) => { await qc.invalidateQueries({ queryKey: ME_KEY }); onCodes(r.recoveryCodes); close(); },
    onError: (e) => setError(e instanceof ApiProblem ? e.detail : 'Couldn’t confirm the code'),
  });
  const close = () => { setSecret(null); setCode(''); setError(null); onClose(); };
  // Generate the secret when the dialog opens (not on page load: an unconfirmed secret should only exist if the user asked for one).
  useEffect(() => { if (open && !secret && !begin.isPending && !begin.isError) begin.mutate(); }, [open]); // eslint-disable-line react-hooks/exhaustive-deps
  return (
    <Dialog open={open} onClose={close} aria-labelledby="mfa-setup-title">
      <DialogTitle id="mfa-setup-title">Set up two-step verification</DialogTitle>
      <DialogContent>
        {error && <Alert severity="error" sx={{ mb: 2 }}>{error}</Alert>}
        {!secret ? (
          <Typography color="text.secondary">Preparing…</Typography>
        ) : (
          <Stack spacing={2}>
            <DialogContentText>Scan this QR code with an authenticator app (1Password, Google Authenticator, Authy…), then enter the 6-digit code it shows.</DialogContentText>
            <Box sx={{ alignSelf: 'center', p: 1.5, bgcolor: '#fff', borderRadius: 2, border: 1, borderColor: 'divider' }}>
              <QRCodeSVG value={secret.otpauthUri} size={168} level="M" title="Authenticator setup QR code" />
            </Box>
            <Stack direction="row" spacing={1} sx={{ alignItems: 'center', justifyContent: 'center' }}>
              <Typography variant="mono" sx={{ letterSpacing: '0.08em', wordBreak: 'break-all' }}>{secret.secret}</Typography>
              <CopyButton value={secret.secret} label="Copy setup key" />
            </Stack>
            <TextField label="6-digit code" value={code} onChange={(e) => setCode(e.target.value.replace(/\D/g, '').slice(0, 6))} autoComplete="one-time-code" slotProps={{ htmlInput: { inputMode: 'numeric', style: { letterSpacing: '0.3em', fontVariantNumeric: 'tabular-nums' } } }} />
          </Stack>
        )}
      </DialogContent>
      <DialogActions>
        <Button onClick={close}>Cancel</Button>
        <Button variant="contained" disabled={code.length !== 6 || confirm.isPending} onClick={() => (setError(null), confirm.mutate())}>Turn on</Button>
      </DialogActions>
    </Dialog>
  );
}

function MfaDisableDialog({ open, onClose }: { open: boolean; onClose: () => void }) {
  const qc = useQueryClient();
  const toast = useToast();
  const [password, setPassword] = useState('');
  const [code, setCode] = useState('');
  const [error, setError] = useState<string | null>(null);
  const m = useMutation({
    mutationFn: () => meApi.mfaDisable(password, code.trim()),
    onSuccess: async () => { await qc.invalidateQueries({ queryKey: ME_KEY }); toast.success('Two-step verification is off'); close(); },
    onError: (e) => setError(e instanceof ApiProblem ? e.detail : 'Couldn’t turn it off'),
  });
  const close = () => { setPassword(''); setCode(''); setError(null); onClose(); };
  return (
    <Dialog open={open} onClose={close}>
      <DialogTitle>Turn off two-step verification?</DialogTitle>
      <DialogContent>
        <Stack spacing={2} sx={{ pt: 0.5 }}>
          <DialogContentText>Confirm with your password and a current code (or a recovery code).</DialogContentText>
          {error && <Alert severity="error">{error}</Alert>}
          <PasswordField label="Password" value={password} onChange={(e) => setPassword(e.target.value)} />
          <TextField label="Code or recovery code" value={code} onChange={(e) => setCode(e.target.value)} autoComplete="one-time-code" />
        </Stack>
      </DialogContent>
      <DialogActions>
        <Button onClick={close}>Cancel</Button>
        <Button color="error" variant="contained" disabled={!password || code.trim().length < 6 || m.isPending} onClick={() => (setError(null), m.mutate())}>Turn off</Button>
      </DialogActions>
    </Dialog>
  );
}

function RecoveryCodesDialog({ codes, onClose }: { codes: string[] | null; onClose: () => void }) {
  return (
    <Dialog open={Boolean(codes)} onClose={onClose}>
      <DialogTitle>Save your recovery codes</DialogTitle>
      <DialogContent>
        <DialogContentText sx={{ mb: 2 }}>Each code works once if you lose your authenticator. They won’t be shown again — store them somewhere safe.</DialogContentText>
        <Box component="ul" sx={{ listStyle: 'none', m: 0, p: 2, display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 1, bgcolor: 'surface.sunken', borderRadius: 2 }}>
          {codes?.map((c) => <Typography key={c} component="li" variant="mono">{c}</Typography>)}
        </Box>
      </DialogContent>
      <DialogActions>
        <CopyButton value={(codes ?? []).join('\n')} label="Copy all codes" />
        <Button variant="contained" onClick={onClose}>I’ve saved them</Button>
      </DialogActions>
    </Dialog>
  );
}

function SessionsSection() {
  const qc = useQueryClient();
  const toast = useToast();
  const confirm = useConfirm();
  const q = useQuery({ queryKey: ['me', 'sessions'], queryFn: meApi.sessions });
  const revoke = useMutation({ mutationFn: meApi.revokeSession, onSuccess: () => { void qc.invalidateQueries({ queryKey: ['me', 'sessions'] }); toast.success('Session signed out'); } });
  const revokeOthers = useMutation({ mutationFn: meApi.revokeOtherSessions, onSuccess: (r) => { void qc.invalidateQueries({ queryKey: ['me', 'sessions'] }); toast.success(r.revoked ? `Signed out ${r.revoked} other session${r.revoked > 1 ? 's' : ''}` : 'No other sessions'); } });
  return (
    <Section
      title="Active sessions"
      description="Devices currently signed in to your account. Sign out any you don’t recognise."
      id="sessions"
      actions={<Button size="small" onClick={async () => { if (await confirm({ title: 'Sign out everywhere else?', body: 'Every other device will need to sign in again.', confirmLabel: 'Sign out others', destructive: true })) revokeOthers.mutate(); }}>Sign out all others</Button>}
    >
      <QueryBoundary query={q}>
        {(items) => (
          <Stack divider={<Box sx={{ borderTop: 1, borderColor: 'divider' }} />}>
            {items.map((s) => (
              <Stack key={s.id} direction="row" spacing={1.5} sx={{ alignItems: 'center', py: 1.25 }}>
                <LaptopMacOutlinedIcon color="disabled" />
                <Box sx={{ flex: 1, minWidth: 0 }}>
                  <Typography variant="subtitle2">{s.deviceLabel ?? 'Unknown device'} {s.current && <Chip size="small" color="primary" label="This device" sx={{ ml: 0.75 }} />}</Typography>
                  <Typography variant="caption" color="text.secondary">
                    {s.ip ?? 'Unknown IP'} · Active <RelativeTime value={s.lastSeenAt} />
                  </Typography>
                </Box>
                {!s.current && <Button size="small" color="error" onClick={() => revoke.mutate(s.id)}>Sign out</Button>}
              </Stack>
            ))}
          </Stack>
        )}
      </QueryBoundary>
    </Section>
  );
}
