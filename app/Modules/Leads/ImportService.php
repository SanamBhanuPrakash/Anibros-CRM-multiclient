<?php
declare(strict_types=1);

namespace App\Modules\Leads;

use App\Core\Actor;
use App\Core\AppError;
use App\Core\Audit;
use App\Core\Csv;
use App\Core\CsvError;
use App\Core\Db;
use App\Core\Errors;
use App\Core\Events;
use App\Core\JobContext;
use App\Core\Jobs;
use App\Core\Json;
use App\Core\Map;
use App\Core\PermanentJobError;
use App\Core\Raw;
use App\Core\Tx;
use App\Modules\Contacts\ContactsService;
use App\Modules\Contacts\Normalize;

/**
 * CSV lead import.
 *  API (ImportService): validates the upload, auto-detects which column is which, stores the file and queues a job: it never processes rows inside the
 *  HTTP request.
 *  Worker (leadsImportHandler): processes 100 rows per transaction. Progress is written in the SAME transaction as the rows it covers, so a crash or retry
 *  resumes at the last committed batch: every row is processed exactly once. A long file is continued by a follow-up job (the cron run has a time budget).
 *  Per-row problems never abort the file: they are counted and the first 200 are kept with their row number.
 */
final class ImportService
{
    public const FIELDS = ['firstName', 'lastName', 'fullName', 'email', 'phone', 'source', 'estimatedValue', 'budgetMin', 'budgetMax', 'bedroomsMin', 'locations', 'tags', 'campaign', 'ownerEmail', 'stage', 'notes'];
    private const MAX_BYTES = 5 * 1024 * 1024;
    private const BATCH = 100;
    private const BUDGET_SECONDS = 25;

    private const SYNONYMS = [
        'firstName' => ['first name', 'firstname', 'first', 'given name'],
        'lastName' => ['last name', 'lastname', 'surname', 'family name'],
        'fullName' => ['name', 'full name', 'fullname', 'customer name', 'lead name', 'contact name', 'client name'],
        'email' => ['email', 'e-mail', 'email address', 'mail'],
        'phone' => ['phone', 'mobile', 'mobile number', 'phone number', 'contact number', 'cell', 'whatsapp', 'telephone'],
        'source' => ['source', 'lead source', 'channel', 'origin'],
        'estimatedValue' => ['value', 'deal value', 'estimated value', 'amount', 'price'],
        'budgetMin' => ['budget min', 'min budget', 'budget from'],
        'budgetMax' => ['budget', 'budget max', 'max budget', 'budget to'],
        'bedroomsMin' => ['bedrooms', 'bhk', 'beds', 'bedroom'],
        'locations' => ['location', 'locations', 'locality', 'area', 'city', 'preferred location'],
        'tags' => ['tags', 'labels', 'tag'],
        'campaign' => ['campaign', 'campaign name', 'utm campaign', 'ad'],
        'ownerEmail' => ['owner', 'owner email', 'assigned to', 'agent', 'agent email', 'sales rep'],
        'stage' => ['stage', 'status', 'pipeline stage'],
        'notes' => ['notes', 'note', 'remarks', 'comments', 'comment', 'message'],
    ];

    private const SOURCE_ALIASES = [
        'website' => 'website', 'web' => 'website', 'web form' => 'website', 'form' => 'website', 'referral' => 'referral', 'reference' => 'referral', 'word of mouth' => 'referral',
        'portal' => 'portal', '99acres' => 'portal', 'magicbricks' => 'portal', 'housing' => 'portal', 'housing.com' => 'portal', 'direct' => 'direct', 'call' => 'direct', 'phone' => 'direct',
        'facebook' => 'meta_ads', 'instagram' => 'meta_ads', 'meta' => 'meta_ads', 'meta ads' => 'meta_ads', 'facebook ads' => 'meta_ads', 'google' => 'google_ads', 'google ads' => 'google_ads',
        'adwords' => 'google_ads', 'walk in' => 'walk_in', 'walk-in' => 'walk_in', 'walkin' => 'walk_in', 'other' => 'other',
    ];

