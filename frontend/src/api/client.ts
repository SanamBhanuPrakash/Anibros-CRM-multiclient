/**
 * HTTP client for the Anibros API (v1).
 *
 *  - Cookie session + CSRF double-submit (header mirrors the JS-readable cookie).
 *  - Responses are `{ data, page? }`; errors are RFC 9457 problem documents → `ApiProblem` with stable `code`.
 *  - One place decides what a 401 means (`onUnauthenticated`), so no page implements its own redirect logic.
 *  - No retries here: TanStack Query owns retry policy (never retry 4xx or unsafe methods).
 */
import { getScope } from '@/app/scope';

/** PHP injects this at render time so one build works at a cPanel domain root and an XAMPP subdirectory. */
const BASE = (window as Window & { __ANIBROS__?: { base?: string } }).__ANIBROS__?.base ?? ((import.meta.env.VITE_API_URL as string | undefined) ?? '');

/**
 * Every call names the client the user is working in (see app/scope.ts); the server verifies it against the user's memberships on each request.
 * Sign-in and invitation links are about the person, not a client. A header a caller sets explicitly always wins (agency screens act on a specific client).
 */
function withClientScope(path: string, headers: Record<string, string>) {
  const scoped = getScope();
  if (!scoped || !path.startsWith('/api/v1/') || /^\/api\/v1\/(auth|invitations)(\/|$)/.test(path)) return;
  if (Object.keys(headers).some((k) => k.toLowerCase() === 'x-tenant-id')) return;
  headers['X-Tenant-Id'] = scoped;
}

export interface Page {
  limit: number;
  next: string | null;
  hasMore: boolean;
}
export interface FieldIssue {
  path: string;
  message: string;
  code?: string;
}

export class ApiProblem extends Error {
  readonly status: number;
  readonly code: string;
  readonly detail: string;
  readonly errors: FieldIssue[];
  readonly requestId?: string;
  readonly retryAfter?: number;
  /** Extension members of the problem document (e.g. `duplicates` on a 409 from POST /contacts). */
  readonly extra: Record<string, unknown>;
  constructor(status: number, body: Partial<{ code: string; detail: string; title: string; error: string; errors: FieldIssue[]; requestId: string }> & Record<string, unknown>, retryAfter?: number) {
    const detail = body.detail ?? body.error ?? body.title ?? `Request failed (${status})`;
    super(detail);
    this.name = 'ApiProblem';
    this.status = status;
    this.code = body.code ?? (status === 0 ? 'NETWORK' : 'UNKNOWN');
    this.detail = detail;
    this.errors = body.errors ?? [];
    this.requestId = body.requestId;
    this.retryAfter = retryAfter;
    this.extra = body;
  }
  /** Field → first message, for forms. Paths look like `body.email`; the `body.` prefix is stripped. */
  fieldErrors(): Record<string, string> {
    const out: Record<string, string> = {};
    for (const e of this.errors) {
      const key = e.path.replace(/^(body|query|path)\./, '');
      out[key] ??= e.message;
    }
    return out;
  }
  get isNetwork() {
    return this.status === 0;
  }
}

let unauthenticatedHandler: (() => void) | undefined;
export const onUnauthenticated = (fn: () => void) => {
  unauthenticatedHandler = fn;
};

const csrfCookie = () => {
  if (typeof document === 'undefined') return undefined;
  const m = /(?:^|;\s*)(?:__Host-)?anibrosdesign_csrf=([^;]*)/.exec(document.cookie);
  return m ? decodeURIComponent(m[1]) : undefined;
};

type Query = Record<string, string | number | boolean | undefined | null>;
export interface RequestOptions {
  query?: Query;
  body?: unknown;
  signal?: AbortSignal;
  headers?: Record<string, string>;
  /** Treat 401 as an ordinary error (e.g. a wrong password) instead of "session expired". */
  expect401?: boolean;
}

