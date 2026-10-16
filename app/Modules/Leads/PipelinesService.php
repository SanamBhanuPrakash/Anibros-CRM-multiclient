<?php
declare(strict_types=1);

namespace App\Modules\Leads;

use App\Core\Actor;
use App\Core\Audit;
use App\Core\Db;
use App\Core\Errors;
use App\Core\Events;
use App\Core\Json;
use App\Core\Raw;
use App\Core\Text;
use App\Core\Tx;

/** Pipelines, their stages and the transition matrix. Invariants every pipeline keeps: >= 2 stages, a non-closing entry stage, a way to win and a way to lose. */
final class PipelinesService
{
    private const STANDARD_STAGES = [
        ['key' => 'new', 'name' => 'New', 'color' => '#6F86A3', 'mapsToStatus' => 'open', 'probability' => 5],
        ['key' => 'contacted', 'name' => 'Contacted', 'color' => '#8A7F5C', 'mapsToStatus' => 'contacted', 'probability' => 15],
        ['key' => 'qualified', 'name' => 'Qualified', 'color' => '#6B8F71', 'mapsToStatus' => 'qualified', 'probability' => 30],
        ['key' => 'negotiation', 'name' => 'Negotiation', 'color' => '#9A7B6B', 'mapsToStatus' => 'negotiation', 'probability' => 70],
        ['key' => 'won', 'name' => 'Won', 'color' => '#2E7D4F', 'mapsToStatus' => 'won', 'probability' => 100],
        ['key' => 'lost', 'name' => 'Lost', 'color' => '#B3382C', 'mapsToStatus' => 'lost', 'probability' => 0],
    ];

    private static function slug(string $s): string
    {
        return Text::slugify($s, 40, 'stage', '_');
    }

    // ── reads ─────────────────────────────────────────────────────────────────────────────────────────────────────

    public static function list(Actor $actor): array
    {
        $actor->assertCan('pipeline:read');
        return Db::tenant($actor)->read(static fn(Tx $tx) => self::loadAll($tx, $actor));
    }

    public static function get(Actor $actor, string $id): array
    {
        $actor->assertCan('pipeline:read');
        return Db::tenant($actor)->read(static function (Tx $tx) use ($actor, $id) {
            $p = self::loadAll($tx, $actor, $id)[0] ?? null;
            if (!$p) throw Errors::notFound('Pipeline');
            return $p;
        });
    }

    // ── pipelines ─────────────────────────────────────────────────────────────────────────────────────────────────

    /** @param array{name:string,description?:?string,copyFromId?:?string,empty?:?bool} $in */
    public static function create(Actor $actor, array $in): array
    {
        $actor->assertCan('pipeline:manage');
        return Db::tenant($actor)->tx(static function (Tx $tx) use ($actor, $in) {
            $n = (int) $tx->val('SELECT COUNT(*) FROM pipelines WHERE tenant_id = ? AND archived_at IS NULL', [$actor->tenantId]);
            if ($n >= 20) throw Errors::conflict('A workspace can have at most 20 pipelines');
            $id = $tx->insert('pipelines', ['tenant_id' => $actor->tenantId, 'name' => trim($in['name']), 'description' => !empty($in['description']) ? trim($in['description']) : null, 'is_default' => $n === 0 ? 1 : 0]);
            $stages = self::STANDARD_STAGES;
            if (!empty($in['copyFromId'])) {
                $src = $tx->all('SELECT name, stage_key AS `key`, color, maps_to_status AS mapsToStatus, probability FROM pipeline_stages WHERE pipeline_id = ? AND tenant_id = ? AND archived_at IS NULL ORDER BY order_index', [$in['copyFromId'], $actor->tenantId]);
                if (!$src) throw Errors::notFound('Pipeline to copy');
                $stages = array_map(static fn($r) => ['key' => $r['key'], 'name' => $r['name'], 'color' => $r['color'], 'mapsToStatus' => $r['mapsToStatus'], 'probability' => (int) $r['probability']], $src);
            }
            if (empty($in['empty'])) {
                foreach ($stages as $i => $s) {
                    [$status, $prob] = self::withDefaults($s['key'] ?? self::slug($s['name']), $s['mapsToStatus'], (int) $s['probability']);
                    $tx->insert('pipeline_stages', ['tenant_id' => $actor->tenantId, 'pipeline_id' => $id, 'name' => $s['name'], 'stage_key' => $s['key'] ?? self::slug($s['name']), 'order_index' => $i, 'color' => $s['color'], 'maps_to_status' => $status, 'probability' => $prob]);
                }
            }
            $dto = self::loadAll($tx, $actor, $id)[0];
            Audit::log($tx, 'pipeline.create', 'pipeline', $id, null, ['name' => $dto['name'], 'stages' => array_column($dto['stages'], 'name')]);
            Events::emit($tx, 'pipeline.updated', $id);
            return $dto;
        });
    }

