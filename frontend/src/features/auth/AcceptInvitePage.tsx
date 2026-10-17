import { useState } from 'react';
import { Link as RouterLink, useNavigate, useSearchParams } from 'react-router-dom';
import { Alert, Box, Button, Skeleton, Stack, TextField, Typography } from '@mui/material';
import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { useForm, useWatch } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import { authApi, ROLE_LABEL } from '@/api/endpoints';
import { PasswordField, PasswordStrength } from '@/ui/fields';
import { useToast } from '@/ui/toast';
import { ME_KEY, useAuth } from './AuthProvider';
import { AuthLayout, FormError, authErrorMessage, rhf } from './AuthLayout';

/** /accept-invite?token=… — new people set a name and password; existing users sign in as the invited address first. */
export function AcceptInvitePage() {
  const [params] = useSearchParams();
  const token = params.get('token') ?? '';
  const { me, status, refresh, signOut } = useAuth();
  const qc = useQueryClient();
  const navigate = useNavigate();
  const toast = useToast();
  const [error, setError] = useState<string | null>(null);

  const preview = useQuery({ queryKey: ['invitation', token], queryFn: () => authApi.previewInvitation(token), enabled: Boolean(token), retry: false });
  const { register, handleSubmit, control, formState: { errors } } = useForm<{ name: string; password: string }>({
    resolver: zodResolver(z.object({ name: z.string().trim().min(2, 'Enter your full name'), password: z.string().min(10, 'Use at least 10 characters').max(128) })),
  });
  const password = useWatch({ control, name: 'password' }) ?? '';
  const name = useWatch({ control, name: 'name' }) ?? '';

  const accept = useMutation({
    mutationFn: (v?: { name: string; password: string }) => authApi.acceptInvitation({ token, ...v }),
    onSuccess: async (r) => {
      qc.removeQueries({ queryKey: ME_KEY });
      await refresh();
      toast.success(r.status === 'joined' ? `You’ve joined ${preview.data?.tenantName ?? 'the workspace'}.` : 'Welcome aboard.');
      navigate('/', { replace: true });
    },
    onError: (e) => setError(authErrorMessage(e)),
  });

  if (!token || preview.isError) {
    return (
      <AuthLayout title="Invitation not valid" subtitle="This invitation has expired, was already used, or was withdrawn. Ask the person who invited you to send a new one.">
        <Button component={RouterLink} to="/sign-in" variant="outlined">Go to sign in</Button>
      </AuthLayout>
    );
  }
  if (preview.isPending) {
    return (
      <AuthLayout title="Loading invitation…">
        <Skeleton variant="rounded" height={120} />
      </AuthLayout>
    );
  }

  const inv = preview.data;
  const heading = `Join ${inv.tenantName}`;
  const sub = <>You’ve been invited as <strong>{ROLE_LABEL[inv.role]}</strong> using <strong>{inv.email}</strong>.</>;

  if (inv.accountExists) {
    const rightUser = status === 'authenticated' && me?.user.email.toLowerCase() === inv.email.toLowerCase();
    return (
      <AuthLayout title={heading} subtitle={sub}>
        <Stack spacing={2}>
          <FormError message={error} />
          {rightUser ? (
            <Button variant="contained" size="large" onClick={() => (setError(null), accept.mutate(undefined))} disabled={accept.isPending}>
              {accept.isPending ? 'Joining…' : 'Accept invitation'}
            </Button>
          ) : (
            <>
              <Alert severity="info">An account already exists for this email{status === 'authenticated' ? ', but you’re signed in as someone else' : ''}. Sign in as <strong>{inv.email}</strong> to accept.</Alert>
              <Button component={RouterLink} to={`/sign-in?next=${encodeURIComponent(`/accept-invite?token=${token}`)}`} variant="contained" size="large">Sign in to accept</Button>
            </>
          )}
        </Stack>
      </AuthLayout>
    );
  }

  // A new person is opening their invitation in a browser where someone else is signed in (e.g. the owner testing the link).
  // Creating the account here would silently swap sessions, so ask first.
  if (status === 'authenticated' && me) {
    return (
      <AuthLayout title={heading} subtitle={sub}>
        <Stack spacing={2}>
          <Alert severity="info">You’re signed in as <strong>{me.user.email}</strong>. This invitation is for <strong>{inv.email}</strong>, so sign out first to create that account.</Alert>
          <Button variant="contained" size="large" onClick={() => void signOut()}>Sign out and continue</Button>
          <Button component={RouterLink} to="/" variant="text">Stay signed in as {me.user.email}</Button>
        </Stack>
      </AuthLayout>
    );
  }

  return (
    <AuthLayout title={heading} subtitle={sub}>
      <Box component="form" noValidate onSubmit={handleSubmit((v) => (setError(null), accept.mutate(v)))}>
        <FormError message={error} />
        <Stack spacing={2}>
          <TextField label="Your name" autoComplete="name" autoFocus error={Boolean(errors.name)} helperText={errors.name?.message} {...rhf(register('name'))} />
          <TextField label="Email" value={inv.email} disabled />
          <PasswordField label="Create a password" autoComplete="new-password" error={Boolean(errors.password)} helperText={errors.password?.message} {...rhf(register('password'))} />
          <PasswordStrength password={password} identity={{ email: inv.email, name }} />
          <Button type="submit" variant="contained" size="large" disabled={accept.isPending}>{accept.isPending ? 'Creating account…' : 'Accept and continue'}</Button>
          <Typography variant="caption" color="text.secondary" sx={{ textAlign: 'center' }}>Your email is verified by this invitation — no extra step.</Typography>
        </Stack>
      </Box>
    </AuthLayout>
  );
}
