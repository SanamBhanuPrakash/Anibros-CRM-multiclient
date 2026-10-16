<?php
declare(strict_types=1);

namespace App\Core\Mail;

use App\Core\Config;

/** Fallback for hosts where SMTP credentials are not configured: PHP's mail() (cPanel's local sendmail). */
final class PhpMailTransport implements Transport
{
    public function name(): string
    {
        return 'phpmail';
    }

    public function send(array $mail): array
    {
        $built = Mime::build($mail, (string) Config::get('mail.from', 'Anibros CRM <no-reply@localhost>'));
        // mail() adds To and Subject itself: strip them from the header block
        $headers = implode("\r\n", array_values(array_filter(explode("\r\n", $built['headers']), static fn($l) => !str_starts_with($l, 'To: ') && !str_starts_with($l, 'Subject: '))));
        $ok = mail($mail['to'], Mime::encodeHeader($mail['subject']), $built['body'], $headers, '-f' . Mime::addrOf((string) Config::get('mail.from', 'no-reply@localhost')));
        if (!$ok) throw new \RuntimeException('mail() refused the message');
        return ['messageId' => $built['messageId']];
    }
}
