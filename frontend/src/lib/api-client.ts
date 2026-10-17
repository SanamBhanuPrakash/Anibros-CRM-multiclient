/**
 * ANIBROS CRM — Native Backend API Client
 * Parameterized, cookie-authenticated, CSRF-protected communication
 * with our native TypeScript PostgreSQL backend.
 */

const API_BASE_URL = import.meta.env.VITE_API_URL || (typeof window !== 'undefined' ? '' : 'http://127.0.0.1:4200');

function getCsrfTokenFromCookie(): string | null {
  if (typeof document === 'undefined') return null;
  const match = document.cookie.match(/(?:^|;\s*)anibrosdesign_csrf=([^;]*)/);
  return match ? decodeURIComponent(match[1]) : null;
}

export class ApiError extends Error {
  constructor(
    message: string,
    public status: number,
    public code?: string,
    public data?: any
  ) {
    super(message);
    this.name = 'ApiError';
  }
}

async function request<T = any>(
  path: string,
  options: RequestInit = {}
): Promise<T> {
  const url = `${API_BASE_URL}${path}`;
  const method = (options.method || 'GET').toUpperCase();

  const headers: Record<string, string> = {
    'Content-Type': 'application/json',
    ...(options.headers as Record<string, string>),
  };

  // Attach CSRF token on mutating requests if present in cookie
  if (['POST', 'PUT', 'PATCH', 'DELETE'].includes(method)) {
    const csrfToken = getCsrfTokenFromCookie();
    if (csrfToken && !headers['x-csrf-token']) {
      headers['x-csrf-token'] = csrfToken;
    }
  }

  const response = await fetch(url, {
    ...options,
    headers,
    credentials: 'include', // sends and receives HttpOnly cookies
  });

  const contentType = response.headers.get('content-type');
  let data: any = null;
  if (contentType && contentType.includes('json')) {
    data = await response.json();
  }

  if (!response.ok) {
    const errorMessage = data?.detail || data?.error || response.statusText || 'Request failed';
    throw new ApiError(errorMessage, response.status, data?.code, data);
  }

  return data as T;
}

export const nativeApi = {
  // Auth endpoints
  auth: {
    getCsrfToken: () => request<{ csrfToken: string }>('/api/auth/csrf-token'),
    signup: (payload: {
      email: string;
      password: string;
      name: string;
      organisationName?: string;
    }) => request('/api/auth/signup', { method: 'POST', body: JSON.stringify(payload) }),
    login: (payload: { email: string; password: string }) =>
      request('/api/auth/login', { method: 'POST', body: JSON.stringify(payload) }),
    getSession: () => request('/api/auth/session'),
    logout: () => request('/api/auth/logout', { method: 'POST' }),
    requestPasswordReset: (email: string) =>
      request('/api/auth/password-reset/request', {
        method: 'POST',
        body: JSON.stringify({ email }),
      }),
    completePasswordReset: (payload: { token: string; password: string }) =>
      request('/api/auth/password-reset/complete', {
        method: 'POST',
        body: JSON.stringify(payload),
      }),
    verifyEmail: (payload: { token: string }) =>
      request('/api/auth/verify-email', {
        method: 'POST',
        body: JSON.stringify(payload),
      }),
    resendVerification: (email: string) =>
      request('/api/auth/resend-verification', {
        method: 'POST',
        body: JSON.stringify({ email }),
      }),
  },

  // Leads endpoints
  // TEMPORARY adapters for pages that have not been rebuilt yet (Property matching, workspace settings).
  // They read the v1 API and reshape the answer for the old pages; delete with those pages (Claude_plan.md 3.8, 6.5).
  leads: {
    list: async (params: { limit?: number; search?: string; stageId?: string } = {}) => {
      const qs = new URLSearchParams({ limit: String(Math.min(params.limit ?? 50, 200)), state: 'open' });
      if (params.search) qs.set('q', params.search);
      if (params.stageId) qs.set('stageId', params.stageId);
      const res = await request<{ data: any[] }>(`/api/v1/leads?${qs}`);
      return {
        data: res.data.map((l) => {
          const [firstName = '', ...rest] = String(l.contactName ?? '').split(' ');
          return { ...l, contact: l.contactId ? { id: l.contactId, firstName, lastName: rest.join(' '), email: l.contactEmail, phone: l.contactPhone } : null };
        }),
      };
    },
    getStages: async () => {
      const res = await request<{ data: any[] }>('/api/v1/pipelines');
      const p = res.data.find((x) => x.isDefault) ?? res.data[0];
      return { data: (p?.stages ?? []).map((s: any) => ({ id: s.id, pipelineId: p.id, name: s.name, stageKey: s.stageKey, orderIndex: s.orderIndex, color: s.color })) };
    },
  },

  // Property matching (rule-based, explainable). Returns { matches: [{ property, score, matchReasons }] }.
  properties: {
    match: async (payload: { budgetMin?: number; budgetMax?: number; location?: string; propertyType?: string; bedrooms?: number }) =>
      (await request<{ data: any }>('/api/v1/matching/properties', { method: 'POST', body: JSON.stringify(payload) })).data,
  },

  // Dashboard numbers. Test leads are left out unless includeTest is set.
  dashboard: {
    getMetrics: async (opts: { includeTest?: boolean } = {}) =>
      (await request<{ data: any }>(`/api/v1/dashboard/metrics?includeTest=${opts.includeTest ? 'true' : 'false'}`)).data,
    getAttribution: async (opts: { includeTest?: boolean } = {}) =>
      request<{ data: any }>(`/api/v1/dashboard/attribution?includeTest=${opts.includeTest ? 'true' : 'false'}`),
  },
};





