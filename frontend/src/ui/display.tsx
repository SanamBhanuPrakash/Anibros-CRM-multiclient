import { useState } from 'react';
import { Avatar, Chip, IconButton, Tooltip, type ChipProps } from '@mui/material';
import ContentCopyRoundedIcon from '@mui/icons-material/ContentCopyRounded';
import CheckRoundedIcon from '@mui/icons-material/CheckRounded';
import { format, formatDistanceToNowStrict } from 'date-fns';
import { ROLE_LABEL, type Role } from '@/api/endpoints';

/** Deterministic, muted tint per person so lists are scannable without a rainbow of accents. */
// Each tint keeps white initials at 4.5:1 or better (WCAG AA): checked, not eyeballed.
const TINTS = ['#4F7A57', '#74683F', '#4E6A8C', '#835F4E', '#566B69', '#76608A', '#7D6A36'];
const tintFor = (seed: string) => TINTS[[...seed].reduce((a, c) => a + c.charCodeAt(0), 0) % TINTS.length];
const initials = (name: string) => name.split(/\s+/).filter(Boolean).slice(0, 2).map((p) => p[0]!.toUpperCase()).join('') || '?';

export function UserAvatar({ name, seed, size = 32 }: { name: string; seed?: string; size?: number }) {
  return (
    <Avatar role="img" aria-label={name} sx={{ width: size, height: size, bgcolor: tintFor(seed ?? name), color: '#fff', fontSize: size * 0.4 }}>
      {initials(name)}
    </Avatar>
  );
}

const ROLE_COLOR: Record<Role, ChipProps['color']> = {
  tenant_owner: 'primary',
  tenant_admin: 'primary',
  sales_manager: 'default',
  sales_agent: 'default',
  marketing_manager: 'default',
  marketing_analyst: 'default',
  executive_readonly: 'default',
  auditor: 'warning',
};
export function RoleChip({ role }: { role: Role }) {
  const emphasised = role === 'tenant_owner' || role === 'tenant_admin';
  return <Chip size="small" label={ROLE_LABEL[role]} color={ROLE_COLOR[role]} variant={emphasised ? 'filled' : 'outlined'} />;
}

/** "3 minutes ago" with the exact timestamp on hover; renders a dash for null. */
export function RelativeTime({ value, fallback = '—' }: { value: string | Date | null | undefined; fallback?: string }) {
  if (!value) return <>{fallback}</>;
  const d = typeof value === 'string' ? new Date(value) : value;
  const diff = Date.now() - d.getTime();
  const text = Math.abs(diff) < 45_000 ? 'just now' : `${formatDistanceToNowStrict(d)} ${diff < 0 ? 'from now' : 'ago'}`;
  return (
    <Tooltip title={format(d, 'PPpp')}>
      <time dateTime={d.toISOString()}>{text}</time>
    </Tooltip>
  );
}

export function CopyButton({ value, label = 'Copy' }: { value: string; label?: string }) {
  const [done, setDone] = useState(false);
  return (
    <Tooltip title={done ? 'Copied' : label}>
      <IconButton
        size="small"
        aria-label={label}
        onClick={async () => {
          try {
            await navigator.clipboard.writeText(value);
            setDone(true);
            setTimeout(() => setDone(false), 1800);
          } catch {
            /* clipboard unavailable (insecure context): user can still select the text */
          }
        }}
      >
        {done ? <CheckRoundedIcon fontSize="small" color="success" /> : <ContentCopyRoundedIcon fontSize="small" />}
      </IconButton>
    </Tooltip>
  );
}
