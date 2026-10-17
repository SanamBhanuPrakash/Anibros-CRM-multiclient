import { fontStacks } from '@/theme/theme';
import { Box, Stack, Typography } from '@mui/material';

/** Brand mark: a rounded tile holding a stylised "A". Drawn with theme tokens so it follows light and dark. */
export function LogoMark({ size = 30 }: { size?: number }) {
  return (
    <Box component="svg" viewBox="0 0 32 32" width={size} height={size} role="img" aria-label="Anibros Design" sx={{ flexShrink: 0, display: 'block' }}>
      <rect width="32" height="32" rx="8" fill="var(--a-palette-primary-main)" />
      <path d="M16 7.5 24.5 24h-4.1l-1.2-2.6h-6.4L11.6 24H7.5L16 7.5Zm0 6.6-1.9 4.2h3.8L16 14.1Z" fill="var(--a-palette-primary-contrastText)" fillRule="evenodd" />
    </Box>
  );
}

export function Logo({ compact = false }: { compact?: boolean }) {
  return (
    <Stack direction="row" spacing={1.25} sx={{ alignItems: 'center', justifyContent: compact ? 'center' : 'flex-start' }}>
      <LogoMark />
      {!compact && (
        <Typography component="span" sx={{ fontFamily: fontStacks.serif, fontSize: '1.2rem', fontWeight: 500, letterSpacing: '-0.02em', whiteSpace: 'nowrap' }}>
          Anibros Design
        </Typography>
      )}
    </Stack>
  );
}
