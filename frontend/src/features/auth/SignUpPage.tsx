import { useState } from 'react';
import { Link as RouterLink } from 'react-router-dom';
import { Box, Button, Link, Stack, TextField, Typography } from '@mui/material';
import MarkEmailReadOutlinedIcon from '@mui/icons-material/MarkEmailReadOutlined';
import { useMutation } from '@tanstack/react-query';
import { useForm, useWatch } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import { ApiProblem } from '@/api/client';
import { authApi } from '@/api/endpoints';
import { PasswordField, PasswordStrength } from '@/ui/fields';
import { useToast } from '@/ui/toast';
import { AuthLayout, FormError, authErrorMessage, rhf } from './AuthLayout';

const schema = z.object({
  name: z.string().trim().min(2, 'Enter your full name'),
  organisationName: z.string().trim().min(2, 'Name your agency or company').max(120),
  email: z.string().trim().email('Enter a valid email address'),
  password: z.string().min(10, 'Use at least 10 characters').max(128),
});
type Values = z.infer<typeof schema>;

export function SignUpPage() {
  const [sentTo, setSentTo] = useState<string | null>(null);
  const [error, setError] = useState<string | null>(null);
  const toast = useToast();
  const { register, handleSubmit, control, setError: setFieldError, formState: { errors } } = useForm<Values>({ resolver: zodResolver(schema), defaultValues: { name: '', organisationName: '', email: '', password: '' } });
  const password = useWatch({ control, name: 'password' }) ?? '';
  const email = useWatch({ control, name: 'email' }) ?? '';
  const name = useWatch({ control, name: 'name' }) ?? '';

  const signup = useMutation({
    mutationFn: (v: Values) => authApi.signup(v),
    onSuccess: (_r, v) => setSentTo(v.email),
    onError: (e) => {
      if (e instanceof ApiProblem && e.code === 'VALIDATION_FAILED') {
        const fields = e.fieldErrors();
        for (const [k, msg] of Object.entries(fields)) if (k in schema.shape) setFieldError(k as keyof Values, { message: msg });
        if (!Object.keys(fields).length) setError(e.detail);
      } else setError(authErrorMessage(e));
    },
  });
  const resend = useMutation({ mutationFn: (e: string) => authApi.resendVerification(e), onSuccess: () => toast.success('If the address still needs verifying, a new link is on its way.') });

  if (sentTo) {
    return (
      <AuthLayout title="Check your email" subtitle={<>We sent a verification link to <strong>{sentTo}</strong>. It expires in 24 hours.</>}>
        <Stack spacing={2} sx={{ alignItems: 'flex-start' }}>
          <Box sx={{ width: 48, height: 48, borderRadius: 2, display: 'grid', placeItems: 'center', bgcolor: 'accentTone.bg', color: 'accentTone.fg' }}>
            <MarkEmailReadOutlinedIcon />
          </Box>
          <Typography color="text.secondary">Open the link on this device to finish setting up your workspace. Can’t find it? Check spam, or send it again.</Typography>
          <Stack direction="row" spacing={1}>
            <Button variant="outlined" onClick={() => resend.mutate(sentTo)} disabled={resend.isPending}>Resend link</Button>
            <Button component={RouterLink} to="/sign-in">Back to sign in</Button>
          </Stack>
        </Stack>
      </AuthLayout>
    );
  }

  return (
    <AuthLayout
      title="Create your agency account"
      subtitle="You’ll add your clients and invite your team next."
      footer={<>Already have an account? <Link component={RouterLink} to="/sign-in">Sign in</Link></>}
    >
      <Box component="form" noValidate onSubmit={handleSubmit((v) => (setError(null), signup.mutate(v)))}>
        <FormError message={error} />
        <Stack spacing={2}>
          <TextField label="Your name" autoComplete="name" autoFocus error={Boolean(errors.name)} helperText={errors.name?.message} {...rhf(register('name'))} />
          <TextField label="Agency or company" autoComplete="organization" error={Boolean(errors.organisationName)} helperText={errors.organisationName?.message} {...rhf(register('organisationName'))} />
          <TextField label="Work email" type="email" autoComplete="email" error={Boolean(errors.email)} helperText={errors.email?.message} {...rhf(register('email'))} />
          <PasswordField label="Password" autoComplete="new-password" error={Boolean(errors.password)} helperText={errors.password?.message} {...rhf(register('password'))} />
          <PasswordStrength password={password} identity={{ email, name }} />
          <Button type="submit" variant="contained" size="large" disabled={signup.isPending}>
            {signup.isPending ? 'Creating…' : 'Create workspace'}
          </Button>
          <Typography variant="caption" color="text.secondary" sx={{ textAlign: 'center' }}>
            By continuing you agree to our terms and acknowledge the privacy policy.
          </Typography>
        </Stack>
      </Box>
    </AuthLayout>
  );
}
