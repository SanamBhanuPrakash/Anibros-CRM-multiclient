<?php
declare(strict_types=1);

namespace App\Modules\Leads;

use App\Core\Actor;
use App\Core\AppError;
use App\Core\Audit;
use App\Core\Csv;
use App\Core\Db;
use App\Core\Errors;
use App\Core\Events;
use App\Core\Json;
use App\Core\Map;
use App\Core\Notify;
use App\Core\Pagination;
use App\Core\Raw;
use App\Core\Time;
use App\Core\Tx;
use App\Modules\Analytics\Touches;
use App\Modules\Contacts\ContactsService;
use App\Modules\Contacts\Normalize;
use App\Modules\CustomFields\CustomFields;
use App\Modules\CustomFields\FieldRules;
use App\Modules\Deals\Ledger;
use App\Modules\Tasks\Recurrence;
use App\Modules\Tasks\TasksService;

/**
 * Leads: list / create / update / move through the pipeline / assign / claim / handlers / archive / bulk / timeline / board / forecast / export.
 * Every stage change, from anywhere (API, bulk, import, automation), goes through moveInTx so rules, history, activity, audit, events and the score stay consistent.
 * The PostgreSQL triggers that kept stage_key / status / closed_at in step with the stage, bumped the version and snapshotted the stage history live here.
 */
final class LeadsService
{
    private const FAR = '9999-12-31 23:59:59.999';

    private const COLUMNS = "l.id, l.contact_id AS contactId, c.display_name AS contactName, c.email AS contactEmail, c.phone AS contactPhone,
        l.pipeline_id AS pipelineId, l.stage_id AS stageId, s.name AS stageName, s.stage_key AS stageKey, s.color AS stageColor, s.probability AS stageProbability,
        l.status, l.owner_id AS ownerId, l.source, l.lead_score AS leadScore, l.score_breakdown AS scoreBreakdown,
        l.estimated_value AS estimatedValue, l.currency, l.budget_min AS budgetMin, l.budget_max AS budgetMax,
        l.bedrooms_min AS bedroomsMin, l.locations, l.property_types AS propertyTypes, l.listing_type AS listingType,
        l.tags, l.campaign, l.custom_fields AS customFields, l.loss_reason AS lossReason, l.loss_reason_code AS lossReasonCode, l.closed_at AS closedAt, l.assigned_at AS assignedAt,
        l.next_followup_at AS nextFollowupAt, l.last_activity_at AS lastActivityAt, l.first_response_due_at AS _due, l.first_response_at AS _resp,
        l.version, l.created_at AS createdAt, l.updated_at AS updatedAt";
    private const FROM = 'leads l LEFT JOIN contacts c ON c.id = l.contact_id AND c.tenant_id = l.tenant_id JOIN pipeline_stages s ON s.id = l.stage_id AND s.tenant_id = l.tenant_id';

    // ── representation ────────────────────────────────────────────────────────────────────────────────────────────

    /** @return array{state:string,dueAt:?string} */
    public static function slaOf(?string $due, ?string $responded, bool $closed, string $createdAt, ?int $nowMs = null): array
    {
        $dueIso = $due !== null ? Time::iso($due) : null;
        if ($responded) return ['state' => 'met', 'dueAt' => $dueIso];
        if (!$due || $closed) return ['state' => 'none', 'dueAt' => $dueIso];
        $dueMs = (int) Time::ms($due);
        $total = max(60000, $dueMs - (int) Time::ms($createdAt));
        $left = $dueMs - ($nowMs ?? Time::nowMs());
        $state = $left < 0 ? 'breached' : ($left < min($total * 0.25, 600000) ? 'at_risk' : 'ok');
        return ['state' => $state, 'dueAt' => $dueIso];
    }

    /** @param array<string,mixed> $r @return array<string,mixed> */
    public static function dto(array $r): array
    {
        $due = $r['_due']; $resp = $r['_resp'];
        unset($r['_due'], $r['_resp']);
        $r = Map::row($r, [
            'json' => ['scoreBreakdown' => [], 'locations' => [], 'propertyTypes' => [], 'tags' => []], 'obj' => ['customFields'],
            'int' => ['stageProbability', 'leadScore', 'bedroomsMin', 'version'], 'float' => ['estimatedValue', 'budgetMin', 'budgetMax'],
        ]);
        $r['sla'] = self::slaOf($due, $resp, $r['closedAt'] !== null, $r['createdAt']);
        return $r;
    }

    public static function fetch(Tx $tx, string $tenantId, string $id): ?array
    {
        $r = $tx->one('SELECT ' . self::COLUMNS . ' FROM ' . self::FROM . ' WHERE l.id = ? AND l.tenant_id = ? AND l.deleted_at IS NULL', [$id, $tenantId]);
        return $r ? self::dto($r) : null;
    }

    // ── settings ──────────────────────────────────────────────────────────────────────────────────────────────────

    /** @return array{assignmentMode:string,firstResponseMinutes:int,scoring:array,routing:array} */
    public static function settings(Tx $tx, string $tenantId): array
    {
        $s = Json::dec((string) $tx->val('SELECT settings FROM tenants WHERE id = ?', [$tenantId]), []) ?: [];
        $mode = $s['leadAssignment'] ?? null;
        $mins = $s['sla']['firstResponseMinutes'] ?? null;
        $sw = $s['scoring']['sourceWeights'] ?? null;
        return [
            'assignmentMode' => ($mode === 'balanced' || $mode === 'unassigned') ? $mode : 'creator',
            'firstResponseMinutes' => (is_numeric($mins) && $mins >= 0 && $mins <= 10080) ? (int) $mins : 30,
            'scoring' => ['sourceWeights' => is_array($sw) ? $sw : null],
            'routing' => Routing::parse($s['routing'] ?? null),
        ];
    }

    // ── reads ─────────────────────────────────────────────────────────────────────────────────────────────────────

    /**
     * The one place list / export / agency-wide filters become SQL. Appends positional parameters to $params; the first condition is the workspace.
     * Visibility (own / all) is applied here.
     *
     * @param array<string,mixed> $f
     * @param array<int,mixed> $params
     * @return string[] conditions to AND together
     */
    public static function whereFor(Tx $tx, Actor $actor, array $f, array &$params): array
    {
        // The pool replaces "only my leads" with "only leads nobody has taken", so a caller never sees someone else's lead through it.
        $pool = !empty($f['pool']) && $actor->kind === 'user' && $actor->scopeIfAllowed('leads:claim') !== null;
        $params[] = $actor->tenantId;
        if ($pool) $vis = 'l.owner_id IS NULL AND l.closed_at IS NULL';
        else {
            $vp = [];
            $vis = Visibility::lead($actor, 'l', $vp);
            if ($vis === false) throw Errors::forbidden();
            array_push($params, ...$vp);
        }
        $where = ['l.tenant_id = ?', 'l.deleted_at IS NULL', $vis];
        if (!empty($f['pipelineId'])) { $where[] = 'l.pipeline_id = ?'; $params[] = $f['pipelineId']; }
        if (!empty($f['stageId'])) { $where[] = 'l.stage_id = ?'; $params[] = $f['stageId']; }
        if (!empty($f['status'])) { $where[] = 'l.status IN (?)'; $params[] = $f['status']; }
        if (($f['state'] ?? null) === 'open') $where[] = 'l.closed_at IS NULL';
        if (($f['state'] ?? null) === 'closed') $where[] = 'l.closed_at IS NOT NULL';
        if (($f['ownerId'] ?? null) === 'unassigned') $where[] = 'l.owner_id IS NULL';
        elseif (!empty($f['ownerId'])) { $where[] = 'l.owner_id = ?'; $params[] = $f['ownerId']; }
        if (!empty($f['source'])) { $where[] = 'l.source = ?'; $params[] = $f['source']; }
        if (!empty($f['tag'])) foreach (Normalize::tags([$f['tag']]) as $t) { $where[] = 'JSON_CONTAINS(l.tags, ?)'; $params[] = Json::enc($t); }
        if (isset($f['scoreMin'])) { $where[] = 'l.lead_score >= ?'; $params[] = $f['scoreMin']; }
        // A date without a time means whole days in the WORKSPACE time zone (createdTo inclusive); a full timestamp is taken as given.
        $tz = null;
        $isDay = static fn(string $v) => (bool) preg_match('/^\d{4}-\d{2}-\d{2}$/', $v);
        $zone = static function () use (&$tz, $tx, $actor): string {
            return $tz ??= (string) ($tx->val('SELECT timezone FROM tenants WHERE id = ?', [$actor->tenantId]) ?: 'UTC');
        };
        if (!empty($f['createdFrom'])) {
            $v = $f['createdFrom'];
            $where[] = 'l.created_at >= ?';
            $params[] = $isDay($v) ? Recurrence::startOfDay(new \DateTimeImmutable($v . ' 12:00:00', new \DateTimeZone($zone())), $zone()) : Time::parse($v);
        }
        if (!empty($f['createdTo'])) {
            $v = $f['createdTo'];
            $where[] = 'l.created_at < ?';
            $params[] = $isDay($v) ? Recurrence::startOfDay((new \DateTimeImmutable($v . ' 12:00:00', new \DateTimeZone($zone())))->modify('+1 day'), $zone()) : Time::parse($v);
        }
        if (($f['test'] ?? null) === 'exclude') $where[] = "NOT JSON_CONTAINS(l.tags, '\"test\"')";
        if (!empty($f['field']) && array_key_exists('fieldValue', $f) && $f['fieldValue'] !== null) {
            if (!preg_match('/^[a-z][a-z0-9_]{0,39}$/', $f['field'])) throw Errors::validation([['path' => 'query.field', 'message' => 'Use the field key, e.g. treatment']]);
            $path = '$.' . $f['field'];
            $where[] = "(JSON_UNQUOTE(JSON_EXTRACT(l.custom_fields, '$path')) = ? OR JSON_CONTAINS(l.custom_fields, ?, '$path'))";
            $params[] = (string) $f['fieldValue'];
            $params[] = Json::enc((string) $f['fieldValue']);
        }
        if (!empty($f['contactId'])) { $where[] = 'l.contact_id = ?'; $params[] = $f['contactId']; }
        if (($f['sla'] ?? null) === 'breached') $where[] = 'l.first_response_at IS NULL AND l.closed_at IS NULL AND l.first_response_due_at < NOW(3)';
        if (($f['sla'] ?? null) === 'at_risk') $where[] = 'l.first_response_at IS NULL AND l.closed_at IS NULL AND l.first_response_due_at >= NOW(3) AND TIMESTAMPDIFF(SECOND, NOW(3), l.first_response_due_at) < LEAST(TIMESTAMPDIFF(SECOND, l.created_at, l.first_response_due_at) * 0.25, 600)';
        if (($f['followup'] ?? null) === 'overdue') $where[] = 'l.next_followup_at < NOW(3) AND l.closed_at IS NULL';
        if (($f['followup'] ?? null) === 'today') $where[] = 'l.next_followup_at >= CURRENT_DATE AND l.next_followup_at < DATE_ADD(CURRENT_DATE, INTERVAL 1 DAY) AND l.closed_at IS NULL';
        if (isset($f['q']) && trim($f['q']) !== '') {
            $term = mb_substr(trim($f['q']), 0, 100);
            $like = '%' . Normalize::escapeLike($term) . '%';
            $parts = ['c.display_name LIKE ?', 'l.campaign LIKE ?', 'EXISTS (SELECT 1 FROM contact_identifiers i WHERE i.contact_id = l.contact_id AND i.released_at IS NULL AND i.value LIKE ?)'];
            array_push($params, $like, $like, $like);
            $digits = preg_replace('/\D/', '', $term);
            if (strlen($digits) >= 3) {
                $parts[] = "EXISTS (SELECT 1 FROM contact_identifiers i WHERE i.contact_id = l.contact_id AND i.kind = 'phone' AND i.released_at IS NULL AND i.value LIKE ?)";
                $params[] = "%$digits%";
            }
            $where[] = '(' . implode(' OR ', $parts) . ')';
        }
        return $where;
    }