    /** What the pipeline_stage_defaults trigger did: a known stage key left at the column defaults takes that key's usual status and probability. */
    private static function withDefaults(string $key, string $status, int $prob): array
    {
        if ($status === 'open' && $prob === 0 && isset(Seeds::STAGE_DEFAULTS[$key])) return Seeds::STAGE_DEFAULTS[$key];
        return [$status, $prob];
    }

    public static function update(Actor $actor, string $id, array $patch): array
    {
        $actor->assertCan('pipeline:manage');
        return Db::tenant($actor)->tx(static function (Tx $tx) use ($actor, $id, $patch) {
            $cur = $tx->one('SELECT id, is_default FROM pipelines WHERE id = ? AND tenant_id = ? AND archived_at IS NULL FOR UPDATE', [$id, $actor->tenantId]);
            if (!$cur) throw Errors::notFound('Pipeline');
            if (array_key_exists('isDefault', $patch) && $patch['isDefault'] === false && $cur['is_default']) throw Errors::conflict('Choose another pipeline as the default instead');
            if (!empty($patch['isDefault'])) $tx->run('UPDATE pipelines SET is_default = 0 WHERE tenant_id = ? AND is_default = 1 AND id <> ?', [$actor->tenantId, $id]);
            $set = [];
            if (isset($patch['name'])) $set['name'] = trim($patch['name']);
            if (array_key_exists('description', $patch)) $set['description'] = !empty($patch['description']) ? trim($patch['description']) : null;
            if (isset($patch['isDefault'])) $set['is_default'] = $patch['isDefault'] ? 1 : 0;
            if (isset($patch['reasonCodesRequired'])) $set['reason_codes_required'] = $patch['reasonCodesRequired'] ? 1 : 0;
            if (isset($patch['dealValueRequired'])) $set['deal_value_required'] = $patch['dealValueRequired'] ? 1 : 0;
            if ($set) $tx->update('pipelines', $set, 'id = ? AND tenant_id = ?', [$id, $actor->tenantId]);
            Audit::log($tx, 'pipeline.update', 'pipeline', $id, null, $patch);
            Events::emit($tx, 'pipeline.updated', $id);
            return self::loadAll($tx, $actor, $id)[0];
        });
    }

    /**
     * Add the recommended beginner pipeline to an EXISTING workspace (decision D7). It never replaces or edits anything: it adds a second pipeline.
     * Existing leads stay where they are; `makeDefault` only decides where NEW leads start. Safe to repeat (the second call returns the pipeline already added).
     *
     * @return array{pipeline:array,created:bool}
     */
    public static function applyRecommended(Actor $actor, array $opts): array
    {
        $actor->assertCan('pipeline:manage');
        return Db::tenant($actor)->tx(static function (Tx $tx) use ($actor, $opts) {
            $had = $tx->val("SELECT id FROM pipelines WHERE tenant_id = ? AND template = 'beginner_v1' AND archived_at IS NULL", [$actor->tenantId]);
            if ($had) {
                if (!empty($opts['makeDefault'])) self::makeDefaultInTx($tx, $actor, $had);
                return ['pipeline' => self::loadAll($tx, $actor, $had)[0], 'created' => false];
            }
            $count = (int) $tx->val('SELECT COUNT(*) FROM pipelines WHERE tenant_id = ? AND archived_at IS NULL', [$actor->tenantId]);
            if ($count >= 20) throw Errors::conflict('A workspace can have at most 20 pipelines');
            $makeDefault = !empty($opts['makeDefault']) || $count === 0;
            $id = Seeds::beginnerPipeline($tx, $actor->tenantId, 'Recommended pipeline', $makeDefault);
            Audit::log($tx, 'pipeline.apply_recommended', 'pipeline', $id, null, ['template' => 'beginner_v1', 'madeDefault' => $makeDefault]);
            Events::emit($tx, 'pipeline.updated', $id);
            return ['pipeline' => self::loadAll($tx, $actor, $id)[0], 'created' => true];
        });
    }

