<?php
declare(strict_types=1);

namespace App\Modules\Tasks;

/**
 * Recurring tasks, in the workspace's time zone. "Every day at 10:00" must stay 10:00 on the wall clock across a daylight-saving change, so the
 * arithmetic is done on local calendar fields (PHP's DateTime does that natively), not by adding 86 400 s. Months clamp to the last day
 * ("monthly" from 31 Jan is 28/29 Feb, then 31 Mar again, never 3 Mar).
 */
final class Recurrence
{
    private static function zone(string $tz): \DateTimeZone
    {
        try {
            return new \DateTimeZone($tz);
        } catch (\Exception) {
            return new \DateTimeZone('UTC');
        }
    }

    /** 00:00 of the local day containing $instant. */
    public static function startOfDay(\DateTimeInterface $instant, string $tz): \DateTimeImmutable
    {
        return \DateTimeImmutable::createFromInterface($instant)->setTimezone(self::zone($tz))->setTime(0, 0, 0);
    }

    public static function add(\DateTimeInterface $instant, string $tz, string $freq, int $n): \DateTimeImmutable
    {
        $z = self::zone($tz);
        $l = \DateTimeImmutable::createFromInterface($instant)->setTimezone($z);
        if ($freq === 'monthly') {
            $total = (int) $l->format('Y') * 12 + ((int) $l->format('n') - 1) + $n;
            $y = intdiv($total, 12);
            $mo = $total % 12 + 1;
            $d = min((int) $l->format('j'), (int) (new \DateTimeImmutable(sprintf('%04d-%02d-01', $y, $mo), $z))->format('t'));
            return $l->setDate($y, $mo, $d);
        }
        $days = $freq === 'weekly' ? 7 * $n : $n;
        return $l->modify(($days >= 0 ? '+' : '') . $days . ' days');
    }

    /**
     * The due date of the occurrence that follows $due. A task completed late does not create a backlog of already-missed occurrences:
     * the next one is the first slot that is still in the future.
     *
     * @param array{freq:string,interval:int} $rule
     */
    public static function next(\DateTimeInterface $due, array $rule, string $tz, ?\DateTimeInterface $now = null): \DateTimeImmutable
    {
        $now ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $next = self::add($due, $tz, $rule['freq'], (int) $rule['interval']);
        for ($i = 0; $i < 400 && $next->getTimestamp() <= $now->getTimestamp(); $i++) $next = self::add($next, $tz, $rule['freq'], (int) $rule['interval']);
        return $next;
    }
}