    private static function norm(string $h): string
    {
        return trim((string) preg_replace('/\s+/', ' ', (string) preg_replace('/[_\-.]+/', ' ', mb_strtolower($h))));
    }

    /** Header => field, first match wins, each field used once. Returns what was understood so the UI can show / override it. @return array<string,string> */
    public static function detectMapping(array $header): array
    {
        $out = [];
        $used = [];
        $normalised = array_map([self::class, 'norm'], $header);
        foreach (self::FIELDS as $field) {
            foreach ($normalised as $i => $h) {
                if (isset($out[$header[$i]])) continue;
                foreach (self::SYNONYMS[$field] as $syn) {
                    if (self::norm($syn) === $h && !isset($used[$field])) { $out[$header[$i]] = $field; $used[$field] = true; break 2; }
                }
            }
        }
        return $out;
    }

    private const COLUMNS = 'id, kind, status, file_name AS fileName, total_rows AS totalRows, processed_rows AS processedRows, created_count AS createdCount, matched_count AS matchedCount,
        skipped_count AS skippedCount, error_count AS errorCount, errors, mapping, created_at AS createdAt, started_at AS startedAt, finished_at AS finishedAt';

    private static function dto(array $r): array
    {
        return Map::row($r, ['json' => ['errors' => []], 'obj' => ['mapping'], 'int' => ['totalRows', 'processedRows', 'createdCount', 'matchedCount', 'skippedCount', 'errorCount']]);
    }

    /** @param array{fileName?:?string,csv:string,mapping?:?array,onDuplicate?:?string,defaultSource?:?string,ownerId?:?string} $in */
    public static function create(Actor $actor, array $in): array
    {
        $actor->assertCan('leads:import');
        if (strlen($in['csv']) > self::MAX_BYTES) throw Errors::payloadTooLarge('Import files can be at most 5 MB (about 40,000 rows). Split the file and import it in parts.');
        try {
            $parsed = Csv::parse($in['csv'], 40000);
        } catch (CsvError $e) {
            throw Errors::validation([['path' => 'body.csv', 'message' => $e->getMessage()]]);
        }
        if (!$parsed['rows']) throw Errors::validation([['path' => 'body.csv', 'message' => 'The file has a header but no rows']]);

        $detected = self::detectMapping($parsed['header']);
        $mapping = $in['mapping'] ?? $detected;
        foreach (array_keys($mapping) as $h) if (!in_array($h, $parsed['header'], true)) throw Errors::validation([['path' => 'body.mapping', 'message' => "The file has no column called “{$h}”"]]);
        $fields = array_flip($mapping);
        $issues = [];
        if (!isset($fields['fullName']) && !isset($fields['firstName'])) $issues[] = ['path' => 'body.mapping', 'message' => 'No column was recognised as the person’s name. Map one to “fullName” or “firstName”.', 'code' => 'missing_name'];
        if (!isset($fields['email']) && !isset($fields['phone'])) $issues[] = ['path' => 'body.mapping', 'message' => 'No column was recognised as an email or phone number. Map at least one.', 'code' => 'missing_contact'];
        if ($issues) throw Errors::validation($issues, 'We couldn’t tell which columns are which. Found: ' . implode(', ', $parsed['header']));

        $sha = hash('sha256', $in['csv']);
        return Db::tenant($actor)->tx(static function (Tx $tx) use ($actor, $in, $parsed, $mapping, $sha, $detected) {
            try {
                $id = $tx->insert('import_jobs', [
                    'tenant_id' => $actor->tenantId, 'kind' => 'leads', 'file_name' => isset($in['fileName']) ? mb_substr($in['fileName'], 0, 200) : null, 'file_sha256' => $sha, 'payload_csv' => $in['csv'],
                    'mapping' => Json::encObj($mapping), 'options' => Json::enc(['onDuplicate' => $in['onDuplicate'] ?? 'skip', 'defaultSource' => $in['defaultSource'] ?? 'other', 'ownerId' => $in['ownerId'] ?? null]),
                    'total_rows' => count($parsed['rows']), 'errors' => '[]', 'created_by' => $actor->userId,
                ]);
            } catch (\PDOException $e) {
                if (($e->errorInfo[1] ?? 0) === 1062) throw Errors::conflict('This exact file is already being imported');
                throw $e;
            }
            Jobs::enqueue($tx, ['type' => 'leads.import', 'payload' => ['importId' => $id], 'dedupeKey' => "import:$id", 'maxAttempts' => 3]);
            Audit::log($tx, 'import.create', 'import', $id, null, ['rows' => count($parsed['rows']), 'fileName' => $in['fileName'] ?? null]);
            Events::emit($tx, 'import.updated', $id, ['status' => 'queued']);
            return self::dto($tx->one('SELECT ' . self::COLUMNS . ' FROM import_jobs WHERE id = ? AND tenant_id = ?', [$id, $actor->tenantId])) + ['detectedMapping' => Json::obj($detected)];
        });
    }

