<?php
declare(strict_types=1);

namespace App\Modules\Analytics;

use App\Core\Actor;
use App\Core\Audit;
use App\Core\AppError;
use App\Core\Csv;
use App\Core\CsvError;
use App\Core\Db;
use App\Core\Errors;
use App\Core\Ids;
use App\Core\Json;
use App\Core\Tx;

/**
 * Marketing spend, entered by hand or by CSV (decision D5; syncing from ad platforms needs accounts and comes later).
 * One figure per source, campaign and exact period; periods for the same source and campaign may not overlap, so money can never be counted twice.
 * A row without a campaign is spend for the whole source. Reports spread each entry evenly over its own days.
 */
final class SpendService
{
    private const COLUMNS = 's.id, s.source, s.campaign, s.period_start AS periodStart, s.period_end AS periodEnd, s.amount, s.currency, s.note, s.version, u.name AS enteredBy, s.created_at AS createdAt';
    private const FROM = 'marketing_spend s LEFT JOIN users u ON u.id = s.entered_by';

    public static function dto(array $r): array
    {
        return [
            'id' => $r['id'], 'source' => $r['source'], 'sourceLabel' => Channels::sourceLabel($r['source']), 'campaign' => $r['campaign'], 'periodStart' => $r['periodStart'], 'periodEnd' => $r['periodEnd'],
            'amount' => (float) $r['amount'], 'currency' => $r['currency'], 'note' => $r['note'], 'version' => (int) $r['version'], 'enteredBy' => $r['enteredBy'], 'createdAt' => $r['createdAt'],
        ];
    }

    private static function clean(?string $v): ?string
    {
        return $v !== null && trim($v) !== '' ? trim((string) preg_replace('/\s+/', ' ', $v)) : null;
    }

    public static function list(Actor $actor, array $q): array
    {
        $actor->assertCan('attribution:read');
        return Db::tenant($actor)->read(static function (Tx $tx) use ($actor, $q) {
            $params = [$actor->tenantId];
            $where = ['s.tenant_id = ?'];
            if (!empty($q['from'])) { $where[] = 's.period_end >= CAST(? AS DATE)'; $params[] = $q['from']; }
            if (!empty($q['to'])) { $where[] = 's.period_start <= CAST(? AS DATE)'; $params[] = $q['to']; }
            return array_map([self::class, 'dto'], $tx->all('SELECT ' . self::COLUMNS . ' FROM ' . self::FROM . ' WHERE ' . implode(' AND ', $where) . ' ORDER BY s.period_start DESC, s.id DESC LIMIT 500', $params));
        });
    }

    public static function create(Actor $actor, array $in): array
    {
        $actor->assertCan('attribution:manage');
        return Db::tenant($actor)->tx(static function (Tx $tx) use ($actor, $in) {
            $currency = self::workspaceCurrency($tx, $actor->tenantId);
            $id = self::insertRow($tx, $actor, $in, $currency, null);
            Audit::log($tx, 'spend.create', 'marketing_spend', $id, null, ['source' => $in['source'], 'campaign' => self::clean($in['campaign'] ?? null), 'periodStart' => $in['periodStart'], 'periodEnd' => $in['periodEnd'], 'amount' => $in['amount']]);
            return self::fetch($tx, $actor->tenantId, $id);
        });
    }

    public static function update(Actor $actor, string $id, array $patch, ?int $expectedVersion = null): array
    {
        $actor->assertCan('attribution:manage');
        return Db::tenant($actor)->tx(static function (Tx $tx) use ($actor, $id, $patch, $expectedVersion) {
            $cur = $tx->one('SELECT source, campaign, period_start AS ps, period_end AS pe, amount, version FROM marketing_spend WHERE id = ? AND tenant_id = ? FOR UPDATE', [$id, $actor->tenantId]);
            if (!$cur) throw Errors::notFound('Spend entry');
            if ($expectedVersion !== null && $expectedVersion !== (int) $cur['version']) throw Errors::preconditionFailed();
            $next = [
                'campaign' => array_key_exists('campaign', $patch) ? self::clean($patch['campaign']) : $cur['campaign'],
                'periodStart' => $patch['periodStart'] ?? $cur['ps'],
                'periodEnd' => $patch['periodEnd'] ?? $cur['pe'],
                'amount' => $patch['amount'] ?? (float) $cur['amount'],
            ];
            self::assertPeriod($next['periodStart'], $next['periodEnd']);
            self::lockSource($tx, $actor->tenantId);
            self::assertNoOverlap($tx, $actor->tenantId, $cur['source'], $next['campaign'], $next['periodStart'], $next['periodEnd'], $id);
            $set = ['campaign' => $next['campaign'], 'period_start' => $next['periodStart'], 'period_end' => $next['periodEnd'], 'amount' => $next['amount'], 'version' => new \App\Core\Raw('version + 1')];
            if (array_key_exists('note', $patch)) $set['note'] = self::clean($patch['note']);
            $tx->update('marketing_spend', $set, 'id = ? AND tenant_id = ?', [$id, $actor->tenantId]);
            Audit::log($tx, 'spend.update', 'marketing_spend', $id, ['campaign' => $cur['campaign'], 'periodStart' => $cur['ps'], 'periodEnd' => $cur['pe'], 'amount' => (float) $cur['amount']], $next);
            return self::fetch($tx, $actor->tenantId, $id);
        });
    }

