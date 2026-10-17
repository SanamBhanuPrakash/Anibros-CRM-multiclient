import { fontStacks } from '@/theme/theme';
import type { ReactNode } from 'react';
import { Alert, Box, Stack, Typography } from '@mui/material';
import { LogoMark } from '@/app/Logo';
import { ApiProblem } from '@/api/client';
import type { UseFormRegisterReturn } from 'react-hook-form';

/**
 * Two-column auth shell. Left: brand statement over a quiet architectural line pattern (pure SVG, no image requests).
 * Right: the form, vertically centred, comfortable measure. The left panel is decorative and hidden from assistive tech.
 */
const POINTS = [
  ['A separate workspace for every client', 'Leads, team and settings never mix between clients.'],
  ['One team, assigned where needed', 'Your people work across clients; each only sees what they are given.'],
  ['Every call and message on record', 'Log it in seconds, then download it all as Excel or CSV.'],
];

export function AuthLayout({ title, subtitle, children, footer }: { title: string; subtitle?: ReactNode; children: ReactNode; footer?: ReactNode }) {
  return (
    <Box sx={{ minHeight: '100dvh', display: 'grid', gridTemplateColumns: { xs: '1fr', md: 'minmax(360px, 5fr) 6fr' }, bgcolor: 'background.default' }}>
      <Box
        aria-hidden
        sx={{
          display: { xs: 'none', md: 'flex' },
          position: 'relative',
          flexDirection: 'column',
          justifyContent: 'space-between',
          p: 6,
          color: '#fff',
          background: 'linear-gradient(160deg, #4F46E5 0%, #3730A3 100%)',
          overflow: 'hidden',
        }}
      >
        <Stack direction="row" spacing={1.25} sx={{ alignItems: 'center', position: 'relative' }}>
          <Box sx={{ width: 32, height: 32, borderRadius: '8px', bgcolor: '#fff', color: '#4F46E5', display: 'grid', placeItems: 'center', fontWeight: 700, fontFamily: fontStacks.sans }}>A</Box>
          <Typography sx={{ fontSize: '1.125rem', fontWeight: 600, letterSpacing: '-0.01em' }}>Anibros Design</Typography>
        </Stack>
        <Box sx={{ position: 'relative', maxWidth: 440 }}>
          <Typography component="p" sx={{ fontSize: '2rem', fontWeight: 600, lineHeight: 1.15, letterSpacing: '-0.02em', textWrap: 'balance' }}>
            Every client’s leads, one calm place.
          </Typography>
          <Stack spacing={2} sx={{ mt: 3.5 }}>
            {POINTS.map(([h, p]) => (
              <Box key={h}>
                <Typography sx={{ fontWeight: 600, fontSize: '0.9375rem' }}>{h}</Typography>
                <Typography sx={{ opacity: 0.78, fontSize: '0.875rem' }}>{p}</Typography>
              </Box>
            ))}
          </Stack>
        </Box>
      </Box>

      <Box sx={{ display: 'flex', flexDirection: 'column', justifyContent: 'center', alignItems: 'center', px: { xs: 2.5, sm: 6 }, py: 6 }}>
        <Box component="main" sx={{ width: '100%', maxWidth: 400 }}>
          <Box sx={{ display: { xs: 'block', md: 'none' }, mb: 4 }}>
            <LogoMark size={34} />
          </Box>
          <Typography variant="h2" component="h1" sx={{ mb: 0.75 }}>
            {title}
          </Typography>
          {subtitle && (
            <Typography color="text.secondary" sx={{ mb: 3.5 }}>
              {subtitle}
            </Typography>
          )}
          {children}
          {footer && (
            <Typography variant="body2" color="text.secondary" sx={{ mt: 3, textAlign: 'center' }}>
              {footer}
            </Typography>
          )}
        </Box>
      </Box>
    </Box>
  );
}

/** Adapt react-hook-form's register() result to MUI TextField (which needs `inputRef`, not `ref`). */
export const rhf = ({ ref, ...rest }: UseFormRegisterReturn) => ({ ...rest, inputRef: ref });

/** Map API problems to calm, specific copy. Never blame the user, never expose internals. */
export function authErrorMessage(e: unknown): string {
  if (!(e instanceof ApiProblem)) return 'Something went wrong. Please try again.';
  if (e.isNetwork) return 'We can’t reach the server. Check your connection and try again.';
  switch (e.code) {
    case 'UNAUTHENTICATED':
      return e.detail || 'Invalid email or password.';
    case 'RATE_LIMITED':
      return e.retryAfter ? `Too many attempts. Try again in ${e.retryAfter > 90 ? `${Math.ceil(e.retryAfter / 60)} minutes` : `${e.retryAfter} seconds`}.` : 'Too many attempts. Please wait a moment and try again.';
    case 'EMAIL_NOT_VERIFIED':
      return 'Please verify your email address first. We can send the link again.';
    case 'FORBIDDEN':
      return e.detail;
    default:
      return e.detail || 'Something went wrong. Please try again.';
  }
}

export function FormError({ message }: { message?: string | null }) {
  return message ? (
    <Alert severity="error" role="alert" sx={{ mb: 2 }}>
      {message}
    </Alert>
  ) : null;
}