    public static function list(Actor $actor): array
    {
        $actor->assertCan('leads:import');
        return Db::tenant($actor)->read(static fn(Tx $tx) => array_map([self::class, 'dto'], $tx->all('SELECT ' . self::COLUMNS . ' FROM import_jobs WHERE tenant_id = ? ORDER BY created_at DESC, id DESC LIMIT 50', [$actor->tenantId])));
    }

    public static function get(Actor $actor, string $id): array
    {
        $actor->assertCan('leads:import');
        return Db::tenant($actor)->read(static function (Tx $tx) use ($actor, $id) {
            $r = $tx->one('SELECT ' . self::COLUMNS . ' FROM import_jobs WHERE id = ? AND tenant_id = ?', [$id, $actor->tenantId]);
            if (!$r) throw Errors::notFound('Import');
            return self::dto($r);
        });
    }

    // ── worker side ───────────────────────────────────────────────────────────────────────────────────────────────

    /** @return float|null|'bad' */
    private static function num(?string $v): float|null|string
    {
        $t = (string) preg_replace('/[,₹$\s]/u', '', (string) $v);
        $t = (string) preg_replace_callback('/^(\d+(?:\.\d+)?)(l|lac|lakh|lakhs)$/i', static fn($m) => (string) ((float) $m[1] * 100000), $t);
        $t = (string) preg_replace_callback('/^(\d+(?:\.\d+)?)(cr|crore|crores)$/i', static fn($m) => (string) ((float) $m[1] * 10000000), $t);
        if ($t === '') return null;
        if (!is_numeric($t)) return 'bad';
        $n = (float) $t;
        return ($n >= 0 && $n < 1e13) ? $n : 'bad';
    }

    /** Job handler for `leads.import`. @param array<string,mixed> $job */
    public static function handle(array $job, JobContext $ctx): array
    {
        try {
            return self::run($job, $ctx);
        } catch (\Throwable $e) {
            // Out of retries (or unrecoverable): say so on the import instead of leaving it "running" forever.
            if ($job['tenantId'] && ($e instanceof PermanentJobError || $job['attempts'] >= $job['maxAttempts'])) {
                try {
                    Db::tenant(['tenantId' => $job['tenantId']])->tx(static function (Tx $tx) use ($job, $e) {
                        $id = (string) $job['payload']['importId'];
                        $cur = (array) Json::dec((string) $tx->val('SELECT errors FROM import_jobs WHERE id = ? AND tenant_id = ?', [$id, $job['tenantId']]), []);
                        $cur[] = ['row' => 0, 'message' => mb_substr($e->getMessage(), 0, 300)];
                        $tx->run("UPDATE import_jobs SET status = 'failed', finished_at = NOW(3), payload_csv = NULL, errors = ? WHERE id = ? AND tenant_id = ?", [Json::enc(array_slice($cur, 0, 200)), $id, $job['tenantId']]);
                        Events::emit($tx, 'import.updated', $id, ['status' => 'failed']);
                    });
                } catch (\Throwable) {
                }
            }
            throw $e;
        }
    }

