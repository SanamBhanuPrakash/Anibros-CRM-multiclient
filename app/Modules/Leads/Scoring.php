<?php
declare(strict_types=1);

namespace App\Modules\Leads;

/**
 * Explainable lead scoring (0-100). Rule-based on purpose: a sales manager can read why a lead scores 62 and argue with it. Every point comes
 * from a named factor and is stored with the lead (`score_breakdown`) so the UI can show it.
 *
 *   contactability  can we reach them                           up to 15
 *   intent          budget / requirements captured              up to 20
 *   source          how well this channel converts              up to 15
 *   progress        how far down the pipeline (stage odds)      up to 30
 *   engagement      recent touch                                up to 15
 *   staleness       open and ignored                            -10 / -20
 * Closed leads are fixed: won = 100, lost / disqualified = 0.
 */
final class Scoring
{
    public const DEFAULT_SOURCE_WEIGHTS = ['referral' => 15, 'walk_in' => 15, 'direct' => 10, 'website' => 8, 'portal' => 6, 'google_ads' => 6, 'meta_ads' => 4, 'other' => 2];

    /**
     * @param array{status:string,stageProbability:int|float,source:string,hasPhone:bool,hasEmail:bool,estimatedValue:?float,budgetMax:?float,hasRequirements:bool,lastActivityAt:?\DateTimeInterface,createdAt:\DateTimeInterface} $in
     * @param array{sourceWeights?:array<string,int|float>} $cfg
     * @return array{score:int,factors:array<int,array{factor:string,points:int,note:string}>}
     */
    public static function compute(array $in, array $cfg = [], ?\DateTimeInterface $now = null): array
    {
        $now ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        if ($in['status'] === 'won') return ['score' => 100, 'factors' => [['factor' => 'outcome', 'points' => 100, 'note' => 'Won']]];
        if ($in['status'] === 'lost' || $in['status'] === 'disqualified') return ['score' => 0, 'factors' => [['factor' => 'outcome', 'points' => 0, 'note' => $in['status'] === 'lost' ? 'Lost' : 'Disqualified']]];

        $factors = [];
        $add = static function (string $factor, int $points, string $note) use (&$factors): void {
            if ($points !== 0) $factors[] = ['factor' => $factor, 'points' => $points, 'note' => $note];
        };
        $clamp = static fn($n, $lo, $hi) => min($hi, max($lo, $n));
        $join = static fn(array $parts) => implode(' + ', array_filter($parts));

        $add('contactability', ($in['hasPhone'] ? 10 : 0) + ($in['hasEmail'] ? 5 : 0), $join([$in['hasPhone'] ? 'phone' : '', $in['hasEmail'] ? 'email' : '']) ?: 'no way to reach them yet');
        $intent = ($in['budgetMax'] ? 10 : 0) + ($in['hasRequirements'] ? 5 : 0) + ($in['estimatedValue'] ? 5 : 0);
        $add('intent', $intent, $join([$in['budgetMax'] ? 'budget' : '', $in['hasRequirements'] ? 'requirements' : '', $in['estimatedValue'] ? 'deal value' : '']) ?: 'nothing captured yet');

        $weights = array_merge(self::DEFAULT_SOURCE_WEIGHTS, $cfg['sourceWeights'] ?? []);
        $add('source', (int) $clamp((int) round((float) ($weights[$in['source']] ?? $weights['other'] ?? 2)), 0, 15), 'via ' . str_replace('_', ' ', $in['source']));
        $add('progress', (int) round($clamp((float) $in['stageProbability'], 0, 100) * 0.3), 'stage odds ' . $in['stageProbability'] . '%');

        $ref = $in['lastActivityAt'] ?? $in['createdAt'];
        $idleDays = ($now->getTimestamp() - $ref->getTimestamp()) / 86400;
        if ($in['lastActivityAt'] && $idleDays <= 3) $add('engagement', 15, 'touched in the last 3 days');
        elseif ($in['lastActivityAt'] && $idleDays <= 14) $add('engagement', 8, 'touched in the last 2 weeks');
        if ($idleDays > 30) $add('staleness', -20, 'no activity for ' . (int) floor($idleDays) . ' days');
        elseif ($idleDays > 14) $add('staleness', -10, 'no activity for ' . (int) floor($idleDays) . ' days');

        $score = (int) $clamp(array_sum(array_column($factors, 'points')), 0, 100);
        return ['score' => $score, 'factors' => $factors];
    }
}