    private const SORTS = [
        'created' => ['l.created_at', 'ts'], 'updated' => ['l.updated_at', 'ts'], 'score' => ['l.lead_score', 'num'],
        'value' => ['COALESCE(l.estimated_value, 0)', 'num'], 'followup' => ["COALESCE(l.next_followup_at, '" . self::FAR . "')", 'ts'],
    ];

    /** @param array<string,mixed> $q */
    public static function list(Actor $actor, array $q): array
    {
        $actor->assertCan('leads:read');
        $sig = "leads:{$q['sort']}:{$q['order']}";
        $cursor = Pagination::decode($q['cursor'] ?? null, $sig);
        [$key, $ktype] = self::SORTS[$q['sort']];
        $rows = Db::tenant($actor)->read(static function (Tx $tx) use ($actor, $q, $cursor, $key, $ktype) {
            $params = [];
            $where = self::whereFor($tx, $actor, $q, $params);
            $pred = Pagination::predicate($cursor, $params, $key, 'l.id', $q['order'], $ktype);
            if ($pred) $where[] = $pred;
            $dir = $q['order'] === 'asc' ? 'ASC' : 'DESC';
            $params[] = $q['limit'] + 1;
            return $tx->all('SELECT ' . self::COLUMNS . ", $key AS _k FROM " . self::FROM . ' WHERE ' . implode(' AND ', $where) . " ORDER BY $key $dir, l.id $dir LIMIT ?", $params);
        });
        $pg = Pagination::page($rows, $q['limit'], $sig, static fn($r) => $r['_k']);
        return ['items' => array_map(static function ($r) { unset($r['_k']); return self::dto($r); }, $pg['items']), 'page' => $pg['page']];
    }

    public static function get(Actor $actor, string $id): array
    {
        $actor->assertCan('leads:read');
        return Db::tenant($actor)->read(static function (Tx $tx) use ($actor, $id) {
            $params = [$actor->tenantId, $id];
            $vis = Visibility::lead($actor, 'l', $params);
            if ($vis === false) throw Errors::forbidden();
            $row = $tx->one('SELECT ' . self::COLUMNS . ' FROM ' . self::FROM . " WHERE l.tenant_id = ? AND l.id = ? AND l.deleted_at IS NULL AND $vis", $params);
            if (!$row) throw Errors::notFound('Lead');
            $due = $row['_due']; $resp = $row['_resp'];
            $lead = self::dto($row);
            return $lead + [
                'history' => self::history($tx, $actor, $id),
                'transitions' => self::allowedTransitions($tx, $actor, $lead),
                'nextStep' => self::nextStep($tx, $actor, $lead, $due, $resp),
                'handlers' => self::handlersIn($tx, $actor->tenantId, $id),
            ];
        });
    }

    /**
     * The ONE thing to do next for this lead, derived from its own data (never typed in): reply, record a visit that has happened, the coming
     * visit, the most urgent open task, or "plan something". The client turns `dueAt` into "in 12 min" / "3 h overdue" so the text never goes stale.
     */
    private static function nextStep(Tx $tx, Actor $actor, array $lead, ?string $due, ?string $responded): ?array
    {
        if ($lead['closedAt']) return null;
        if (!$responded && $due) {
            $late = Time::ms($due) < Time::nowMs();
            return ['kind' => $late ? 'reply_overdue' : 'reply', 'title' => $late ? 'Reply to this lead: the first reply is overdue' : 'Reply to this lead', 'detail' => 'The first reply stops the response clock. A call, e-mail or meeting logged on the lead counts.', 'dueAt' => Time::iso($due), 'urgent' => $late];
        }
        $visit = $tx->one(
            "SELECT v.id, v.scheduled_at, v.duration_minutes, p.title FROM viewings v JOIN properties p ON p.id = v.property_id AND p.tenant_id = v.tenant_id
              WHERE v.tenant_id = ? AND v.lead_id = ? AND v.status IN ('scheduled','confirmed') ORDER BY v.scheduled_at LIMIT 1",
            [$actor->tenantId, $lead['id']]
        );
        if ($visit) {
            $ended = Time::ms($visit['scheduled_at']) + (int) $visit['duration_minutes'] * 60000 < Time::nowMs();
            return $ended
                ? ['kind' => 'record_visit', 'title' => 'Record how the site visit went', 'detail' => "Visit at {$visit['title']}. Say what came of it and plan the follow-up.", 'dueAt' => Time::iso($visit['scheduled_at']), 'urgent' => true, 'viewingId' => $visit['id']]
                : ['kind' => 'visit', 'title' => 'Site visit coming up', 'detail' => "At {$visit['title']}.", 'dueAt' => Time::iso($visit['scheduled_at']), 'urgent' => false, 'viewingId' => $visit['id']];
        }
        $task = $tx->one(
            "SELECT k.id, k.title, k.due_date FROM tasks k
              WHERE k.tenant_id = ? AND k.lead_id = ? AND k.deleted_at IS NULL AND k.status IN ('pending','in_progress')
                AND NOT EXISTS (SELECT 1 FROM viewings v WHERE v.reminder_task_id = k.id)
              ORDER BY (k.due_date IS NULL), k.due_date, k.created_at LIMIT 1",
            [$actor->tenantId, $lead['id']]
        );
        if ($task) {
            $overdue = $task['due_date'] && Time::ms($task['due_date']) < Time::nowMs();
            return ['kind' => 'task', 'title' => $task['title'], 'detail' => $overdue ? 'This task is overdue.' : 'Your next task for this lead.', 'dueAt' => $task['due_date'] ? Time::iso($task['due_date']) : null, 'urgent' => (bool) $overdue, 'taskId' => $task['id']];
        }
        return ['kind' => 'plan', 'title' => 'Plan the next step', 'detail' => 'Nothing is planned for this lead yet. Add a follow-up or book a visit so it does not go quiet.', 'dueAt' => null, 'urgent' => false];
    }

    private static function history(Tx $tx, Actor $actor, string $leadId): array
    {
        return $tx->all(
            'SELECT h.id, h.created_at AS `at`, h.reason, h.reason_code AS reasonCode, COALESCE(h.from_stage_name, fs.name) AS fromStage, COALESCE(h.to_stage_name, ts.name) AS toStage, u.name AS changedBy
               FROM lead_stage_history h LEFT JOIN pipeline_stages fs ON fs.id = h.from_stage_id JOIN pipeline_stages ts ON ts.id = h.to_stage_id LEFT JOIN users u ON u.id = h.changed_by
              WHERE h.lead_id = ? AND h.tenant_id = ? ORDER BY h.created_at DESC, h.id DESC LIMIT 50',
            [$leadId, $actor->tenantId]
        );
    }

    /** Stages this lead may move to right now (transition matrix applied), with what each move will demand. */
    private static function allowedTransitions(Tx $tx, Actor $actor, array $lead): array
    {
        $rows = $tx->all(
            "SELECT s.id AS stageId, s.name, s.color, s.maps_to_status AS status, s.probability, COALESCE(t.required_fields, '[]') AS requiredFields
               FROM pipeline_stages s
               LEFT JOIN pipeline_stage_transitions t ON t.pipeline_id = s.pipeline_id AND t.is_allowed = 1 AND t.to_stage_id = s.id AND t.from_stage_id = ?
              WHERE s.pipeline_id = ? AND s.tenant_id = ? AND s.archived_at IS NULL AND s.id <> ?
                AND (NOT EXISTS (SELECT 1 FROM pipeline_stage_transitions x WHERE x.pipeline_id = s.pipeline_id AND x.is_allowed = 1) OR t.id IS NOT NULL
                     OR (? = 1 AND s.maps_to_status NOT IN ('won','lost','disqualified')))
              ORDER BY s.order_index",
            [$lead['stageId'], $lead['pipelineId'], $actor->tenantId, $lead['stageId'], $lead['closedAt'] !== null ? 1 : 0]
        );
        $codesRequired = (bool) $tx->val('SELECT reason_codes_required FROM pipelines WHERE id = ? AND tenant_id = ?', [$lead['pipelineId'], $actor->tenantId]);
        return array_map(static function ($r) use ($codesRequired) {
            $kind = Vocab::reasonKindOf($r['status']);
            return [
                'stageId' => $r['stageId'], 'name' => $r['name'], 'color' => $r['color'], 'status' => $r['status'], 'probability' => (int) $r['probability'],
                'requiredFields' => array_values((array) Json::dec($r['requiredFields'], [])), 'requiresReason' => $r['status'] === 'lost' || $r['status'] === 'disqualified',
                'reasonKind' => $kind, 'reasonCodeRequired' => $codesRequired && $kind !== null, 'closes' => Vocab::isClosed($r['status']),
            ];
        }, $rows);
    }

