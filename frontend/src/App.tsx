import { lazy, Suspense, useState } from 'react';
import { BrowserRouter, Navigate, Outlet, Route, Routes, useLocation } from 'react-router-dom';
import { QueryClientProvider } from '@tanstack/react-query';
import { Box, Button, CssBaseline, Stack, ThemeProvider, Typography } from '@mui/material';
import { theme } from '@/theme/theme';
import { createQueryClient } from '@/app/queryClient';
import { AppShell } from '@/app/AppShell';
import { ScopedQueryProvider } from '@/app/ScopedQuery';
import { AgencyLayout, ClientLayout, RealEstateLayout, RootRedirect, SettingsLayout } from '@/app/layouts';
import { AuthProvider, useAuth } from '@/features/auth/AuthProvider';
import { RealtimeProvider } from '@/features/realtime/RealtimeProvider';
import { SignInPage } from '@/features/auth/SignInPage';
import { SignUpPage } from '@/features/auth/SignUpPage';
import { ForgotPasswordPage, ResetPasswordPage, VerifyEmailPage } from '@/features/auth/RecoveryPages';
import { AcceptInvitePage } from '@/features/auth/AcceptInvitePage';
import { ToastProvider } from '@/ui/toast';
import { ConfirmProvider } from '@/ui/confirm';
import { ErrorBoundary } from '@/ui/ErrorBoundary';
import { ErrorState, SkeletonRows } from '@/ui/states';

const page = <T extends Record<string, React.ComponentType>>(load: () => Promise<T>, name: keyof T) => lazy(() => load().then((m) => ({ default: m[name] as React.ComponentType })));

// Agency (all clients)
const AgencyHomePage = page(() => import('@/features/agency/AgencyHomePage'), 'AgencyHomePage');
const AllLeadsPage = page(() => import('@/features/agency/AllLeadsPage'), 'AllLeadsPage');
const ActivityPage = page(() => import('@/features/agency/ActivityPage'), 'ActivityPage');
const AgencyTeamPage = page(() => import('@/features/agency/AgencyTeamPage'), 'AgencyTeamPage');

// One client
const ProfileSecurityPage = page(() => import('@/features/settings/ProfileSecurityPage'), 'ProfileSecurityPage');
const ClientTeamPage = page(() => import('@/features/agency/ClientTeamPage'), 'ClientTeamPage');
const ClientSettingsPage = page(() => import('@/features/agency/ClientSettingsPage'), 'ClientSettingsPage');
const CustomFieldsPage = page(() => import('@/features/settings/CustomFieldsPage'), 'CustomFieldsPage');
const TeamPage = page(() => import('@/features/settings/TeamPage'), 'TeamPage');
const LeadsPage = page(() => import('@/features/leads/LeadsPage'), 'LeadsPage');
const LeadDetailPage = page(() => import('@/features/leads/LeadDetailPage'), 'LeadDetailPage');
const PipelineSettingsPage = page(() => import('@/features/settings/PipelineSettingsPage'), 'PipelineSettingsPage');
const TasksPage = page(() => import('@/features/tasks/TasksPage'), 'TasksPage');
const DealsPage = page(() => import('@/features/deals/DealsPage'), 'DealsPage');
const ContactsPage = page(() => import('@/features/contacts/ContactsPage'), 'ContactsPage');
const PropertiesPage = page(() => import('@/features/inventory/PropertiesPage'), 'PropertiesPage');
const PropertyDetailPage = page(() => import('@/features/inventory/PropertyDetailPage'), 'PropertyDetailPage');
const ProjectsPage = page(() => import('@/features/inventory/ProjectsPage'), 'ProjectsPage');
const LeadSourcesPage = page(() => import('@/features/integrations/LeadSourcesPage'), 'LeadSourcesPage');
const WebhooksPage = page(() => import('@/features/integrations/WebhooksPage'), 'WebhooksPage');
const MonitorPage = page(() => import('@/features/monitor/MonitorPage'), 'MonitorPage');
const ApiKeysPage = page(() => import('@/features/settings/ApiKeysPage'), 'ApiKeysPage');
const ClientOverviewPage = page(() => import('@/features/agency/ClientOverviewPage'), 'ClientOverviewPage');
const InsightsPage = page(() => import('@/features/insights/InsightsPage'), 'InsightsPage');
const AgencyReportsPage = page(() => import('@/features/insights/AgencyReportsPage'), 'AgencyReportsPage');
const MatchingPage = page(() => import('@/features/matching/MatchingPage'), 'MatchingPage');
const AutomationPage = page(() => import('@/features/settings/AutomationPage'), 'AutomationPage');
const WorkspaceSettingsPage = page(() => import('@/features/settings/WorkspaceSettingsPage'), 'WorkspaceSettingsPage');

