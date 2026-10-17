<?php
declare(strict_types=1);

namespace App\Modules\Automation;

use App\Core\Actor;
use App\Core\Audit;
use App\Core\Db;
use App\Core\Errors;
use App\Core\Events;
use App\Core\Ids;
use App\Core\Jobs;
use App\Core\Json;
use App\Core\Pagination;
use App\Core\Tx;

/** Fixed, reviewable automation templates. There are deliberately no user supplied scripts or URLs. */
final class AutomationService
{
    private const KEY = 'new_lead_follow_up';
    private const TEMPLATE = [
        'key' => self::KEY,
        'name' => 'Follow up every new lead',
        'summary' => 'Makes sure every new lead has an owner, a call task and a nudge, so none waits unnoticed.',
        'trigger' => 'lead_created',
        'triggerLabel' => 'A lead is created: by hand, by import or by a lead source (Meta, Google, website)',
        'steps' => [
            ['id' => 'owner', 'label' => 'Keep the owner if one was chosen. Otherwise assign using the workspace assignment rule.'],
            ['id' => 'task', 'label' => 'Create one call task for the owner, due within the first-reply time.'],
            ['id' => 'notify', 'label' => 'Notify the owner unless they created the lead themselves.'],
        ],
        'usesSettings' => ['leadAssignment', 'firstResponseMinutes'],
    ];