    private static function makeDefaultInTx(Tx $tx, Actor $actor, string $id): void
    {
        $cur = $tx->one('SELECT is_default FROM pipelines WHERE id = ? AND tenant_id = ? FOR UPDATE', [$id, $actor->tenantId]);
        if ($cur && $cur['is_default']) return;
        $tx->run('UPDATE pipelines SET is_default = 0 WHERE tenant_id = ? AND is_default = 1 AND id <> ?', [$actor->tenantId, $id]);
        $tx->run('UPDATE pipelines SET is_default = 1 WHERE id = ? AND tenant_id = ?', [$id, $actor->tenantId]);
        Audit::log($tx, 'pipeline.update', 'pipeline', $id, null, ['isDefault' => true]);
        Events::emit($tx, 'pipeline.updated', $id);
    }

    public static function archive(Actor $actor, string $id): void
    {
        $actor->assertCan('pipeline:manage');
        Db::tenant($actor)->tx(static function (Tx $tx) use ($actor, $id) {
            $cur = $tx->one('SELECT is_default FROM pipelines WHERE id = ? AND tenant_id = ? AND archived_at IS NULL FOR UPDATE', [$id, $actor->tenantId]);
            if (!$cur) throw Errors::notFound('Pipeline');
            if ($cur['is_default']) throw Errors::conflict('The default pipeline cannot be archived. Make another pipeline the default first.');
            $open = (int) $tx->val('SELECT COUNT(*) FROM leads WHERE pipeline_id = ? AND tenant_id = ? AND deleted_at IS NULL AND closed_at IS NULL', [$id, $actor->tenantId]);
            if ($open > 0) throw Errors::conflict("This pipeline still has $open open lead" . ($open === 1 ? '' : 's') . '. Move or close them first.');
            $tx->run('UPDATE pipelines SET archived_at = NOW(3) WHERE id = ? AND tenant_id = ?', [$id, $actor->tenantId]);
            Audit::log($tx, 'pipeline.archive', 'pipeline', $id);
            Events::emit($tx, 'pipeline.updated', $id);
        });
    }

    // ── stages ────────────────────────────────────────────────────────────────────────────────────────────────────

    /** @param array{name:string,color?:?string,mapsToStatus?:?string,probability?:?int,afterStageId?:?string} $in */
    public static function addStage(Actor $actor, string $pipelineId, array $in): array
    {
        $actor->assertCan('pipeline:manage');
        return Db::tenant($actor)->tx(static function (Tx $tx) use ($actor, $pipelineId, $in) {
            $order = self::lockPipelineStages($tx, $actor, $pipelineId);
            if (count($order) >= 25) throw Errors::conflict('A pipeline can have at most 25 stages');
            $existing = array_flip($tx->col('SELECT stage_key FROM pipeline_stages WHERE pipeline_id = ? AND tenant_id = ? AND archived_at IS NULL', [$pipelineId, $actor->tenantId]));
            $base = self::slug($in['name']);
            $key = $base;
            for ($n = 2; isset($existing[$key]); $n++) $key = substr($base, 0, 36) . '_' . $n;
            $status = $in['mapsToStatus'] ?? 'open';
            $sid = $tx->insert('pipeline_stages', [
                'tenant_id' => $actor->tenantId, 'pipeline_id' => $pipelineId, 'name' => trim($in['name']), 'stage_key' => $key, 'order_index' => count($order), 'color' => $in['color'] ?? '#7C8C8A',
                'maps_to_status' => $status, 'probability' => self::probabilityFor($status, $in['probability'] ?? null),
            ]);
            $ids = array_column($order, 'id');
            $at = !empty($in['afterStageId']) ? array_search($in['afterStageId'], $ids, true) : false;
            if (!empty($in['afterStageId']) && $at === false) throw Errors::validation([['path' => 'body.afterStageId', 'message' => 'Unknown stage']]);
            array_splice($ids, $at === false ? count($ids) : $at + 1, 0, [$sid]);
            self::applyOrder($tx, $actor, $pipelineId, $ids);
            self::assertShape($tx, $actor, $pipelineId);
            Audit::log($tx, 'pipeline.stage_add', 'pipeline', $pipelineId, null, ['name' => $in['name'], 'status' => $status]);
            Events::emit($tx, 'pipeline.updated', $pipelineId);
            return self::loadAll($tx, $actor, $pipelineId)[0];
        });
    }

