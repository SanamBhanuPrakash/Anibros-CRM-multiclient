import { useEffect, useRef, useState } from 'react';
import { Link as RouterLink, useNavigate, useSearchParams } from 'react-router-dom';
import { Alert, Box, Button, CircularProgress, Link, Stack, TextField, Typography } from '@mui/material';
import { useMutation } from '@tanstack/react-query';
import { useForm, useWatch } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import { ApiProblem } from '@/api/client';
import { authApi } from '@/api/endpoints';
import { PasswordField, PasswordStrength } from '@/ui/fields';
import { useToast } from '@/ui/toast';
import { useAuth } from './AuthProvider';
import { AuthLayout, FormError, authErrorMessage, rhf } from './AuthLayout';

/** /forgot-password — always answers the same way so the form can’t be used to discover accounts. */
export function ForgotPasswordPage() {
  const [sent, setSent] = useState(false);
  const { register, handleSubmit, formState: { errors } } = useForm<{ email: string }>({ resolver: zodResolver(z.object({ email: z.string().trim().email('Enter a valid email address') })) });
  const [error, setError] = useState<string | null>(null);
  const m = useMutation({ mutationFn: (v: { email: string }) => authApi.requestReset(v.email), onSuccess: () => setSent(true), onError: (e) => setError(authErrorMessage(e)) });

  if (sent) {
    return (
      <AuthLayout title="Check your email" subtitle="If an account exists for that address, we’ve sent a link to choose a new password. It expires in one hour.">
        <Button component={RouterLink} to="/sign-in" variant="outlined">Back to sign in</Button>
      </AuthLayout>
    );
  }
  return (
    <AuthLayout title="Reset your password" subtitle="Enter your email and we’ll send you a reset link." footer={<Link component={RouterLink} to="/sign-in">Back to sign in</Link>}>
      <Box component="form" noValidate onSubmit={handleSubmit((v) => (setError(null), m.mutate(v)))}>
        <FormError message={error} />
        <Stack spacing={2}>
          <TextField label="Email" type="email" autoComplete="email" autoFocus error={Boolean(errors.email)} helperText={errors.email?.message} {...rhf(register('email'))} />
          <Button type="submit" variant="contained" size="large" disabled={m.isPending}>{m.isPending ? 'Sending…' : 'Send reset link'}</Button>
        </Stack>
      </Box>
    </AuthLayout>
  );
}

export function ResetPasswordPage() {
  const [params] = useSearchParams();
  const token = params.get('token') ?? '';
  const navigate = useNavigate();
  const toast = useToast();
  const [error, setError] = useState<string | null>(null);
  const { register, handleSubmit, control, formState: { errors } } = useForm<{ password: string }>({ resolver: zodResolver(z.object({ password: z.string().min(10, 'Use at least 10 characters').max(128) })) });
  const password = useWatch({ control, name: 'password' }) ?? '';
  const m = useMutation({
    mutationFn: (v: { password: string }) => authApi.completeReset(token, v.password),
    onSuccess: () => { toast.success('Password updated. Sign in with your new password.'); navigate('/sign-in', { replace: true }); },
    onError: (e) => setError(e instanceof ApiProblem && e.code === 'BAD_REQUEST' ? 'This reset link is invalid or has expired. Request a new one.' : authErrorMessage(e)),
  });

  if (!token) {
    return (
      <AuthLayout title="Link not valid" subtitle="This password reset link is missing its token.">
        <Button component={RouterLink} to="/forgot-password" variant="contained">Request a new link</Button>
      </AuthLayout>
    );
  }
  return (
    <AuthLayout title="Choose a new password" subtitle="You’ll be signed out everywhere else once it’s changed.">
      <Box component="form" noValidate onSubmit={handleSubmit((v) => (setError(null), m.mutate(v)))}>
        <FormError message={error} />
        {error?.includes('expired') && <Button component={RouterLink} to="/forgot-password" size="small" sx={{ mb: 2, mt: -1 }}>Request a new link</Button>}
        <Stack spacing={2}>
          <PasswordField label="New password" autoComplete="new-password" autoFocus error={Boolean(errors.password)} helperText={errors.password?.message} {...rhf(register('password'))} />
          <PasswordStrength password={password} />
          <Button type="submit" variant="contained" size="large" disabled={m.isPending}>{m.isPending ? 'Saving…' : 'Update password'}</Button>
        </Stack>
      </Box>
    </AuthLayout>
  );
}

/** /verify-email?token=… — verifies once on mount (guarded against React StrictMode's double effect) and signs the user in. */
export function VerifyEmailPage() {
  const [params] = useSearchParams();
  const token = params.get('token') ?? '';
  const { signIn } = useAuth();
  const navigate = useNavigate();
  const started = useRef(false);
  const [state, setState] = useState<'working' | 'failed'>(token ? 'working' : 'failed');
  const [email, setEmail] = useState('');
  const toast = useToast();
  const resend = useMutation({ mutationFn: () => authApi.resendVerification(email.trim()), onSuccess: () => toast.success('If that address needs verifying, a new link is on its way.') });

  useEffect(() => {
    if (!token || started.current) return;
    started.current = true;
    authApi.verifyEmail(token).then(
      (me) => { signIn(me); toast.success('Email verified. Welcome aboard.'); navigate('/', { replace: true }); },
      () => setState('failed')
    );
  }, [token, signIn, navigate, toast]);

  if (state === 'working') {
    return (
      <AuthLayout title="Verifying your email…">
        <Stack direction="row" spacing={1.5} sx={{ alignItems: 'center', color: 'text.secondary' }} role="status">
          <CircularProgress size={20} /> <Typography>One moment.</Typography>
        </Stack>
      </AuthLayout>
    );
  }
  return (
    <AuthLayout title="This link has expired" subtitle="Verification links work once and last 24 hours. Enter your email and we’ll send a fresh one.">
      <Stack spacing={2}>
        <Alert severity="info">If you’d already verified, just sign in.</Alert>
        <TextField label="Email" type="email" value={email} onChange={(e) => setEmail(e.target.value)} autoComplete="email" />
        <Button variant="contained" onClick={() => resend.mutate()} disabled={!/.+@.+\..+/.test(email) || resend.isPending}>Send a new link</Button>
        <Button component={RouterLink} to="/sign-in">Go to sign in</Button>
      </Stack>
    </AuthLayout>
  );
}
