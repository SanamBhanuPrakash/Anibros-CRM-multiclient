import type { ReactNode } from 'react';
import { Link as RouterLink } from 'react-router-dom';
import { Button, Paper } from '@mui/material';
import ConstructionOutlinedIcon from '@mui/icons-material/ConstructionOutlined';
import { PageHeader } from '@/ui/PageHeader';
import { EmptyState } from '@/ui/states';

/**
 * Honest placeholder for a feature that is not built yet. It never shows controls that look like they work:
 * the earlier Custom Fields and Integrations pages saved settings that nothing ever read.
 */
export function NotAvailablePage({ title, description, detail, action }: { title: string; description: string; detail: ReactNode; action?: { to: string; label: string } }) {
  return (
    <>
      <PageHeader title={title} description={description} />
      <Paper variant="outlined">
        <EmptyState
          icon={<ConstructionOutlinedIcon />}
          title="Not available yet"
          description={detail}
          action={action ? <Button component={RouterLink} to={action.to} variant="outlined">{action.label}</Button> : undefined}
        />
      </Paper>
    </>
  );
}

export function CustomFieldsPage() {
  return (
    <NotAvailablePage
      title="Custom fields"
      description="Extra details you track on leads, contacts and properties."
      detail="Custom fields are being rebuilt with proper validation. The earlier editor saved definitions that no form displayed, so it has been removed. Existing values are kept safely in the database."
    />
  );
}