    // ── create ────────────────────────────────────────────────────────────────────────────────────────────────────

    public static function create(Actor $actor, array $input): array
    {
        $actor->assertCan('leads:create');
        // People (and API keys) saving a lead must fill the client's required fields; intake that has no form to ask them (ad forms, CSV imports,
        // automation) goes through createInTx directly and is not held to it.
        return Db::tenant($actor)->tx(static fn(Tx $tx) => self::createInTx($tx, $actor, $input, ['enforceRequired' => $actor->kind !== 'system']));
    }

    /**
     * Lead creation inside the caller's transaction (API, import batches, webhook / automation intake).
     *
     * @param array<string,mixed> $input
     * @param array{enforceRequired?:bool} $opts
     */
    public static function createInTx(Tx $tx, Actor $actor, array $input, array $opts = []): array
    {
        $actor->assertCan('leads:create');
        if (empty($input['contactId']) && empty($input['contact'])) throw Errors::validation([['path' => 'body.contact', 'message' => 'Provide contactId or a contact (name plus email or phone)']]);
        $st = self::settings($tx, $actor->tenantId);
        $fields = FieldRules::validate(CustomFields::loadDefs($tx, $actor->tenantId), $input['customFields'] ?? null, ['mode' => 'create', 'enforceRequired' => $opts['enforceRequired'] ?? false]);
        if ($fields['problems']) throw Errors::validation($fields['problems']);
        $now = Time::now();

        // contact
        $contactId = $input['contactId'] ?? null;
        if ($contactId) {
            if (!$tx->exists('SELECT 1 FROM contacts WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL', [$contactId, $actor->tenantId])) throw Errors::validation([['path' => 'body.contactId', 'message' => 'Contact not found']]);
        } else {
            $actor->assertCan('contacts:create');
            $c = $input['contact'];
            if (empty($c['email']) && empty($c['phone'])) throw Errors::validation([['path' => 'body.contact', 'message' => 'A contact needs an email address or a phone number']]);
            $contactId = ContactsService::findOrCreateInTx($tx, ['tenantId' => $actor->tenantId, 'userId' => $actor->userId], $c)['id'];
        }

        // pipeline / stage
        $pipeline = !empty($input['pipelineId'])
            ? $tx->one('SELECT id FROM pipelines WHERE id = ? AND tenant_id = ? AND archived_at IS NULL', [$input['pipelineId'], $actor->tenantId])
            : $tx->one('SELECT id FROM pipelines WHERE tenant_id = ? AND is_default = 1 AND archived_at IS NULL', [$actor->tenantId]);
        if (!$pipeline) throw !empty($input['pipelineId']) ? Errors::validation([['path' => 'body.pipelineId', 'message' => 'Pipeline not found']]) : Errors::conflict('This workspace has no default pipeline yet');
        $pipelineId = $pipeline['id'];
        $stage = !empty($input['stageId'])
            ? $tx->one('SELECT id, stage_key, probability, maps_to_status AS status FROM pipeline_stages WHERE id = ? AND pipeline_id = ? AND tenant_id = ? AND archived_at IS NULL', [$input['stageId'], $pipelineId, $actor->tenantId])
            : $tx->one('SELECT id, stage_key, probability, maps_to_status AS status FROM pipeline_stages WHERE pipeline_id = ? AND tenant_id = ? AND archived_at IS NULL ORDER BY order_index LIMIT 1', [$pipelineId, $actor->tenantId]);
        if (!$stage) throw Errors::validation([['path' => 'body.stageId', 'message' => 'Stage not found in this pipeline']]);
        if (Vocab::isClosed($stage['status'])) throw Errors::validation([['path' => 'body.stageId', 'message' => 'A new lead cannot start in a closing stage']]);

        // duplicates: the same person should not have two open leads in one pipeline
        if (empty($input['allowDuplicate'])) {
            $dup = $tx->val('SELECT id FROM leads WHERE tenant_id = ? AND contact_id = ? AND pipeline_id = ? AND closed_at IS NULL AND deleted_at IS NULL LIMIT 1', [$actor->tenantId, $contactId, $pipelineId]);
            if ($dup) throw Errors::conflict('This contact already has an open lead in this pipeline', ['reason' => 'duplicate_lead', 'leadId' => $dup]);
        }

        $source = $input['source'] ?? 'other';
        $ownerId = self::resolveOwner($tx, $actor, array_key_exists('ownerId', $input) ? $input['ownerId'] : false, $st, $source);
        $contact = $tx->one('SELECT phone, email FROM contacts WHERE id = ? AND tenant_id = ?', [$contactId, $actor->tenantId]);
        $score = Scoring::compute([
            'status' => $stage['status'], 'stageProbability' => (int) $stage['probability'], 'source' => $source, 'hasPhone' => !empty($contact['phone']), 'hasEmail' => !empty($contact['email']),
            'estimatedValue' => $input['estimatedValue'] ?? null, 'budgetMax' => $input['budgetMax'] ?? null,
            'hasRequirements' => !empty($input['locations']) || !empty($input['propertyTypes']) || !empty($input['bedroomsMin']), 'lastActivityAt' => null, 'createdAt' => $now,
        ], $st['scoring'], $now);
        $locations = array_values(array_filter(array_map('trim', $input['locations'] ?? []), static fn($l) => $l !== ''));
        $id = $tx->insert('leads', [
            'tenant_id' => $actor->tenantId, 'contact_id' => $contactId, 'pipeline_id' => $pipelineId, 'stage_id' => $stage['id'], 'stage_key' => $stage['stage_key'], 'status' => $stage['status'],
            'owner_id' => $ownerId, 'source' => $source, 'estimated_value' => $input['estimatedValue'] ?? null, 'currency' => strtoupper($input['currency'] ?? 'INR'),
            'budget_min' => $input['budgetMin'] ?? null, 'budget_max' => $input['budgetMax'] ?? null, 'bedrooms_min' => $input['bedroomsMin'] ?? null,
            'locations' => Json::enc($locations), 'property_types' => Json::enc(array_values($input['propertyTypes'] ?? [])), 'listing_type' => $input['listingType'] ?? null,
            'tags' => Json::enc(Normalize::tags($input['tags'] ?? [])), 'campaign' => !empty($input['campaign']) ? trim($input['campaign']) : null,
            'custom_fields' => Json::encObj($fields['set']), 'created_by' => $actor->userId, 'assigned_at' => $ownerId ? new Raw('NOW(3)') : null,
            'lead_score' => $score['score'], 'score_breakdown' => Json::enc($score['factors']),
            'first_response_due_at' => $st['firstResponseMinutes'] > 0 ? new Raw('DATE_ADD(NOW(3), INTERVAL ' . (int) $st['firstResponseMinutes'] . ' MINUTE)') : null,
            'next_followup_at' => $input['nextFollowupAt'] ?? null, 'source_details' => Json::encObj($input['sourceDetails'] ?? []),
        ]);
        // where this lead came from, as it was at the time; never edited afterwards
        Touches::recordFirst($tx, $actor->tenantId, $id, Touches::from(['source' => $source, 'campaign' => $input['campaign'] ?? null, 'sourceDetails' => $input['sourceDetails'] ?? null]));
        self::recordHistory($tx, $actor->tenantId, $id, null, $stage['id'], $actor->userId, 'Lead created', null);
        $tx->insert('activities', ['tenant_id' => $actor->tenantId, 'lead_id' => $id, 'user_id' => $actor->userId, 'type' => 'note', 'description' => 'Lead created', 'metadata' => Json::enc(['event' => 'created', 'source' => $source])]);
        $dto = self::fetch($tx, $actor->tenantId, $id);
        Audit::log($tx, 'lead.create', 'lead', $id, null, ['contactId' => $contactId, 'stageId' => $dto['stageId'], 'ownerId' => $ownerId, 'source' => $source]);
        Events::emit($tx, 'lead.created', $id, ['stageId' => $dto['stageId'], 'ownerId' => $ownerId, 'source' => $source, 'ownerRequested' => array_key_exists('ownerId', $input)]);
        // Nobody owns it yet: tell the people who can take it, so a lead in the pool does not sit there unseen.
        if (!$ownerId) self::notifyPool($tx, $actor, $id, $dto['contactName']);
        return $dto;
    }

    /** What the PostgreSQL trigger snapshot_stage_history did: remember the stage names / status at the time of the move. */
    public static function recordHistory(Tx $tx, string $tenantId, string $leadId, ?string $fromStageId, string $toStageId, ?string $by, ?string $reason, ?string $reasonCode, bool $clock = false): void
    {
        $to = $tx->one('SELECT name, maps_to_status, probability FROM pipeline_stages WHERE id = ? AND tenant_id = ?', [$toStageId, $tenantId]);
        $fromName = $fromStageId ? $tx->val('SELECT name FROM pipeline_stages WHERE id = ? AND tenant_id = ?', [$fromStageId, $tenantId]) : null;
        $tx->insert('lead_stage_history', [
            'tenant_id' => $tenantId, 'lead_id' => $leadId, 'from_stage_id' => $fromStageId, 'to_stage_id' => $toStageId, 'changed_by' => $by, 'reason' => $reason, 'reason_code' => $reasonCode,
            'from_stage_name' => $fromName, 'to_stage_name' => $to['name'] ?? null, 'to_status' => $to['maps_to_status'] ?? null, 'to_probability' => $to ? (int) $to['probability'] : null,
        ]);
    }