export interface Envelope<T> {
  data: T;
  page?: Page;
  /** Response headers (for example `X-Total-Count` on the analytics drill-downs). */
  headers?: Headers;
}

async function request<T>(method: string, path: string, opts: RequestOptions = {}): Promise<Envelope<T>> {
  const url = new URL(`${BASE}${path}`, window.location.origin);
  for (const [k, v] of Object.entries(opts.query ?? {})) if (v !== undefined && v !== null && v !== '') url.searchParams.set(k, String(v));

  const headers: Record<string, string> = { Accept: 'application/json', ...opts.headers };
  withClientScope(path, headers);
  if (opts.body !== undefined) headers['Content-Type'] = 'application/json';
  if (method !== 'GET' && method !== 'HEAD') {
    const csrf = csrfCookie();
    if (csrf) headers['x-csrf-token'] = csrf;
  }

  let res: Response;
  try {
    res = await fetch(url, { method, headers, credentials: 'include', signal: opts.signal, body: opts.body === undefined ? undefined : JSON.stringify(opts.body) });
  } catch (e) {
    if ((e as Error).name === 'AbortError') throw e;
    throw new ApiProblem(0, { code: 'NETWORK', detail: 'Cannot reach the server. Check your connection and try again.' });
  }

  if (res.status === 204) return { data: undefined as T };
  const text = await res.text();
  let json: any = undefined;
  try {
    json = text ? JSON.parse(text) : undefined;
  } catch {
    /* non-JSON error page from a proxy */
  }

  if (!res.ok) {
    const problem = new ApiProblem(res.status, json ?? {}, Number(res.headers.get('retry-after')) || undefined);
    if (res.status === 401 && !opts.expect401) unauthenticatedHandler?.();
    throw problem;
  }
  return { ...(json ?? { data: undefined }), headers: res.headers } as Envelope<T>;
}

/** Binary upload (pictures, PDFs): the file itself is the body and its own type is the Content-Type. Cookie session + CSRF like every mutation. */
async function upload<T>(path: string, file: Blob, query?: Record<string, string>): Promise<Envelope<T>> {
  const url = new URL(`${BASE}${path}`, window.location.origin);
  for (const [k, v] of Object.entries(query ?? {})) url.searchParams.set(k, v);
  const headers: Record<string, string> = { Accept: 'application/json', 'Content-Type': file.type || 'application/octet-stream' };
  withClientScope(path, headers);
  const csrf = csrfCookie();
  if (csrf) headers['x-csrf-token'] = csrf;
  let res: Response;
  try {
    res = await fetch(url, { method: 'POST', headers, credentials: 'include', body: file });
  } catch {
    throw new ApiProblem(0, { code: 'NETWORK', detail: 'Cannot reach the server. Check your connection and try again.' });
  }
  const text = await res.text();
  let json: any;
  try {
    json = text ? JSON.parse(text) : undefined;
  } catch {
    /* proxy error page */
  }
  if (!res.ok) throw new ApiProblem(res.status, json ?? {}, Number(res.headers.get('retry-after')) || undefined);
  return json as Envelope<T>;
}

export const api = {
  get: <T>(path: string, o?: RequestOptions) => request<T>('GET', path, o),
  post: <T>(path: string, body?: unknown, o?: RequestOptions) => request<T>('POST', path, { ...o, body: body ?? {} }),
  put: <T>(path: string, body?: unknown, o?: RequestOptions) => request<T>('PUT', path, { ...o, body: body ?? {} }),
  patch: <T>(path: string, body?: unknown, o?: RequestOptions) => request<T>('PATCH', path, { ...o, body: body ?? {} }),
  delete: <T = void>(path: string, o?: RequestOptions) => request<T>('DELETE', path, o),
  upload,
};

/** Convenience: unwrap `.data`. */
export const unwrap = async <T>(p: Promise<Envelope<T>>): Promise<T> => (await p).data;
