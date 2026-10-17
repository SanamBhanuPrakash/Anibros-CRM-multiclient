import { fontStacks } from '@/theme/theme';
import type { ReactNode } from 'react';
import { Alert, AlertTitle, Box, Button, Skeleton, Stack, Typography } from '@mui/material';
import type { UseQueryResult } from '@tanstack/react-query';
import ErrorOutlineRoundedIcon from '@mui/icons-material/ErrorOutlineRounded';
import { ApiProblem } from '@/api/client';

/** Composed empty state: says what this place is for and offers the next step — never a bare "No data". */
export function EmptyState({ icon, title, description, action }: { icon?: ReactNode; title: string; description?: ReactNode; action?: ReactNode }) {
  return (
    <Stack spacing={1.25} sx={{ alignItems: 'center', textAlign: 'center', py: 7, px: 2 }}>
      {icon && (
        <Box sx={{ width: 44, height: 44, borderRadius: 2, display: 'grid', placeItems: 'center', bgcolor: 'accentTone.bg', color: 'accentTone.fg' }} aria-hidden>
          {icon}
        </Box>
      )}
      <Typography variant="h4" component="p">
        {title}
      </Typography>
      {description && (
        <Typography variant="body2" color="text.secondary" sx={{ maxWidth: '46ch' }}>
          {description}
        </Typography>
      )}
      {action}
    </Stack>
  );
}

/** Error state that tells the truth: what failed, whether retrying helps, and the id support needs. */
export function ErrorState({ error, onRetry, title = 'We couldn’t load this' }: { error: unknown; onRetry?: () => void; title?: string }) {
  const problem = error instanceof ApiProblem ? error : null;
  const message = problem?.isNetwork
    ? 'We can’t reach the server. Check your connection and try again.'
    : problem?.status === 403
      ? 'You don’t have permission to view this.'
      : (problem?.detail ?? (error as Error)?.message ?? 'Something went wrong.');
  return (
    <Alert severity="error" icon={<ErrorOutlineRoundedIcon />} sx={{ alignItems: 'center' }} action={onRetry && problem?.status !== 403 ? <Button color="inherit" size="small" onClick={onRetry}>Try again</Button> : undefined}>
      <AlertTitle sx={{ mb: 0 }}>{title}</AlertTitle>
      {message}
      {problem?.requestId && (
        <Typography variant="caption" component="div" sx={{ opacity: 0.7, mt: 0.5, fontFamily: fontStacks.mono }}>
          Reference {problem.requestId}
        </Typography>
      )}
    </Alert>
  );
}

export function SkeletonRows({ rows = 5, height = 44 }: { rows?: number; height?: number }) {
  return (
    <Stack spacing={1} aria-busy="true" aria-label="Loading">
      {Array.from({ length: rows }, (_, i) => (
        <Skeleton key={i} variant="rounded" height={height} sx={{ opacity: 1 - i * 0.12 }} />
      ))}
    </Stack>
  );
}

/**
 * One component for the four states every data view has: loading → error → empty → content.
 * Pages pass the query and a render function; they never hand-roll spinners or silent failures.
 */
export function QueryBoundary<T>({
  query,
  children,
  isEmpty,
  empty,
  skeleton,
}: {
  query: UseQueryResult<T>;
  children: (data: T) => ReactNode;
  isEmpty?: (data: T) => boolean;
  empty?: ReactNode;
  skeleton?: ReactNode;
}) {
  if (query.isPending) return <>{skeleton ?? <SkeletonRows />}</>;
  if (query.isError) return <ErrorState error={query.error} onRetry={() => void query.refetch()} />;
  if (isEmpty?.(query.data)) return <>{empty}</>;
  return <>{children(query.data)}</>;
}