    /** A new lead has no owner: one notification each for the callers, team leads and admins of this client (not the person who added it). */
    private static function notifyPool(Tx $tx, Actor $actor, string $leadId, ?string $contactName): void
    {
        $rows = $tx->all(
            "SELECT tm.user_id AS id FROM tenant_memberships tm JOIN users u ON u.id = tm.user_id AND u.is_active = 1
              WHERE tm.tenant_id = ? AND tm.status = 'active' AND tm.is_active = 1 AND tm.role IN ('sales_agent', 'sales_manager', 'tenant_admin', 'tenant_owner') AND (? IS NULL OR tm.user_id <> ?) LIMIT 50",
            [$actor->tenantId, $actor->userId, $actor->userId]
        );
        foreach ($rows as $r) {
            Notify::send($tx, ['userId' => $r['id'], 'title' => 'New lead waiting for an owner', 'message' => $contactName ?? 'A new lead', 'type' => 'lead_pool', 'link' => '/leads?owner=unassigned', 'entityType' => 'lead', 'entityId' => $leadId, 'dedupeKey' => "pool:$leadId:{$r['id']}"]);
        }
    }

    /**
     * Managers may assign anyone; agents own what they create. Machine actors follow the workspace assignment mode. A routing rule for the lead's source
     * ("Instagram leads go to Divya and Arjun") comes first for everything except a person adding a lead by hand in "whoever adds it" mode.
     * $requested === false means "not specified".
     */
    private static function resolveOwner(Tx $tx, Actor $actor, string|null|false $requested, array $st, string $source): ?string
    {
        $manager = $actor->kind === 'system' || $actor->scopeIfAllowed('leads:assign') === 'all';
        if ($requested !== false) {
            if (!$manager && $requested !== $actor->userId) throw Errors::forbidden('Only managers can assign a lead to someone else');
            $owner = $requested;
        } else {
            // "whoever adds it" keeps a hand-added lead with its creator, except when the workspace sends EVERYTHING to chosen people
            $routed = self::routedOwner($tx, $actor->tenantId, $st, $source, $actor->kind === 'user' && $st['assignmentMode'] === 'creator');
            if ($routed) $owner = $routed;
            elseif ($actor->kind === 'user' && $st['assignmentMode'] === 'creator') $owner = $actor->userId;
            elseif ($st['assignmentMode'] === 'unassigned') $owner = null;
            else $owner = ($actor->kind === 'user' && $st['assignmentMode'] !== 'balanced') ? $actor->userId : self::pickBalancedOwner($tx, $actor->tenantId);
        }
        if (!$owner) return null;
        self::assertAssignable($tx, $actor->tenantId, $owner);
        return $owner;
    }

    private static function assertAssignable(Tx $tx, string $tenantId, string $userId): void
    {
        if (!$tx->exists("SELECT 1 FROM tenant_memberships tm JOIN users u ON u.id = tm.user_id WHERE tm.tenant_id = ? AND tm.user_id = ? AND tm.status = 'active' AND tm.is_active = 1 AND u.is_active = 1", [$tenantId, $userId])) {
            throw Errors::validation([['path' => 'body.ownerId', 'message' => 'Owner must be an active member of this workspace']]);
        }
    }

    private static function leastBusy(Tx $tx, string $tenantId, array $roles, ?array $userIds = null): ?string
    {
        $params = [$tenantId, $roles];
        $sql = "SELECT tm.user_id AS id FROM tenant_memberships tm JOIN users u ON u.id = tm.user_id AND u.is_active = 1
                 WHERE tm.tenant_id = ? AND tm.status = 'active' AND tm.is_active = 1 AND tm.role IN (?)";
        if ($userIds !== null) { $sql .= ' AND tm.user_id IN (?)'; $params[] = $userIds; }
        $sql .= ' ORDER BY (SELECT COUNT(*) FROM leads l WHERE l.tenant_id = tm.tenant_id AND l.owner_id = tm.user_id AND l.closed_at IS NULL AND l.deleted_at IS NULL) ASC,
                           (SELECT MAX(l.assigned_at) FROM leads l WHERE l.tenant_id = tm.tenant_id AND l.owner_id = tm.user_id) ASC, tm.created_at LIMIT 1';
        return $tx->val($sql, $params) ?: null;
    }

    /** One assignment decision at a time per workspace (the least-busy count must not race). */
    private static function lockAssignment(Tx $tx, string $tenantId): void
    {
        $tx->val('SELECT id FROM tenants WHERE id = ? FOR UPDATE', [$tenantId]);
    }

    /** Least-loaded sales person (open leads), ties broken by who was assigned least recently. */
    public static function pickBalancedOwner(Tx $tx, string $tenantId): ?string
    {
        self::lockAssignment($tx, $tenantId);
        foreach ([['sales_agent'], ['sales_manager'], ['tenant_admin', 'tenant_owner']] as $roles) {
            $id = self::leastBusy($tx, $tenantId, $roles);
            if ($id) return $id;
        }
        return null;
    }

    /**
     * The routing rule for this source, if there is one: the least-busy person among the people it names (only those still active in this client).
     * Null when there is no rule, or nobody on it is available, so the normal assignment mode takes over.
     */
    public static function routedOwner(Tx $tx, string $tenantId, array $st, string $source, bool $catchAllOnly = false): ?string
    {
        $rule = null;
        if (!$catchAllOnly) foreach ($st['routing'] as $r) if ($r['source'] === $source) { $rule = $r; break; }
        if (!$rule) foreach ($st['routing'] as $r) if ($r['source'] === 'all') { $rule = $r; break; }
        if (!$rule) return null;
        self::lockAssignment($tx, $tenantId);
        return self::leastBusy($tx, $tenantId, ['sales_agent', 'sales_manager', 'tenant_admin', 'tenant_owner'], $rule['userIds']);
    }

    // ── update / move / assign / archive ──────────────────────────────────────────────────────────────────────────

    /** $allowHandler: someone added to the lead as a handler may act on it too (logging work, never editing, moving or reassigning). */
    private static function lockLead(Tx $tx, Actor $actor, string $id, string $permission, array $opts = []): array
    {
        $row = $tx->one(
            'SELECT id, owner_id, version, stage_id, pipeline_id, closed_at, deleted_at, contact_id, custom_fields FROM leads WHERE id = ? AND tenant_id = ?' . (!empty($opts['includeDeleted']) ? '' : ' AND deleted_at IS NULL') . ' FOR UPDATE',
            [$id, $actor->tenantId]
        );
        if (!$row) throw Errors::notFound('Lead');
        if (!empty($opts['allowHandler']) && $actor->kind === 'user' && !$actor->canActOn($permission, $row['owner_id'])) {
            if (!$tx->exists('SELECT 1 FROM lead_handlers WHERE tenant_id = ? AND lead_id = ? AND user_id = ?', [$actor->tenantId, $id, $actor->userId])) $actor->assertCanActOn($permission, $row['owner_id']);
        } else {
            $actor->assertCanActOn($permission, $row['owner_id']);
        }
        return $row;
    }

    public static function update(Actor $actor, string $id, array $patch, ?int $expectedVersion = null): array
    {
        $actor->assertCan('leads:update');
        return Db::tenant($actor)->tx(static function (Tx $tx) use ($actor, $id, $patch, $expectedVersion) {
            $row = self::lockLead($tx, $actor, $id, 'leads:update');
            if ($expectedVersion !== null && $expectedVersion !== (int) $row['version']) throw Errors::preconditionFailed();
            $before = self::fetch($tx, $actor->tenantId, $id);
            $set = [];
            foreach (['source' => 'source', 'estimatedValue' => 'estimated_value', 'budgetMin' => 'budget_min', 'budgetMax' => 'budget_max', 'bedroomsMin' => 'bedrooms_min', 'listingType' => 'listing_type'] as $k => $col) {
                if (array_key_exists($k, $patch)) $set[$col] = $patch[$k];
            }
            if (array_key_exists('currency', $patch)) $set['currency'] = strtoupper($patch['currency']);
            if (array_key_exists('locations', $patch)) $set['locations'] = Json::enc(array_values(array_filter(array_map('trim', $patch['locations']), static fn($l) => $l !== '')));
            if (array_key_exists('propertyTypes', $patch)) $set['property_types'] = Json::enc(array_values($patch['propertyTypes']));
            if (array_key_exists('tags', $patch)) $set['tags'] = Json::enc(Normalize::tags($patch['tags']));
            if (array_key_exists('campaign', $patch)) $set['campaign'] = !empty($patch['campaign']) ? trim($patch['campaign']) : null;
            if (array_key_exists('customFields', $patch)) {
                // The client's own field definitions decide what is valid. Keys not mentioned are left alone (a merge, not a replace), so two
                // people editing different fields of one lead never overwrite each other.
                $v = FieldRules::validate(CustomFields::loadDefs($tx, $actor->tenantId), $patch['customFields'], ['mode' => 'patch', 'enforceRequired' => $actor->kind !== 'system']);
                if ($v['problems']) throw Errors::validation($v['problems']);
                $cur = Json::dec($row['custom_fields'], []) ?: [];
                foreach ($v['clear'] as $k) unset($cur[$k]);
                $set['custom_fields'] = Json::encObj(array_merge($cur, $v['set']));
            }
            if ($set) {
                $set['version'] = new Raw('version + 1');
                $tx->update('leads', $set, 'id = ? AND tenant_id = ?', [$id, $actor->tenantId]);
            }
            // The follow-up date is a task, not a column: one source of truth that also gets reminders.
            if (array_key_exists('nextFollowupAt', $patch)) TasksService::setLeadFollowup($tx, $actor, $id, $patch['nextFollowupAt']);
            self::rescore($tx, $actor->tenantId, $id);
            $after = self::fetch($tx, $actor->tenantId, $id);
            Audit::log($tx, 'lead.update', 'lead', $id, $before, $after);
            Events::emit($tx, 'lead.updated', $id, ['version' => $after['version']]);
            return $after;
        });
    }

