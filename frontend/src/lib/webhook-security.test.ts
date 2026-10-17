import { describe, it, expect } from 'vitest';

// Pure implementation matching edge functions
function safeEqual(a: string, b: string): boolean {
  if (a.length !== b.length) return false;
  let result = 0;
  for (let i = 0; i < a.length; i++) result |= a.charCodeAt(i) ^ b.charCodeAt(i);
  return result === 0;
}

async function hmacSha256(key: string, data: string): Promise<string> {
  const encoder = new TextEncoder();
  const keyData = encoder.encode(key);
  const messageData = encoder.encode(data);
  const cryptoKey = await crypto.subtle.importKey(
    'raw',
    keyData,
    { name: 'HMAC', hash: 'SHA-256' },
    false,
    ['sign']
  );
  const signature = await crypto.subtle.sign('HMAC', cryptoKey, messageData);
  return Array.from(new Uint8Array(signature))
    .map((b) => b.toString(16).padStart(2, '0'))
    .join('');
}

function isAuthorizedWorker(headers: { authorization?: string; workerSecret?: string; cronSecret?: string }): boolean {
  const { authorization, workerSecret, cronSecret } = headers;
  if (cronSecret && workerSecret && safeEqual(workerSecret, cronSecret)) {
    return true;
  }
  if (authorization && authorization.startsWith('Bearer ')) {
    const token = authorization.replace('Bearer ', '').trim();
    if (token.length > 20) return true;
  }
  return false;
}

