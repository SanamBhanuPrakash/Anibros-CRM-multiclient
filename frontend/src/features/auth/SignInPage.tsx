import { useState } from 'react';
import { Link as RouterLink, useLocation, useNavigate } from 'react-router-dom';
import { Box, Button, Link, Stack, TextField, Typography } from '@mui/material';
import { useMutation } from '@tanstack/react-query';
import { useForm } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import { ApiProblem } from '@/api/client';
import { authApi, type Me } from '@/api/endpoints';
import { PasswordField } from '@/ui/fields';
import { useToast } from '@/ui/toast';
import { useAuth } from './AuthProvider';
import { AuthLayout, FormError, authErrorMessage, rhf } from './AuthLayout';

const schema = z.object({ email: z.string().trim().email('Enter a valid email address'), password: z.string().min(1, 'Enter your password') });
type Values = z.infer<typeof schema>;

export function SignInPage() {
  const { signIn } = useAuth();
  const navigate = useNavigate();
  const location = useLocation();
  const toast = useToast();
  const from = (location.state as { from?: { pathname: string; search?: string } } | null)?.from;
  const next = new URLSearchParams(location.search).get('next');
  const [challenge, setChallenge] = useState<string | null>(null);
  const [serverError, setServerError] = useState<string | null>(null);
  const [unverified, setUnverified] = useState<string | null>(null);

  const { register, handleSubmit, getValues, formState: { errors } } = useForm<Values>({ resolver: zodResolver(schema), defaultValues: { email: '', password: '' } });

  const finish = (me: Me) => {
    signIn(me);
    navigate(next && next.startsWith('/') ? next : from ? `${from.pathname}${from.search ?? ''}` : '/', { replace: true });
  };

  const login = useMutation({
    mutationFn: (v: Values) => authApi.login(v.email, v.password),
    onSuccess: (r) => (r.status === 'mfa_required' ? setChallenge(r.challenge) : finish(r)),
    onError: (e) => {
      setServerError(authErrorMessage(e));
      setUnverified(e instanceof ApiProblem && e.code === 'EMAIL_NOT_VERIFIED' ? getValues('email') : null);
    },
  });

  const resend = useMutation({
    mutationFn: (email: string) => authApi.resendVerification(email),
    onSuccess: () => toast.success('If that address needs verifying, a new link is on its way.'),
  });

  if (challenge) return <MfaStep challenge={challenge} onDone={finish} onBack={() => (setChallenge(null), setServerError(null))} />;

  return (
    <AuthLayout
      title="Welcome back"
      subtitle="Sign in to your workspace."
      footer={
        <>
          New to Anibros?{' '}
          <Link component={RouterLink} to="/sign-up">
            Create a workspace
          </Link>
        </>
      }
    >
      <Box component="form" noValidate onSubmit={handleSubmit((v) => (setServerError(null), login.mutate(v)))}>
        <FormError message={serverError} />
        {unverified && (
          <Button size="small" onClick={() => resend.mutate(unverified)} disabled={resend.isPending} sx={{ mb: 2, mt: -1 }}>
            Resend verification email
          </Button>
        )}
        <Stack spacing={2}>
          <TextField label="Email" type="email" autoComplete="username" autoFocus error={Boolean(errors.email)} helperText={errors.email?.message} {...rhf(register('email'))} />
          <PasswordField label="Password" autoComplete="current-password" error={Boolean(errors.password)} helperText={errors.password?.message} {...rhf(register('password'))} />
          <Box sx={{ textAlign: 'right', mt: -1 }}>
            <Link component={RouterLink} to="/forgot-password" variant="body2">
              Forgot password?
            </Link>
          </Box>
          <Button type="submit" variant="contained" size="large" disabled={login.isPending}>
            {login.isPending ? 'Signing in…' : 'Sign in'}
          </Button>
        </Stack>
      </Box>
    </AuthLayout>
  );
}

function MfaStep({ challenge, onDone, onBack }: { challenge: string; onDone: (me: Me) => void; onBack: () => void }) {
  const [useRecovery, setUseRecovery] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [value, setValue] = useState('');
  const verify = useMutation({
    mutationFn: () => authApi.loginMfa(challenge, useRecovery ? { recoveryCode: value.trim() } : { code: value.trim() }),
    onSuccess: onDone,
    onError: (e) => setError(e instanceof ApiProblem && e.status === 401 ? 'That code isn’t valid. Check your authenticator app and try again.' : authErrorMessage(e)),
  });
  const valid = useRecovery ? value.trim().length >= 8 : /^\d{6}$/.test(value.trim());
  return (
    <AuthLayout title="Two-step verification" subtitle={useRecovery ? 'Enter one of your saved recovery codes.' : 'Enter the 6-digit code from your authenticator app.'}>
      <Box component="form" noValidate onSubmit={(e) => { e.preventDefault(); if (valid) { setError(null); verify.mutate(); } }}>
        <FormError message={error} />
        <Stack spacing={2}>
          <TextField
            label={useRecovery ? 'Recovery code' : 'Authentication code'}
            value={value}
            onChange={(e) => setValue(e.target.value)}
            autoFocus
            autoComplete="one-time-code"
            slotProps={{ htmlInput: useRecovery ? { 'aria-label': 'Recovery code' } : { inputMode: 'numeric', maxLength: 6, pattern: '[0-9]*', style: { letterSpacing: '0.35em', fontVariantNumeric: 'tabular-nums', fontSize: '1.125rem' } } }}
          />
          <Button type="submit" variant="contained" size="large" disabled={!valid || verify.isPending}>
            {verify.isPending ? 'Verifying…' : 'Verify and sign in'}
          </Button>
          <Stack direction="row" sx={{ justifyContent: 'space-between' }}>
            <Link component="button" type="button" variant="body2" onClick={() => (setUseRecovery((r) => !r), setValue(''), setError(null))}>
              {useRecovery ? 'Use authenticator app' : 'Use a recovery code'}
            </Link>
            <Link component="button" type="button" variant="body2" onClick={onBack}>
              Back to sign in
            </Link>
          </Stack>
          <Typography variant="caption" color="text.secondary">
            Codes refresh every 30 seconds. If your code was just used it may be rejected — wait for the next one.
          </Typography>
        </Stack>
      </Box>
    </AuthLayout>
  );
}