    /** @param array{stageId:string,reason?:?string,lossReason?:?string,reasonCode?:?string,deal?:array} $input */
    public static function move(Actor $actor, string $id, array $input, ?int $expectedVersion = null): array
    {
        $actor->assertCan('leads:update');
        return Db::tenant($actor)->tx(static function (Tx $tx) use ($actor, $id, $input, $expectedVersion) {
            $row = self::lockLead($tx, $actor, $id, 'leads:update');
            if ($expectedVersion !== null && $expectedVersion !== (int) $row['version']) throw Errors::preconditionFailed();
            self::moveInTx($tx, $actor, $row, $input);
            return self::fetch($tx, $actor->tenantId, $id);
        });
    }

    /**
     * The single place stage changes happen (API, bulk, import, automation): rules, history, activity, audit, event, score.
     *
     * @param array{id:string,stage_id:string,pipeline_id:string,closed_at:?string,owner_id:?string,contact_id:?string} $row
     */
    public static function moveInTx(Tx $tx, Actor $actor, array $row, array $input): void
    {
        if ($input['stageId'] === $row['stage_id']) return;
        $target = $tx->one('SELECT id, name, stage_key, maps_to_status AS status, pipeline_id FROM pipeline_stages WHERE id = ? AND tenant_id = ? AND archived_at IS NULL', [$input['stageId'], $actor->tenantId]);
        if (!$target) throw Errors::validation([['path' => 'body.stageId', 'message' => 'Stage not found']]);
        if ($target['pipeline_id'] !== $row['pipeline_id']) throw Errors::validation([['path' => 'body.stageId', 'message' => 'That stage belongs to a different pipeline']]);
        $from = $tx->one('SELECT id, name, order_index, maps_to_status AS status FROM pipeline_stages WHERE id = ? AND tenant_id = ?', [$row['stage_id'], $actor->tenantId]);

        // Transition rules describe forward progress. Reopening a closed lead (to any open stage) is always allowed — closing by mistake must
        // never be a dead end — but required-field gates of a matching rule still apply.
        $reopening = $row['closed_at'] !== null && !Vocab::isClosed($target['status']);
        $rules = $tx->all('SELECT from_stage_id, to_stage_id, required_fields FROM pipeline_stage_transitions WHERE pipeline_id = ? AND tenant_id = ? AND is_allowed = 1', [$row['pipeline_id'], $actor->tenantId]);
        $required = [];
        if ($rules) {
            $rule = null;
            foreach ($rules as $r) if ($r['from_stage_id'] === $row['stage_id'] && $r['to_stage_id'] === $input['stageId']) $rule = $r;
            if (!$rule && !$reopening) throw Errors::invalidTransition("This pipeline does not allow moving a lead from “{$from['name']}” to “{$target['name']}”");
            $required = array_values(array_filter((array) Json::dec($rule['required_fields'] ?? '[]', []), static fn($f) => in_array($f, Vocab::GATE_FIELDS, true)));
        }
        if ($required) self::assertGate($tx, $actor, $row, $required, $target['name']);

        $closing = $target['status'] === 'lost' || $target['status'] === 'disqualified';
        $kind = Vocab::reasonKindOf($target['status']);
        $word = $target['status'] === 'lost' ? 'lost' : 'not a fit';
        $note = isset($input['lossReason']) && trim((string) $input['lossReason']) !== '' ? trim($input['lossReason']) : null;
        $codeIn = isset($input['reasonCode']) && trim((string) $input['reasonCode']) !== '' ? trim($input['reasonCode']) : null;
        $codesRequired = (bool) $tx->val('SELECT reason_codes_required FROM pipelines WHERE id = ? AND tenant_id = ?', [$row['pipeline_id'], $actor->tenantId]);
        if ($codeIn && !$kind) throw Errors::validation([['path' => 'body.reasonCode', 'message' => 'A reason code only applies when moving a lead to Lost or Not a fit']]);
        $picked = $codeIn && $kind ? Vocab::findReason($kind, $codeIn) : null;
        if ($codeIn && $kind && !$picked) throw Errors::validation([['path' => 'body.reasonCode', 'message' => 'Choose one of the listed reasons']]);
        if ($closing && $codesRequired && !$picked) throw Errors::validation([['path' => 'body.reasonCode', 'message' => "Choose why this lead is $word"]]);
        // "Other" and pipelines without codes need words; a chosen code stands on its own (its label is kept as the written reason)
        if ($closing && ((!$picked && (!$note || mb_strlen($note) < 2)) || (($picked['code'] ?? null) === 'other' && (!$note || mb_strlen($note) < 2)))) {
            throw Errors::validation([['path' => 'body.lossReason', 'message' => $picked ? 'Explain the reason in a few words' : "Say why this lead is $word"]]);
        }
        $lossReason = $closing ? ($note ?: ($picked['label'] ?? null)) : null;
        $reasonCode = $closing ? ($picked['code'] ?? null) : null;

        // Decision D3: a completed sale is Won AND a recorded deal value tied to a unit. Pipelines that require it refuse Won without one.
        $winning = $target['status'] === 'won';
        if ($winning && empty($input['deal'])) {
            if ((bool) $tx->val('SELECT deal_value_required FROM pipelines WHERE id = ? AND tenant_id = ?', [$row['pipeline_id'], $actor->tenantId])) {
                throw Errors::unprocessable('Record the deal value and the unit that was sold before marking this lead as won', ['reason' => 'deal_value_required']);
            }
        }
        // A recorded sale is taken back when its lead is reopened (the unit is released), which only people who manage inventory may do.
        $leavingWon = $from['status'] === 'won' && $target['status'] !== 'won';
        if ($leavingWon && Ledger::hasCompletedDeal($tx, $actor->tenantId, $row['id'])) {
            $mayRelease = $actor->kind === 'system' || ($actor->scopeIfAllowed('reservations:update') === 'all' && $actor->scopeIfAllowed('properties:update') === 'all');
            if (!$mayRelease) throw Errors::forbidden('This lead has a recorded sale. A manager has to reopen it, because the unit becomes available again.');
        }
        if ($winning && !empty($input['deal'])) Ledger::completeDeal($tx, $actor, $row, $input['deal']);
        if ($leavingWon) Ledger::reverseDeals($tx, $actor, $row['id'], trim((string) ($input['reason'] ?? '')) ?: (trim((string) ($input['lossReason'] ?? '')) ?: ($closing ? 'Lead closed another way' : 'Lead reopened')));

        $firstStage = $tx->val('SELECT id FROM pipeline_stages WHERE pipeline_id = ? AND tenant_id = ? AND archived_at IS NULL ORDER BY order_index LIMIT 1', [$row['pipeline_id'], $actor->tenantId]);
        $isClosing = Vocab::isClosed($target['status']);
        $set = [
            'stage_id' => $input['stageId'], 'stage_key' => $target['stage_key'], 'status' => $target['status'],
            'closed_at' => $isClosing ? new Raw('NOW(3)') : null, 'loss_reason' => $isClosing ? $lossReason : null, 'loss_reason_code' => $isClosing ? $reasonCode : null,
            'version' => new Raw('version + 1'),
        ];
        if ($firstStage === $row['stage_id']) $set['first_response_at'] = new Raw('COALESCE(first_response_at, NOW(3))');
        $tx->update('leads', $set, 'id = ? AND tenant_id = ?', [$row['id'], $actor->tenantId]);
        $reason = trim((string) ($input['reason'] ?? '')) ?: ($reopening ? 'Reopened' : ($closing ? $lossReason : null));
        self::recordHistory($tx, $actor->tenantId, $row['id'], $row['stage_id'], $input['stageId'], $actor->userId, $reason, $reasonCode);
        $meta = ['fromStageId' => $row['stage_id'], 'toStageId' => $input['stageId'], 'reason' => $reason];
        if ($closing) $meta['lossReason'] = $lossReason;
        if ($reasonCode) $meta['reasonCode'] = $reasonCode;
        $tx->insert('activities', ['tenant_id' => $actor->tenantId, 'lead_id' => $row['id'], 'user_id' => $actor->userId, 'type' => 'stage_change', 'description' => "Moved from {$from['name']} to {$target['name']}", 'metadata' => Json::enc($meta), 'created_at' => new Raw('NOW(3)')]);
        self::rescore($tx, $actor->tenantId, $row['id']);
        Audit::log($tx, 'lead.stage_change', 'lead', $row['id'], ['stageId' => $row['stage_id']], ['stageId' => $input['stageId'], 'status' => $target['status'], 'reason' => $reason, 'reasonCode' => $reasonCode]);
        Events::emit($tx, 'lead.stage_changed', $row['id'], ['fromStageId' => $row['stage_id'], 'toStageId' => $input['stageId'], 'status' => $target['status']]);
    }

    private static function assertGate(Tx $tx, Actor $actor, array $row, array $fields, string $stageName): void
    {
        $r = $tx->one('SELECT l.owner_id, l.estimated_value, l.budget_max, l.next_followup_at, c.phone, c.email FROM leads l LEFT JOIN contacts c ON c.id = l.contact_id AND c.tenant_id = l.tenant_id WHERE l.id = ? AND l.tenant_id = ?', [$row['id'], $actor->tenantId]);
        $have = ['ownerId' => !empty($r['owner_id']), 'estimatedValue' => $r['estimated_value'] !== null, 'budgetMax' => $r['budget_max'] !== null, 'phone' => !empty($r['phone']), 'email' => !empty($r['email']), 'nextFollowupAt' => !empty($r['next_followup_at'])];
        $label = ['ownerId' => 'an owner', 'estimatedValue' => 'an estimated value', 'budgetMax' => 'a maximum budget', 'phone' => 'a phone number', 'email' => 'an email address', 'nextFollowupAt' => 'a next follow-up date'];
        $missing = array_values(array_filter($fields, static fn($f) => !$have[$f]));
        if ($missing) throw Errors::unprocessable("Before moving to “{$stageName}” this lead needs " . implode(', ', array_map(static fn($m) => $label[$m], $missing)), ['reason' => 'missing_required_fields', 'missing' => $missing]);
    }

