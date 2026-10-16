<?php
declare(strict_types=1);

namespace App\Core;

use App\Core\Mail\FileTransport;
use App\Core\Mail\PhpMailTransport;
use App\Core\Mail\SmtpTransport;
use App\Core\Mail\Transport;

/**
 * Transactional e-mail.
 *
 *  - Callers enqueue a `mail.send` job INSIDE their transaction (a signup that rolls back sends no mail; an SMTP outage never fails the
 *    signup). The worker delivers with retries.
 *  - One-time secrets (verification / reset / invitation tokens) are stored encrypted in the job payload (AAD-bound to the recipient) and
 *    decrypted only inside the worker at send time.
 */
final class Mailer
{
    private static ?Transport $transport = null;

    public static function transport(): Transport
    {
        if (self::$transport) return self::$transport;
        $kind = strtolower((string) Config::get('mail.transport', ''));
        if ($kind === '') $kind = Config::get('mail.host') ? 'smtp' : (Config::isProduction() ? 'phpmail' : 'file');
        return self::$transport = match ($kind) {
            'smtp' => new SmtpTransport(),
            'phpmail' => new PhpMailTransport(),
            default => new FileTransport(),
        };
    }

    public static function setTransport(?Transport $t): void
    {
        self::$transport = $t;
    }

    /** @return array{transport:string,external:bool} */
    public static function status(): array
    {
        $t = self::transport();
        return ['transport' => $t->name(), 'external' => $t->name() !== 'file'];
    }

    // ── templates ─────────────────────────────────────────────────────────────────────────────────────────────────

