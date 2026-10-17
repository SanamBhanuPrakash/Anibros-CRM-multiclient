import { describe, it, expect } from 'vitest';
import { sanitizeContext, generateCorrelationId } from './telemetry';

describe('telemetry', () => {
  it('generates non-empty unique correlation IDs', () => {
    const id1 = generateCorrelationId();
    const id2 = generateCorrelationId();
    expect(id1).toBeTruthy();
    expect(id2).toBeTruthy();
    expect(id1).not.toBe(id2);
  });

  it('redacts sensitive keys including password, token, apikey, secret', () => {
    const dirty = {
      user: 'admin',
      password: 'supersecretpassword123',
      token: 'jwt-token-value',
      access_token: 'secret-token',
      api_key: 'key-12345',
      meta: {
        clientSecret: 'client-secret-999',
        normalField: 'all-good',
      },
      list: [
        { normal: 'ok', secret: 'nested-secret' },
      ],
    };

    const clean = sanitizeContext(dirty) as any;

    expect(clean.user).toBe('admin');
    expect(clean.password).toBe('[REDACTED]');
    expect(clean.token).toBe('[REDACTED]');
    expect(clean.access_token).toBe('[REDACTED]');
    expect(clean.api_key).toBe('[REDACTED]');
    expect(clean.meta.clientSecret).toBe('[REDACTED]');
    expect(clean.meta.normalField).toBe('all-good');
    expect(clean.list[0].normal).toBe('ok');
    expect(clean.list[0].secret).toBe('[REDACTED]');
  });

  it('handles null, undefined, primitives, and arrays cleanly', () => {
    expect(sanitizeContext(null)).toBeNull();
    expect(sanitizeContext(undefined)).toBeUndefined();
    expect(sanitizeContext('test')).toBe('test');
    expect(sanitizeContext(123)).toBe(123);
    expect(sanitizeContext([1, 2, 3])).toEqual([1, 2, 3]);
  });
});
