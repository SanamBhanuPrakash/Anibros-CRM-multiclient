import { createContext, useCallback, useContext, useEffect, useMemo, useState, type ReactNode } from 'react';
import { useQuery, useQueryClient } from '@tanstack/react-query';
import { ApiProblem, onUnauthenticated } from '@/api/client';
import { authApi, meApi, type Me, type Role, type TenantInfo } from '@/api/endpoints';
import { useAuthStore } from '@/stores/auth-store';
import { closeClient, openClient, pruneTabs, resetScope, useScopeState } from '@/app/scope';

type Status = 'loading' | 'authenticated' | 'anonymous' | 'error';

interface AuthValue {
  status: Status;
  me: Me | null;
  /** The workspace the current screen works in (the open client; at the agency home, the session's default workspace). */
  tenant: TenantInfo | null;
  role: Role | null;
  error: ApiProblem | null;
  /** Does the current role hold this permission (any scope)? Presentation only — the server enforces. */
  can(permission: string): boolean;
  scope(permission: string): 'all' | 'own' | undefined;
  /** The open client's id, or null at the agency home. */
  scopeId: string | null;
  /** True for the moment after switching client, until that client's permissions have arrived. Screens must not render yet. */
  switching: boolean;
  /** Member of the agency (HQ): can use the agency home, all-leads and activity screens. */
  isAgencyMember: boolean;
  /** Agency owner or admin: sees every client, manages clients and the team. */
  isAgencyAdmin: boolean;
  isAgencyOwner: boolean;
  /** The agency's own workspace. */
  hq: TenantInfo | null;
  /** Every client workspace the person belongs to. */
  clients: TenantInfo[];
  signIn(me: Me): void;
  signOut(): Promise<void>;
  /** Kept for older call sites: opens that workspace as the current tab. */
  switchTenant(tenantId: string): Promise<void>;
  /** Re-read who I am and what I may do (also after the list of clients changed). */
  refresh(): Promise<unknown>;
}

const AuthCtx = createContext<AuthValue | null>(null);
/** Prefix key: invalidating it refreshes the answer for every open client. */
export const ME_KEY = ['me'] as const;
const meKey = (scope: string | null) => ['me', scope ?? 'agency'] as const;

export function AuthProvider({ children }: { children: ReactNode }) {
  const qc = useQueryClient();
  const { scope: scopeId } = useScopeState();
  const [forcedAnonymous, setForcedAnonymous] = useState(false);

  // The answer depends on the open client: capabilities are the person's role IN that client.
  const q = useQuery({
    queryKey: meKey(scopeId),
    queryFn: meApi.get,
    retry: (n, e) => !(e instanceof ApiProblem && e.status < 500 && e.status !== 0) && n < 2,
    staleTime: 5 * 60_000,
    refetchOnWindowFocus: true,
    placeholderData: (previous) => previous,
  });

  // Any 401 anywhere in the app means the session ended: drop caches and show the sign-in page.
  useEffect(() => {
    onUnauthenticated(() => {
      setForcedAnonymous(true);
      resetScope();
      qc.clear();
    });
  }, [qc]);

  // A client that is no longer ours (removed, archived) answers 403: close its tab instead of showing an error.
  useEffect(() => {
    if (scopeId && q.error instanceof ApiProblem && q.error.status === 403) closeClient(scopeId);
  }, [scopeId, q.error]);

  const me = forcedAnonymous ? null : (q.data ?? null);
  const status: Status = forcedAnonymous
    ? 'anonymous'
    : q.isPending
      ? 'loading'
      : q.data
        ? 'authenticated'
        : q.error instanceof ApiProblem && q.error.status === 401
          ? 'anonymous'
          : q.error instanceof ApiProblem && q.error.status === 403 && scopeId
            ? 'loading'
            : 'error';

  const tenant = me?.tenants.find((t) => t.id === me.activeTenantId) ?? null;
  const hq = me?.tenants.find((t) => t.kind === 'agency_hq') ?? null;
  const clients = useMemo(() => (me?.tenants ?? []).filter((t) => t.kind === 'client'), [me?.tenants]);
  const isAgencyMember = Boolean(hq);
  const isAgencyOwner = hq?.role === 'tenant_owner';
  const isAgencyAdmin = isAgencyOwner || hq?.role === 'tenant_admin';
  // Placeholder data = the previous client's answer while this client's answer is on its way.
  const switching = q.isPlaceholderData || (Boolean(scopeId) && me !== null && me.activeTenantId !== scopeId);

  // Forget tabs for clients that are gone; a person who belongs to no agency just works in their (first) client.
  useEffect(() => {
    if (!me) return;
    pruneTabs(new Set(me.tenants.map((t) => t.id)));
    if (!isAgencyMember && scopeId === null && me.tenants[0]) openClient(me.tenants[0].id);
  }, [me, isAgencyMember, scopeId]);

  // Bridge for legacy (not yet rebuilt) pages that still read the old zustand store. Removed with the last legacy page.
  useEffect(() => {
    useAuthStore.setState(
      me && tenant
        ? ({
            user: { id: me.user.id, email: me.user.email, user_metadata: { name: me.user.name } } as any,
            tenants: me.tenants as any,
            activeTenant: tenant as any,
            tenantMembership: { id: tenant.id, tenant_id: tenant.id, user_id: me.user.id, role: tenant.role, status: 'active' } as any,
            initializing: false,
            tenantLoading: false,
          } as any)
        : ({ user: null, tenants: [], activeTenant: null, tenantMembership: null, initializing: status === 'loading', tenantLoading: false } as any)
    );
  }, [me, tenant, status]);

  const signIn = useCallback(
    (next: Me) => {
      setForcedAnonymous(false);
      resetScope();
      qc.clear();
      qc.setQueryData(meKey(null), { user: next.user, tenants: next.tenants, activeTenantId: next.activeTenantId, capabilities: next.capabilities, sessionId: next.sessionId });
    },
    [qc]
  );

  const signOut = useCallback(async () => {
    try {
      await authApi.logout();
    } finally {
      resetScope();
      qc.clear();
      setForcedAnonymous(true);
    }
  }, [qc]);

  const switchTenant = useCallback(async (tenantId: string) => openClient(tenantId), []);
  const refresh = useCallback(() => qc.invalidateQueries({ queryKey: ME_KEY }), [qc]);

  const value = useMemo<AuthValue>(
    () => ({
      status,
      me,
      tenant,
      role: tenant?.role ?? null,
      error: q.error instanceof ApiProblem ? q.error : null,
      can: (p) => Boolean(me?.capabilities[p]),
      scope: (p) => me?.capabilities[p],
      scopeId,
      switching,
      isAgencyMember,
      isAgencyAdmin,
      isAgencyOwner,
      hq,
      clients,
      signIn,
      signOut,
      switchTenant,
      refresh,
    }),
    [status, me, tenant, q.error, scopeId, switching, isAgencyMember, isAgencyAdmin, isAgencyOwner, hq, clients, signIn, signOut, switchTenant, refresh]
  );

  return <AuthCtx.Provider value={value}>{children}</AuthCtx.Provider>;
}

export function useAuth(): AuthValue {
  const v = useContext(AuthCtx);
  if (!v) throw new Error('useAuth must be used inside <AuthProvider>');
  return v;
}

export const useCan = (permission: string) => useAuth().can(permission);

/** Render children only when the permission is held. */
export function Can({ permission, children, fallback = null }: { permission: string; children: ReactNode; fallback?: ReactNode }) {
  return <>{useCan(permission) ? children : fallback}</>;
}
