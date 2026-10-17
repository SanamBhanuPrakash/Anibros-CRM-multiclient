import { useSyncExternalStore } from 'react';

/**
 * Which client the user is working in, per BROWSER TAB.
 *
 * The agency home ("All clients") has no client: scope = null. Opening a client from the tab strip sets scope to that client's id; every API
 * request then names it (X-Tenant-Id, added in api/client.ts), and the server checks it against the user's memberships each time. The server
 * keeps no "current client" for the whole login, so two browser tabs on two different clients can never fight over it.
 *
 * It lives in sessionStorage: a reload keeps your tabs, a new browser tab starts clean at the agency home. `?c=<clientId>` in a link opens
 * that client (used when a link to a lead is shared).
 */
const SCOPE_KEY = 'anibrosdesign.scope';
const TABS_KEY = 'anibrosdesign.tabs';
const UUID_RE = /^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i;

const read = (key: string): string | null => {
  try {
    return sessionStorage.getItem(key);
  } catch {
    return null;
  }
};
const write = (key: string, value: string | null) => {
  try {
    if (value === null) sessionStorage.removeItem(key);
    else sessionStorage.setItem(key, value);
  } catch {
    /* private mode: the scope simply does not survive a reload */
  }
};

let scope: string | null = read(SCOPE_KEY);
let tabs: string[] = (() => {
  try {
    const parsed = JSON.parse(read(TABS_KEY) ?? '[]');
    return Array.isArray(parsed) ? parsed.filter((x): x is string => typeof x === 'string' && UUID_RE.test(x)) : [];
  } catch {
    return [];
  }
})();
if (scope && !UUID_RE.test(scope)) scope = null;

// A shared link may name the client it belongs to.
if (typeof window !== 'undefined') {
  try {
    const url = new URL(window.location.href);
    const c = url.searchParams.get('c');
    if (c && UUID_RE.test(c)) {
      if (!tabs.includes(c)) tabs = [...tabs, c];
      scope = c;
      url.searchParams.delete('c');
      window.history.replaceState(null, '', `${url.pathname}${url.search}${url.hash}`);
    }
  } catch {
    /* ignore a malformed URL */
  }
}

export interface ScopeSnapshot {
  scope: string | null;
  tabs: string[];
}
let snapshot: ScopeSnapshot = { scope, tabs };
const listeners = new Set<() => void>();
const commit = () => {
  write(SCOPE_KEY, scope);
  write(TABS_KEY, JSON.stringify(tabs));
  snapshot = { scope, tabs };
  listeners.forEach((l) => l());
};
const subscribe = (l: () => void) => {
  listeners.add(l);
  return () => listeners.delete(l);
};

/** Read at request time by the API client. */
export const getScope = () => scope;

/** Open (or switch to) a client in this browser tab. */
export function openClient(id: string) {
  if (!UUID_RE.test(id)) return;
  if (!tabs.includes(id)) tabs = [...tabs, id];
  scope = id;
  commit();
}

/** Back to the agency home (no client). */
export function showAgency() {
  if (scope === null) return;
  scope = null;
  commit();
}

/** Close a client tab; if it was the open one, move to its neighbour (or the agency home). */
export function closeClient(id: string) {
  const i = tabs.indexOf(id);
  if (i < 0 && scope !== id) return;
  tabs = tabs.filter((t) => t !== id);
  if (scope === id) scope = tabs[Math.min(Math.max(i, 0), tabs.length - 1)] ?? null;
  commit();
}

/** Forget tabs for clients the user no longer belongs to (removed, archived). */
export function pruneTabs(valid: ReadonlySet<string>) {
  const keep = tabs.filter((t) => valid.has(t));
  const scopeOk = scope === null || valid.has(scope);
  if (keep.length === tabs.length && scopeOk) return;
  tabs = keep;
  if (!scopeOk) scope = null;
  commit();
}

/** Sign-out / sign-in as someone else: nothing of the previous session's tabs survives. */
export function resetScope() {
  if (scope === null && tabs.length === 0) return;
  scope = null;
  tabs = [];
  commit();
}

export const useScopeState = (): ScopeSnapshot => useSyncExternalStore(subscribe, () => snapshot, () => snapshot);