    public static function remove(Actor $actor, string $id): void
    {
        $actor->assertCan('attribution:manage');
        Db::tenant($actor)->tx(static function (Tx $tx) use ($actor, $id) {
            $r = $tx->one('SELECT source, campaign, period_start AS ps, period_end AS pe, amount FROM marketing_spend WHERE id = ? AND tenant_id = ? FOR UPDATE', [$id, $actor->tenantId]);
            if (!$r) throw Errors::notFound('Spend entry');
            $tx->run('DELETE FROM marketing_spend WHERE id = ? AND tenant_id = ?', [$id, $actor->tenantId]);
            Audit::log($tx, 'spend.delete', 'marketing_spend', $id, ['source' => $r['source'], 'campaign' => $r['campaign'], 'ps' => $r['ps'], 'pe' => $r['pe'], 'amount' => (float) $r['amount']]);
        });
    }

    /**
     * CSV import. Columns (by header): source, campaign (optional), from, to, amount, note (optional). Dates are YYYY-MM-DD. The whole file is checked first and
     * nothing is saved if any row is wrong (money is not imported "mostly"). A row that repeats an entry already saved is skipped, so importing the same file
     * twice changes nothing. `dryRun` reports what would happen without saving.
     */
    public static function importCsv(Actor $actor, array $input): array
    {
        $actor->assertCan('attribution:manage');
        try {
            $parsed = Csv::parse($input['csv'], 1000, 12);
        } catch (CsvError $e) {
            throw Errors::validation([['path' => 'body.csv', 'message' => $e->getMessage()]]);
        }
        $col = static function (array $names) use ($parsed): int {
            foreach ($parsed['header'] as $i => $h) if (in_array(preg_replace('/[^a-z]/', '', strtolower($h)), $names, true)) return $i;
            return -1;
        };
        $idx = [
            'source' => $col(['source', 'channel', 'platform']), 'campaign' => $col(['campaign', 'campaignname']), 'from' => $col(['from', 'start', 'startdate', 'periodstart']),
            'to' => $col(['to', 'end', 'enddate', 'periodend']), 'amount' => $col(['amount', 'spend', 'cost']), 'note' => $col(['note', 'notes']),
        ];
        $missing = array_values(array_filter(['source', 'from', 'to', 'amount'], static fn($k) => $idx[$k] < 0));
        if ($missing) throw Errors::validation([['path' => 'body.csv', 'message' => 'The file needs these columns: ' . implode(', ', $missing) . ' (a header row naming them, e.g. source, campaign, from, to, amount)']]);
        if (!$parsed['rows']) throw Errors::validation([['path' => 'body.csv', 'message' => 'The file has a header but no rows']]);

        $errors = [];
        $rows = [];
        foreach ($parsed['rows'] as $i => $cells) {
            $row = $i + 2; // as a spreadsheet shows it: header is row 1
            $get = static fn(string $k) => $idx[$k] >= 0 ? trim($cells[$idx[$k]] ?? '') : '';
            $src = self::matchSource($get('source'));
            if (!$src) {
                $names = array_map(static fn($s) => Channels::SOURCE_LABEL[$s], array_filter(Channels::SOURCES, static fn($s) => $s !== 'other'));
                $errors[] = ['row' => $row, 'message' => 'Unknown source “' . $get('source') . '”. Use one of: ' . implode(', ', $names)];
                continue;
            }
            $from = $get('from');
            $to = $get('to');
            if (!self::isIsoDate($from) || !self::isIsoDate($to)) { $errors[] = ['row' => $row, 'message' => 'Dates must look like 2026-10-14']; continue; }
            try {
                self::assertPeriod($from, $to);
            } catch (AppError $e) {
                $errors[] = ['row' => $row, 'message' => $e->errors[0]['message'] ?? $e->detail ?? 'Invalid dates'];
                continue;
            }
            $amount = self::parseAmount($get('amount'));
            if ($amount === null) { $errors[] = ['row' => $row, 'message' => '“' . $get('amount') . '” is not an amount']; continue; }
            $campaign = self::clean($get('campaign'));
            if ($campaign !== null && mb_strlen($campaign) > 150) { $errors[] = ['row' => $row, 'message' => 'Campaign names are limited to 150 characters']; continue; }
            $note = self::clean($get('note'));
            $rows[] = ['row' => $row, 'input' => ['source' => $src, 'campaign' => $campaign, 'periodStart' => $from, 'periodEnd' => $to, 'amount' => $amount, 'note' => $note !== null ? mb_substr($note, 0, 300) : null]];
        }
        // overlaps inside the file itself
        for ($a = 0, $n = count($rows); $a < $n; $a++) {
            for ($b = $a + 1; $b < $n; $b++) {
                $x = $rows[$a]['input'];
                $y = $rows[$b]['input'];
                if ($x['source'] === $y['source'] && mb_strtolower($x['campaign'] ?? '') === mb_strtolower($y['campaign'] ?? '') && $x['periodStart'] <= $y['periodEnd'] && $y['periodStart'] <= $x['periodEnd']) {
                    $same = $x['periodStart'] === $y['periodStart'] && $x['periodEnd'] === $y['periodEnd'];
                    $errors[] = ['row' => $rows[$b]['row'], 'message' => $same ? "Repeats row {$rows[$a]['row']} (same source, campaign and dates)" : "Its dates overlap row {$rows[$a]['row']} for the same source and campaign"];
                }
            }
        }
        if ($errors) {
            $count = count($errors);
            throw Errors::validation(array_map(static fn($e) => ['path' => 'row ' . $e['row'], 'message' => $e['message']], array_slice($errors, 0, 50)), "$count row" . ($count === 1 ? '' : 's') . ' could not be imported; nothing was saved');
        }

        return Db::tenant($actor)->tx(static function (Tx $tx) use ($actor, $input, $rows) {
            $currency = self::workspaceCurrency($tx, $actor->tenantId);
            $batch = Ids::uuid();
            $preview = [];
            $created = 0;
            $skipped = 0;
            self::lockSource($tx, $actor->tenantId);
            foreach ($rows as $r) {
                $i = $r['input'];
                $same = $tx->exists(
                    "SELECT 1 FROM marketing_spend WHERE tenant_id = ? AND source = ? AND LOWER(COALESCE(campaign, '')) = LOWER(?) AND period_start = CAST(? AS DATE) AND period_end = CAST(? AS DATE)",
                    [$actor->tenantId, $i['source'], $i['campaign'] ?? '', $i['periodStart'], $i['periodEnd']]
                );
                $base = ['row' => $r['row'], 'source' => $i['source'], 'sourceLabel' => Channels::sourceLabel($i['source']), 'campaign' => $i['campaign'], 'periodStart' => $i['periodStart'], 'periodEnd' => $i['periodEnd'], 'amount' => $i['amount']];
                if ($same) { $skipped++; $preview[] = $base + ['status' => 'already_saved']; continue; }
                self::assertNoOverlap($tx, $actor->tenantId, $i['source'], $i['campaign'], $i['periodStart'], $i['periodEnd'], null, $r['row']);
                if (empty($input['dryRun'])) self::insertRow($tx, $actor, $i, $currency, $batch);
                $created++;
                $preview[] = $base + ['status' => 'new'];
            }
            if (empty($input['dryRun'])) Audit::log($tx, 'spend.import', 'marketing_spend', $batch, null, ['created' => $created, 'skipped' => $skipped]);
            return ['dryRun' => !empty($input['dryRun']), 'created' => $created, 'skipped' => $skipped, 'errors' => [], 'preview' => array_slice($preview, 0, 200)];
        });
    }

