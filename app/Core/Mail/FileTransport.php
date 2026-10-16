<?php
declare(strict_types=1);

namespace App\Core\Mail;

use App\Core\Config;
use App\Core\Ids;

/**
 * Development transport: writes each message as an .eml file in storage/mail (the XAMPP test bed has no mail server).
 * Never selected in production (the installer requires SMTP there).
 */
final class FileTransport implements Transport
{
    public function name(): string
    {
        return 'file';
    }

    public function send(array $mail): array
    {
        $built = Mime::build($mail, (string) Config::get('mail.from', 'Anibros CRM <no-reply@localhost>'));
        $dir = rtrim((string) Config::get('storage_dir'), '/\\') . '/mail';
        if (!is_dir($dir)) @mkdir($dir, 0750, true);
        $file = $dir . '/' . gmdate('Ymd-His') . '-' . Ids::hex(3) . '.eml';
        file_put_contents($file, $built['headers'] . "\r\n\r\n" . $built['body']);
        // plain-text copy next to it, handy for grabbing the verification link
        file_put_contents(substr($file, 0, -4) . '.txt', 'To: ' . $mail['to'] . "\nSubject: " . $mail['subject'] . "\n\n" . $mail['text']);
        return ['messageId' => $built['messageId']];
    }
}