    public static function updateStage(Actor $actor, string $pipelineId, string $stageId, array $patch): array
    {
        $actor->assertCan('pipeline:manage');
        return Db::tenant($actor)->tx(static function (Tx $tx) use ($actor, $pipelineId, $stageId, $patch) {
            self::lockPipelineStages($tx, $actor, $pipelineId);
            $cur = $tx->one('SELECT maps_to_status AS status, probability FROM pipeline_stages WHERE id = ? AND pipeline_id = ? AND tenant_id = ? AND archived_at IS NULL FOR UPDATE', [$stageId, $pipelineId, $actor->tenantId]);
            if (!$cur) throw Errors::notFound('Stage');
            $status = $patch['mapsToStatus'] ?? $cur['status'];
            $probability = $patch['probability'] ?? (isset($patch['mapsToStatus']) ? self::probabilityFor($status, null) : (int) $cur['probability']);
            $set = ['maps_to_status' => $status, 'probability' => self::probabilityFor($status, $probability)];
            if (isset($patch['name'])) $set['name'] = trim($patch['name']);
            if (isset($patch['color'])) $set['color'] = $patch['color'];
            $tx->update('pipeline_stages', $set, 'id = ? AND pipeline_id = ? AND tenant_id = ?', [$stageId, $pipelineId, $actor->tenantId]);
            // Leads sitting in the stage must follow its new meaning (status / closed_at are derived from the stage).
            if ($status !== $cur['status']) {
                $closing = Vocab::isClosed($status);
                $tx->run(
                    'UPDATE leads SET status = ?, closed_at = ' . ($closing ? 'COALESCE(closed_at, NOW(3))' : 'NULL') . ', loss_reason = ' . ($closing ? 'loss_reason' : 'NULL') . ', loss_reason_code = ' . ($closing ? 'loss_reason_code' : 'NULL') . ', version = version + 1 WHERE stage_id = ? AND tenant_id = ?',
                    [$status, $stageId, $actor->tenantId]
                );
            }
            self::assertShape($tx, $actor, $pipelineId);
            Audit::log($tx, 'pipeline.stage_update', 'pipeline', $pipelineId, null, ['stageId' => $stageId] + $patch);
            Events::emit($tx, 'pipeline.updated', $pipelineId);
            return self::loadAll($tx, $actor, $pipelineId)[0];
        });
    }

