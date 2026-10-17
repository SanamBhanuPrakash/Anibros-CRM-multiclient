<?php
declare(strict_types=1);

namespace App\Modules\Inbound;

use App\Core\Crypto;
use App\Core\EventLog;
use App\Core\Logger;
use App\Core\RateLimiter;
use App\Core\Request;
use App\Core\Response;

final class Receiver
{
    private const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i';
    private const MAX_BODY = 262144;

    public static function handle(Request $request, Response $response): void
    {
        $response->header('Cache-Control', 'no-store');
        $response->header('X-Content-Type-Options', 'nosniff');
        $path = trim($request->path, '/');
        if (!preg_match('#^hooks/([0-9a-f-]{36})(/capture\.js)?$#i', $path, $m) || !preg_match(self::UUID, $m[1])) {
            self::reject($response, 404, 'NOT_FOUND', 'Not found');
            return;
        }
        $id = strtolower($m[1]);
        $capture = !empty($m[2]);
        if ($request->method === 'OPTIONS') {
            $endpoint = self::lookup($id, $response);
            if ($endpoint === false) return;
            if (!$endpoint || $endpoint['provider'] !== 'website' || !self::cors($request, $response, $endpoint['config'])) {
                $response->status = 204;
                return;
            }
            $response->header('Access-Control-Allow-Methods', 'POST, OPTIONS');
            $response->header('Access-Control-Allow-Headers', 'Content-Type');
            $response->header('Access-Control-Max-Age', '600');
            $response->status = 204;
            return;
        }

        if (!self::rateLimit('hook-ip', $request->ip, 120, 20, $response)) return;
        if ($capture) {
            $endpoint = self::lookup($id, $response);
            if ($endpoint === false) return;
            if (!$endpoint || $endpoint['provider'] !== 'website') {
                self::reject($response, 404, 'NOT_FOUND', 'Not found');
                return;
            }
            $response->header('Access-Control-Allow-Origin', '*');
            $response->header('Cross-Origin-Resource-Policy', 'cross-origin');
            $response->header('Cache-Control', 'public, max-age=300');
            $response->header('Content-Type', 'application/javascript; charset=utf-8');
            $response->body = self::captureScript(InboundService::hookUrl($request, $id));
            return;
        }
        if ($request->method === 'GET') {
            $endpoint = self::lookup($id, $response);
            if ($endpoint === false) return;
            $token = (string) ($request->query['hub.verify_token'] ?? $request->query['hub_verify_token'] ?? '');
            $mode = $request->query['hub.mode'] ?? $request->query['hub_mode'] ?? null;
            $challenge = (string) ($request->query['hub.challenge'] ?? $request->query['hub_challenge'] ?? '');
            if (!$endpoint || $endpoint['provider'] !== 'meta' || $mode !== 'subscribe' || !$endpoint['verify_token'] || $token === '' || !hash_equals($endpoint['verify_token'], $token) || !preg_match('/^[\w-]{1,200}$/', $challenge)) {
                Logger::warn('Inbound Meta verification refused', ['endpointId' => $id]);
                if ($endpoint) EventLog::record(['tenantId' => $endpoint['tenant_id'], 'component' => 'security', 'severity' => 'warn', 'message' => 'Inbound webhook verification was refused', 'context' => ['endpointId' => $id, 'provider' => $endpoint['provider']], 'correlationId' => $request->id, 'throttleKey' => 'hook-verify:' . $id]);
                self::reject($response, 403, 'FORBIDDEN', 'Verification failed');
                return;
            }
            $response->header('Content-Type', 'text/plain; charset=utf-8');
            $response->body = $challenge;
            return;
        }
        if ($request->method !== 'POST') {
            self::reject($response, 405, 'METHOD_NOT_ALLOWED', 'Method not allowed');
            return;
        }

        if (!self::rateLimit('hook-endpoint', $id, 600, 100, $response, 'Too many requests for this source')) return;
        $length = (int) ($request->header('content-length') ?? 0);
        if ($length > self::MAX_BODY) {
            self::reject($response, 413, 'PAYLOAD_TOO_LARGE', 'Request body is too large');
            return;
        }
        try {
            $raw = self::readBody($request);
        } catch (\Throwable $e) {
            Logger::error('Inbound request body could not be read', ['endpointId' => $id, 'error' => $e->getMessage()]);
            self::reject($response, 503, 'UNAVAILABLE', 'Temporarily unavailable', ['Retry-After' => '30']);
            return;
        }
        if (strlen($raw) > self::MAX_BODY) {
            self::reject($response, 413, 'PAYLOAD_TOO_LARGE', 'Request body is too large');
            return;
        }
        $endpoint = self::lookup($id, $response);
        if ($endpoint === false) return;
        if (!$endpoint) {
            self::reject($response, 404, 'NOT_FOUND', 'Not found');
            return;
        }
        $provider = $endpoint['provider'];
        if ($endpoint['status'] !== 'active') {
            self::reject($response, 503, 'PAUSED', 'This source is paused', ['Retry-After' => '3600']);
            return;
        }

        try {
            $body = null;
            if ($provider === 'website') {
                if (!self::rateLimit('hook-web-ip', $id . ':' . $request->ip, 8, 0.2, $response, 'Too many submissions. Please try again shortly.')) return;
                if (!self::cors($request, $response, $endpoint['config'])) {
                    Logger::warn('Inbound website form from a disallowed origin', ['endpointId' => $id, 'origin' => $request->header('origin')]);
                    EventLog::record(['tenantId' => $endpoint['tenant_id'], 'component' => 'security', 'severity' => 'warn', 'message' => 'A website not on the lead source allowed-origin list attempted to submit a lead', 'context' => ['endpointId' => $id, 'origin' => $request->header('origin')], 'correlationId' => $request->id, 'throttleKey' => 'hook-origin:' . $id]);
                    self::reject($response, 403, 'FORBIDDEN', 'This website is not allowed to post to this form');
                    return;
                }
            } else {
                $secret = InboundService::readSecret($endpoint['id'], $endpoint['secret_enc']);
                $ok = false;
                if ($secret !== null) {
                    if ($provider === 'meta') {
                        $ok = Providers::verifyMeta($raw, $request->header('x-hub-signature-256'), $secret);
                    } elseif ($provider === 'generic') {
                        $ok = Providers::verifyGeneric($raw, $request->header('x-anibros-signature'), $secret);
                        if (!$ok) {
                            $authorization = $request->header('authorization') ?? '';
                            $bearer = preg_match('/^Bearer\s+(\S{8,300})$/i', $authorization, $bm) ? $bm[1] : '';
                            $presented = $bearer ?: ($request->header('x-anibros-key') ?? (string) ($request->query['key'] ?? ''));
                            $ok = strlen($presented) >= 8 && strlen($presented) <= 300 && Crypto::safeEqual($presented, $secret);
                        }
                    } elseif ($provider === 'google') {
                        try {
                            $body = Providers::parseBody($raw, $request->header('content-type'));
                            $key = $body['google_key'] ?? null;
                            $ok = is_string($key) && Crypto::safeEqual($key, $secret);
                        } catch (BadPayload) {
                            $ok = false;
                        }
                    }
                }
                if (!$ok) {
                    Logger::warn('Inbound signature rejected', ['endpointId' => $id, 'provider' => $provider, 'ip' => $request->ip]);
                    EventLog::record(['tenantId' => $endpoint['tenant_id'], 'component' => 'security', 'severity' => 'warn', 'message' => 'An inbound lead delivery failed provider authentication', 'context' => ['endpointId' => $id, 'provider' => $provider], 'correlationId' => $request->id, 'throttleKey' => 'hook-auth:' . $id]);
                    self::reject($response, 401, 'UNAUTHENTICATED', 'Signature check failed');
                    return;
                }
            }

            $body ??= Providers::parseBody($raw, $request->header('content-type'));
            if ($provider === 'website' && Providers::honeypot($body)) {
                $response->json(200, ['status' => 'received', 'accepted' => 1]);
                return;
            }
            $events = Providers::extract($provider, $body, $raw, $request->headers);
            if (count($events) > 500) throw new BadPayload('Too many events in one request');
            $persistStartedAt = hrtime(true);
            $accepted = InboundService::receive($endpoint, $events);
            Logger::info('Inbound events persisted before acknowledgement', [
                'endpointId' => $id,
                'provider' => $provider,
                'accepted' => $accepted,
                'persistMs' => round((hrtime(true) - $persistStartedAt) / 1_000_000, 3),
            ]);
            $response->json(200, ['status' => 'received', 'accepted' => count($events)]);
        } catch (BadPayload $e) {
            EventLog::record(['tenantId' => $endpoint['tenant_id'], 'component' => 'inbound', 'severity' => 'warn', 'message' => 'An authenticated lead delivery had an invalid payload', 'context' => ['endpointId' => $id, 'provider' => $provider, 'reason' => $e->getMessage()], 'correlationId' => $request->id, 'throttleKey' => 'hook-payload:' . $id]);
            self::reject($response, 400, 'BAD_REQUEST', $e->getMessage());
        } catch (\Throwable $e) {
            Logger::error('Inbound receipt failed; provider should retry', ['endpointId' => $id, 'provider' => $provider, 'error' => $e->getMessage()]);
            EventLog::record(['tenantId' => $endpoint['tenant_id'], 'component' => 'inbound', 'severity' => 'error', 'message' => 'Durable inbound receipt failed; the provider was asked to retry', 'context' => ['endpointId' => $id, 'provider' => $provider], 'correlationId' => $request->id, 'throttleKey' => 'hook-receipt-failure:' . $id]);
            self::reject($response, 503, 'UNAVAILABLE', 'Temporarily unavailable', ['Retry-After' => '30']);
        }
    }