    private static function esc(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private static function layout(string $title, string $bodyHtml, ?array $cta = null): string
    {
        $button = '';
        if ($cta) {
            $button = '<p style="margin:28px 0"><a href="' . self::esc($cta['url']) . '" style="background:#1f6f5c;color:#fff;text-decoration:none;padding:12px 20px;border-radius:8px;font-weight:600;display:inline-block">' . self::esc($cta['label']) . '</a></p>'
                . '<p style="color:#6b6b66;font-size:13px">If the button doesn’t work, paste this link into your browser:<br><span style="word-break:break-all">' . self::esc($cta['url']) . '</span></p>';
        }
        return '<!doctype html><html><body style="margin:0;background:#f6f5f0;font-family:system-ui,-apple-system,Segoe UI,Roboto,sans-serif;color:#1c1c1a">'
            . '<div style="max-width:520px;margin:32px auto;background:#fff;border:1px solid #e6e4dc;border-radius:12px;padding:32px">'
            . '<h1 style="font-size:20px;margin:0 0 16px">' . self::esc($title) . '</h1>' . $bodyHtml . $button
            . '<hr style="border:none;border-top:1px solid #eceae3;margin:28px 0 12px"><p style="color:#8a8a84;font-size:12px;margin:0">Anibros CRM</p></div></body></html>';
    }

    public static function baseUrl(): string
    {
        $c = (string) Config::get('app_url', '');
        return rtrim($c !== '' ? $c : 'http://localhost', '/');
    }

    /**
     * @param array{name?:string,tenantName?:string,inviterName?:string,role?:string} $data
     * @return array{to:string,subject:string,html:string,text:string}
     */
    public static function render(string $template, string $to, array $data, ?string $token = null): array
    {
        $base = self::baseUrl();
        $name = $data['name'] ?? null;
        $hello = $name ? 'Hi ' . self::esc($name) . ',' : 'Hi,';
        switch ($template) {
            case 'verify_email':
                $url = "$base/verify-email?token=$token";
                return [
                    'to' => $to, 'subject' => 'Verify your email — Anibros CRM',
                    'html' => self::layout('Confirm your email address', "<p>$hello</p><p>Welcome to Anibros CRM. Confirm your email to activate your workspace. This link expires in 24 hours.</p>", ['label' => 'Verify email', 'url' => $url]),
                    'text' => ($name ? "Hi $name,\n\n" : '') . "Confirm your email to activate your Anibros CRM workspace (link valid 24 hours):\n$url\n",
                ];
            case 'password_reset':
                $url = "$base/reset-password?token=$token";
                return [
                    'to' => $to, 'subject' => 'Reset your password — Anibros CRM',
                    'html' => self::layout('Reset your password', "<p>$hello</p><p>We received a request to reset your password. This link expires in 1 hour. If you didn’t ask for this, you can ignore this email — your password will not change.</p>", ['label' => 'Choose a new password', 'url' => $url]),
                    'text' => "Reset your Anibros CRM password (link valid 1 hour). If you didn't request this, ignore this email.\n$url\n",
                ];
            case 'password_changed':
                return [
                    'to' => $to, 'subject' => 'Your password was changed — Anibros CRM',
                    'html' => self::layout('Password changed', "<p>$hello</p><p>The password for your Anibros CRM account was just changed and all other sessions were signed out. If this wasn’t you, reset your password immediately and contact your workspace admin.</p>"),
                    'text' => "The password for your Anibros CRM account was just changed and other sessions were signed out. If this wasn't you, reset it immediately.\n",
                ];
            case 'account_exists':
                $url = "$base/forgot-password";
                return [
                    'to' => $to, 'subject' => 'You already have an Anibros CRM account',
                    'html' => self::layout('You already have an account', "<p>$hello</p><p>Someone (hopefully you) tried to sign up with this email address, but an account already exists. You can sign in, or reset your password if you’ve forgotten it.</p>", ['label' => 'Reset password', 'url' => $url]),
                    'text' => "An Anibros CRM account already exists for this email. Sign in, or reset your password: $url\n",
                ];
            case 'invitation':
                $url = "$base/accept-invite?token=$token";
                $inviter = $data['inviterName'] ?? 'A teammate';
                $tenant = $data['tenantName'] ?? 'a workspace';
                $role = str_replace('_', ' ', $data['role'] ?? 'member');
                return [
                    'to' => $to, 'subject' => "$inviter invited you to $tenant on Anibros CRM",
                    'html' => self::layout('Join ' . ($data['tenantName'] ?? 'the workspace'), "<p>$hello</p><p>" . self::esc($inviter) . ' invited you to join <strong>' . self::esc($tenant) . '</strong> as <strong>' . self::esc($role) . '</strong>. The invitation expires in 7 days.</p>', ['label' => 'Accept invitation', 'url' => $url]),
                    'text' => "$inviter invited you to $tenant on Anibros CRM (valid 7 days):\n$url\n",
                ];
        }
        throw new \InvalidArgumentException("Unknown mail template $template");
    }

    // ── enqueue + worker handler ──────────────────────────────────────────────────────────────────────────────────

    /** Queue an e-mail from inside a transaction (platform job: no workspace). */
    public static function enqueue(Tx $tx, string $template, string $to, array $data = [], ?string $token = null, ?string $dedupeKey = null): void
    {
        $to = strtolower($to);
        $payload = ['template' => $template, 'to' => $to, 'data' => $data ?: new \stdClass()];
        if ($token !== null) $payload['tokenEnc'] = Crypto::encrypt($token, 'mail:' . $to);
        Jobs::enqueue($tx, ['type' => 'mail.send', 'tenantId' => null, 'payload' => $payload, 'dedupeKey' => $dedupeKey, 'maxAttempts' => 6, 'priority' => 10]);
    }

    /** @param array<string,mixed> $job */
    public static function handle(array $job): array
    {
        $p = $job['payload'];
        if (empty($p['to']) || empty($p['template'])) throw new PermanentJobError('Malformed mail job');
        $token = null;
        if (!empty($p['tokenEnc'])) {
            try {
                $token = Crypto::decrypt($p['tokenEnc'], 'mail:' . $p['to']);
            } catch (\Throwable) {
                throw new PermanentJobError('Mail token failed authentication');
            }
        }
        $mail = self::render($p['template'], $p['to'], (array) ($p['data'] ?? []), $token);
        $res = self::transport()->send($mail);
        return ['messageId' => $res['messageId'] ?? null, 'transport' => self::transport()->name()];
    }
}
