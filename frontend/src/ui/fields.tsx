import { forwardRef, useCallback, useState, type ReactNode } from 'react';
import { Box, IconButton, InputAdornment, LinearProgress, TextField, Typography, type TextFieldProps } from '@mui/material';
import VisibilityOutlinedIcon from '@mui/icons-material/VisibilityOutlined';
import VisibilityOffOutlinedIcon from '@mui/icons-material/VisibilityOffOutlined';

/** Password input with a visibility toggle (the toggle is a real button with an accessible name). */
export const PasswordField = forwardRef<HTMLInputElement, TextFieldProps>(function PasswordField(props, ref) {
  const [show, setShow] = useState(false);
  // react-hook-form passes its ref via `inputRef`; a forwarded ref may exist too. Both must receive the element.
  const external = props.inputRef;
  const mergedRef = useCallback(
    (el: HTMLInputElement | null) => {
      for (const r of [ref, external]) {
        if (typeof r === 'function') r(el);
        else if (r) (r as React.MutableRefObject<HTMLInputElement | null>).current = el;
      }
    },
    [ref, external]
  );
  return (
    <TextField
      {...props}
      inputRef={mergedRef}
      type={show ? 'text' : 'password'}
      autoComplete={props.autoComplete ?? 'current-password'}
      slotProps={{
        ...props.slotProps,
        input: {
          ...(props.slotProps?.input as object),
          endAdornment: (
            <InputAdornment position="end">
              <IconButton size="small" edge="end" aria-label={show ? 'Hide password' : 'Show password'} aria-pressed={show} onClick={() => setShow((s) => !s)} onMouseDown={(e) => e.preventDefault()}>
                {show ? <VisibilityOffOutlinedIcon fontSize="small" /> : <VisibilityOutlinedIcon fontSize="small" />}
              </IconButton>
            </InputAdornment>
          ),
        },
      }}
    />
  );
});

/**
 * Client-side hints only (the server's policy is authoritative): length first, no composition theatre.
 * Mirrors server/src/modules/identity/password-policy.ts closely enough to guide, never to gate.
 */
export function passwordHints(password: string, identity: { email?: string; name?: string } = {}) {
  const lower = password.toLowerCase();
  const local = identity.email?.split('@')[0]?.toLowerCase();
  const nameHit = (identity.name ?? '').toLowerCase().split(/\s+/).some((p) => p.length >= 4 && lower.includes(p));
  const classes = [/[a-z]/, /[A-Z]/, /[0-9]/, /[^A-Za-z0-9]/].filter((r) => r.test(password)).length;
  const rules = [
    { ok: password.length >= 10, label: 'At least 10 characters' },
    { ok: password.length >= 16 || classes >= 2, label: 'Letters plus numbers or symbols — or a longer passphrase' },
    { ok: !(local && local.length >= 4 && lower.includes(local)) && !nameHit && password.length > 0, label: 'Doesn’t contain your name or email' },
  ];
  const score = password.length === 0 ? 0 : Math.min(4, rules.filter((r) => r.ok).length + (password.length >= 14 ? 1 : 0));
  return { rules, score };
}

export function PasswordStrength({ password, identity }: { password: string; identity?: { email?: string; name?: string } }) {
  const { rules, score } = passwordHints(password, identity);
  const color = score <= 1 ? 'error' : score === 2 ? 'warning' : 'success';
  const label = ['Enter a password', 'Too weak', 'Getting there', 'Good', 'Strong'][score];
  return (
    <Box aria-live="polite" sx={{ mt: -0.5 }}>
      <LinearProgress variant="determinate" value={score * 25} color={color} aria-label="Password strength" sx={{ mb: 0.75 }} />
      <Typography variant="caption" color="text.secondary" component="div" sx={{ mb: 0.25 }}>
        {label}
      </Typography>
      <Box component="ul" sx={{ m: 0, p: 0, listStyle: 'none' }}>
        {rules.map((r) => (
          <Typography key={r.label} component="li" variant="caption" sx={{ color: r.ok ? 'success.main' : 'text.secondary', display: 'flex', gap: 0.75 }}>
            <span aria-hidden>{r.ok ? '✓' : '·'}</span>
            {r.label}
          </Typography>
        ))}
      </Box>
    </Box>
  );
}

/** Wraps children with a labelled, described region for assistive tech (used by grouped form controls). */
export function FieldGroup({ legend, hint, children }: { legend: string; hint?: ReactNode; children: ReactNode }) {
  return (
    <Box component="fieldset" sx={{ border: 0, p: 0, m: 0, minWidth: 0 }}>
      <Typography component="legend" variant="subtitle2" sx={{ mb: hint ? 0 : 1 }}>
        {legend}
      </Typography>
      {hint && (
        <Typography variant="caption" color="text.secondary" component="p" sx={{ mb: 1 }}>
          {hint}
        </Typography>
      )}
      {children}
    </Box>
  );
}