    public static function assign(Actor $actor, string $id, ?string $ownerId): array
    {
        $actor->assertCan('leads:assign');
        return Db::tenant($actor)->tx(static function (Tx $tx) use ($actor, $id, $ownerId) {
            $row = self::lockLead($tx, $actor, $id, 'leads:assign');
            self::assignInTx($tx, $actor, $row, $ownerId);
            return self::fetch($tx, $actor->tenantId, $id);
        });
    }

    /**
     * Take a lead nobody owns yet (the pool). Anyone who may claim can do it, and two people clicking at once is safe: the lead is locked and only one
     * of them finds it still unowned; the other is told so instead of silently taking it over.
     */
    public static function claim(Actor $actor, string $id): array
    {
        $actor->assertCan('leads:claim');
        return Db::tenant($actor)->tx(static function (Tx $tx) use ($actor, $id) {
            $row = $tx->one('SELECT id, owner_id, closed_at FROM leads WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL FOR UPDATE', [$id, $actor->tenantId]);
            if (!$row) throw Errors::notFound('Lead');
            if ($row['owner_id'] === $actor->userId) return self::fetch($tx, $actor->tenantId, $id);
            if ($row['closed_at']) throw Errors::conflict('This lead is closed');
            if ($row['owner_id']) throw Errors::conflict('Someone else has already taken this lead', ['reason' => 'already_claimed']);
            self::assignInTx($tx, $actor, $row, $actor->userId, 'claimed');
            return self::fetch($tx, $actor->tenantId, $id);
        });
    }

    /** @param array{id:string,owner_id:?string} $row */
    public static function assignInTx(Tx $tx, Actor $actor, array $row, ?string $ownerId, string $how = 'assigned'): void
    {
        if ($ownerId === $row['owner_id']) return;
        if ($ownerId) self::assertAssignable($tx, $actor->tenantId, $ownerId);
        $tx->run('UPDATE leads SET owner_id = ?, assigned_at = CASE WHEN ? IS NULL THEN NULL ELSE NOW(3) END, version = version + 1 WHERE id = ? AND tenant_id = ?', [$ownerId, $ownerId, $row['id'], $actor->tenantId]);
        $text = $how === 'claimed' ? 'Lead claimed' : ($ownerId ? 'Lead reassigned' : 'Lead unassigned');
        $tx->insert('activities', ['tenant_id' => $actor->tenantId, 'lead_id' => $row['id'], 'user_id' => $actor->userId, 'type' => 'note', 'description' => $text, 'metadata' => Json::enc(['event' => 'assigned', 'how' => $how, 'from' => $row['owner_id'], 'to' => $ownerId])]);
        Audit::log($tx, $how === 'claimed' ? 'lead.claim' : 'lead.assign', 'lead', $row['id'], ['ownerId' => $row['owner_id']], ['ownerId' => $ownerId]);
        Events::emit($tx, 'lead.assigned', $row['id'], ['from' => $row['owner_id'], 'to' => $ownerId]);
    }

    // ── specialists and handlers ──────────────────────────────────────────────────────────────────────────────────

    /** What kinds of work this person may log in this client (null = everything). */
    public static function workChannelsOf(Tx $tx, string $tenantId, ?string $userId): ?array
    {
        if (!$userId) return null;
        $wc = $tx->val("SELECT work_channels FROM tenant_memberships WHERE tenant_id = ? AND user_id = ? AND status = 'active' AND is_active = 1", [$tenantId, $userId]);
        $a = $wc !== null ? Json::dec($wc, null) : null;
        return is_array($a) ? array_values($a) : null;
    }

    /** A person who handles WhatsApp only cannot log a phone call: the record of who did what must be true. Notes are always fine. */
    private static function assertMayLog(Tx $tx, Actor $actor, string $type): void
    {
        $channel = Vocab::channelOf($type);
        if (!$channel || $actor->kind !== 'user') return;
        $allowed = self::workChannelsOf($tx, $actor->tenantId, $actor->userId);
        if ($allowed && !in_array($channel, $allowed, true)) {
            $what = $channel === 'call' ? 'a phone call' : ($channel === 'meeting' ? 'a visit or meeting' : ($channel === 'sms' ? 'a text message' : ($channel === 'email' ? 'an e-mail' : ($channel === 'instagram' ? 'an Instagram message' : 'a WhatsApp message'))));
            throw Errors::forbidden('You handle ' . Vocab::describeChannels($allowed) . " for this client, so you can’t log $what");
        }
    }

    public static function handlers(Actor $actor, string $leadId): array
    {
        $actor->assertCan('leads:read');
        return Db::tenant($actor)->read(static function (Tx $tx) use ($actor, $leadId) {
            $params = [$actor->tenantId, $leadId];
            $vis = Visibility::lead($actor, 'l', $params);
            if ($vis === false) throw Errors::forbidden();
            if (!$tx->exists("SELECT 1 FROM leads l WHERE l.tenant_id = ? AND l.id = ? AND l.deleted_at IS NULL AND $vis", $params)) throw Errors::notFound('Lead');
            return self::handlersIn($tx, $actor->tenantId, $leadId);
        });
    }

    private static function handlersIn(Tx $tx, string $tenantId, string $leadId): array
    {
        $rows = $tx->all(
            'SELECT h.user_id AS userId, u.name, tm.work_channels AS workChannels, h.created_at AS addedAt
               FROM lead_handlers h JOIN users u ON u.id = h.user_id LEFT JOIN tenant_memberships tm ON tm.tenant_id = h.tenant_id AND tm.user_id = h.user_id
              WHERE h.tenant_id = ? AND h.lead_id = ? ORDER BY h.created_at, h.user_id',
            [$tenantId, $leadId]
        );
        return array_map(static function ($r) { $w = $r['workChannels'] !== null ? Json::dec($r['workChannels'], null) : null; $r['workChannels'] = is_array($w) ? array_values($w) : null; return $r; }, $rows);
    }

    /** The owner stays accountable; a handler is somebody else who works the lead (the caller, the WhatsApp person, the one who does the visit). */
    public static function addHandler(Actor $actor, string $leadId, string $userId): array
    {
        $actor->assertCan('leads:assign');
        return Db::tenant($actor)->tx(static function (Tx $tx) use ($actor, $leadId, $userId) {
            $row = self::lockLead($tx, $actor, $leadId, 'leads:assign');
            if ($row['owner_id'] === $userId) throw Errors::conflict('This person already owns the lead');
            $m = $tx->one("SELECT tm.role, u.name FROM tenant_memberships tm JOIN users u ON u.id = tm.user_id AND u.is_active = 1 WHERE tm.tenant_id = ? AND tm.user_id = ? AND tm.status = 'active' AND tm.is_active = 1", [$actor->tenantId, $userId]);
            if (!$m || !in_array($m['role'], ['sales_agent', 'sales_manager', 'tenant_admin', 'tenant_owner'], true)) throw Errors::validation([['path' => 'body.userId', 'message' => 'Choose someone who works leads in this client']]);
            $ins = $tx->insertIgnore('lead_handlers', ['tenant_id' => $actor->tenantId, 'lead_id' => $leadId, 'user_id' => $userId, 'added_by' => $actor->userId]);
            if ($ins !== null) {
                $tx->insert('activities', ['tenant_id' => $actor->tenantId, 'lead_id' => $leadId, 'user_id' => $actor->userId, 'type' => 'note', 'description' => $m['name'] . ' was added to work on this lead', 'metadata' => Json::enc(['event' => 'assigned', 'how' => 'handler', 'to' => $userId])]);
                Audit::log($tx, 'lead.handler_add', 'lead', $leadId, null, ['userId' => $userId]);
                if ($userId !== $actor->userId) Notify::send($tx, ['userId' => $userId, 'title' => 'You were added to a lead', 'message' => 'You can log your calls and messages on it.', 'type' => 'lead_assigned', 'link' => "/leads/$leadId", 'entityType' => 'lead', 'entityId' => $leadId, 'dedupeKey' => "handler:$leadId:$userId"]);
            }
            return self::handlersIn($tx, $actor->tenantId, $leadId);
        });
    }

    public static function removeHandler(Actor $actor, string $leadId, string $userId): array
    {
        $actor->assertCan('leads:assign');
        return Db::tenant($actor)->tx(static function (Tx $tx) use ($actor, $leadId, $userId) {
            self::lockLead($tx, $actor, $leadId, 'leads:assign');
            $n = $tx->run('DELETE FROM lead_handlers WHERE tenant_id = ? AND lead_id = ? AND user_id = ?', [$actor->tenantId, $leadId, $userId]);
            if ($n) Audit::log($tx, 'lead.handler_remove', 'lead', $leadId, ['userId' => $userId]);
            return self::handlersIn($tx, $actor->tenantId, $leadId);
        });
    }

    public static function archive(Actor $actor, string $id): void
    {
        $actor->assertCan('leads:delete');
        Db::tenant($actor)->tx(static function (Tx $tx) use ($actor, $id) {
            $row = self::lockLead($tx, $actor, $id, 'leads:delete');
            $tx->run('UPDATE leads SET deleted_at = NOW(3), version = version + 1 WHERE id = ? AND tenant_id = ?', [$id, $actor->tenantId]);
            Audit::log($tx, 'lead.delete', 'lead', $id, ['stageId' => $row['stage_id'], 'ownerId' => $row['owner_id']]);
            Events::emit($tx, 'lead.deleted', $id);
        });
    }

    public static function restore(Actor $actor, string $id): array
    {
        $actor->assertCan('leads:delete');
        return Db::tenant($actor)->tx(static function (Tx $tx) use ($actor, $id) {
            self::lockLead($tx, $actor, $id, 'leads:delete', ['includeDeleted' => true]);
            $n = $tx->run('UPDATE leads SET deleted_at = NULL, version = version + 1 WHERE id = ? AND tenant_id = ? AND deleted_at IS NOT NULL', [$id, $actor->tenantId]);
            if (!$n) throw Errors::notFound('Archived lead');
            $dto = self::fetch($tx, $actor->tenantId, $id);
            Audit::log($tx, 'lead.restore', 'lead', $id);
            Events::emit($tx, 'lead.updated', $id, ['restored' => true]);
            return $dto;
        });
    }

