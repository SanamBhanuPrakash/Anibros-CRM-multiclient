<?php
declare(strict_types=1);

namespace App\Core\Mail;

use App\Core\Ids;

/** Builds a multipart/alternative (text + HTML) RFC 5322 message. Header values can never carry line breaks. */
final class Mime
{
    /**
     * Exactly one plain address. Anything a mail library could re-interpret (display names, groups, comments, several addresses,
     * line breaks) is refused, so a malformed value can never be turned into a different recipient.
     */
    public static function assertPlainAddress(string $to): void
    {
        if (strlen($to) > 254 || !preg_match("/^[A-Za-z0-9.!#$%&'*+\\/=?^_`{|}~-]{1,64}@[A-Za-z0-9](?:[A-Za-z0-9.-]{0,251}[A-Za-z0-9])?$/", $to)) {
            throw new \InvalidArgumentException('Refusing to send: recipient is not a single plain e-mail address');
        }
    }

    public static function addrOf(string $from): string
    {
        return preg_match('/<([^>]+)>/', $from, $m) ? $m[1] : trim($from);
    }

    public static function encodeHeader(string $s): string
    {
        $s = preg_replace('/[\r\n]+/', ' ', $s);
        if (preg_match('/^[\x20-\x7E]*$/', $s)) return $s;
        return '=?UTF-8?B?' . base64_encode($s) . '?=';
    }

    /**
     * @param array{fromName?:?string,replyTo?:?string,to:string,subject:string,html:string,text:string} $mail
     * @return array{headers:string,body:string,messageId:string}
     */
    public static function build(array $mail, string $fromHeaderDefault): array
    {
        self::assertPlainAddress($mail['to']);
        if (!empty($mail['replyTo'])) self::assertPlainAddress($mail['replyTo']);
        $addr = self::addrOf($fromHeaderDefault);
        $name = isset($mail['fromName']) ? trim(preg_replace('/\s+/', ' ', preg_replace('/[\r\n"<>]/', ' ', (string) $mail['fromName'])) ?? '') : '';
        $name = mb_substr($name, 0, 80);
        $from = $name !== '' ? self::encodeHeader($name) . ' <' . $addr . '>' : $fromHeaderDefault;
        $domain = substr(strrchr($addr, '@') ?: '@localhost', 1);
        $messageId = '<' . Ids::hex(12) . '@' . $domain . '>';
        $boundary = '=_' . Ids::hex(12);
        $h = [
            'Date: ' . gmdate('D, d M Y H:i:s') . ' +0000',
            'From: ' . $from,
            'To: ' . $mail['to'],
            'Subject: ' . self::encodeHeader($mail['subject']),
            'Message-ID: ' . $messageId,
            'MIME-Version: 1.0',
            'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
            'X-Mailer: Anibros CRM',
        ];
        if (!empty($mail['replyTo'])) $h[] = 'Reply-To: ' . $mail['replyTo'];
        $qp = static fn(string $s): string => quoted_printable_encode(str_replace(["\r\n", "\r"], "\n", $s));
        $body = "--$boundary\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: quoted-printable\r\n\r\n" . str_replace("\n", "\r\n", $qp($mail['text'])) . "\r\n"
            . "--$boundary\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: quoted-printable\r\n\r\n" . str_replace("\n", "\r\n", $qp($mail['html'])) . "\r\n"
            . "--$boundary--\r\n";
        return ['headers' => implode("\r\n", $h), 'body' => $body, 'messageId' => $messageId];
    }
}