    private static function insertRow(Tx $tx, Actor $actor, array $i, string $currency, ?string $batch): string
    {
        self::assertPeriod($i['periodStart'], $i['periodEnd']);
        $campaign = self::clean($i['campaign'] ?? null);
        self::lockSource($tx, $actor->tenantId);
        self::assertNoOverlap($tx, $actor->tenantId, $i['source'], $campaign, $i['periodStart'], $i['periodEnd'], null);
        try {
            return (string) $tx->insert('marketing_spend', [
                'tenant_id' => $actor->tenantId, 'source' => $i['source'], 'campaign' => $campaign, 'period_start' => $i['periodStart'], 'period_end' => $i['periodEnd'], 'amount' => $i['amount'],
                'currency' => $currency, 'note' => self::clean($i['note'] ?? null), 'import_batch' => $batch, 'entered_by' => $actor->userId,
            ]);
        } catch (\PDOException $e) {
            if (($e->errorInfo[1] ?? 0) === 1062) throw Errors::conflict('There is already spend entered for this source, campaign and period. Edit that entry instead.', ['reason' => 'spend_overlaps']);
            throw $e;
        }
    }

    private static function fetch(Tx $tx, string $tenantId, string $id): array
    {
        $r = $tx->one('SELECT ' . self::COLUMNS . ' FROM ' . self::FROM . ' WHERE s.tenant_id = ? AND s.id = ?', [$tenantId, $id]);
        if (!$r) throw Errors::notFound('Spend entry');
        return self::dto($r);
    }

