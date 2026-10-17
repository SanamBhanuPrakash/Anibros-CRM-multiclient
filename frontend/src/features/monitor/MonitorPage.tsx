import { useSearchParams } from 'react-router-dom';
import { Tab, Tabs } from '@mui/material';
import { useAuth } from '@/features/auth/AuthProvider';
import { PageHeader } from '@/ui/PageHeader';
import { ActivityTab } from './ActivityTab';
import { AuditTab } from './AuditTab';
import { OverviewTab } from './OverviewTab';

const TABS = ['overview', 'activity', 'audit'] as const;
type TabId = (typeof TABS)[number];

/** Monitor: is everything running (overview), what went wrong (activity log), who changed what (audit trail). */
export function MonitorPage() {
  const { can } = useAuth();
  const [params, setParams] = useSearchParams();
  const wanted = params.get('tab') as TabId | null;
  const allowed = TABS.filter((t) => (t === 'audit' ? can('audit:read') : can('operations:read')));
  const tab: TabId = wanted && allowed.includes(wanted) ? wanted : allowed[0] ?? 'overview';

  return (
    <>
      <PageHeader title="Monitor" description="Is everything running, what went wrong, and who changed what. Everything here is about your workspace only." />
      <Tabs value={tab} onChange={(_, v: TabId) => setParams(v === 'overview' ? {} : { tab: v }, { replace: true })} aria-label="Monitor sections" sx={{ mb: 2.5 }}>
        {allowed.includes('overview') && <Tab value="overview" label="Health" />}
        {allowed.includes('activity') && <Tab value="activity" label="Activity log" />}
        {allowed.includes('audit') && <Tab value="audit" label="Audit trail" />}
      </Tabs>
      {tab === 'overview' && <OverviewTab />}
      {tab === 'activity' && <ActivityTab />}
      {tab === 'audit' && <AuditTab />}
    </>
  );
}
