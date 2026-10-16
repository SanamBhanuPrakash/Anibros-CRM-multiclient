<?php
declare(strict_types=1);

namespace App\Modules\Viewings;

use App\Core\Time;

/**
 * iCalendar (RFC 5545) export of one viewing, for Apple / Google / Outlook calendars.
 *
 * What goes in: when, where, which property and the buyer's NAME. What stays out: the buyer's phone and e-mail address. A calendar file is forwarded
 * and synced to other people's devices, so it carries no more personal data than the appointment itself needs. All text is escaped and lines are
 * folded, so a note containing line breaks or iCalendar syntax can never inject properties.
 */
final class Ics
{
    private const STATUS = ['scheduled' => 'TENTATIVE', 'confirmed' => 'CONFIRMED', 'completed' => 'CONFIRMED', 'no_show' => 'CONFIRMED', 'cancelled' => 'CANCELLED'];

    public static function date(\DateTimeInterface $d): string
    {
        return \DateTimeImmutable::createFromInterface($d)->setTimezone(new \DateTimeZone('UTC'))->format('Ymd\THis\Z');
    }

    /** TEXT value escaping per RFC 5545 §3.3.11, plus removal of control characters. */
    public static function escape(string $s): string
    {
        $s = (string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $s);
        $s = str_replace(['\\', ';', ','], ['\\\\', '\\;', '\\,'], $s);
        return (string) preg_replace('/\r\n|\r|\n/', '\\n', $s);
    }

    /** Fold a content line at 75 octets (RFC 5545 §3.1) without splitting a multi-byte character. */
    public static function fold(string $line): string
    {
        $out = [];
        $cur = '';
        $bytes = 0;
        foreach (preg_split('//u', $line, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $ch) {
            $b = strlen($ch);
            $limit = $out === [] ? 75 : 74; // continuation lines start with a space
            if ($bytes + $b > $limit) {
                $out[] = $cur;
                $cur = '';
                $bytes = 0;
            }
            $cur .= $ch;
            $bytes += $b;
        }
        $out[] = $cur;
        return implode("\r\n ", $out);
    }

    /** @param array{id:string,scheduledAt:string,durationMinutes:int,status:string,notes:?string,propertyTitle:string,propertyLocation:?string,buyerName:?string,agentName:?string,updatedAt:?string,version:int} $v */
    public static function build(array $v, ?\DateTimeImmutable $now = null): string
    {
        $now ??= Time::now();
        $start = Time::parse($v['scheduledAt']);
        $end = $start->modify('+' . (int) $v['durationMinutes'] . ' minutes');
        $summary = 'Site visit: ' . $v['propertyTitle'];
        $description = implode("\n", array_filter([$v['buyerName'] ? 'Buyer: ' . $v['buyerName'] : null, $v['agentName'] ? 'Agent: ' . $v['agentName'] : null, $v['notes'] ? 'Notes: ' . $v['notes'] : null]));
        $lines = [
            'BEGIN:VCALENDAR', 'VERSION:2.0', 'PRODID:-//Anibros CRM//Viewings//EN', 'CALSCALE:GREGORIAN', 'METHOD:PUBLISH', 'BEGIN:VEVENT',
            'UID:viewing-' . $v['id'] . '@anibros.crm',
            'DTSTAMP:' . self::date($now),
            'LAST-MODIFIED:' . self::date(Time::parse($v['updatedAt'] ?? null) ?? $now),
            'SEQUENCE:' . max(0, ((int) ($v['version'] ?? 1)) - 1),
            'DTSTART:' . self::date($start),
            'DTEND:' . self::date($end),
            'SUMMARY:' . self::escape($summary),
        ];
        if ($v['propertyLocation']) $lines[] = 'LOCATION:' . self::escape($v['propertyLocation']);
        if ($description !== '') $lines[] = 'DESCRIPTION:' . self::escape($description);
        array_push($lines, 'STATUS:' . (self::STATUS[$v['status']] ?? 'TENTATIVE'), 'BEGIN:VALARM', 'ACTION:DISPLAY', 'DESCRIPTION:' . self::escape($summary), 'TRIGGER:-PT1H', 'END:VALARM', 'END:VEVENT', 'END:VCALENDAR');
        return implode("\r\n", array_map([self::class, 'fold'], $lines)) . "\r\n";
    }
}