    // ── helpers ───────────────────────────────────────────────────────────────────────────────────────

    private static function isIsoDate(string $v): bool
    {
        return preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $v, $m) === 1 && checkdate((int) $m[2], (int) $m[3], (int) $m[1]);
    }

    private static function assertPeriod(string $from, string $to): void
    {
        if (!self::isIsoDate($from) || !self::isIsoDate($to)) throw Errors::validation([['path' => 'body.periodStart', 'message' => 'Dates must look like 2026-10-14']]);
        $days = (strtotime("$to 00:00:00 UTC") - strtotime("$from 00:00:00 UTC")) / 86400;
        if ($days < 0) throw Errors::validation([['path' => 'body.periodEnd', 'message' => 'The end date must not be before the start date']]);
        if ($days > 366) throw Errors::validation([['path' => 'body.periodEnd', 'message' => 'One entry can cover at most a year. Split longer periods.']]);
    }

    private static function parseAmount(string $v): ?float
    {
        $t = (string) preg_replace('/[,\s]/', '', (string) preg_replace('/(?:INR|USD|AED|EUR|GBP|Rs\.?|₹|\$|€|£)/iu', '', $v));
        if (preg_match('/^\d+(\.\d{1,2})?$/', $t) !== 1) return null;
        $n = (float) $t;
        return $n < 1e12 ? $n : null;
    }

    private static function matchSource(string $v): ?string
    {
        $k = (string) preg_replace('/[^a-z]/', '', strtolower(trim($v)));
        if ($k === '') return null;
        $aliases = ['meta' => 'meta_ads', 'facebook' => 'meta_ads', 'instagram' => 'meta_ads', 'metaads' => 'meta_ads', 'facebookads' => 'meta_ads', 'google' => 'google_ads', 'googleads' => 'google_ads', 'adwords' => 'google_ads', 'walkin' => 'walk_in', 'propertyportal' => 'portal', 'portals' => 'portal'];
        if (isset($aliases[$k])) return $aliases[$k];
        foreach (Channels::SOURCES as $s) {
            if ($s === 'other') continue;
            if (preg_replace('/[^a-z]/', '', $s) === $k || preg_replace('/[^a-z]/', '', strtolower(Channels::SOURCE_LABEL[$s])) === $k) return $s;
        }
        return null;
    }

    private static function workspaceCurrency(Tx $tx, string $tenantId): string
    {
        $raw = $tx->val('SELECT settings FROM tenants WHERE id = ?', [$tenantId]);
        $s = is_string($raw) ? (Json::dec($raw, [])['currency'] ?? null) : null;
        return is_string($s) && preg_match('/^[A-Z]{3}$/', $s) === 1 ? $s : 'INR';
    }

    /** Serialises spend writes for a workspace, so two people entering overlapping periods cannot both pass the overlap check. */
    private static function lockSource(Tx $tx, string $tenantId): void
    {
        $tx->val('SELECT id FROM tenants WHERE id = ? FOR UPDATE', [$tenantId]);
    }

    private static function assertNoOverlap(Tx $tx, string $tenantId, string $source, ?string $campaign, string $start, string $end, ?string $exceptId, ?int $row = null): void
    {
        $sql = "SELECT period_start AS ps, period_end AS pe FROM marketing_spend WHERE tenant_id = ? AND source = ? AND LOWER(COALESCE(campaign, '')) = LOWER(?) AND period_start <= CAST(? AS DATE) AND period_end >= CAST(? AS DATE)";
        $params = [$tenantId, $source, $campaign ?? '', $end, $start];
        if ($exceptId !== null) { $sql .= ' AND id <> ?'; $params[] = $exceptId; }
        $r = $tx->one($sql . ' LIMIT 1', $params);
        if ($r) {
            throw Errors::conflict(($row ? "Row $row: " : '') . 'There is already spend entered for ' . Channels::sourceLabel($source) . ($campaign ? " · $campaign" : '') . " from {$r['ps']} to {$r['pe']}. Edit that entry instead of adding one that overlaps it.", ['reason' => 'spend_overlaps']);
        }
    }
}