    private static function rateLimit(string $name, string $key, float $capacity, float $refill, Response $response, string $message = 'Too many requests'): bool
    {
        $d = RateLimiter::take($name, $key, $capacity, $refill);
        if (!$d['allowed']) {
            self::reject($response, 429, 'RATE_LIMITED', $message, ['Retry-After' => (string) $d['retryAfter']]);
            return false;
        }
        return true;
    }

    private static function cors(Request $request, Response $response, array $config): bool
    {
        $origin = $request->header('origin');
        $allowed = is_array($config['allowedOrigins'] ?? null) ? array_map(static fn($o) => rtrim((string) $o, '/'), $config['allowedOrigins']) : [];
        if ($origin === null || $origin === '') return true;
        if ($allowed && !in_array(rtrim($origin, '/'), $allowed, true)) return false;
        $response->header('Access-Control-Allow-Origin', $allowed ? $origin : '*');
        $response->header('Cross-Origin-Resource-Policy', 'cross-origin');
        $response->header('Vary', 'Origin');
        return true;
    }

    /** @return array<string,mixed>|null|false false means lookup failed and a retryable response was already sent */
    private static function lookup(string $id, Response $response): array|null|false
    {
        try {
            return InboundService::lookupPublic($id);
        } catch (\Throwable $e) {
            Logger::error('Inbound endpoint lookup failed', ['endpointId' => $id, 'error' => $e->getMessage()]);
            self::reject($response, 503, 'UNAVAILABLE', 'Temporarily unavailable', ['Retry-After' => '30']);
            return false;
        }
    }