    // ── bulk ──────────────────────────────────────────────────────────────────────────────────────────────────────

    /** @param array{ids:string[],action:string,ownerId?:?string,stageId?:string,lossReason?:?string,reasonCode?:?string,tags?:string[]} $in */
    public static function bulk(Actor $actor, array $in): array
    {
        $actor->assertCan('leads:bulk');
        $ids = array_values(array_unique($in['ids']));
        return Db::tenant($actor)->tx(static function (Tx $tx) use ($actor, $in, $ids) {
            $skipped = [];
            $updated = 0;
            foreach ($ids as $id) {
                $tx->run('SAVEPOINT bulk_item');
                try {
                    $perm = $in['action'] === 'assign' ? 'leads:assign' : ($in['action'] === 'archive' ? 'leads:delete' : 'leads:update');
                    $row = self::lockLead($tx, $actor, $id, $perm);
                    if ($in['action'] === 'assign') self::assignInTx($tx, $actor, $row, $in['ownerId'] ?? null);
                    elseif ($in['action'] === 'move_stage') self::moveInTx($tx, $actor, $row, ['stageId' => $in['stageId'], 'lossReason' => $in['lossReason'] ?? null, 'reasonCode' => $in['reasonCode'] ?? null]);
                    elseif ($in['action'] === 'archive') {
                        $tx->run('UPDATE leads SET deleted_at = NOW(3), version = version + 1 WHERE id = ? AND tenant_id = ?', [$id, $actor->tenantId]);
                        Audit::log($tx, 'lead.delete', 'lead', $id, null, ['bulk' => true]);
                        Events::emit($tx, 'lead.deleted', $id);
                    } else {
                        $tags = Normalize::tags($in['tags'] ?? []);
                        $cur = (array) Json::dec((string) $tx->val('SELECT tags FROM leads WHERE id = ? AND tenant_id = ?', [$id, $actor->tenantId]), []);
                        $next = $in['action'] === 'add_tags' ? array_values(array_unique(array_merge($cur, $tags))) : array_values(array_diff($cur, $tags));
                        if (!($in['action'] === 'add_tags' && count($next) > 30)) $tx->run('UPDATE leads SET tags = ?, version = version + 1 WHERE id = ? AND tenant_id = ?', [Json::enc($next), $id, $actor->tenantId]);
                        Audit::log($tx, 'lead.' . $in['action'], 'lead', $id, null, ['tags' => $tags]);
                        Events::emit($tx, 'lead.updated', $id, ['tags' => true]);
                    }
                    $tx->run('RELEASE SAVEPOINT bulk_item');
                    $updated++;
                } catch (AppError $e) {
                    $tx->run('ROLLBACK TO SAVEPOINT bulk_item');
                    if ($e->status >= 500) throw $e;
                    $skipped[] = ['id' => $id, 'reason' => $e->errors[0]['message'] ?? $e->detail ?? $e->getMessage()];
                }
            }
            Audit::log($tx, 'lead.bulk', 'lead', $ids[0] ?? $actor->tenantId, null, ['action' => $in['action'], 'requested' => count($ids), 'updated' => $updated, 'skipped' => count($skipped)]);
            return ['updated' => $updated, 'skipped' => $skipped];
        });
    }

    // ── activities ────────────────────────────────────────────────────────────────────────────────────────────────

    public static function activities(Actor $actor, string $leadId, array $q): array
    {
        $actor->assertCan('activities:read');
        $sig = 'activities:occurred:desc';
        $cursor = Pagination::decode($q['cursor'] ?? null, $sig);
        $rows = Db::tenant($actor)->read(static function (Tx $tx) use ($actor, $leadId, $q, $cursor) {
            $lp = [$actor->tenantId, $leadId];
            $vis = Visibility::lead($actor, 'l', $lp);
            if ($vis === false) throw Errors::forbidden();
            if (!$tx->exists("SELECT 1 FROM leads l WHERE l.tenant_id = ? AND l.id = ? AND l.deleted_at IS NULL AND $vis", $lp)) throw Errors::notFound('Lead');
            $params = [$actor->tenantId, $leadId];
            $pred = Pagination::predicate($cursor, $params, 'a.created_at', 'a.id', 'desc', 'ts');
            $params[] = $q['limit'] + 1;
            return $tx->all(
                'SELECT a.id, a.type, a.description, a.metadata, a.direction, a.outcome, a.duration_minutes AS durationMinutes, a.created_at AS createdAt, u.name AS userName, a.user_id AS userId
                   FROM activities a LEFT JOIN users u ON u.id = a.user_id
                  WHERE a.tenant_id = ? AND a.lead_id = ? ' . ($pred ? "AND $pred " : '') . 'ORDER BY a.created_at DESC, a.id DESC LIMIT ?',
                $params
            );
        });
        $pg = Pagination::page($rows, $q['limit'], $sig, static fn($r) => $r['createdAt']);
        $items = array_map(static function ($r) {
            $r['metadata'] = Json::obj(Json::dec($r['metadata'], []));
            $r['durationMinutes'] = $r['durationMinutes'] !== null ? (int) $r['durationMinutes'] : null;
            return $r;
        }, $pg['items']);
        return ['items' => $items, 'page' => $pg['page']];
    }

    /**
     * @param array{type:string,description:string,direction?:?string,outcome?:?string,occurredAt?:?\DateTimeImmutable,durationMinutes?:?int,nextFollowupAt?:?\DateTimeImmutable} $in
     * @param array{metadata?:array,after?:callable(Tx,array):void} $opts used by sending (e-mail / WhatsApp): extra facts stored on the activity and a step that runs in the SAME transaction
     */
    public static function logActivity(Actor $actor, string $leadId, array $in, array $opts = []): array
    {
        $actor->assertCan('activities:create');
        $problems = [];
        $outcomeDef = null;
        if (!empty($in['outcome'])) foreach (Vocab::ACTIVITY_OUTCOMES as $o) if ($o['code'] === $in['outcome']) $outcomeDef = $o;
        if (!empty($in['outcome']) && !$outcomeDef) $problems[] = ['path' => 'body.outcome', 'message' => 'Choose one of the listed outcomes'];
        elseif ($outcomeDef && !in_array($in['type'], $outcomeDef['forTypes'], true)) $problems[] = ['path' => 'body.outcome', 'message' => "“{$outcomeDef['label']}” does not apply to a {$in['type']}"];
        if (!empty($in['direction']) && ($in['type'] === 'note' || $in['type'] === 'meeting')) $problems[] = ['path' => 'body.direction', 'message' => "A {$in['type']} has no direction"];
        if (isset($in['durationMinutes']) && $in['type'] !== 'call' && $in['type'] !== 'meeting') $problems[] = ['path' => 'body.durationMinutes', 'message' => 'Only calls and meetings have a duration'];
        if ($problems) throw Errors::validation($problems);
        // Calls, messages and e-mails default to outbound (we reached out); a lead writing to us is logged as inbound.
        $direction = ($in['type'] === 'note' || $in['type'] === 'meeting') ? null : ($in['direction'] ?? 'outbound');

        return Db::tenant($actor)->tx(static function (Tx $tx) use ($actor, $leadId, $in, $opts, $direction) {
            self::lockLead($tx, $actor, $leadId, 'leads:update', ['allowHandler' => true]);
            self::assertMayLog($tx, $actor, $in['type']);
            $when = $in['occurredAt'] ?? Time::now();
            if ($when->getTimestamp() > time() + 300) throw Errors::validation([['path' => 'body.occurredAt', 'message' => 'Activities cannot be in the future']]);
            $meta = array_filter(['outcome' => $in['outcome'] ?? null, 'durationMinutes' => $in['durationMinutes'] ?? null], static fn($v) => $v !== null) + ($opts['metadata'] ?? []);
            $aid = $tx->insert('activities', [
                'tenant_id' => $actor->tenantId, 'lead_id' => $leadId, 'user_id' => $actor->userId, 'type' => $in['type'], 'description' => trim($in['description']), 'direction' => $direction,
                'outcome' => $in['outcome'] ?? null, 'duration_minutes' => $in['durationMinutes'] ?? null, 'metadata' => Json::encObj($meta), 'created_at' => $when,
            ]);
            $row = $tx->one('SELECT id, type, description, direction, outcome, duration_minutes AS durationMinutes, metadata, created_at AS createdAt, user_id AS userId FROM activities WHERE id = ?', [$aid]);
            $row['metadata'] = Json::obj(Json::dec($row['metadata'], []));
            $row['durationMinutes'] = $row['durationMinutes'] !== null ? (int) $row['durationMinutes'] : null;
            if (!empty($opts['after'])) ($opts['after'])($tx, $row);
            // An outbound touch (not a plain note, not the lead writing to us) answers the lead: it stops the first-response clock. Any touch counts as engagement.
            $answers = $in['type'] !== 'note' && $direction !== 'inbound';
            $tx->run('UPDATE leads SET last_activity_at = GREATEST(COALESCE(last_activity_at, ?), ?), first_response_at = CASE WHEN first_response_at IS NULL AND ? = 1 THEN ? ELSE first_response_at END WHERE id = ? AND tenant_id = ?', [$when, $when, $answers ? 1 : 0, $when, $leadId, $actor->tenantId]);
            if (array_key_exists('nextFollowupAt', $in)) TasksService::setLeadFollowup($tx, $actor, $leadId, $in['nextFollowupAt']);
            self::rescore($tx, $actor->tenantId, $leadId);
            Audit::log($tx, 'activity.create', 'lead', $leadId, null, ['type' => $in['type'], 'direction' => $direction, 'outcome' => $in['outcome'] ?? null, 'activityId' => $aid]);
            Events::emit($tx, 'activity.created', $aid, ['leadId' => $leadId, 'type' => $in['type']]);
            return $row;
        });
    }