    public static function archiveStage(Actor $actor, string $pipelineId, string $stageId, ?string $moveLeadsTo = null): array
    {
        $actor->assertCan('pipeline:manage');
        return Db::tenant($actor)->tx(static function (Tx $tx) use ($actor, $pipelineId, $stageId, $moveLeadsTo) {
            $order = self::lockPipelineStages($tx, $actor, $pipelineId);
            $ids = array_column($order, 'id');
            if (!in_array($stageId, $ids, true)) throw Errors::notFound('Stage');
            if (count($order) <= 2) throw Errors::conflict('A pipeline needs at least two stages');
            $used = (int) $tx->val('SELECT COUNT(*) FROM leads WHERE stage_id = ? AND tenant_id = ? AND deleted_at IS NULL', [$stageId, $actor->tenantId]);
            if ($used > 0) {
                if (!$moveLeadsTo) throw Errors::conflict("$used lead" . ($used === 1 ? ' is' : 's are') . ' in this stage. Choose a stage to move them to.', ['reason' => 'stage_in_use', 'leadCount' => $used]);
                if ($moveLeadsTo === $stageId || !in_array($moveLeadsTo, $ids, true)) throw Errors::validation([['path' => 'body.moveLeadsTo', 'message' => 'Choose a different stage of this pipeline']]);
                $target = $tx->one('SELECT stage_key, name, maps_to_status AS status, probability FROM pipeline_stages WHERE id = ? AND tenant_id = ?', [$moveLeadsTo, $actor->tenantId]);
                $leads = $tx->all('SELECT id, stage_id FROM leads WHERE stage_id = ? AND tenant_id = ? AND deleted_at IS NULL FOR UPDATE', [$stageId, $actor->tenantId]);
                foreach ($leads as $l) LeadsService::recordHistory($tx, $actor->tenantId, $l['id'], $l['stage_id'], $moveLeadsTo, $actor->userId, 'Stage removed from pipeline', null);
                $closing = Vocab::isClosed($target['status']);
                $tx->run(
                    'UPDATE leads SET stage_id = ?, stage_key = ?, status = ?, closed_at = ' . ($closing ? 'NOW(3)' : 'NULL') . ', loss_reason = ' . ($closing ? 'loss_reason' : 'NULL') . ', loss_reason_code = ' . ($closing ? 'loss_reason_code' : 'NULL') . ', version = version + 1 WHERE stage_id = ? AND tenant_id = ? AND deleted_at IS NULL',
                    [$moveLeadsTo, $target['stage_key'], $target['status'], $stageId, $actor->tenantId]
                );
            }
            $tx->run('UPDATE pipeline_stages SET archived_at = NOW(3) WHERE id = ? AND tenant_id = ?', [$stageId, $actor->tenantId]);
            $tx->run('DELETE FROM pipeline_stage_transitions WHERE pipeline_id = ? AND tenant_id = ? AND (from_stage_id = ? OR to_stage_id = ?)', [$pipelineId, $actor->tenantId, $stageId, $stageId]);
            self::applyOrder($tx, $actor, $pipelineId, array_values(array_filter($ids, static fn($i) => $i !== $stageId)));
            self::assertShape($tx, $actor, $pipelineId);
            Audit::log($tx, 'pipeline.stage_archive', 'pipeline', $pipelineId, null, ['stageId' => $stageId, 'moveLeadsTo' => $moveLeadsTo]);
            Events::emit($tx, 'pipeline.updated', $pipelineId);
            return self::loadAll($tx, $actor, $pipelineId)[0];
        });
    }

    /** @param string[] $stageIds */
    public static function reorder(Actor $actor, string $pipelineId, array $stageIds): array
    {
        $actor->assertCan('pipeline:manage');
        return Db::tenant($actor)->tx(static function (Tx $tx) use ($actor, $pipelineId, $stageIds) {
            $order = self::lockPipelineStages($tx, $actor, $pipelineId);
            $have = array_flip(array_column($order, 'id'));
            $bad = count($stageIds) !== count($have) || count(array_unique($stageIds)) !== count($stageIds);
            foreach ($stageIds as $i) if (!isset($have[$i])) $bad = true;
            if ($bad) throw Errors::validation([['path' => 'body.stageIds', 'message' => 'List every stage of the pipeline exactly once']]);
            self::applyOrder($tx, $actor, $pipelineId, $stageIds);
            self::assertShape($tx, $actor, $pipelineId);
            Audit::log($tx, 'pipeline.reorder', 'pipeline', $pipelineId, null, ['stageIds' => $stageIds]);
            Events::emit($tx, 'pipeline.updated', $pipelineId);
            return self::loadAll($tx, $actor, $pipelineId)[0];
        });
    }

    // ── transition matrix ─────────────────────────────────────────────────────────────────────────────────────────