    private static function readBody(Request $request): string
    {
        if ($request->rawBody !== null) return $request->rawBody;
        $stream = fopen('php://input', 'rb');
        if ($stream === false) throw new \RuntimeException('Unable to read webhook request body');
        try {
            $body = stream_get_contents($stream, self::MAX_BODY + 1);
        } finally {
            fclose($stream);
        }
        if ($body === false) throw new \RuntimeException('Unable to read webhook request body');
        $request->rawBody = $body;
        return $body;
    }

    private static function reject(Response $response, int $status, string $code, string $message, array $headers = []): void
    {
        foreach ($headers as $key => $value) $response->header($key, $value);
        $response->json($status, ['status' => 'error', 'code' => $code, 'message' => $message]);
    }

    private static function captureScript(string $endpoint): string
    {
        $literal = json_encode($endpoint, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $script = <<<'JS'
(function () {
  'use strict';
  var ENDPOINT = __ENDPOINT__;
  if (window.AnibrosCRM && window.AnibrosCRM.loaded) return;
  var EMAIL = /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/;
  var PRIVATE = /(pass(word|wd)?|card|cvv|cvc|ccnum|expir|otp|ssn|aadhaar|aadhar|pan_?no|secret)/i;
  var TRAP = /^(_hp|hp|honeypot|website_url|url_website|bot_?field|trap|fax)$/i;
  var recent = {}, talk = [];
  function text(v, n) { return String(v == null ? '' : v).replace(/[\x00-\x1f\x7f]/g, ' ').replace(/\s+/g, ' ').trim().slice(0, n || 2000); }
  function label(e) { return text((e.labels && e.labels[0] && e.labels[0].textContent) || e.getAttribute('aria-label') || e.placeholder || e.title || '', 80).replace(/[*:]+$/g, ''); }
  function key(e) {
    var a = (e.getAttribute('autocomplete') || '').toLowerCase();
    if (a === 'email' || e.type === 'email') return 'email';
    if (a === 'tel' || e.type === 'tel') return 'phone';
    if (a === 'given-name') return 'first_name';
    if (a === 'family-name') return 'last_name';
    var k = text(e.name || e.id || '', 100);
    return k || label(e) || 'field';
  }
  function read(f) {
    var d = {}, contact = false, trap = false;
    for (var i = 0; i < f.elements.length; i++) {
      var e = f.elements[i], t = (e.type || '').toLowerCase(), n = e.name || e.id || '';
      if (t === 'password') return null;
      if (e.disabled || /^(submit|button|reset|image|file)$/.test(t) || PRIVATE.test(n) || /^cc-/.test(e.autocomplete || '')) continue;
      var v = '';
      if (t === 'hidden') { if (/^(utm_|gclid$|fbclid$)/i.test(n) && e.value) d[n] = text(e.value); continue; }
      if (t === 'checkbox' || t === 'radio') { if (e.checked) v = e.value && e.value !== 'on' ? e.value : label(e) || 'Yes'; }
      else if (e.tagName === 'SELECT') { for (var j = 0; j < e.options.length; j++) if (e.options[j].selected) v += (v ? ', ' : '') + text(e.options[j].text, 200); }
      else v = e.value;
      v = text(v);
      if (!v) continue;
      if (TRAP.test(n)) { trap = true; continue; }
      if (!e.offsetWidth && !e.offsetHeight && !e.getClientRects().length) { if (/^(url|website|company_?url|homepage)$/i.test(n)) trap = true; continue; }
      var k = key(e);
      d[k] = d[k] ? text(d[k] + ', ' + v) : v;
      if (t === 'email' || EMAIL.test(v) || t === 'tel' || (/^\+?[0-9\s().-]{7,22}$/.test(v) && v.replace(/\D/g, '').length >= 7)) contact = true;
    }
    return trap || !contact ? null : d;
  }
  function send(d) {
    var fingerprint = JSON.stringify(d), now = Date.now();
    if (recent[fingerprint] && now - recent[fingerprint] < 10000) return false;
    recent[fingerprint] = now;
    d.eventId = window.crypto && crypto.randomUUID ? crypto.randomUUID() : String(now) + Math.random().toString(16).slice(2);
    var body = JSON.stringify(d);
    try {
      if (window.fetch) {
        fetch(ENDPOINT, { method: 'POST', body: body, keepalive: true, mode: 'cors', credentials: 'omit', headers: { 'Content-Type': 'text/plain;charset=UTF-8' } }).catch(function () {});
        return true;
      }
    } catch (e) {}
    try { return navigator.sendBeacon(ENDPOINT, new Blob([body], { type: 'text/plain;charset=UTF-8' })); } catch (e) { return false; }
  }
  function context(d, f) {
    d.page_url = (location.origin + location.pathname).slice(0, 300);
    d.page_title = text(document.title, 150);
    if (document.referrer) d.referrer = text(document.referrer, 300);
    if (f) d.form_name = text(f.getAttribute('data-anibros-form') || f.getAttribute('aria-label') || f.id || f.name || '', 80);
    var qs = new URLSearchParams(location.search), keys = ['utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'gclid', 'fbclid'], saved = {};
    try { saved = JSON.parse(sessionStorage.getItem('anibros_utm') || '{}'); } catch (e) {}
    var changed = false;
    for (var i = 0; i < keys.length; i++) { var v = qs.get(keys[i]); if (v) { saved[keys[i]] = text(v, 200); changed = true; } if (saved[keys[i]] && !d[keys[i]]) d[keys[i]] = saved[keys[i]]; }
    if (changed) try { sessionStorage.setItem('anibros_utm', JSON.stringify(saved)); } catch (e) {}
    return d;
  }
  document.addEventListener('submit', function (e) {
    try {
      var f = e.target;
      if (!f || f.tagName !== 'FORM' || f.getAttribute('data-anibros') === 'ignore' || f.getAttribute('role') === 'search' || /(^|\s)no-crm(\s|$)/.test(f.className || '')) return;
      var d = read(f);
      if (d) send(context(d, f));
    } catch (err) {}
  }, true);
  window.AnibrosCRM = {
    loaded: true,
    say: function (who, line) { line = text(line, 600); if (!line) return; talk.push({ from: who === 'bot' ? 'bot' : 'visitor', text: line }); if (talk.length > 60) talk.shift(); },
    lead: function (fields) {
      if (!fields || typeof fields !== 'object') return false;
      var d = {};
      for (var k in fields) if (Object.prototype.hasOwnProperty.call(fields, k) && fields[k] != null && typeof fields[k] !== 'object') d[k] = text(fields[k]);
      if (talk.length && !d.conversation) d.conversation = talk.slice();
      d.form_name = d.form_name || 'chatbot';
      return send(context(d, null));
    }
  };
})();
JS;
        return str_replace('__ENDPOINT__', $literal, $script);
    }
}
