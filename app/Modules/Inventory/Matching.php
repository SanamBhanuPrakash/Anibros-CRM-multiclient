<?php
declare(strict_types=1);

namespace App\Modules\Inventory;

use App\Core\Actor;
use App\Core\Db;
use App\Core\Tx;

/**
 * Property matching (rule-based, explainable). This is NOT AI: fixed weights, every point is explained in `matchReasons`.
 * Weights: budget 40, location 25, bedrooms 20, type 15 (neutral credit when a criterion is not given, so a vague search still ranks).
 * Only live (non-archived) properties that are currently available are considered.
 */
final class Matching
{
    /**
     * @param array{price:mixed,currency:string,location:?string,bedrooms:?int,property_type:?string} $row
     * @param array<string,mixed> $c budgetMin, budgetMax, location, propertyType, bedrooms
     * @return array{score:int,reasons:array<int,string>}
     */
    public static function score(array $row, array $c): array
    {
        $score = 0;
        $reasons = [];
        $price = (float) $row['price'];
        $num = static fn(float $n): string => rtrim(rtrim(number_format($n, 2, '.', ''), '0'), '.');

        if (isset($c['budgetMin']) || isset($c['budgetMax'])) {
            $min = $c['budgetMin'] ?? 0;
            $max = $c['budgetMax'] ?? INF;
            if ($price >= $min && $price <= $max) {
                $score += 40;
                $reasons[] = "Price ({$num($price)} {$row['currency']}) is within requested budget range";
            } elseif ((isset($c['budgetMin']) && $price >= $c['budgetMin'] * 0.85 && $price < $c['budgetMin']) || (isset($c['budgetMax']) && $price <= $c['budgetMax'] * 1.15 && $price > $c['budgetMax'])) {
                $score += 20;
                $reasons[] = "Price ({$num($price)} {$row['currency']}) is within 15% margin of budget";
            }
        } else {
            $score += 20;
        }

        $loc = isset($c['location']) ? trim((string) $c['location']) : null;
        if ($loc !== null && $loc !== '') {
            if ($row['location'] !== null && mb_stripos($row['location'], $loc) !== false) {
                $score += 25;
                $reasons[] = "Location matches preferred area '$loc' (found: {$row['location']})";
            }
        } elseif (!array_key_exists('location', $c) || $c['location'] === null) {
            $score += 15;
        }

        if (isset($c['bedrooms']) && $c['bedrooms'] > 0) {
            $beds = (int) ($row['bedrooms'] ?? 0);
            if ($row['bedrooms'] !== null && $beds === (int) $c['bedrooms']) {
                $score += 20;
                $reasons[] = "Exact bedroom match: {$c['bedrooms']} BHK";
            } elseif (abs($beds - (int) $c['bedrooms']) === 1) {
                $score += 10;
                $reasons[] = "Close bedroom count: property has {$beds} BHK";
            }
        } else {
            $score += 10;
        }

        $type = isset($c['propertyType']) ? trim((string) $c['propertyType']) : '';
        if ($type !== '') {
            if ($row['property_type'] !== null && strtolower($row['property_type']) === strtolower($type)) {
                $score += 15;
                $reasons[] = "Property type matches: $type";
            }
        } else {
            $score += 10;
        }

        return ['score' => min(100, (int) round($score)), 'reasons' => $reasons];
    }

    public static function match(Actor $actor, array $c, int $limit = 25): array
    {
        $actor->assertCan('matching:use');
        return Db::tenant($actor)->read(static function (Tx $tx) use ($actor, $c, $limit) {
            $params = [$actor->tenantId];
            $where = ['tenant_id = ?', 'deleted_at IS NULL', "status = 'available'"];
            if (!empty($c['listingType'])) { $where[] = 'listing_type = ?'; $params[] = $c['listingType']; }
            $rows = $tx->all(
                "SELECT id, project_id, title, description, property_type, listing_type, status, price, currency, bedrooms, bathrooms, area_sqft,
                        COALESCE(location, NULLIF(CONCAT_WS(', ', locality, city), '')) AS location
                   FROM properties WHERE " . implode(' AND ', $where) . ' ORDER BY created_at DESC, id DESC LIMIT 2000',
                $params
            );
            $matches = [];
            foreach ($rows as $row) {
                $row['price'] = (float) $row['price'];
                $row['bedrooms'] = $row['bedrooms'] !== null ? (int) $row['bedrooms'] : null;
                $s = self::score($row, $c);
                $matches[] = [
                    'property' => [
                        'id' => $row['id'], 'title' => $row['title'], 'description' => $row['description'], 'propertyType' => $row['property_type'], 'listingType' => $row['listing_type'],
                        'status' => $row['status'], 'price' => $row['price'], 'currency' => $row['currency'], 'bedrooms' => $row['bedrooms'],
                        'bathrooms' => $row['bathrooms'] !== null ? (int) $row['bathrooms'] : null, 'areaSqft' => $row['area_sqft'] !== null ? (float) $row['area_sqft'] : null, 'location' => $row['location'],
                    ],
                    'score' => $s['score'], 'matchReasons' => $s['reasons'],
                ];
            }
            // stable: equal scores keep the newest-first order
            $i = 0;
            foreach ($matches as &$m) $m['_i'] = $i++;
            unset($m);
            usort($matches, static fn($a, $b) => $b['score'] <=> $a['score'] ?: $a['_i'] <=> $b['_i']);
            $matches = array_map(static function ($m) { unset($m['_i']); return $m; }, array_slice($matches, 0, $limit));
            return ['matches' => $matches];
        });
    }
}