    /** `restricted: false` means no rules stored: any stage may follow any other. */
    public static function transitions(Actor $actor, string $pipelineId): array
    {
        $actor->assertCan('pipeline:read');
        return Db::tenant($actor)->read(static function (Tx $tx) use ($actor, $pipelineId) {
            if (!$tx->exists('SELECT 1 FROM pipelines WHERE id = ? AND tenant_id = ? AND archived_at IS NULL', [$pipelineId, $actor->tenantId])) throw Errors::notFound('Pipeline');
            $rows = $tx->all(
                'SELECT t.from_stage_id AS fromStageId, t.to_stage_id AS toStageId, t.required_fields AS requiredFields
                   FROM pipeline_stage_transitions t
                   JOIN pipeline_stages f ON f.id = t.from_stage_id AND f.archived_at IS NULL
                   JOIN pipeline_stages s ON s.id = t.to_stage_id AND s.archived_at IS NULL
                  WHERE t.pipeline_id = ? AND t.tenant_id = ? AND t.is_allowed = 1 ORDER BY f.order_index, s.order_index',
                [$pipelineId, $actor->tenantId]
            );
            foreach ($rows as &$r) $r['requiredFields'] = array_values((array) Json::dec($r['requiredFields'], []));
            return ['restricted' => count($rows) > 0, 'transitions' => $rows];
        });
    }

    /** @param array{restricted:bool,transitions:array<int,array{fromStageId:string,toStageId:string,requiredFields:string[]}>} $in */
    public static function setTransitions(Actor $actor, string $pipelineId, array $in): array
    {
        $actor->assertCan('pipeline:manage');
        return Db::tenant($actor)->tx(static function (Tx $tx) use ($actor, $pipelineId, $in) {
            self::lockPipelineStages($tx, $actor, $pipelineId);
            $byId = [];
            foreach ($tx->all('SELECT id, stage_key FROM pipeline_stages WHERE pipeline_id = ? AND tenant_id = ? AND archived_at IS NULL', [$pipelineId, $actor->tenantId]) as $r) $byId[$r['id']] = $r['stage_key'];
            if ($in['restricted']) {
                if (!$in['transitions']) throw Errors::validation([['path' => 'body.transitions', 'message' => 'A restricted pipeline needs at least one allowed move (or switch to “any stage to any stage”)']]);
                $seen = [];
                foreach ($in['transitions'] as $i => $t) {
                    if (!isset($byId[$t['fromStageId']]) || !isset($byId[$t['toStageId']]) || $t['fromStageId'] === $t['toStageId']) throw Errors::validation([['path' => "body.transitions.$i", 'message' => 'Both stages must belong to this pipeline and differ']]);
                    $k = $t['fromStageId'] . '>' . $t['toStageId'];
                    if (isset($seen[$k])) throw Errors::validation([['path' => "body.transitions.$i", 'message' => 'Duplicate move']]);
                    $seen[$k] = true;
                }
            }
            $tx->run('DELETE FROM pipeline_stage_transitions WHERE pipeline_id = ? AND tenant_id = ?', [$pipelineId, $actor->tenantId]);
            if ($in['restricted']) {
                foreach ($in['transitions'] as $t) {
                    $tx->insert('pipeline_stage_transitions', [
                        'tenant_id' => $actor->tenantId, 'pipeline_id' => $pipelineId, 'from_stage_id' => $t['fromStageId'], 'to_stage_id' => $t['toStageId'],
                        'from_stage_key' => $byId[$t['fromStageId']], 'to_stage_key' => $byId[$t['toStageId']], 'is_allowed' => 1, 'required_fields' => Json::enc(array_values(array_unique($t['requiredFields'] ?? []))),
                    ]);
                }
            }
            Audit::log($tx, 'pipeline.transitions', 'pipeline', $pipelineId, null, ['restricted' => $in['restricted'], 'count' => count($in['transitions'])]);
            Events::emit($tx, 'pipeline.updated', $pipelineId);
            return ['restricted' => $in['restricted'], 'transitions' => $in['restricted'] ? $in['transitions'] : []];
        });
    }

    // ── internals ─────────────────────────────────────────────────────────────────────────────────────────────────

    private static function probabilityFor(string $status, ?int $requested): int
    {
        if ($status === 'won') return 100;
        if ($status === 'lost' || $status === 'disqualified') return 0;
        return min(99, max(0, (int) round($requested ?? 0)));
    }