function FullPageLoading() {
  return (
    <Box sx={{ minHeight: '100dvh', display: 'grid', placeItems: 'center' }} role="status" aria-label="Loading">
      <Stack spacing={2} sx={{ width: 240 }}>
        <SkeletonRows rows={3} height={14} />
      </Stack>
    </Box>
  );
}

/** Gate for the signed-in area: loading → anonymous → error → render. Redirects remember where the user was going. */
function RequireAuth() {
  const { status, refresh, error, switching } = useAuth();
  const location = useLocation();
  const [retrying, setRetrying] = useState(false);
  if (status === 'loading') return <FullPageLoading />;
  if (status === 'anonymous') return <Navigate to="/sign-in" replace state={{ from: location }} />;
  if (status === 'error') {
    return (
      <Box sx={{ minHeight: '100dvh', display: 'grid', placeItems: 'center', p: 3 }}>
        <Stack spacing={2} sx={{ maxWidth: 480 }}>
          <Typography variant="h3" component="h1">We can’t load your workspace</Typography>
          <ErrorState error={error} title="Connection problem" />
          <Button variant="contained" disabled={retrying} onClick={async () => { setRetrying(true); await refresh(); setRetrying(false); }}>Try again</Button>
        </Stack>
      </Box>
    );
  }
  return (
    <ScopedQueryProvider>
      <RealtimeProvider>
        <AppShell>
          <ErrorBoundary resetKey={location.pathname}>
            <Suspense fallback={<SkeletonRows rows={6} />}>{switching ? <SkeletonRows rows={6} /> : <Outlet />}</Suspense>
          </ErrorBoundary>
        </AppShell>
      </RealtimeProvider>
    </ScopedQueryProvider>
  );
}

/** Signed-in users who open /sign-in etc. go straight to the app. */
function PublicOnly() {
  const { status } = useAuth();
  if (status === 'loading') return <FullPageLoading />;
  if (status === 'authenticated') return <Navigate to="/" replace />;
  return <Outlet />;
}

function NotFound() {
  return (
    <Stack spacing={1.5} sx={{ py: 10, alignItems: 'center', textAlign: 'center' }}>
      <Typography variant="display">404</Typography>
      <Typography variant="h3" component="h1">That page doesn’t exist</Typography>
      <Typography color="text.secondary">It may have moved, or the link is wrong.</Typography>
      <Button variant="contained" href="/">Go to Home</Button>
    </Stack>
  );
}

/** The team of the open client (agency screens), or the plain workspace team for the agency's own workspace. */
function TeamRoute() {
  const { tenant } = useAuth();
  return tenant?.kind === 'agency_hq' ? <TeamPage /> : <ClientTeamPage />;
}