    public static function list(Actor $actor): array
    {
        $actor->assertCan('automation:read');
        return Db::tenant($actor)->read(static function (Tx $tx) {
            $r = $tx->one("SELECT id, status, version, updated_at AS updatedAt,
                 (SELECT COUNT(*) FROM automation_executions e WHERE e.rule_id = ar.id AND e.status = 'completed') AS runCount,
                 (SELECT MAX(completed_at) FROM automation_executions e WHERE e.rule_id = ar.id AND e.status = 'completed') AS lastRunAt,
                 (SELECT COUNT(*) FROM automation_executions e WHERE e.rule_id = ar.id AND e.status = 'failed') AS failedRuns
                 FROM automation_rules ar WHERE ar.template = ? AND ar.status <> 'archived'", [self::KEY]);
            $out = self::TEMPLATE;
            $out['rule'] = $r ? ['id' => $r['id'], 'status' => $r['status'], 'version' => (int) $r['version'], 'runCount' => (int) $r['runCount'], 'lastRunAt' => $r['lastRunAt'], 'updatedAt' => $r['updatedAt']] : null;
            $out['failedRuns'] = $r ? (int) $r['failedRuns'] : 0;
            return [$out];
        });
    }

    public static function enable(Actor $actor, string $key): array { return self::set($actor, $key, 'active'); }
    public static function pause(Actor $actor, string $key): array { return self::set($actor, $key, 'paused'); }

    private static function set(Actor $actor, string $key, string $status): array
    {
        $actor->assertCan('automation:manage'); self::assertKey($key);
        Db::tenant($actor)->tx(static function (Tx $tx) use ($actor, $status) {
            $cur = $tx->one('SELECT id, status FROM automation_rules WHERE template = ? AND status <> \'archived\' FOR UPDATE', [self::KEY]);
            if (!$cur) {
                $id = $tx->insert('automation_rules', ['tenant_id' => $actor->tenantId, 'name' => self::TEMPLATE['name'], 'description' => self::TEMPLATE['summary'], 'trigger_type' => 'lead_created', 'trigger_config' => '{}', 'conditions' => '[]', 'actions' => Json::enc(['owner', 'task', 'notify']), 'status' => $status, 'template' => self::KEY, 'params' => '{}', 'created_by' => $actor->userId, 'updated_by' => $actor->userId, 'active_since' => $status === 'active' ? new \App\Core\Raw('NOW(3)') : null]);
            } else {
                $id = $cur['id'];
                $tx->run('UPDATE automation_rules SET status = ?, version = version + 1, updated_by = ?, active_since = CASE WHEN ? = \'active\' THEN NOW(3) ELSE active_since END WHERE id = ?', [$status, $actor->userId, $status, $id]);
            }
            Audit::log($tx, 'automation.' . ($status === 'active' ? 'enable' : 'pause'), 'automation_rule', $id, null, ['template' => self::KEY]);
            Events::emit($tx, 'automation.updated', $id, ['template' => self::KEY, 'status' => $status]);
        });
        return self::list($actor)[0];
    }

    public static function executions(Actor $actor, array $q): array
    {
        $actor->assertCan('automation:read'); $cursor = Pagination::decode($q['cursor'] ?? null, 'automation.executions');
        return Db::tenant($actor)->read(static function (Tx $tx) use ($q, $cursor) {
            $p=[]; $where=['e.tenant_id = ?']; $p[]=$tx->tenantId;
            if (!empty($q['status'])) { $where[]='e.status = ?'; $p[]=$q['status']; }
            if (!empty($q['leadId'])) { $where[]='e.trigger_entity_id = ?'; $p[]=$q['leadId']; }
            if ($cursor) $where[] = Pagination::predicate($cursor, $p, 'e.created_at', 'e.id', 'desc', 'ts');
            $p[]=(int)$q['limit']+1;
            $rows=$tx->all('SELECT e.id, e.rule_id AS ruleId, r.template, e.status, e.trigger_entity_id AS leadId, c.display_name AS leadName, e.summary, e.error_message AS error, e.attempts, e.execution_log AS steps, e.started_at AS startedAt, e.completed_at AS completedAt, e.created_at AS createdAt FROM automation_executions e JOIN automation_rules r ON r.id=e.rule_id AND r.tenant_id=e.tenant_id LEFT JOIN leads l ON l.id=e.trigger_entity_id AND l.tenant_id=e.tenant_id LEFT JOIN contacts c ON c.id=l.contact_id AND c.tenant_id=l.tenant_id WHERE '.implode(' AND ',$where).' ORDER BY e.created_at DESC,e.id DESC LIMIT ?', $p);
            foreach ($rows as &$r) { $r['attempts']=(int)$r['attempts']; $r['steps']=Json::dec($r['steps'], []); } unset($r);
            $pg=Pagination::page($rows,(int)$q['limit'],'automation.executions',static fn($r)=>$r['createdAt']); return ['items'=>$pg['items'],'page'=>$pg['page']];
        });
    }

    public static function execution(Actor $actor, string $id): array { $r=self::executions($actor,['limit'=>1,'leadId'=>null,'cursor'=>null,'id'=>$id]); return Db::tenant($actor)->read(static function(Tx $tx) use($id) { $row=$tx->one('SELECT e.id,e.rule_id AS ruleId,r.template,e.status,e.trigger_entity_id AS leadId,c.display_name AS leadName,e.summary,e.error_message AS error,e.attempts,e.execution_log AS steps,e.started_at AS startedAt,e.completed_at AS completedAt,e.created_at AS createdAt FROM automation_executions e JOIN automation_rules r ON r.id=e.rule_id AND r.tenant_id=e.tenant_id LEFT JOIN leads l ON l.id=e.trigger_entity_id AND l.tenant_id=e.tenant_id LEFT JOIN contacts c ON c.id=l.contact_id AND c.tenant_id=l.tenant_id WHERE e.id=?',[$id]); if(!$row) throw Errors::notFound('Execution'); $row['attempts']=(int)$row['attempts'];$row['steps']=Json::dec($row['steps'],[]);return $row; }); }

    public static function retry(Actor $actor, string $id): array
    {
        $actor->assertCan('automation:manage');
        Db::tenant($actor)->tx(static function(Tx $tx) use($id) { $e=$tx->one('SELECT id,status,attempts FROM automation_executions WHERE id=? FOR UPDATE',[$id]); if(!$e) throw Errors::notFound('Execution'); if($e['status']!=='failed') throw Errors::conflict('Only a failed run can be retried'); $tx->run("UPDATE automation_executions SET status='pending',error_message=NULL,completed_at=NULL WHERE id=?",[$id]); Jobs::enqueue($tx,['type'=>'automation.retry','payload'=>['executionId'=>$id],'dedupeKey'=>'retry:'.$id.':'.(int)$e['attempts'],'maxAttempts'=>4]); Audit::log($tx,'automation.retry','automation_execution',$id,null,['attempts'=>(int)$e['attempts']]); Events::emit($tx,'automation.updated',$id,['status'=>'pending']); });
        return self::execution($actor,$id);
    }
    private static function assertKey(string $key): void { if ($key !== self::KEY) throw Errors::notFound('Automation template'); }
}