    // ── board & forecast ──────────────────────────────────────────────────────────────────────────────────────────

    public static function board(Actor $actor, string $pipelineId, array $f): array
    {
        $actor->assertCan('leads:read');
        $actor->assertCan('pipeline:read');
        return Db::tenant($actor)->read(static function (Tx $tx) use ($actor, $pipelineId, $f) {
            $stages = $tx->all('SELECT id, name, color, order_index AS orderIndex, maps_to_status AS mapsToStatus, probability FROM pipeline_stages WHERE pipeline_id = ? AND tenant_id = ? AND archived_at IS NULL ORDER BY order_index', [$pipelineId, $actor->tenantId]);
            if (!$stages) throw Errors::notFound('Pipeline');
            $params = [];
            $where = self::whereFor($tx, $actor, ['pipelineId' => $pipelineId, 'ownerId' => $f['ownerId'] ?? null, 'q' => $f['q'] ?? null], $params);
            $totals = [];
            foreach ($tx->all('SELECT l.stage_id AS stageId, COUNT(*) AS count, COALESCE(SUM(l.estimated_value), 0) AS value FROM leads l LEFT JOIN contacts c ON c.id = l.contact_id AND c.tenant_id = l.tenant_id WHERE ' . implode(' AND ', $where) . ' GROUP BY l.stage_id', $params) as $t) $totals[$t['stageId']] = $t;
            $out = [];
            foreach ($stages as $s) {
                $p = $params;
                $w = array_merge($where, ['l.stage_id = ?']);
                $p[] = $s['id'];
                $p[] = (int) $f['perStage'];
                $cards = array_map([self::class, 'dto'], $tx->all('SELECT ' . self::COLUMNS . ' FROM ' . self::FROM . ' WHERE ' . implode(' AND ', $w) . ' ORDER BY l.updated_at DESC, l.id DESC LIMIT ?', $p));
                $out[] = [
                    'id' => $s['id'], 'name' => $s['name'], 'color' => $s['color'], 'orderIndex' => (int) $s['orderIndex'], 'mapsToStatus' => $s['mapsToStatus'], 'probability' => (int) $s['probability'],
                    'isClosed' => Vocab::isClosed($s['mapsToStatus']), 'count' => (int) ($totals[$s['id']]['count'] ?? 0), 'value' => (float) ($totals[$s['id']]['value'] ?? 0), 'leads' => $cards,
                ];
            }
            return ['stages' => $out];
        });
    }

    /** Weighted pipeline: open value x stage probability. Values are summed in the workspace currency as stored (no FX). */
    public static function forecast(Actor $actor, string $pipelineId): array
    {
        $actor->assertCan('leads:read');
        $actor->assertCan('pipeline:read');
        return Db::tenant($actor)->read(static function (Tx $tx) use ($actor, $pipelineId) {
            $vp = [];
            $vis = Visibility::lead($actor, 'l', $vp);
            if ($vis === false) throw Errors::forbidden();
            $rows = $tx->all(
                "SELECT s.id AS stageId, s.name, s.maps_to_status AS status, s.probability, s.order_index,
                        COUNT(l.id) AS count, COALESCE(SUM(l.estimated_value), 0) AS value, COALESCE(SUM(l.estimated_value * s.probability / 100.0), 0) AS weighted
                   FROM pipeline_stages s LEFT JOIN leads l ON l.stage_id = s.id AND l.tenant_id = s.tenant_id AND l.deleted_at IS NULL AND $vis
                  WHERE s.pipeline_id = ? AND s.tenant_id = ? AND s.archived_at IS NULL GROUP BY s.id, s.name, s.maps_to_status, s.probability, s.order_index ORDER BY s.order_index",
                array_merge($vp, [$pipelineId, $actor->tenantId])
            );
            if (!$rows) throw Errors::notFound('Pipeline');
            // D3: "won deal value" is the sum of recorded sales, never the estimate typed on the lead
            $vp2 = [];
            Visibility::lead($actor, 'l', $vp2);
            $deals = $tx->one(
                "SELECT COALESCE(SUM(r.deal_value), 0) AS v, COUNT(DISTINCT l.id) AS n FROM reservations r JOIN leads l ON l.id = r.lead_id AND l.tenant_id = r.tenant_id
                  WHERE r.status = 'completed' AND r.tenant_id = ? AND l.pipeline_id = ? AND l.status = 'won' AND l.deleted_at IS NULL AND $vis",
                array_merge([$actor->tenantId, $pipelineId], $vp2)
            );
            $stages = array_map(static fn($r) => ['stageId' => $r['stageId'], 'name' => $r['name'], 'status' => $r['status'], 'probability' => (int) $r['probability'], 'count' => (int) $r['count'], 'value' => (float) $r['value'], 'weighted' => (float) $r['weighted'], 'isClosed' => Vocab::isClosed($r['status'])], $rows);
            $sum = static fn(array $rs, string $k) => array_sum(array_column($rs, $k));
            $open = array_values(array_filter($stages, static fn($r) => !$r['isClosed']));
            $won = array_values(array_filter($stages, static fn($r) => $r['status'] === 'won'));
            $count = (int) $sum($won, 'count');
            return [
                'stages' => $stages,
                'open' => ['count' => (int) $sum($open, 'count'), 'value' => (float) $sum($open, 'value'), 'weighted' => round($sum($open, 'weighted') * 100) / 100],
                'won' => ['count' => $count, 'value' => (float) $sum($won, 'value'), 'dealValue' => (float) $deals['v'], 'withoutDealValue' => max(0, $count - (int) $deals['n'])],
            ];
        });
    }

    // ── export ────────────────────────────────────────────────────────────────────────────────────────────────────

    /** @return array{csv:string,rows:int} */
    public static function exportCsv(Actor $actor, array $f): array
    {
        $actor->assertCan('leads:export');
        return Db::tenant($actor)->tx(static function (Tx $tx) use ($actor, $f) {
            $params = [];
            $where = self::whereFor($tx, $actor, $f, $params);
            $rows = $tx->all(
                'SELECT l.id, l.created_at, c.display_name AS contact, c.email, c.phone, s.name AS stage, l.status, o.name AS owner, l.source, l.lead_score, l.estimated_value, l.currency,
                        l.budget_min, l.budget_max, l.locations, l.tags, l.campaign, l.next_followup_at, l.loss_reason
                   FROM ' . self::FROM . ' LEFT JOIN users o ON o.id = l.owner_id WHERE ' . implode(' AND ', $where) . ' ORDER BY l.created_at DESC, l.id DESC LIMIT 20001',
                $params
            );
            if (count($rows) > 20000) throw Errors::unprocessable('That is more than 20,000 leads. Narrow the filters (for example by date) and export in parts.');
            Audit::log($tx, 'lead.export', 'lead', $actor->tenantId, null, ['rows' => count($rows), 'filters' => $f]);
            $header = ['Lead ID', 'Created', 'Contact', 'Email', 'Phone', 'Stage', 'Status', 'Owner', 'Source', 'Score', 'Estimated value', 'Currency', 'Budget min', 'Budget max', 'Locations', 'Tags', 'Campaign', 'Next follow-up', 'Loss reason'];
            $num = static fn($v) => $v === null ? null : (float) $v + 0;
            return ['rows' => count($rows), 'csv' => Csv::write($header, array_map(static fn($r) => [
                $r['id'], $r['created_at'], $r['contact'], $r['email'], $r['phone'], $r['stage'], $r['status'], $r['owner'], $r['source'], (int) $r['lead_score'], $num($r['estimated_value']), $r['currency'],
                $num($r['budget_min']), $num($r['budget_max']), (array) Json::dec($r['locations'], []), (array) Json::dec($r['tags'], []), $r['campaign'], $r['next_followup_at'], $r['loss_reason'],
            ], $rows))];
        });
    }

    // ── internals ─────────────────────────────────────────────────────────────────────────────────────────────────

    /** Recompute the explainable score from current data and store it (only when it changed, to keep rows quiet). */
    public static function rescore(Tx $tx, string $tenantId, string $leadId): void
    {
        $r = $tx->one(
            'SELECT l.status, s.probability, l.source, c.phone, c.email, l.estimated_value AS ev, l.budget_max AS bmax, l.locations, l.property_types, l.bedrooms_min,
                    l.last_activity_at, l.created_at, l.lead_score, l.score_breakdown
               FROM leads l JOIN pipeline_stages s ON s.id = l.stage_id AND s.tenant_id = l.tenant_id LEFT JOIN contacts c ON c.id = l.contact_id AND c.tenant_id = l.tenant_id
              WHERE l.id = ? AND l.tenant_id = ?',
            [$leadId, $tenantId]
        );
        if (!$r) return;
        $st = self::settings($tx, $tenantId);
        $req = (Json::dec($r['locations'], []) ?: []) || (Json::dec($r['property_types'], []) ?: []) || $r['bedrooms_min'] !== null;
        $res = Scoring::compute([
            'status' => $r['status'], 'stageProbability' => (int) $r['probability'], 'source' => $r['source'], 'hasPhone' => !empty($r['phone']), 'hasEmail' => !empty($r['email']),
            'estimatedValue' => $r['ev'] !== null ? (float) $r['ev'] : null, 'budgetMax' => $r['bmax'] !== null ? (float) $r['bmax'] : null, 'hasRequirements' => (bool) $req,
            'lastActivityAt' => $r['last_activity_at'] ? Time::parse($r['last_activity_at']) : null, 'createdAt' => Time::parse($r['created_at']),
        ], $st['scoring']);
        $json = Json::enc($res['factors']);
        if ((int) $r['lead_score'] !== $res['score'] || $r['score_breakdown'] !== $json) {
            $tx->run('UPDATE leads SET lead_score = ?, score_breakdown = ? WHERE id = ? AND tenant_id = ?', [$res['score'], $json, $leadId, $tenantId]);
        }
    }
}