    private static function run(array $job, JobContext $ctx): array
    {
        $importId = (string) ($job['payload']['importId'] ?? '');
        $tenantId = $job['tenantId'];
        if (!$tenantId || $importId === '') throw new PermanentJobError('Malformed import job');
        $meta = Db::tenant(['tenantId' => $tenantId])->tx(static fn(Tx $tx) => $tx->one('SELECT * FROM import_jobs WHERE id = ? AND tenant_id = ?', [$importId, $tenantId]));
        if (!$meta) throw new PermanentJobError('Import not found');
        if ($meta['status'] === 'completed' || $meta['status'] === 'failed') return ['skipped' => true];
        if (!$meta['payload_csv']) throw new PermanentJobError('Import file is gone');

        $parsed = Csv::parse($meta['payload_csv'], 40000);
        $mapping = (array) Json::dec($meta['mapping'], []);
        $opts = (array) Json::dec($meta['options'], []) + ['onDuplicate' => 'skip', 'defaultSource' => 'other', 'ownerId' => null];
        $colOf = [];
        foreach ($mapping as $h => $f) $colOf[$f] = array_search($h, $parsed['header'], true);
        $cell = static fn(array $row, string $f): string => isset($colOf[$f]) && $colOf[$f] !== false ? trim((string) ($row[$colOf[$f]] ?? '')) : '';
        $by = $meta['created_by'];
        $scope = ['tenantId' => $tenantId, 'userId' => $by];

        Db::tenant($scope)->tx(static function (Tx $tx) use ($importId, $tenantId) {
            $tx->run("UPDATE import_jobs SET status = 'running', started_at = COALESCE(started_at, NOW(3)) WHERE id = ? AND tenant_id = ?", [$importId, $tenantId]);
            Events::emit($tx, 'import.updated', $importId, ['status' => 'running']);
        });

        $deadline = microtime(true) + self::BUDGET_SECONDS;
        $finished = false;
        while (true) {
            if (microtime(true) > $deadline) break;
            $done = Db::tenant($scope)->tx(function (Tx $tx) use ($importId, $tenantId, $parsed, $cell, $opts, $by) {
                $offset = (int) $tx->val('SELECT processed_rows FROM import_jobs WHERE id = ? AND tenant_id = ? FOR UPDATE', [$importId, $tenantId]);
                if ($offset >= count($parsed['rows'])) return true;
                $slice = array_slice($parsed['rows'], $offset, self::BATCH);
                $stats = ['created' => 0, 'matched' => 0, 'skipped' => 0, 'errors' => []];
                foreach ($slice as $i => $row) {
                    $rowNo = $offset + $i + 2; // 1-based and the header is row 1: matches what the user sees in a spreadsheet
                    $tx->run('SAVEPOINT import_row');
                    try {
                        self::importRow($tx, $row, $cell, $opts, $by, $tenantId, $stats);
                        $tx->run('RELEASE SAVEPOINT import_row');
                    } catch (\Throwable $e) {
                        $tx->run('ROLLBACK TO SAVEPOINT import_row');
                        $mapped = $e instanceof AppError ? $e : Errors::fromThrowable($e);
                        if ($mapped && $mapped->status < 500) {
                            $err = ['row' => $rowNo, 'message' => $mapped->errors[0]['message'] ?? $mapped->detail ?? $mapped->getMessage()];
                            if (isset($mapped->errors[0]['path'])) $err['field'] = preg_replace('/^body\./', '', $mapped->errors[0]['path']);
                            $stats['errors'][] = $err;
                        } else {
                            \App\Core\Logger::warn('Import row failed', ['error' => $e->getMessage()]);
                            $stats['errors'][] = ['row' => $rowNo, 'message' => 'Unexpected error while saving this row'];
                        }
                    }
                }
                $cur = (array) Json::dec((string) $tx->val('SELECT errors FROM import_jobs WHERE id = ? AND tenant_id = ?', [$importId, $tenantId]), []);
                $all = array_slice(array_merge($cur, array_slice($stats['errors'], 0, 200)), 0, 200);
                $tx->run(
                    'UPDATE import_jobs SET processed_rows = processed_rows + ?, created_count = created_count + ?, matched_count = matched_count + ?, skipped_count = skipped_count + ?, error_count = error_count + ?, errors = ? WHERE id = ? AND tenant_id = ?',
                    [count($slice), $stats['created'], $stats['matched'], $stats['skipped'], count($stats['errors']), Json::enc($all), $importId, $tenantId]
                );
                Events::emit($tx, 'import.updated', $importId, ['status' => 'running', 'processed' => $offset + count($slice)]);
                return $offset + count($slice) >= count($parsed['rows']);
            });
            if ($done) { $finished = true; break; }
        }

        if (!$finished) {
            // Out of time for this run: queue the continuation (progress is already committed) and let the next run resume.
            Db::tenant($scope)->tx(static function (Tx $tx) use ($importId, $tenantId) {
                $off = (int) $tx->val('SELECT processed_rows FROM import_jobs WHERE id = ? AND tenant_id = ?', [$importId, $tenantId]);
                Jobs::enqueue($tx, ['type' => 'leads.import', 'payload' => ['importId' => $importId], 'dedupeKey' => "import:$importId:$off", 'maxAttempts' => 3]);
            });
            return ['continued' => true];
        }

        Db::tenant($scope)->tx(static function (Tx $tx) use ($importId, $tenantId) {
            $tx->run("UPDATE import_jobs SET status = 'completed', finished_at = NOW(3), payload_csv = NULL WHERE id = ? AND tenant_id = ?", [$importId, $tenantId]);
            $r = $tx->one('SELECT created_count, matched_count, skipped_count, error_count FROM import_jobs WHERE id = ? AND tenant_id = ?', [$importId, $tenantId]);
            Audit::log($tx, 'import.complete', 'import', $importId, null, $r);
            Events::emit($tx, 'import.updated', $importId, ['status' => 'completed']);
        });
        return ['rows' => count($parsed['rows'])];
    }

