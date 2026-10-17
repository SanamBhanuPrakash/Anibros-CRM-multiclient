import type { ReactNode } from 'react';
import { Box, Breadcrumbs, Link as MuiLink, Stack, Typography } from '@mui/material';
import { Link as RouterLink } from 'react-router-dom';

export interface Crumb {
  label: string;
  to?: string;
}

/**
 * Page title block: a quiet breadcrumb (so you always know where you are and can go back up), the title, one line of explanation,
 * and the page's actions on the right. Pages never invent their own heading styles: hierarchy comes from here.
 */
export function PageHeader({ title, description, actions, crumbs, meta }: { title: string; description?: ReactNode; actions?: ReactNode; crumbs?: Crumb[]; meta?: ReactNode }) {
  return (
    <Box component="header" sx={{ mb: { xs: 2, md: 2.5 } }}>
      {crumbs && crumbs.length > 0 && (
        <Breadcrumbs aria-label="Breadcrumb" separator="›" sx={{ mb: 0.5, '& .MuiBreadcrumbs-separator': { mx: 0.75, color: 'text.disabled' } }}>
          {crumbs.map((c, i) =>
            c.to && i < crumbs.length - 1 ? (
              <MuiLink key={c.label} component={RouterLink} to={c.to} color="text.secondary" underline="hover" variant="body2">
                {c.label}
              </MuiLink>
            ) : (
              <Typography key={c.label} variant="body2" color="text.secondary" noWrap sx={{ maxWidth: 280 }}>
                {c.label}
              </Typography>
            )
          )}
        </Breadcrumbs>
      )}
      <Stack direction={{ xs: 'column', sm: 'row' }} spacing={1.5} sx={{ alignItems: { sm: 'center' }, justifyContent: 'space-between' }}>
        <Box sx={{ minWidth: 0 }}>
          <Stack direction="row" spacing={1.25} sx={{ alignItems: 'center', flexWrap: 'wrap', rowGap: 0.5 }}>
            <Typography variant="h1" component="h1" noWrap sx={{ maxWidth: '100%' }}>
              {title}
            </Typography>
            {meta}
          </Stack>
          {description && (
            <Typography variant="body2" component="div" color="text.secondary" sx={{ mt: 0.25, maxWidth: '75ch' }}>
              {description}
            </Typography>
          )}
        </Box>
        {actions && <Stack direction="row" spacing={1} sx={{ flexShrink: 0, flexWrap: 'wrap', rowGap: 1, alignItems: 'center' }}>{actions}</Stack>}
      </Stack>
    </Box>
  );
}

/** A flat, divided region of a page (preferred over nesting cards inside cards). */
export function Section({ title, description, actions, children, id }: { title: string; description?: ReactNode; actions?: ReactNode; children: ReactNode; id?: string }) {
  return (
    <Box component="section" id={id} aria-labelledby={id ? `${id}-title` : undefined} sx={{ py: 2.5, '&:not(:first-of-type)': { borderTop: 1, borderColor: 'divider' } }}>
      <Stack direction={{ xs: 'column', md: 'row' }} spacing={{ xs: 1.5, md: 5 }}>
        <Box sx={{ width: { md: 260 }, flexShrink: 0 }}>
          <Typography id={id ? `${id}-title` : undefined} variant="h4" component="h2">
            {title}
          </Typography>
          {description && (
            <Typography variant="body2" color="text.secondary" sx={{ mt: 0.5 }}>
              {description}
            </Typography>
          )}
        </Box>
        <Box sx={{ flex: 1, minWidth: 0 }}>
          {actions && <Box sx={{ mb: 1.5, display: 'flex', justifyContent: 'flex-end', gap: 1 }}>{actions}</Box>}
          {children}
        </Box>
      </Stack>
    </Box>
  );
}
