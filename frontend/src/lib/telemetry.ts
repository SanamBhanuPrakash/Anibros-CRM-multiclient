/**
 * telemetry.ts
 *
 * Operational telemetry logging and error sanitization for Anibros CRM.
 * Security: Redacts sensitive tokens, passwords, and secret keys.
 */

import { useAuthStore } from '@/stores/auth-store';

export type LogSeverity = 'debug' | 'info' | 'warn' | 'error' | 'fatal';
export type LogComponent = 'frontend' | 'auth' | 'database' | 'webhook' | 'outbox' | 'background_job';

export interface TelemetryEventOptions {
  tenantId?: string | null;
  severity: LogSeverity;
  component: LogComponent;
  eventName: string;
  message: string;
  correlationId?: string;
  context?: Record<string, unknown>;
  durationMs?: number;
}

const SENSITIVE_KEYS = new Set([
  'password',
  'token',
  'access_token',
  'refresh_token',
  'authorization',
  'secret',
  'service_role_key',
  'google_key',
  'api_key',
  'apikey',
]);

let isLogging = false;
let logCountInWindow = 0;
let windowStartTime = Date.now();
const MAX_LOGS_PER_MINUTE = 30;

export function sanitizeContext(data: unknown): unknown {
  if (data === null || data === undefined) return data;
  if (typeof data !== 'object') return data;

  if (Array.isArray(data)) {
    return data.map((item) => sanitizeContext(item));
  }

  const sanitized: Record<string, unknown> = {};
  for (const [key, val] of Object.entries(data as Record<string, unknown>)) {
    const lowerKey = key.toLowerCase();
    if (SENSITIVE_KEYS.has(lowerKey) || lowerKey.includes('secret') || lowerKey.includes('password')) {
      sanitized[key] = '[REDACTED]';
    } else if (typeof val === 'object' && val !== null) {
      sanitized[key] = sanitizeContext(val);
    } else {
      sanitized[key] = val;
    }
  }
  return sanitized;
}

export function generateCorrelationId(): string {
  if (typeof crypto !== 'undefined' && crypto.randomUUID) {
    return crypto.randomUUID();
  }
  return 'corr-' + Math.random().toString(36).substring(2, 15) + '-' + Date.now();
}

export async function logOperationalEvent({
  tenantId = null,
  severity,
  component,
  eventName,
  message,
  correlationId,
  context = {},
  durationMs,
}: TelemetryEventOptions): Promise<string> {
  const corrId = correlationId || generateCorrelationId();

  if (isLogging) {
    return corrId;
  }

  const now = Date.now();
  if (now - windowStartTime > 60_000) {
    windowStartTime = now;
    logCountInWindow = 0;
  }
  if (logCountInWindow >= MAX_LOGS_PER_MINUTE) {
    return corrId;
  }

  isLogging = true;
  logCountInWindow++;

  const sanitizedCtx = sanitizeContext(context) as Record<string, unknown>;
  const cleanMessage = (message || 'Operational event').substring(0, 1000);

  try {
    const resolvedTenant = tenantId ?? useAuthStore.getState().activeTenant?.id ?? null;
    if (process.env.NODE_ENV !== 'production') {
      const logger = severity === 'error' || severity === 'fatal' ? console.error : severity === 'warn' ? console.warn : console.info;
      logger(`[Telemetry ${severity.toUpperCase()}] ${eventName}`, {
        tenantId: resolvedTenant,
        component,
        message: cleanMessage,
        correlationId: corrId,
        durationMs,
        context: sanitizedCtx,
      });
    }
  } catch (err) {
    // Suppress telemetry failures to avoid crashing app
  } finally {
    isLogging = false;
  }

  return corrId;
}

let globalTelemetryInitialized = false;

export function initGlobalErrorTelemetry(): void {
  if (globalTelemetryInitialized || typeof window === 'undefined') return;
  globalTelemetryInitialized = true;

  window.addEventListener('error', (event: ErrorEvent) => {
    const tenantId = useAuthStore.getState().activeTenant?.id ?? null;
    logOperationalEvent({
      tenantId,
      severity: 'error',
      component: 'frontend',
      eventName: 'frontend.unhandled_runtime_error',
      message: event.message || 'Unhandled window error',
      context: {
        filename: event.filename,
        lineno: event.lineno,
        colno: event.colno,
        stack: event.error?.stack,
      },
    });
  });

  window.addEventListener('unhandledrejection', (event: PromiseRejectionEvent) => {
    const tenantId = useAuthStore.getState().activeTenant?.id ?? null;
    const reason = event.reason;
    const message = reason instanceof Error ? reason.message : typeof reason === 'string' ? reason : 'Unhandled promise rejection';
    const stack = reason instanceof Error ? reason.stack : undefined;

    logOperationalEvent({
      tenantId,
      severity: 'error',
      component: 'frontend',
      eventName: 'frontend.unhandled_promise_rejection',
      message,
      context: {
        reason: typeof reason === 'object' ? sanitizeContext(reason) : reason,
        stack,
      },
    });
  });
}