    /** @param array{created:int,matched:int,skipped:int,errors:array} $stats */
    private static function importRow(Tx $tx, array $row, callable $cell, array $opts, ?string $by, string $tid, array &$stats): void
    {
        $actor = Actor::system($tid, $by);
        $issue = static fn(string $field, string $message) => Errors::validation([['path' => "body.$field", 'message' => $message]]);

        $first = $cell($row, 'firstName');
        $last = $cell($row, 'lastName');
        if ($first === '') {
            $parts = array_values(array_filter(preg_split('/\s+/', $cell($row, 'fullName')) ?: []));
            $first = (string) array_shift($parts);
            $last = $last !== '' ? $last : implode(' ', $parts);
        }
        if ($first === '') throw $issue('name', 'Name is empty');
        $emailRaw = $cell($row, 'email');
        $phoneRaw = $cell($row, 'phone');
        if ($emailRaw === '' && $phoneRaw === '') throw $issue('email', 'Needs an email address or a phone number');
        if ($emailRaw !== '' && !Normalize::email($emailRaw)) throw $issue('email', "“{$emailRaw}” is not a valid email address");
        if ($phoneRaw !== '' && !Normalize::phone($phoneRaw, 'IN')) throw $issue('phone', "“{$phoneRaw}” is not a valid phone number");

        $sourceRaw = mb_strtolower($cell($row, 'source'));
        $source = $sourceRaw === '' ? $opts['defaultSource'] : (in_array($sourceRaw, Routing::LEAD_SOURCES, true) ? $sourceRaw : (self::SOURCE_ALIASES[$sourceRaw] ?? 'other'));
        $nums = [];
        foreach (['estimatedValue', 'budgetMin', 'budgetMax', 'bedroomsMin'] as $k) {
            $v = self::num($cell($row, $k));
            if ($v === 'bad') throw $issue($k, '“' . $cell($row, $k) . '” is not a number');
            $nums[$k] = $v;
        }
        if ($nums['budgetMin'] !== null && $nums['budgetMax'] !== null && $nums['budgetMin'] > $nums['budgetMax']) throw $issue('budgetMin', 'Budget min is greater than budget max');

        $ownerId = $opts['ownerId'] ?? false; // false = not specified
        $ownerEmail = Normalize::email($cell($row, 'ownerEmail'));
        if ($ownerEmail) {
            $o = $tx->val("SELECT u.id FROM tenant_memberships tm JOIN users u ON u.id = tm.user_id WHERE tm.tenant_id = ? AND u.email = ? AND tm.status = 'active'", [$tid, $ownerEmail]);
            if (!$o) throw $issue('ownerEmail', "No active team member with email $ownerEmail");
            $ownerId = $o;
        }
        $stageId = null;
        $stageName = $cell($row, 'stage');
        if ($stageName !== '') {
            $stageId = $tx->val(
                "SELECT s.id FROM pipeline_stages s JOIN pipelines p ON p.id = s.pipeline_id AND p.tenant_id = s.tenant_id AND p.is_default = 1 AND p.archived_at IS NULL
                  WHERE s.tenant_id = ? AND s.archived_at IS NULL AND s.maps_to_status NOT IN ('won','lost','disqualified') AND (LOWER(s.name) = LOWER(?) OR s.stage_key = ?)",
                [$tid, $stageName, trim((string) preg_replace('/[^a-z0-9]+/', '_', mb_strtolower($stageName)), '')]
            );
            if (!$stageId) throw $issue('stage', "“{$stageName}” is not an open stage of your default pipeline");
        }
        $found = ContactsService::findOrCreateInTx($tx, ['tenantId' => $tid, 'userId' => $by], ['firstName' => $first, 'lastName' => $last !== '' ? $last : null, 'email' => $emailRaw !== '' ? $emailRaw : null, 'phone' => $phoneRaw !== '' ? $phoneRaw : null]);
        if (!$found['created']) $stats['matched']++;
        $input = [
            'contactId' => $found['id'], 'source' => $source, 'estimatedValue' => $nums['estimatedValue'], 'budgetMin' => $nums['budgetMin'], 'budgetMax' => $nums['budgetMax'],
            'bedroomsMin' => $nums['bedroomsMin'] !== null ? (int) $nums['bedroomsMin'] : null,
            'locations' => array_values(array_filter(array_map('trim', preg_split('/[;|]/', $cell($row, 'locations')) ?: []))),
            'tags' => Normalize::tags(preg_split('/[;|,]/', $cell($row, 'tags')) ?: []), 'campaign' => $cell($row, 'campaign') ?: null, 'allowDuplicate' => $opts['onDuplicate'] === 'create',
        ];
        if ($stageId) $input['stageId'] = $stageId;
        if ($ownerId !== false) $input['ownerId'] = $ownerId;
        try {
            $lead = LeadsService::createInTx($tx, $actor, $input);
            $stats['created']++;
            $notes = $cell($row, 'notes');
            if ($notes !== '') $tx->insert('activities', ['tenant_id' => $tid, 'lead_id' => $lead['id'], 'user_id' => $by, 'type' => 'note', 'description' => mb_substr($notes, 0, 2000), 'metadata' => '{"event":"import"}']);
        } catch (AppError $e) {
            if (($e->extensions['reason'] ?? null) === 'duplicate_lead') { $stats['skipped']++; return; }
            throw $e;
        }
    }
}