export default function App() {
  const [queryClient] = useState(createQueryClient);
  const basename = (window as Window & { __ANIBROS__?: { base?: string } }).__ANIBROS__?.base || undefined;
  return (
    <ThemeProvider theme={theme} defaultMode="system" noSsr>
      <CssBaseline enableColorScheme />
      <QueryClientProvider client={queryClient}>
        <ToastProvider>
          <ConfirmProvider>
            <BrowserRouter basename={basename}>
              <AuthProvider>
                <Routes>
                  <Route element={<PublicOnly />}>
                    <Route path="/sign-in" element={<SignInPage />} />
                    <Route path="/sign-up" element={<SignUpPage />} />
                    <Route path="/forgot-password" element={<ForgotPasswordPage />} />
                  </Route>
                  {/* Reachable regardless of auth state (links from email) */}
                  <Route path="/reset-password" element={<ResetPasswordPage />} />
                  <Route path="/verify-email" element={<VerifyEmailPage />} />
                  <Route path="/accept-invite" element={<AcceptInvitePage />} />

                  <Route element={<RequireAuth />}>
                    <Route index element={<RootRedirect />} />

                    {/* All clients */}
                    <Route element={<AgencyLayout />}>
                      <Route path="/agency" element={<AgencyHomePage />} />
                      <Route path="/agency/leads" element={<AllLeadsPage />} />
                      <Route path="/agency/activity" element={<ActivityPage />} />
                      <Route path="/agency/reports" element={<AgencyReportsPage />} />
                      <Route path="/agency/team" element={<AgencyTeamPage />} />
                    </Route>

                    <Route path="/settings/profile" element={<ProfileSecurityPage />} />

                    {/* One client */}
                    <Route element={<ClientLayout />}>
                      <Route path="/overview" element={<ClientOverviewPage />} />
                      <Route path="/leads" element={<LeadsPage />} />
                      <Route path="/leads/:id" element={<LeadDetailPage />} />
                      <Route path="/pipeline" element={<Navigate to="/leads?view=board" replace />} />
                      <Route path="/tasks" element={<TasksPage />} />
                      <Route path="/calendar" element={<Navigate to="/tasks?view=calendar" replace />} />
                      <Route path="/contacts" element={<ContactsPage />} />

                      <Route path="/reports" element={<InsightsPage />} />
                      <Route path="/dashboard" element={<Navigate to="/reports" replace />} />
                      <Route path="/attribution" element={<Navigate to="/reports?tab=sources" replace />} />

                      <Route element={<RealEstateLayout />}>
                        <Route path="/properties" element={<PropertiesPage />} />
                        <Route path="/properties/:id" element={<PropertyDetailPage />} />
                        <Route path="/projects" element={<ProjectsPage />} />
                        <Route path="/property-matching" element={<MatchingPage />} />
                        <Route path="/deals" element={<DealsPage />} />
                      </Route>

                      <Route element={<SettingsLayout />}>
                        <Route path="/settings" element={<Navigate to="/settings/client" replace />} />
                        <Route path="/settings/client" element={<ClientSettingsPage />} />
                        <Route path="/settings/team" element={<TeamRoute />} />
                        <Route path="/settings/custom-fields" element={<CustomFieldsPage />} />
                        <Route path="/settings/pipelines" element={<PipelineSettingsPage />} />
                        <Route path="/settings/workspace" element={<WorkspaceSettingsPage />} />
                        <Route path="/settings/tenant" element={<Navigate to="/settings/workspace" replace />} />
                        <Route path="/settings/automation" element={<AutomationPage />} />
                        <Route path="/settings/api-keys" element={<ApiKeysPage />} />
                        <Route path="/integrations/lead-sources" element={<LeadSourcesPage />} />
                        <Route path="/integrations/webhooks" element={<WebhooksPage />} />
                        <Route path="/integrations" element={<Navigate to="/integrations/lead-sources" replace />} />
                        <Route path="/operations" element={<MonitorPage />} />
                      </Route>
                    </Route>

                    <Route path="*" element={<NotFound />} />
                  </Route>
                  {/* Old URLs */}
                  <Route path="/signin" element={<Navigate to="/sign-in" replace />} />
                  <Route path="/signup" element={<Navigate to="/sign-up" replace />} />
                </Routes>
              </AuthProvider>
            </BrowserRouter>
          </ConfirmProvider>
        </ToastProvider>
      </QueryClientProvider>
    </ThemeProvider>
  );
}