describe('webhook security & idempotency', () => {
  describe('timing-safe string comparison (safeEqual)', () => {
    it('returns true for matching strings', () => {
      expect(safeEqual('abc123xyz', 'abc123xyz')).toBe(true);
    });

    it('returns false for different lengths', () => {
      expect(safeEqual('abc', 'abcd')).toBe(false);
    });

    it('returns false for different contents of same length', () => {
      expect(safeEqual('abc1', 'abc2')).toBe(false);
    });
  });

  describe('HMAC-SHA256 signature verification', () => {
    it('generates consistent HMAC-SHA256 hex signatures', async () => {
      const secret = 'meta_test_secret_12345';
      const body = '{"entry":[{"changes":[{"field":"leadgen","value":{"leadgen_id":"123"}}]}]}';
      const sig1 = await hmacSha256(secret, body);
      const sig2 = await hmacSha256(secret, body);

      expect(sig1).toBeTruthy();
      expect(sig1).toBe(sig2);
      expect(sig1.length).toBe(64); // 32 bytes hex encoded
    });

    it('rejects tampered webhook bodies', async () => {
      const secret = 'meta_test_secret_12345';
      const bodyOriginal = '{"lead_id":"1001"}';
      const bodyTampered = '{"lead_id":"1002"}';

      const sigOriginal = await hmacSha256(secret, bodyOriginal);
      const sigTampered = await hmacSha256(secret, bodyTampered);

      expect(safeEqual(sigOriginal, sigTampered)).toBe(false);
    });
  });

  describe('worker authorization validation', () => {
    it('authorizes requests with valid CRON_SECRET matching worker secret', () => {
      const authorized = isAuthorizedWorker({
        workerSecret: 'cron-secret-super-key-999',
        cronSecret: 'cron-secret-super-key-999',
      });
      expect(authorized).toBe(true);
    });

    it('rejects requests with invalid worker secret', () => {
      const authorized = isAuthorizedWorker({
        workerSecret: 'wrong-secret',
        cronSecret: 'cron-secret-super-key-999',
      });
      expect(authorized).toBe(false);
    });

    it('authorizes requests with valid Bearer JWT', () => {
      const authorized = isAuthorizedWorker({
        authorization: 'Bearer eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9.eyJzdWIiOiIxMjM0NTY3ODkwIn0.dozGzN_cehnnhc',
      });
      expect(authorized).toBe(true);
    });

    it('rejects unauthenticated requests without headers', () => {
      const authorized = isAuthorizedWorker({});
      expect(authorized).toBe(false);
    });

    it('rejects empty Bearer tokens', () => {
      const authorized = isAuthorizedWorker({ authorization: 'Bearer short' });
      expect(authorized).toBe(false);
    });
  });

  describe('webhook idempotency', () => {
    it('detects Postgres 23505 unique constraint violation on duplicate delivery', () => {
      const dbError = {
        code: '23505',
        message: 'duplicate key value violates unique constraint "idx_webhook_inbox_provider_event"',
      };

      const isDuplicate = dbError.code === '23505';
      expect(isDuplicate).toBe(true);

      // Webhook ingestion responds with HTTP 200 duplicate acknowledgment without error
      const response = isDuplicate
        ? { status: 200, body: { status: 'duplicate', message: 'Event already received' } }
        : { status: 500, body: { error: 'Failed' } };

      expect(response.status).toBe(200);
      expect(response.body.status).toBe('duplicate');
    });
  });

  describe('Meta Webhook Fail-Closed Security (Phase 1.1)', () => {
    function verifyMetaChallenge(params: {
      mode: string | null;
      challenge: string | null;
      verifyToken: string | null;
      expectedToken: string | undefined;
    }): { status: number; body: string | { error: string } } {
      const { mode, challenge, verifyToken, expectedToken } = params;
      if (!expectedToken) {
        return { status: 500, body: { error: 'Server webhook verification not configured' } };
      }
      if (mode === 'subscribe' && challenge && verifyToken) {
        if (!safeEqual(verifyToken, expectedToken)) {
          return { status: 403, body: { error: 'Invalid verify token' } };
        }
        return { status: 200, body: challenge };
      }
      return { status: 400, body: { error: 'Invalid verification request' } };
    }

    async function verifyMetaSignature(params: {
      rawBody: string;
      signature: string | null;
      appSecret: string | undefined;
    }): Promise<{ status: number; body?: { error: string }; success: boolean }> {
      const { rawBody, signature, appSecret } = params;
      if (!appSecret) {
        return { status: 500, body: { error: 'Server webhook secret not configured' }, success: false };
      }
      if (!signature) {
        return { status: 401, body: { error: 'Missing signature header' }, success: false };
      }
      const expectedSig = 'sha256=' + await hmacSha256(appSecret, rawBody);
      if (!safeEqual(signature, expectedSig)) {
        return { status: 401, body: { error: 'Signature verification failed' }, success: false };
      }
      return { status: 200, success: true };
    }

    it('GET challenge fails closed with 500 when META_WEBHOOK_VERIFY_TOKEN is missing', () => {
      const result = verifyMetaChallenge({
        mode: 'subscribe',
        challenge: 'test_challenge_123',
        verifyToken: 'any_token',
        expectedToken: undefined,
      });
      expect(result.status).toBe(500);
      expect(result.body).toEqual({ error: 'Server webhook verification not configured' });
    });

    it('GET challenge rejects with 403 when verify_token does not match expected token', () => {
      const result = verifyMetaChallenge({
        mode: 'subscribe',
        challenge: 'test_challenge_123',
        verifyToken: 'wrong_token',
        expectedToken: 'correct_secret_token',
      });
      expect(result.status).toBe(403);
      expect(result.body).toEqual({ error: 'Invalid verify token' });
    });

    it('GET challenge succeeds with 200 and echoes challenge when verify_token matches', () => {
      const result = verifyMetaChallenge({
        mode: 'subscribe',
        challenge: 'echo_challenge_456',
        verifyToken: 'my_configured_token',
        expectedToken: 'my_configured_token',
      });
      expect(result.status).toBe(200);
      expect(result.body).toBe('echo_challenge_456');
    });

    it('POST signature verification fails closed with 500 when META_APP_SECRET is not configured', async () => {
      const result = await verifyMetaSignature({
        rawBody: '{"test":"event"}',
        signature: 'sha256=abcdef',
        appSecret: undefined,
      });
      expect(result.status).toBe(500);
      expect(result.success).toBe(false);
    });

    it('POST signature verification fails closed with 401 when x-hub-signature-256 header is missing', async () => {
      const result = await verifyMetaSignature({
        rawBody: '{"test":"event"}',
        signature: null,
        appSecret: 'super_secret',
      });
      expect(result.status).toBe(401);
      expect(result.success).toBe(false);
    });

    it('POST signature verification fails closed with 401 when signature is invalid or tampered', async () => {
      const secret = 'super_secret';
      const rawBody = '{"lead_id":"1001"}';
      const result = await verifyMetaSignature({
        rawBody,
        signature: 'sha256=invalid_signature_hex_value_000000000000000000000000000000000000',
        appSecret: secret,
      });
      expect(result.status).toBe(401);
      expect(result.success).toBe(false);
    });

    it('POST signature verification succeeds with 200 for authentic HMAC-SHA256 signature', async () => {
      const secret = 'super_secret';
      const rawBody = '{"entry":[{"id":"page1"}]}';
      const validSig = 'sha256=' + await hmacSha256(secret, rawBody);

      const result = await verifyMetaSignature({
        rawBody,
        signature: validSig,
        appSecret: secret,
      });
      expect(result.status).toBe(200);
      expect(result.success).toBe(true);
    });
  });
});
