import { create } from 'zustand';
import { nativeApi } from '@/lib/api-client';
import type { User, Tenant, TenantMembership, OrganisationMembership } from '@/types';

interface AuthState {
  user: User | null;
  loading: boolean;
  tenants: Tenant[];
  activeTenant: Tenant | null;
  tenantMembership: TenantMembership | null;
  orgMemberships: OrganisationMembership[];
  initializing: boolean;
  tenantLoading: boolean;
  onboardingError: string | null;

  setUser: (user: User | null) => void;
  setLoading: (loading: boolean) => void;
  setInitializing: (initializing: boolean) => void;
  loadTenantData: (userId?: string) => Promise<void>;
  setActiveTenant: (tenant: Tenant) => void;
  signOut: () => Promise<void>;
}

export const useAuthStore = create<AuthState>((set, get) => ({
  user: null,
  loading: false,
  tenants: [],
  activeTenant: null,
  tenantMembership: null,
  orgMemberships: [],
  initializing: true,
  tenantLoading: false,
  onboardingError: null,

  setUser: (user) => set({ user }),
  setLoading: (loading) => set({ loading }),
  setInitializing: (initializing) => set({ initializing }),

  loadTenantData: async () => {
    try {
      set({ tenantLoading: true });
      const sessionRes = await nativeApi.auth.getSession();
      if (sessionRes?.user && sessionRes?.activeTenant) {
        const storedTenantId = localStorage.getItem('activeTenantId');
        const allTenants: Tenant[] = sessionRes.allTenants || [sessionRes.activeTenant];
        const activeTenant = allTenants.find((t) => t.id === storedTenantId) || sessionRes.activeTenant;

        set({
          user: {
            id: sessionRes.user.id,
            email: sessionRes.user.email,
            app_metadata: {},
            user_metadata: { name: sessionRes.user.name },
            aud: 'authenticated',
            created_at: new Date().toISOString(),
          } as any,
          tenants: allTenants,
          activeTenant,
          tenantMembership: {
            id: activeTenant.id,
            tenant_id: activeTenant.id,
            user_id: sessionRes.user.id,
            role: activeTenant.role,
            status: 'active',
            branch_id: null,
            created_at: new Date().toISOString(),
            updated_at: new Date().toISOString(),
          } as any,
          orgMemberships: [],
          tenantLoading: false,
        });
      } else {
        set({
          user: null,
          tenants: [],
          activeTenant: null,
          tenantMembership: null,
          orgMemberships: [],
          tenantLoading: false,
        });
      }
    } catch {
      set({ tenantLoading: false });
    }
  },

  setActiveTenant: (tenant) => {
    localStorage.setItem('activeTenantId', tenant.id);
    set({
      activeTenant: tenant,
      tenantMembership: {
        id: tenant.id,
        tenant_id: tenant.id,
        user_id: get().user?.id || '',
        role: (tenant as any).role || 'tenant_owner',
        status: 'active',
        branch_id: null,
        created_at: new Date().toISOString(),
        updated_at: new Date().toISOString(),
      } as any,
    });
  },

  signOut: async () => {
    try {
      await nativeApi.auth.logout();
    } catch {
      // ignore network errors on signout
    }
    localStorage.removeItem('activeTenantId');
    set({
      user: null,
      tenants: [],
      activeTenant: null,
      tenantMembership: null,
      orgMemberships: [],
      onboardingError: null,
      tenantLoading: false,
    });
  },
}));