    /** @return array<int,array{id:string}> */
    private static function lockPipelineStages(Tx $tx, Actor $actor, string $pipelineId): array
    {
        if (!$tx->exists('SELECT id FROM pipelines WHERE id = ? AND tenant_id = ? AND archived_at IS NULL FOR UPDATE', [$pipelineId, $actor->tenantId])) throw Errors::notFound('Pipeline');
        return $tx->all('SELECT id FROM pipeline_stages WHERE pipeline_id = ? AND tenant_id = ? AND archived_at IS NULL ORDER BY order_index, created_at, id', [$pipelineId, $actor->tenantId]);
    }

    /** @param string[] $ids */
    private static function applyOrder(Tx $tx, Actor $actor, string $pipelineId, array $ids): void
    {
        foreach (array_values($ids) as $pos => $sid) $tx->run('UPDATE pipeline_stages SET order_index = ? WHERE id = ? AND pipeline_id = ? AND tenant_id = ?', [$pos, $sid, $pipelineId, $actor->tenantId]);
    }

    private static function assertShape(Tx $tx, Actor $actor, string $pipelineId): void
    {
        $statuses = $tx->col('SELECT maps_to_status FROM pipeline_stages WHERE pipeline_id = ? AND tenant_id = ? AND archived_at IS NULL ORDER BY order_index', [$pipelineId, $actor->tenantId]);
        if (count($statuses) < 2) throw Errors::conflict('A pipeline needs at least two stages');
        if (Vocab::isClosed($statuses[0])) throw Errors::conflict('The first stage cannot be a closing stage (won, lost or disqualified)');
        if (!in_array('won', $statuses, true)) throw Errors::conflict('A pipeline needs a stage that means “won”');
        if (!in_array('lost', $statuses, true) && !in_array('disqualified', $statuses, true)) throw Errors::conflict('A pipeline needs a stage that means “lost”');
    }

    /** @return array<int,array<string,mixed>> */
    private static function loadAll(Tx $tx, Actor $actor, ?string $onlyId = null): array
    {
        $vp = [];
        $vis = Visibility::lead($actor, 'l', $vp);
        $counts = $vis === false ? '0' : "(SELECT COUNT(*) FROM leads l WHERE l.stage_id = s.id AND l.tenant_id = s.tenant_id AND l.deleted_at IS NULL AND $vis)";
        $params = $vis === false ? [] : $vp;
        $params[] = $actor->tenantId;
        $idFilter = '';
        if ($onlyId) { $idFilter = 'AND p.id = ?'; $params[] = $onlyId; }
        $rows = $tx->all(
            "SELECT p.id AS pipelineId, p.name AS pipelineName, p.description, p.is_default AS isDefault, p.reason_codes_required AS reasonCodesRequired, p.deal_value_required AS dealValueRequired, p.template,
                    s.id AS stageId, s.name AS stageName, s.stage_key AS stageKey, s.order_index AS orderIndex, s.color, s.maps_to_status AS mapsToStatus, s.probability, $counts AS leadCount
               FROM pipelines p LEFT JOIN pipeline_stages s ON s.pipeline_id = p.id AND s.tenant_id = p.tenant_id AND s.archived_at IS NULL
              WHERE p.tenant_id = ? AND p.archived_at IS NULL $idFilter
              ORDER BY p.is_default DESC, p.created_at, p.id, s.order_index",
            $params
        );
        $out = [];
        foreach ($rows as $r) {
            $out[$r['pipelineId']] ??= [
                'id' => $r['pipelineId'], 'name' => $r['pipelineName'], 'description' => $r['description'], 'isDefault' => (bool) $r['isDefault'], 'reasonCodesRequired' => (bool) $r['reasonCodesRequired'],
                'dealValueRequired' => (bool) $r['dealValueRequired'], 'template' => $r['template'], 'stages' => [],
            ];
            if ($r['stageId']) {
                $out[$r['pipelineId']]['stages'][] = [
                    'id' => $r['stageId'], 'name' => $r['stageName'], 'stageKey' => $r['stageKey'], 'orderIndex' => (int) $r['orderIndex'], 'color' => $r['color'], 'mapsToStatus' => $r['mapsToStatus'],
                    'probability' => (int) $r['probability'], 'isClosed' => Vocab::isClosed($r['mapsToStatus']), 'leadCount' => (int) $r['leadCount'],
                ];
            }
        }
        return array_values($out);
    }
}
