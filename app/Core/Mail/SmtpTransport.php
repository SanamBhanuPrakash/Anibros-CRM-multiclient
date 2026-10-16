<?php
declare(strict_types=1);

namespace App\Core\Mail;

use App\Core\Config;

/**
 * Minimal SMTP client (RFC 5321): implicit TLS (port 465), STARTTLS (587) or plain, AUTH LOGIN / PLAIN.
 * Enough for a cPanel mailbox such as no-reply@yourdomain; no external library.
 */
final class SmtpTransport implements Transport
{
    /** @var resource|null */
    private $fp = null;

    /** @param array{host?:string,port?:int,user?:string,pass?:string,secure?:string,from?:string}|null $o */
    public function __construct(private ?array $o = null)
    {
        $this->o ??= (array) Config::get('mail');
    }

    public function name(): string
    {
        return 'smtp';
    }

    public function send(array $mail): array
    {
        $o = $this->o;
        $built = Mime::build($mail, (string) ($o['from'] ?? 'Anibros CRM <no-reply@localhost>'));
        $fromAddr = Mime::addrOf((string) ($o['from'] ?? 'no-reply@localhost'));
        $port = (int) ($o['port'] ?? 587);
        $secure = strtolower((string) ($o['secure'] ?? ''));
        if ($secure === '' && $port === 465) $secure = 'ssl';
        if ($secure === '' && $port === 587) $secure = 'tls';
        try {
            $this->connect((string) $o['host'], $port, $secure === 'ssl');
            $this->expect(220);
            $ehlo = $this->cmd('EHLO ' . (preg_replace('/[^A-Za-z0-9.-]/', '', (string) gethostname()) ?: 'localhost'), 250);
            if ($secure === 'tls') {
                $this->cmd('STARTTLS', 220);
                if (!@stream_socket_enable_crypto($this->fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) throw new \RuntimeException('SMTP: TLS negotiation failed');
                $ehlo = $this->cmd('EHLO ' . (preg_replace('/[^A-Za-z0-9.-]/', '', (string) gethostname()) ?: 'localhost'), 250);
            }
            if (!empty($o['user'])) {
                if (stripos($ehlo, 'AUTH') !== false && stripos($ehlo, 'PLAIN') !== false && stripos($ehlo, 'LOGIN') === false) {
                    $this->cmd('AUTH PLAIN ' . base64_encode("\0" . $o['user'] . "\0" . $o['pass']), 235);
                } else {
                    $this->cmd('AUTH LOGIN', 334);
                    $this->cmd(base64_encode((string) $o['user']), 334);
                    $this->cmd(base64_encode((string) $o['pass']), 235);
                }
            }
            $this->cmd('MAIL FROM:<' . $fromAddr . '>', 250);
            $this->cmd('RCPT TO:<' . $mail['to'] . '>', 250, 251);
            $this->cmd('DATA', 354);
            $data = $built['headers'] . "\r\n\r\n" . $built['body'];
            $data = preg_replace('/^\./m', '..', str_replace(["\r\n", "\r", "\n"], "\n", $data));
            $data = str_replace("\n", "\r\n", $data);
            fwrite($this->fp, $data . "\r\n.\r\n");
            $this->expect(250);
            $this->cmd('QUIT', 221);
        } finally {
            if ($this->fp) { @fclose($this->fp); $this->fp = null; }
        }
        return ['messageId' => $built['messageId']];
    }

    private function connect(string $host, int $port, bool $ssl): void
    {
        $ctx = stream_context_create(['ssl' => ['verify_peer' => true, 'verify_peer_name' => true, 'allow_self_signed' => false]]);
        $errno = 0;
        $err = '';
        $fp = @stream_socket_client(($ssl ? 'ssl://' : 'tcp://') . $host . ':' . $port, $errno, $err, 10, STREAM_CLIENT_CONNECT, $ctx);
        if (!$fp) throw new \RuntimeException("SMTP: cannot connect to $host:$port ($err)");
        stream_set_timeout($fp, 15);
        $this->fp = $fp;
    }

    private function read(): string
    {
        $resp = '';
        while (($line = fgets($this->fp, 1024)) !== false) {
            $resp .= $line;
            if (strlen($line) < 4 || $line[3] !== '-') break;
        }
        if ($resp === '') throw new \RuntimeException('SMTP: no response from server');
        return $resp;
    }

    private function expect(int ...$codes): string
    {
        $r = $this->read();
        if (!in_array((int) substr($r, 0, 3), $codes, true)) throw new \RuntimeException('SMTP: unexpected reply: ' . trim(substr($r, 0, 200)));
        return $r;
    }

    private function cmd(string $line, int ...$codes): string
    {
        fwrite($this->fp, $line . "\r\n");
        return $this->expect(...$codes);
    }
}
