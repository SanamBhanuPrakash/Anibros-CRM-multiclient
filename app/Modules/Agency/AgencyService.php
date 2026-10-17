<?php
declare(strict_types=1);

namespace App\Modules\Agency;

use App\Core\Actor;
use App\Core\Audit;
use App\Core\Csv;
use App\Core\Db;
use App\Core\Errors;
use App\Core\Events;
use App\Core\Ids;
use App\Core\Json;
use App\Core\Raw;
use App\Core\Time;
use App\Core\Tx;
use App\Modules\Analytics\AttributionService;
use App\Modules\Analytics\ReportsService;
use App\Modules\CustomFields\CustomFields;
use App\Modules\CustomFields\FieldRules;
use App\Modules\Dashboard\DashboardOverviewService;
use App\Modules\Leads\LeadsService;
use App\Modules\Leads\Seeds;
use App\Modules\Leads\Vocab;
use App\Modules\Members\MembersService;

/**
 * Agency operations span several client workspaces without weakening their isolation.
 * Every cross-client operation first derives the caller's agency/HQ membership and then
 * opens a distinct tenant transaction for each client it is allowed to touch.
 */
final class AgencyService
{
    public const CLIENT_ROLES = ['sales_agent', 'sales_manager', 'marketing_manager', 'marketing_analyst', 'executive_readonly'];
    public const INDUSTRIES = [
        ['code' => 'real_estate', 'label' => 'Real estate', 'appointment' => 'Site visit', 'realEstateModules' => true],
        ['code' => 'healthcare', 'label' => 'Healthcare / clinic', 'appointment' => 'Appointment', 'realEstateModules' => false],
        ['code' => 'restaurant', 'label' => 'Restaurant / food', 'appointment' => 'Table booking', 'realEstateModules' => false],
        ['code' => 'finance', 'label' => 'Bank / finance', 'appointment' => 'Meeting', 'realEstateModules' => false],
        ['code' => 'retail', 'label' => 'Shop / retail', 'appointment' => 'Store visit', 'realEstateModules' => false],
        ['code' => 'education', 'label' => 'Education / coaching', 'appointment' => 'Counselling session', 'realEstateModules' => false],
        ['code' => 'beauty_wellness', 'label' => 'Beauty / wellness', 'appointment' => 'Appointment', 'realEstateModules' => false],
        ['code' => 'automotive', 'label' => 'Automotive', 'appointment' => 'Test drive', 'realEstateModules' => false],
        ['code' => 'travel', 'label' => 'Travel / hospitality', 'appointment' => 'Consultation', 'realEstateModules' => false],
        ['code' => 'professional_services', 'label' => 'Professional services', 'appointment' => 'Meeting', 'realEstateModules' => false],
        ['code' => 'other', 'label' => 'Other', 'appointment' => 'Appointment', 'realEstateModules' => false],
    ];

    /** @return array{orgId:string,orgName:string,hqId:string,userId:string,role:string,isAdmin:bool,isOwner:bool} */
    private static function context(Actor $actor): array
    {
        if ($actor->kind !== 'user' || !$actor->userId) throw Errors::forbidden('Agency tools are for signed-in staff');
        $r = Db::asUser($actor->userId)->one(
            "SELECT o.id AS orgId,o.name AS orgName,hq.id AS hqId,m.role
               FROM tenants cur JOIN organisations o ON o.id=cur.organisation_id
               JOIN tenants hq ON hq.organisation_id=o.id AND hq.kind='agency_hq' AND hq.is_active=1
               JOIN tenant_memberships m ON m.tenant_id=hq.id AND m.user_id=? AND m.status='active' AND m.is_active=1
              WHERE cur.id=?",
            [$actor->userId, $actor->tenantId]
        );
        if (!$r) throw Errors::forbidden('Only agency staff can use this');
        $isAdmin = in_array($r['role'], ['tenant_owner', 'tenant_admin'], true);
        $ctx = ['orgId' => $r['orgId'], 'orgName' => $r['orgName'], 'hqId' => $r['hqId'], 'userId' => $actor->userId, 'role' => $r['role'], 'isAdmin' => $isAdmin, 'isOwner' => $r['role'] === 'tenant_owner'];
        self::reconcileAdminMemberships($ctx);
        return $ctx;
    }

    /** Agency admin membership is derived into every client; normal staff must be explicitly assigned. */
    private static function reconcileAdminMemberships(array $ctx): void
    {
        if (!$ctx['isAdmin']) return;
        Db::identity()->tx(static function (Tx $tx) use ($ctx): void {
            $clients = $tx->col("SELECT id FROM tenants WHERE organisation_id=? AND kind='client'", [$ctx['orgId']]);
            foreach ($clients as $clientId) {
                $tx->insert('tenant_memberships', ['tenant_id' => $clientId, 'user_id' => $ctx['userId'], 'role' => $ctx['role'], 'status' => 'active', 'is_active' => 1, 'invited_by' => $ctx['userId']], [
                    'onDuplicate' => ['role' => $ctx['role'], 'status' => 'active', 'is_active' => 1, 'updated_by' => $ctx['userId']],
                ]);
            }
        });
    }

    private static function requireAdmin(array $ctx): void
    {
        if (!$ctx['isAdmin']) throw Errors::forbidden('Only agency admins can do this');
    }

    private static function clientActor(array $ctx, Actor $request, array $client): Actor
    {
        return new Actor($client['id'], $ctx['userId'], $client['myRole'], 'user', null, null, $request->requestId, $request->ip);
    }

    /** @return array<int,array<string,mixed>> */
    private static function accessibleClients(array $ctx, string $status = 'active', ?string $id = null): array
    {
        $sql = "SELECT t.id,t.name,t.slug,t.industry,t.website,t.contact_name AS contactName,t.contact_email AS contactEmail,t.contact_phone AS contactPhone,t.notes,t.timezone,t.default_currency AS currency,t.archived_at AS archivedAt,t.created_at AS createdAt,m.role AS myRole
                  FROM tenants t LEFT JOIN tenant_memberships m ON m.tenant_id=t.id AND m.user_id=? AND m.status='active' AND m.is_active=1
                 WHERE t.organisation_id=? AND t.kind='client'";
        $params = [$ctx['userId'], $ctx['orgId']];
        if ($id) { $sql .= ' AND t.id=?'; $params[] = $id; }
        if ($status === 'active') $sql .= ' AND t.archived_at IS NULL';
        elseif ($status === 'archived') $sql .= ' AND t.archived_at IS NOT NULL';
        if (!$ctx['isAdmin']) $sql .= ' AND m.role IS NOT NULL';
        $sql .= ' ORDER BY LOWER(t.name),t.id';
        return Db::asUser($ctx['userId'])->query($sql, $params);
    }

    private static function client(array $ctx, string $id, bool $includeArchived = true): array
    {
        $rows = self::accessibleClients($ctx, $includeArchived ? 'all' : 'active', $id);
        if (!$rows) throw Errors::notFound('Client');
        return $rows[0];
    }

    private static function dto(array $r, ?array $stats = null, int $assigned = 0): array
    {
        return [
            'id' => $r['id'], 'name' => $r['name'], 'slug' => $r['slug'], 'industry' => $r['industry'], 'website' => $r['website'],
            'contactName' => $r['contactName'], 'contactEmail' => $r['contactEmail'], 'contactPhone' => $r['contactPhone'], 'notes' => $r['notes'],
            'timezone' => $r['timezone'], 'currency' => $r['currency'], 'status' => $r['archivedAt'] ? 'archived' : 'active',
            'createdAt' => $r['createdAt'], 'archivedAt' => $r['archivedAt'], 'myRole' => $r['myRole'] ?? null, 'assignedStaff' => $assigned, 'stats' => $stats,
        ];
    }

    /** @return array{totalLeads:int,openLeads:int,newLast7Days:int,unassigned:int,slaBreached:int,won:int,lastLeadAt:?string,spendThisMonth:?float,currency:string,leadSources:?array,apiKeys:?int}|null */
    private static function stats(array $ctx, Actor $request, array $client): ?array
    {
        if (!$client['myRole']) return null;
        $actor = self::clientActor($ctx, $request, $client);
        if (!$actor->permitted('leads:read')) return null;
        return Db::tenant($actor)->read(static function (Tx $tx) use ($actor, $client): array {
            $own = $actor->scopeFor('leads:read') === 'own' ? ' AND l.owner_id = ?' : '';
            $params = [$actor->tenantId]; if ($own) $params[] = $actor->userId;
            $row = $tx->one("SELECT COUNT(*) AS total, SUM(l.closed_at IS NULL) AS openLeads, SUM(l.created_at >= DATE_SUB(NOW(3), INTERVAL 7 DAY)) AS new7, SUM(l.closed_at IS NULL AND l.owner_id IS NULL) AS unassigned, SUM(l.first_response_at IS NULL AND l.closed_at IS NULL AND l.first_response_due_at < NOW(3)) AS breached, SUM(l.status='won') AS won, MAX(l.created_at) AS lastLeadAt FROM leads l WHERE l.tenant_id=? AND l.deleted_at IS NULL$own", $params) ?? [];
            $spend = $actor->permitted('attribution:read') ? (float) ($tx->val('SELECT COALESCE(SUM(amount),0) FROM marketing_spend WHERE tenant_id=? AND currency=? AND period_start >= DATE_FORMAT(UTC_DATE(),\'%Y-%m-01\')', [$actor->tenantId, $client['currency']]) ?? 0) : null;
            $sources = $actor->permitted('integrations:read') ? $tx->one("SELECT SUM(status='active') AS active,MAX(last_received_at) AS lastReceivedAt FROM inbound_endpoints WHERE tenant_id=? AND deleted_at IS NULL", [$actor->tenantId]) : null;
            $keys = $actor->permitted('api_keys:manage') ? (int) ($tx->val('SELECT COUNT(*) FROM api_keys WHERE tenant_id=? AND revoked_at IS NULL AND (expires_at IS NULL OR expires_at>NOW(3))', [$actor->tenantId]) ?? 0) : null;
            return ['totalLeads'=>(int)($row['total']??0),'openLeads'=>(int)($row['openLeads']??0),'newLast7Days'=>(int)($row['new7']??0),'unassigned'=>(int)($row['unassigned']??0),'slaBreached'=>(int)($row['breached']??0),'won'=>(int)($row['won']??0),'lastLeadAt'=>$row['lastLeadAt']??null,'spendThisMonth'=>$spend,'currency'=>$client['currency'],'leadSources'=>$sources ? ['active'=>(int)$sources['active'],'lastReceivedAt'=>$sources['lastReceivedAt']] : null,'apiKeys'=>$keys];
        });
    }

    private static function assignedCount(array $ctx, string $clientId): int
    {
        return (int) (Db::asUser($ctx['userId'])->query("SELECT COUNT(*) AS n FROM tenant_memberships m JOIN tenants h ON h.id=? LEFT JOIN tenant_memberships hm ON hm.tenant_id=? AND hm.user_id=m.user_id AND hm.status='active' AND hm.is_active=1 WHERE m.tenant_id=? AND m.status='active' AND m.is_active=1 AND COALESCE(hm.role,'') NOT IN ('tenant_owner','tenant_admin')", [$ctx['hqId'], $ctx['hqId'], $clientId])[0]['n'] ?? 0);
    }

    public static function options(): array { return ['industries' => self::INDUSTRIES, 'clientRoles' => self::CLIENT_ROLES]; }

    public static function clients(Actor $actor, string $status, bool $withStats): array
    {
        $ctx = self::context($actor); $rows = self::accessibleClients($ctx, $status);
        return array_map(static fn($c) => self::dto($c, $withStats && !$c['archivedAt'] ? self::stats($ctx, $actor, $c) : null, self::assignedCount($ctx, $c['id'])), $rows);
    }

    public static function get(Actor $actor, string $id): array
    {
        $ctx = self::context($actor); $c = self::client($ctx, $id); return self::dto($c, $c['archivedAt'] ? null : self::stats($ctx, $actor, $c), self::assignedCount($ctx, $id));
    }

    public static function overview(Actor $actor): array
    {
        $ctx = self::context($actor); $clients = self::clients($actor, 'all', true); $active = array_values(array_filter($clients, static fn($c) => $c['status'] === 'active'));
        $sum = static fn(string $k) => array_sum(array_map(static fn($c) => (int)($c['stats'][$k] ?? 0), $active));
        return ['agency'=>['name'=>$ctx['orgName'],'hqTenantId'=>$ctx['hqId'],'isAdmin'=>$ctx['isAdmin'],'isOwner'=>$ctx['isOwner']], 'clients'=>['active'=>count($active),'archived'=>count($clients)-count($active)], 'staff'=>$ctx['isAdmin'] ? (int)(Db::asUser($ctx['userId'])->query("SELECT COUNT(*) AS n FROM tenant_memberships WHERE tenant_id=? AND status='active' AND is_active=1", [$ctx['hqId']])[0]['n'] ?? 0) : null, 'totals'=>['totalLeads'=>$sum('totalLeads'),'openLeads'=>$sum('openLeads'),'newLast7Days'=>$sum('newLast7Days'),'unassigned'=>$sum('unassigned'),'slaBreached'=>$sum('slaBreached'),'won'=>$sum('won')]];
    }

    public static function create(Actor $actor, array $in): array
    {
        $ctx = self::context($actor); self::requireAdmin($ctx);
        $name = trim($in['name']); $industry = $in['industry'] ?? 'other';
        if (!in_array($industry, array_column(self::INDUSTRIES, 'code'), true)) throw Errors::validation([['path'=>'body.industry','message'=>'Unknown industry']]);
        $clientId = Db::identity()->tx(static function (Tx $tx) use ($ctx, $actor, $in, $name, $industry): string {
            $tx->val('SELECT id FROM organisations WHERE id=? FOR UPDATE', [$ctx['orgId']]);
            if ($tx->exists("SELECT 1 FROM tenants WHERE organisation_id=? AND kind='client' AND LOWER(name)=LOWER(?)", [$ctx['orgId'], $name])) throw Errors::conflict('You already have a client with this name');
            $base = trim((string)preg_replace('/-+/', '-', preg_replace('/[^a-z0-9]+/', '-', strtolower($name)))); if ($base === '') $base = 'client'; $base = mb_substr($base, 0, 40);
            $slug = $base; for ($n=2; $tx->exists("SELECT 1 FROM tenants WHERE organisation_id=? AND slug=?", [$ctx['orgId'], $slug]); $n++) $slug = mb_substr($base,0,35).'-'.$n;
            $hq = $tx->one('SELECT timezone,default_currency FROM tenants WHERE id=?', [$ctx['hqId']]);
            $id = $tx->insert('tenants', ['organisation_id'=>$ctx['orgId'],'name'=>$name,'slug'=>$slug,'kind'=>'client','industry'=>$industry,'website'=>self::nullable($in['website']??null),'contact_name'=>self::nullable($in['contactName']??null),'contact_email'=>self::nullable($in['contactEmail']??null),'contact_phone'=>self::nullable($in['contactPhone']??null),'notes'=>self::nullable($in['notes']??null),'timezone'=>$in['timezone']??$hq['timezone'],'default_currency'=>strtoupper($in['currency']??$hq['default_currency']),'settings'=>Json::encObj(['leadAssignment'=>'balanced','sla'=>['firstResponseMinutes'=>30]])]);
            foreach ($tx->all("SELECT user_id,role FROM tenant_memberships WHERE tenant_id=? AND status='active' AND is_active=1 AND role IN ('tenant_owner','tenant_admin')", [$ctx['hqId']]) as $admin) $tx->insert('tenant_memberships',['tenant_id'=>$id,'user_id'=>$admin['user_id'],'role'=>$admin['role'],'status'=>'active','is_active'=>1,'invited_by'=>$ctx['userId']]);
            foreach ($in['staff'] ?? [] as $staff) self::upsertAssignment($tx, $ctx, $id, $staff['userId'], $staff['role']);
            return $id;
        });
        $clientActor = new Actor($clientId, $ctx['userId'], $ctx['role'], 'user', null, null, $actor->requestId, $actor->ip);
        Db::tenant($clientActor)->tx(static function (Tx $tx) use ($clientId, $ctx, $industry): void {
            $pipeline = Seeds::beginnerPipeline($tx, $clientId); Seeds::scoringConfig($tx, $clientId);
            foreach (self::stageNames($industry) as $key => $label) $tx->run('UPDATE pipeline_stages SET name=? WHERE tenant_id=? AND pipeline_id=? AND stage_key=?', [$label,$clientId,$pipeline,$key]);
            if ($industry !== 'real_estate') $tx->run('UPDATE pipelines SET deal_value_required=0 WHERE id=? AND tenant_id=?', [$pipeline,$clientId]);
            CustomFields::seedStarterFields($tx, $clientId, $industry);
            Audit::log($tx,'client.create','tenant',$clientId,null,['industry'=>$industry]); Events::emit($tx,'member.updated',$clientId,['agencyClientCreated'=>true]);
        });
        return self::get($actor, $clientId);
    }

    public static function update(Actor $actor, string $id, array $patch): array
    {
        $ctx = self::context($actor); self::requireAdmin($ctx); $c = self::client($ctx, $id, false);
        $set=[]; foreach(['name'=>'name','industry'=>'industry','website'=>'website','contactName'=>'contact_name','contactEmail'=>'contact_email','contactPhone'=>'contact_phone','notes'=>'notes','timezone'=>'timezone'] as $from=>$to) if(array_key_exists($from,$patch)) $set[$to]=in_array($from,['website','contactName','contactEmail','contactPhone','notes'],true)?self::nullable($patch[$from]):$patch[$from];
        if(array_key_exists('currency',$patch)) $set['default_currency']=strtoupper($patch['currency']);
        if($set) Db::tenant(self::clientActor($ctx,$actor,$c))->tx(static function(Tx $tx) use($id,$set): void { $tx->update('tenants',$set,'id=? AND kind=\'client\'',[$id]); Audit::log($tx,'client.update','tenant',$id,null,['fields'=>array_keys($set)]); });
        return self::get($actor,$id);
    }

    public static function archive(Actor $actor, string $id, bool $archived): array
    {
        $ctx=self::context($actor); self::requireAdmin($ctx); $c=self::client($ctx,$id); Db::tenant(self::clientActor($ctx,$actor,$c))->tx(static function(Tx $tx) use($id,$archived): void { $tx->run('UPDATE tenants SET archived_at=?,is_active=? WHERE id=? AND kind=\'client\'',[$archived?new Raw('NOW(3)'):null,$archived?0:1,$id]); Audit::log($tx,'client.'.($archived?'archive':'restore'),'tenant',$id,null,[]); }); if($archived) \App\Modules\Identity\Sessions::detach($id); return self::get($actor,$id);
    }

    private static function upsertAssignment(Tx $tx,array $ctx,string $clientId,string $userId,string $role,?array $channels=null,bool $channelsSpecified=true): void
    {
        if(!in_array($role,self::CLIENT_ROLES,true)) throw Errors::validation([['path'=>'body.role','message'=>'Invalid client role']]);
        if(!$tx->exists("SELECT 1 FROM tenant_memberships WHERE tenant_id=? AND user_id=? AND status='active' AND is_active=1",[$ctx['hqId'],$userId])) throw Errors::validation([['path'=>'body.userId','message'=>'Employee must be an active agency member']]);
        $workChannels = $channels === null || $channels === [] ? null : Json::enc(array_values($channels));
        $tx->insert('tenant_memberships',['tenant_id'=>$clientId,'user_id'=>$userId,'role'=>$role,'status'=>'active','is_active'=>1,'invited_by'=>$ctx['userId'],'work_channels'=>$workChannels],['onDuplicate'=>['role'=>$role,'status'=>'active','is_active'=>1,'work_channels'=>$channelsSpecified?$workChannels:new Raw('work_channels'),'updated_by'=>$ctx['userId']]]);
    }

    public static function team(Actor $actor,string $id): array
    {
        $ctx=self::context($actor); self::requireAdmin($ctx); self::client($ctx,$id); return Db::asUser($ctx['userId'])->query("SELECT u.id AS userId,u.name,u.email,m.role AS clientRole,m.work_channels AS workChannels,m.created_at AS assignedAt,EXISTS(SELECT 1 FROM tenant_memberships h WHERE h.tenant_id=? AND h.user_id=u.id AND h.status='active' AND h.is_active=1 AND h.role IN ('tenant_owner','tenant_admin')) AS isAgencyAdmin FROM tenant_memberships m JOIN users u ON u.id=m.user_id WHERE m.tenant_id=? AND m.status='active' AND m.is_active=1 ORDER BY isAgencyAdmin DESC,u.name",[$ctx['hqId'],$id]);
    }

    public static function assign(Actor $actor,string $clientId,string $userId,string $role,?array $channels,bool $channelsSpecified=true): array
    {
        $ctx=self::context($actor); self::requireAdmin($ctx); self::client($ctx,$clientId); Db::identity()->tx(static fn(Tx $tx)=>self::upsertAssignment($tx,$ctx,$clientId,$userId,$role,$channels,$channelsSpecified)); return ['userId'=>$userId,'clientId'=>$clientId,'role'=>$role];
    }

    public static function unassign(Actor $actor,string $clientId,string $userId): array
    {
        $ctx=self::context($actor); self::requireAdmin($ctx); $c=self::client($ctx,$clientId); if(Db::asUser($ctx['userId'])->exists("SELECT 1 FROM tenant_memberships WHERE tenant_id=? AND user_id=? AND role IN ('tenant_owner','tenant_admin') AND status='active' AND is_active=1",[$ctx['hqId'],$userId])) throw Errors::conflict('Agency admins work in every client and cannot be removed here');
        $a=self::clientActor($ctx,$actor,$c); $n=Db::tenant($a)->tx(static function(Tx $tx) use($clientId,$userId): int { $n=$tx->run('UPDATE leads SET owner_id=NULL,assigned_at=NULL WHERE tenant_id=? AND owner_id=? AND closed_at IS NULL AND deleted_at IS NULL',[$clientId,$userId]); $tx->run("UPDATE tenant_memberships SET status='revoked',is_active=0 WHERE tenant_id=? AND user_id=?",[$clientId,$userId]); Audit::log($tx,'agency.client_unassign','membership',$userId,null,['leadsUnassigned'=>$n]); return $n; }); \App\Modules\Identity\Sessions::detach($clientId,$userId); return ['leadsUnassigned'=>$n];
    }

    public static function staff(Actor $actor): array
    {
        $ctx=self::context($actor); self::requireAdmin($ctx); $staff=Db::asUser($ctx['userId'])->query("SELECT u.id AS userId,u.name,u.email,h.role,h.status,h.created_at AS joinedAt FROM tenant_memberships h JOIN users u ON u.id=h.user_id WHERE h.tenant_id=? ORDER BY u.name",[$ctx['hqId']]);
        foreach($staff as &$s){$s['agencyRole']=$s['role']==='tenant_owner'?'owner':($s['role']==='tenant_admin'?'admin':'staff'); $s['clients']=Db::asUser($ctx['userId'])->query("SELECT m.tenant_id AS clientId,t.name AS clientName,m.role,m.work_channels AS workChannels FROM tenant_memberships m JOIN tenants t ON t.id=m.tenant_id WHERE m.user_id=? AND t.organisation_id=? AND t.kind='client' AND m.status='active' AND m.is_active=1 ORDER BY t.name",[$s['userId'],$ctx['orgId']]); unset($s['role']);}
        $invitations=Db::tenant(['tenantId'=>$ctx['hqId'],'userId'=>$ctx['userId']])->query("SELECT id,email,created_at AS createdAt,expires_at AS expiresAt FROM tenant_invitations WHERE tenant_id=? AND accepted_at IS NULL AND revoked_at IS NULL AND expires_at>NOW(3) ORDER BY created_at DESC",[$ctx['hqId']]); return ['staff'=>$staff,'invitations'=>$invitations];
    }

    public static function inviteStaff(Actor $actor,string $email): array
    {
        $ctx=self::context($actor); self::requireAdmin($ctx); $agencyActor=new Actor($ctx['hqId'],$ctx['userId'],$ctx['role'],'user',null,null,$actor->requestId,$actor->ip); $user=Db::asUser($ctx['userId'])->one('SELECT name FROM users WHERE id=?',[$ctx['userId']]); $name=(string)($user['name']??'A teammate'); return MembersService::invite($agencyActor,['email'=>$email,'role'=>'sales_agent'],$name);
    }

    public static function setStaffRole(Actor $actor,string $userId,string $agencyRole): array
    {
        $ctx=self::context($actor); if(!$ctx['isOwner']) throw Errors::forbidden('Only the agency owner can change agency roles'); if($agencyRole==='admin'){$new='tenant_admin';}elseif($agencyRole==='staff'){$new='sales_agent';}else { throw Errors::validation([['path'=>'body.agencyRole','message'=>'Invalid agency role']]); }
        Db::identity()->tx(static function(Tx $tx) use($ctx,$userId,$new): void { $m=$tx->one('SELECT id,role FROM tenant_memberships WHERE tenant_id=? AND user_id=? FOR UPDATE',[$ctx['hqId'],$userId]); if(!$m)throw Errors::notFound('Employee'); if($m['role']==='tenant_owner')throw Errors::conflict('The agency owner cannot be demoted'); $tx->run('UPDATE tenant_memberships SET role=?,updated_by=? WHERE id=?',[$new,$ctx['userId'],$m['id']]); if($new==='tenant_admin')foreach($tx->col("SELECT id FROM tenants WHERE organisation_id=? AND kind='client'",[$ctx['orgId']])as$cid)$tx->insert('tenant_memberships',['tenant_id'=>$cid,'user_id'=>$userId,'role'=>'tenant_admin','status'=>'active','is_active'=>1,'invited_by'=>$ctx['userId']],['onDuplicate'=>['role'=>'tenant_admin','status'=>'active','is_active'=>1]]); else $tx->run("UPDATE tenant_memberships m JOIN tenants t ON t.id=m.tenant_id SET m.status='revoked',m.is_active=0 WHERE t.organisation_id=? AND t.kind='client' AND m.user_id=? AND m.role IN ('tenant_owner','tenant_admin')",[$ctx['orgId'],$userId]); }); return ['userId'=>$userId,'agencyRole'=>$agencyRole];
    }

    public static function removeStaff(Actor $actor,string $userId): array
    {
        $ctx=self::context($actor); self::requireAdmin($ctx); $clients=self::accessibleClients($ctx,'all'); $n=0; foreach($clients as$c){try{$n+=self::unassign($actor,$c['id'],$userId)['leadsUnassigned'];}catch(\Throwable $e){if($e instanceof \App\Core\AppError)throw $e;}} Db::identity()->run("UPDATE tenant_memberships SET status='revoked',is_active=0,updated_by=? WHERE tenant_id=? AND user_id=? AND role<>'tenant_owner'",[$ctx['userId'],$ctx['hqId'],$userId]); \App\Modules\Identity\Sessions::detach($ctx['hqId'],$userId); return ['leadsUnassigned'=>$n,'clientsLeft'=>count($clients)];
    }

    /** Cross-client lead list; each tenant query is constrained by the caller's membership role. */
    public static function leads(Actor $actor, array $query): array
    {
        $ctx = self::context($actor);
        $pageSize = max(1, min(100, (int) ($query['pageSize'] ?? 50)));
        $page = max(1, (int) ($query['page'] ?? 1));
        $window = $page * $pageSize;
        if ($window > 5000) throw Errors::unprocessable('That page is too deep (the first 5,000 leads can be paged). Narrow by client, date or status, or download the data.');

        $clientId = $query['clientId'] ?? null;
        $clients = self::accessibleClients($ctx, 'active', $clientId);
        if ($clientId !== null && !$clients) throw Errors::notFound('Client');
        $sort = $query['sort'] ?? 'created';
        $order = ($query['order'] ?? 'desc') === 'asc' ? 'ASC' : 'DESC';
        $sortColumns = [
            'created' => 'l.created_at',
            'updated' => 'l.updated_at',
            'score' => 'l.lead_score',
            'value' => 'COALESCE(l.estimated_value, 0)',
        ];
        if (!isset($sortColumns[$sort])) throw Errors::validation([['path' => 'query.sort', 'message' => 'Choose a valid sort field']]);
        $sortColumn = $sortColumns[$sort];
        $filters = $query;
        unset($filters['clientId'], $filters['page'], $filters['pageSize'], $filters['sort'], $filters['order']);
        if (($filters['ownerId'] ?? null) === 'me') $filters['ownerId'] = $ctx['userId'];
        if (isset($filters['status']) && is_string($filters['status'])) {
            $filters['status'] = array_values(array_filter(array_map('trim', explode(',', $filters['status']))));
        }
        if (isset($filters['status']) && array_diff($filters['status'], Vocab::STATUSES)) {
            throw Errors::validation([['path' => 'query.status', 'message' => 'Choose valid lead statuses']]);
        }

        $parts = [];
        foreach ($clients as $client) {
            if (empty($client['myRole'])) continue;
            $clientActor = self::clientActor($ctx, $actor, $client);
            if ($clientActor->scopeIfAllowed('leads:read') === null) {
                if ($clientId !== null) throw Errors::forbidden();
                continue;
            }
            $part = Db::tenant($clientActor)->read(static function (Tx $tx) use ($clientActor, $client, $filters, $sortColumn, $order, $window): array {
                $params = [];
                $where = LeadsService::whereFor($tx, $clientActor, $filters, $params);
                $whereSql = implode(' AND ', $where);
                $total = (int) $tx->val(
                    'SELECT COUNT(*) FROM leads l LEFT JOIN contacts c ON c.id = l.contact_id AND c.tenant_id = l.tenant_id WHERE ' . $whereSql,
                    $params
                );
                $params[] = $window;
                $rows = $tx->all(
                    'SELECT l.id,l.created_at AS createdAt,l.updated_at AS updatedAt,c.display_name AS contactName,c.phone AS contactPhone,c.email AS contactEmail,
                            s.name AS stageName,s.color AS stageColor,l.status,l.owner_id AS ownerId,u.name AS ownerName,l.source,l.campaign,l.lead_score AS leadScore,
                            l.estimated_value AS estimatedValue,l.currency,l.tags,l.next_followup_at AS nextFollowupAt,l.last_activity_at AS lastActivityAt,
                            l.first_response_due_at AS dueAt,l.first_response_at AS responseAt,l.closed_at AS closedAt,' . $sortColumn . ' AS sortKey
                       FROM leads l LEFT JOIN contacts c ON c.id = l.contact_id AND c.tenant_id = l.tenant_id
                       JOIN pipeline_stages s ON s.id = l.stage_id AND s.tenant_id = l.tenant_id
                       LEFT JOIN users u ON u.id = l.owner_id
                      WHERE ' . $whereSql . ' ORDER BY ' . $sortColumn . ' ' . $order . ',l.id ' . $order . ' LIMIT ?',
                    $params
                );
                return ['client' => $client, 'total' => $total, 'rows' => $rows];
            });
            $parts[] = $part;
        }

        $merged = [];
        $byClient = [];
        foreach ($parts as $part) {
            $byClient[] = ['clientId' => $part['client']['id'], 'clientName' => $part['client']['name'], 'total' => $part['total']];
            foreach ($part['rows'] as $lead) $merged[] = ['client' => $part['client'], 'lead' => $lead];
        }
        usort($merged, static function ($left, $right) use ($sort, $order): int {
            $a = $left['lead']['sortKey'];
            $b = $right['lead']['sortKey'];
            $comparison = in_array($sort, ['score', 'value'], true) ? ((float) $a <=> (float) $b) : strcmp((string) $a, (string) $b);
            if ($comparison === 0) $comparison = strcmp($left['lead']['id'], $right['lead']['id']);
            return $order === 'ASC' ? $comparison : -$comparison;
        });
        $pageRows = array_slice($merged, ($page - 1) * $pageSize, $pageSize);
        $touches = [];
        $pageClients = [];
        foreach ($pageRows as $entry) {
            $id = $entry['client']['id'];
            $pageClients[$id]['client'] = $entry['client'];
            $pageClients[$id]['ids'][] = $entry['lead']['id'];
        }
        foreach ($pageClients as $clientData) {
            $client = $clientData['client'];
            $clientActor = self::clientActor($ctx, $actor, $client);
            $activityRows = Db::tenant($clientActor)->read(static fn(Tx $tx) => $tx->all(
                "SELECT lead_id, SUM(type = 'call') AS calls, SUM(type = 'call' AND outcome = 'connected') AS connected,
                        SUM(type IN ('call','whatsapp','sms','email','instagram','meeting')) AS total,
                        MAX(CASE WHEN type IN ('call','whatsapp','sms','email','instagram','meeting') THEN created_at END) AS lastTouchAt
                   FROM activities WHERE tenant_id = ? AND lead_id IN (?) GROUP BY lead_id",
                [$clientActor->tenantId, $clientData['ids']]
            ));
            foreach ($activityRows as $activity) {
                $touches[$activity['lead_id']] = [
                    'calls' => (int) $activity['calls'],
                    'connected' => (int) $activity['connected'],
                    'total' => (int) $activity['total'],
                    'lastTouchAt' => $activity['lastTouchAt'] ? Time::iso($activity['lastTouchAt']) : null,
                ];
            }
        }

        $items = [];
        foreach ($pageRows as $entry) {
            $client = $entry['client'];
            $lead = $entry['lead'];
            $sla = LeadsService::slaOf($lead['dueAt'], $lead['responseAt'], $lead['closedAt'] !== null, $lead['createdAt']);
            $items[] = [
                'id' => $lead['id'],
                'client' => ['id' => $client['id'], 'name' => $client['name'], 'industry' => $client['industry']],
                'contactName' => $lead['contactName'],
                'contactPhone' => $lead['contactPhone'],
                'contactEmail' => $lead['contactEmail'],
                'stageName' => $lead['stageName'],
                'stageColor' => $lead['stageColor'],
                'status' => $lead['status'],
                'ownerId' => $lead['ownerId'],
                'ownerName' => $lead['ownerName'],
                'source' => $lead['source'],
                'campaign' => $lead['campaign'],
                'leadScore' => (int) $lead['leadScore'],
                'estimatedValue' => $lead['estimatedValue'] === null ? null : (float) $lead['estimatedValue'],
                'currency' => $lead['currency'],
                'tags' => Json::dec($lead['tags'], []),
                'createdAt' => Time::iso($lead['createdAt']),
                'updatedAt' => Time::iso($lead['updatedAt']),
                'lastActivityAt' => $lead['lastActivityAt'] ? Time::iso($lead['lastActivityAt']) : null,
                'nextFollowupAt' => $lead['nextFollowupAt'] ? Time::iso($lead['nextFollowupAt']) : null,
                'sla' => ['state' => $sla['state'], 'dueAt' => $sla['dueAt']],
                'touches' => $touches[$lead['id']] ?? ['calls' => 0, 'connected' => 0, 'total' => 0, 'lastTouchAt' => null],
            ];
        }
        $total = array_sum(array_column($parts, 'total'));
        return [
            'items' => $items,
            'page' => ['page' => $page, 'pageSize' => $pageSize, 'total' => $total, 'totalPages' => max(1, (int) ceil($total / $pageSize))],
            'byClient' => $byClient,
        ];
    }

    public static function unread(Actor $actor): array { $ctx=self::context($actor);$out=[];foreach(self::accessibleClients($ctx,'active')as$c){if(!$c['myRole'])continue;$a=self::clientActor($ctx,$actor,$c);if(!$a->permitted('notifications:read'))continue;$n=(int)Db::tenant($a)->read(static fn(Tx $tx)=>(int)$tx->val('SELECT COUNT(*) FROM notifications WHERE tenant_id=? AND user_id=? AND is_read=0',[$a->tenantId,$a->userId]));if($n)$out[]=['clientId'=>$c['id'],'unread'=>$n];}return $out; }

    public static function tasks(Actor $actor): array
    {
        $ctx = self::context($actor);
        $out = [];
        foreach (self::accessibleClients($ctx, 'active') as $client) {
            if (empty($client['myRole'])) continue;
            $clientActor = self::clientActor($ctx, $actor, $client);
            if (!$clientActor->permitted('tasks:read')) continue;
            $timezoneName = (string) ($client['timezone'] ?? 'UTC');
            try { $timezone = new \DateTimeZone($timezoneName); } catch (\Exception) { $timezone = new \DateTimeZone('UTC'); }
            $endOfToday = (new \DateTimeImmutable('now', $timezone))->setTime(0, 0)->modify('+1 day')->setTimezone(new \DateTimeZone('UTC'));
            $rows = Db::tenant($clientActor)->read(static fn(Tx $tx) => $tx->all(
                "SELECT t.id,t.title,t.kind,t.priority,t.due_date AS dueDate,t.lead_id AS leadId,co.display_name AS leadName
                   FROM tasks t
                   LEFT JOIN leads l ON l.id = t.lead_id AND l.tenant_id = t.tenant_id
                   LEFT JOIN contacts co ON co.id = l.contact_id AND co.tenant_id = l.tenant_id
                  WHERE t.tenant_id = ? AND t.assigned_to = ? AND t.deleted_at IS NULL AND t.status IN ('pending','in_progress')
                    AND t.due_date IS NOT NULL AND t.due_date < ?
                  ORDER BY t.due_date,t.id LIMIT 10",
                [$clientActor->tenantId, $clientActor->userId, Time::db($endOfToday)]
            ));
            foreach ($rows as $row) {
                $row['client'] = ['id' => $client['id'], 'name' => $client['name']];
                $row['overdue'] = (Time::ms($row['dueDate']) ?? 0) < Time::nowMs();
                $row['dueDate'] = Time::iso($row['dueDate']);
                $out[] = $row;
            }
        }
        usort($out, static fn($a, $b) => strcmp($a['dueDate'], $b['dueDate']));
        return array_slice($out, 0, 30);
    }

    public static function activity(Actor $actor, array $query): array
    {
        $ctx = self::context($actor);
        $user = Db::asUser($ctx['userId'])->one('SELECT timezone FROM users WHERE id = ?', [$ctx['userId']]);
        $timezoneName = (string) ($user['timezone'] ?? 'Asia/Kolkata');
        try { $timezone = new \DateTimeZone($timezoneName); } catch (\Exception) { $timezone = new \DateTimeZone('Asia/Kolkata'); $timezoneName = 'Asia/Kolkata'; }
        $today = (new \DateTimeImmutable('now', $timezone))->format('Y-m-d');
        $from = $query['from'] ?? $query['to'] ?? $today;
        $to = $query['to'] ?? $query['from'] ?? $today;
        $date = static function ($value, string $path): \DateTimeImmutable {
            if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value, $matches)
                || !checkdate((int) substr($value, 5, 2), (int) substr($value, 8, 2), (int) substr($value, 0, 4))) {
                throw Errors::validation([['path' => $path, 'message' => 'Use a date like 2026-10-14']]);
            }
            return new \DateTimeImmutable($value . ' 00:00:00', new \DateTimeZone('UTC'));
        };
        $fromDate = $date($from, 'query.from');
        $toDate = $date($to, 'query.to');
        if ($from > $to) throw Errors::validation([['path' => 'query.from', 'message' => 'The start date is after the end date']]);
        if ($toDate->getTimestamp() - $fromDate->getTimestamp() > 366 * 86400) {
            throw Errors::validation([['path' => 'query.to', 'message' => 'Choose a period of at most a year']]);
        }

        $utc = new \DateTimeZone('UTC');
        $start = (new \DateTimeImmutable($from . ' 00:00:00', $timezone))->setTimezone($utc);
        $end = (new \DateTimeImmutable($to . ' 00:00:00', $timezone))->modify('+1 day')->setTimezone($utc);
        $clients = self::accessibleClients($ctx, 'active', $query['clientId'] ?? null);
        if (!empty($query['clientId']) && !$clients) throw Errors::notFound('Client');
        $rows = [];
        foreach ($clients as $client) {
            if (empty($client['myRole'])) continue;
            $clientActor = self::clientActor($ctx, $actor, $client);
            if (!$clientActor->permitted('activities:read')) {
                if (!empty($query['clientId'])) throw Errors::forbidden();
                continue;
            }
            $seeAll = $ctx['isAdmin'] || $clientActor->scopeIfAllowed('leads:assign') === 'all';
            $part = Db::tenant($clientActor)->read(static function (Tx $tx) use ($clientActor, $client, $start, $end, $seeAll): array {
                $params = [$clientActor->tenantId, Time::db($start), Time::db($end)];
                $own = '';
                if (!$seeAll) {
                    $own = ' AND a.user_id = ?';
                    $params[] = $clientActor->userId;
                }
                return $tx->all(
                    "SELECT a.user_id AS userId,u.name AS userName,
                            SUM(a.type = 'call') AS calls,SUM(a.type = 'call' AND a.outcome = 'connected') AS connected,
                            SUM(a.type = 'whatsapp') AS whatsapp,SUM(a.type = 'sms') AS sms,SUM(a.type = 'email') AS emails,
                            SUM(a.type = 'instagram') AS instagram,SUM(a.type = 'meeting') AS meetings,COUNT(DISTINCT a.lead_id) AS leadsTouched
                       FROM activities a JOIN users u ON u.id = a.user_id
                      WHERE a.tenant_id = ? AND a.type IN ('call','whatsapp','sms','email','instagram','meeting')
                        AND a.created_at >= ? AND a.created_at < ?$own
                      GROUP BY a.user_id,u.name ORDER BY LOWER(u.name)",
                    $params
                );
            });
            foreach ($part as $row) {
                $rows[] = ['clientId' => $client['id'], 'clientName' => $client['name']] + array_map(
                    static fn($value) => is_numeric($value) ? (int) $value : $value,
                    $row
                );
            }
        }
        $totals = [];
        foreach (['calls', 'connected', 'whatsapp', 'sms', 'emails', 'instagram', 'meetings'] as $key) {
            $totals[$key] = array_sum(array_column($rows, $key));
        }
        return ['from' => $from, 'to' => $to, 'timeZone' => $timezoneName, 'rows' => $rows, 'totals' => $totals];
    }

    public static function report(Actor $actor, array $query): array
    {
        $ctx = self::context($actor);
        $user = Db::asUser($ctx['userId'])->one('SELECT timezone FROM users WHERE id = ?', [$ctx['userId']]);
        $timezoneName = (string) ($user['timezone'] ?? 'Asia/Kolkata');
        try { $timezone = new \DateTimeZone($timezoneName); } catch (\Exception) { $timezone = new \DateTimeZone('Asia/Kolkata'); }
        $today = (new \DateTimeImmutable('now', $timezone))->format('Y-m-d');
        $to = $query['to'] ?? $today;
        $from = $query['from'] ?? (new \DateTimeImmutable($to . ' 00:00:00', new \DateTimeZone('UTC')))->modify('-29 days')->format('Y-m-d');
        $includeTest = in_array($query['includeTest'] ?? false, [true, 1, '1', 'true'], true);
        $range = ['from' => $from, 'to' => $to, 'includeTest' => $includeTest];
        $clients = self::accessibleClients($ctx, 'active', $query['clientId'] ?? null);
        if (!empty($query['clientId']) && !$clients) throw Errors::notFound('Client');

        $rows = [];
        $excluded = [];
        foreach ($clients as $client) {
            if (empty($client['myRole'])) continue;
            $clientActor = self::clientActor($ctx, $actor, $client);
            $scope = $clientActor->scopeIfAllowed('dashboard:read');
            if ($scope === null) {
                $excluded[] = ['clientId' => $client['id'], 'clientName' => $client['name'], 'reason' => 'Your role in this client has no access to its reports'];
                continue;
            }

            $overview = DashboardOverviewService::overview($clientActor, $range);
            $report = $clientActor->permitted('reports:read') ? ReportsService::overview($clientActor, $range) : null;
            $attribution = $clientActor->permitted('attribution:read')
                ? AttributionService::report($clientActor, $range + ['model' => 'first'])
                : null;
            $leadMetrics = $overview['leads'] ?? [];
            $rows[] = [
                'client' => ['id' => $client['id'], 'name' => $client['name'], 'industry' => $client['industry']],
                'scope' => $scope,
                'currency' => $overview['currency'],
                'timezone' => $overview['range']['timezone'],
                'leads' => [
                    'created' => $leadMetrics['created'] ?? 0,
                    'previousCreated' => $leadMetrics['previousCreated'] ?? 0,
                    'open' => $leadMetrics['open'] ?? 0,
                    'unassigned' => $leadMetrics['unassigned'] ?? 0,
                    'waitingForReply' => $leadMetrics['lateFirstReplies'] ?? 0,
                    'followupsOverdue' => $leadMetrics['followupsOverdue'] ?? 0,
                    'won' => $leadMetrics['wonOfCreated'] ?? 0,
                    'winRate' => $leadMetrics['conversionRate'] ?? null,
                ],
                'deals' => isset($overview['sales']) ? ['count' => $overview['sales']['count'], 'value' => $overview['sales']['dealValue']] : null,
                'firstReply' => isset($report['engagement']['firstReply'])
                    ? ['medianMinutes' => $report['engagement']['firstReply']['medianMinutes'], 'inTimeRate' => $report['engagement']['firstReply']['inTimeRate']]
                    : null,
                'money' => isset($attribution['money']) ? [
                    'spend' => $attribution['money']['spend'],
                    'costPerLead' => $attribution['money']['costPerLead'],
                    'costPerSale' => $attribution['money']['costPerSale'],
                ] : null,
                'trend' => array_map(static fn($point) => ['label' => $point['label'], 'leads' => $point['leads']], $overview['trend'] ?? []),
                '_bucket' => $overview['bucket'],
            ];
        }
        usort($rows, static fn($a, $b) => $b['leads']['created'] <=> $a['leads']['created'] ?: strcmp($a['client']['name'], $b['client']['name']));
        usort($excluded, static fn($a, $b) => strcmp($a['clientName'], $b['clientName']));
        $buckets = array_values(array_unique(array_column($rows, '_bucket')));
        $trend = [];
        if (count($buckets) <= 1) {
            $combined = [];
            foreach ($rows as $row) foreach ($row['trend'] as $point) $combined[$point['label']] = ($combined[$point['label']] ?? 0) + $point['leads'];
            ksort($combined, SORT_STRING);
            foreach ($combined as $label => $leads) $trend[] = ['label' => $label, 'leads' => $leads];
        }
        $currencies = array_values(array_unique(array_column($rows, 'currency')));
        sort($currencies, SORT_STRING);
        $sum = static fn(callable $value): int|float => array_sum(array_map($value, $rows));
        $created = (int) $sum(static fn($row) => $row['leads']['created']);
        $won = (int) $sum(static fn($row) => $row['leads']['won']);
        $byCurrency = [];
        foreach ($currencies as $currency) {
            $inCurrency = array_values(array_filter($rows, static fn($row) => $row['currency'] === $currency));
            $byCurrency[] = [
                'currency' => $currency,
                'dealValue' => round(array_sum(array_map(static fn($row) => $row['deals']['value'] ?? 0, $inCurrency)), 2),
                'spend' => round(array_sum(array_map(static fn($row) => $row['money']['spend'] ?? 0, $inCurrency)), 2),
                'clientsWithSpend' => count(array_filter($inCurrency, static fn($row) => isset($row['money']) && $row['money']['spend'] > 0)),
            ];
        }
        $publicRows = array_map(static function ($row) { unset($row['_bucket']); return $row; }, $rows);
        return [
            'range' => ['from' => $from, 'to' => $to],
            'includesTestLeads' => $includeTest,
            'bucket' => count($buckets) === 1 ? $buckets[0] : null,
            'partial' => (bool) array_filter($rows, static fn($row) => $row['scope'] === 'own'),
            'excluded' => $excluded,
            'rows' => $publicRows,
            'totals' => [
                'clients' => count($rows),
                'leads' => [
                    'created' => $created,
                    'previousCreated' => $sum(static fn($row) => $row['leads']['previousCreated']),
                    'open' => $sum(static fn($row) => $row['leads']['open']),
                    'unassigned' => $sum(static fn($row) => $row['leads']['unassigned']),
                    'waitingForReply' => $sum(static fn($row) => $row['leads']['waitingForReply']),
                    'followupsOverdue' => $sum(static fn($row) => $row['leads']['followupsOverdue']),
                    'won' => $won,
                    'winRate' => $created > 0 ? round($won / $created, 4) : null,
                ],
                'dealCount' => $sum(static fn($row) => $row['deals']['count'] ?? 0),
                'byCurrency' => $byCurrency,
            ],
            'trend' => $trend,
        ];
    }

    /** @return array{body:string,filename:string,rows:int} */
    public static function exportFile(Actor $actor, array $query): array
    {
        $format = $query['format'] ?? 'xlsx';
        $dataset = $query['dataset'] ?? 'leads';
        if (!in_array($format, ['xlsx', 'csv'], true)) throw Errors::validation([['path' => 'query.format', 'message' => 'Choose xlsx or csv']]);
        if (!in_array($dataset, ['leads', 'activities'], true)) throw Errors::validation([['path' => 'query.dataset', 'message' => 'Choose leads or activities']]);
        $filterKeys = ['q', 'status', 'state', 'ownerId', 'source', 'tag', 'scoreMin', 'createdFrom', 'createdTo', 'test', 'sla', 'followup', 'field', 'fieldValue', 'pool'];
        $filters = array_intersect_key($query, array_fill_keys($filterKeys, true));
        if (isset($filters['status']) && is_string($filters['status'])) $filters['status'] = array_values(array_unique(array_filter(array_map('trim', explode(',', $filters['status'])))));
        if (isset($filters['status']) && array_diff($filters['status'], Vocab::STATUSES)) throw Errors::validation([['path' => 'query.status', 'message' => 'Choose valid lead statuses']]);
        if (($filters['ownerId'] ?? null) === 'me') $filters['ownerId'] = $actor->userId;

        $ctx = self::context($actor);
        $clientId = isset($query['clientId']) ? (string) $query['clientId'] : null;
        $clients = self::accessibleClients($ctx, 'active', $clientId);
        if ($clientId && !$clients) throw Errors::notFound('Client');
        $targets = [];
        foreach ($clients as $client) {
            if (empty($client['myRole'])) continue;
            $clientActor = self::clientActor($ctx, $actor, $client);
            if ($clientActor->permitted('leads:export')) $targets[] = [$client, $clientActor];
        }
        if (!$targets) throw Errors::forbidden('You do not have export access in any client');

        $user = Db::asUser($ctx['userId'])->one('SELECT timezone FROM users WHERE id = ?', [$ctx['userId']]);
        $userTimezone = (string) ($user['timezone'] ?? 'Asia/Kolkata');
        try { $timezone = new \DateTimeZone($userTimezone); } catch (\Throwable) { $timezone = new \DateTimeZone('Asia/Kolkata'); }
        $tzName = $timezone->getName();
        $leadRows = [];
        $customRows = [];
        $customColumns = [];
        $customColumnByLabel = [];
        $activityRows = [];
        $clientRows = [];
        $team = [];
        $maxLeads = 20000;
        $maxActivities = 100000;

        foreach ($targets as [$client, $clientActor]) {
            $clientActor->assertCan('leads:export');
            Db::tenant($clientActor)->tx(static function (Tx $tx) use (
                $actor, $client, $clientActor, $filters, $format, $dataset, $tzName,
                &$leadRows, &$customRows, &$customColumns, &$customColumnByLabel, &$activityRows, &$clientRows, &$team,
                $maxLeads, $maxActivities
            ): void {
                $params = [];
                $where = LeadsService::whereFor($tx, $clientActor, $filters, $params);
                $room = $maxLeads - count($leadRows);
                $params[] = $room + 1;
                $leads = $tx->all(
                    'SELECT l.id, l.created_at, l.closed_at, l.status, l.source, l.campaign, l.tags, l.lead_score, l.estimated_value, l.currency,
                            l.budget_min, l.budget_max, l.locations, l.first_response_due_at, l.first_response_at, l.last_activity_at,
                            l.next_followup_at, l.loss_reason, l.source_details, l.custom_fields,
                            c.display_name AS contact_name, c.phone, c.email, s.name AS stage_name, o.name AS owner_name, o.email AS owner_email
                       FROM leads l LEFT JOIN contacts c ON c.id = l.contact_id AND c.tenant_id = l.tenant_id
                       JOIN pipeline_stages s ON s.id = l.stage_id AND s.tenant_id = l.tenant_id
                       LEFT JOIN users o ON o.id = l.owner_id
                      WHERE ' . implode(' AND ', $where) . ' ORDER BY l.created_at DESC, l.id DESC LIMIT ?',
                    $params
                );
                if (count($leads) > $room) throw Errors::unprocessable('That is more than 20,000 leads. Narrow by client, date or status and download in parts.');

                $defs = CustomFields::loadDefs($tx, $clientActor->tenantId);
                $defsByKey = [];
                foreach ($defs as $def) {
                    $defsByKey[$def['key']] = $def;
                    $labelKey = mb_strtolower(trim($def['label']));
                    if (!isset($customColumnByLabel[$labelKey])) {
                        $customColumnByLabel[$labelKey] = count($customColumns);
                        $customColumns[] = trim($def['label']);
                    }
                }

                $leadIds = array_column($leads, 'id');
                $activities = $leadIds ? $tx->all(
                    "SELECT a.lead_id, a.created_at, CASE WHEN a.type = 'note' AND JSON_CONTAINS_PATH(a.metadata, 'one', '$.event') = 1 THEN 'system' ELSE a.type END AS type,
                            a.direction, a.outcome, a.duration_minutes, a.description, u.id AS user_id, u.name AS user_name, u.email AS user_email
                       FROM activities a LEFT JOIN users u ON u.id = a.user_id
                      WHERE a.tenant_id = ? AND a.lead_id IN (?)
                      ORDER BY a.created_at, a.id",
                    [$clientActor->tenantId, $leadIds]
                ) : [];
                if (count($activityRows) + count($activities) > $maxActivities) throw Errors::unprocessable('That is more than 100,000 logged activities. Narrow by client or date and download in parts.');
                $byLead = [];
                foreach ($activities as $activity) $byLead[$activity['lead_id']][] = $activity;
                $leadById = [];
                foreach ($leads as $lead) $leadById[$lead['id']] = $lead;

                foreach ($leads as $lead) {
                    $list = $byLead[$lead['id']] ?? [];
                    $touches = array_values(array_filter($list, static fn($x) => in_array($x['type'], ['call', 'whatsapp', 'sms', 'email', 'instagram', 'meeting'], true)));
                    $last = $touches ? $touches[count($touches) - 1] : null;
                    $countType = static fn(string $type): int => count(array_filter($list, static fn($x) => $x['type'] === $type));
                    $sourceDetails = Json::dec($lead['source_details'], []);
                    $tracking = is_array($sourceDetails['tracking'] ?? null) ? $sourceDetails['tracking'] : [];
                    $answers = is_array($sourceDetails['answers'] ?? null)
                        ? implode(' | ', array_map(static fn($answer) => (string) ($answer['label'] ?? '') . ': ' . (string) ($answer['value'] ?? ''), $sourceDetails['answers']))
                        : '';
                    $responseMinutes = null;
                    if (!empty($lead['first_response_at'])) {
                        $responseMinutes = round((strtotime($lead['first_response_at']) - strtotime($lead['created_at'])) / 60, 1);
                    }
                    $sla = LeadsService::slaOf($lead['first_response_due_at'], $lead['first_response_at'], $lead['closed_at'] !== null, $lead['created_at'])['state'];
                    $customValues = Json::dec($lead['custom_fields'], []);
                    $otherCustom = [];
                    $custom = [];
                    foreach ($customValues as $key => $value) {
                        if (isset($defsByKey[$key])) {
                            $labelKey = mb_strtolower(trim($defsByKey[$key]['label']));
                            $custom[$customColumnByLabel[$labelKey]] = FieldRules::display($defsByKey[$key], $value);
                        } else {
                            $otherCustom[$key] = $value;
                        }
                    }
                    $customRows[] = $custom;
                    $leadRows[] = [
                        $client['name'], $client['industry'], $lead['id'], self::exportDate($lead['created_at']), $lead['status'], $lead['stage_name'],
                        $lead['contact_name'], $lead['phone'], $lead['email'], $lead['owner_name'], $lead['owner_email'], str_replace('_', ' ', $lead['source']),
                        $lead['campaign'], implode(', ', (array) Json::dec($lead['tags'], [])), (int) $lead['lead_score'], $lead['estimated_value'] === null ? null : (float) $lead['estimated_value'],
                        $lead['currency'], self::exportDate($lead['first_response_due_at']), self::exportDate($lead['first_response_at']), $responseMinutes, $sla,
                        self::exportDate($lead['last_activity_at']), self::exportDate($lead['next_followup_at']), count($touches), $countType('call'),
                        count(array_filter($list, static fn($x) => $x['type'] === 'call' && $x['outcome'] === 'connected')),
                        $countType('whatsapp'), $countType('sms'), $countType('email'), $countType('meeting'), $countType('note'),
                        $last['type'] ?? null, $last['outcome'] ?? null, self::exportDate($last['created_at'] ?? null), $last['user_name'] ?? null,
                        isset($last['description']) ? mb_substr($last['description'], 0, 500) : null, $lead['loss_reason'], self::exportDate($lead['closed_at']),
                        $sourceDetails['provider'] ?? null, $sourceDetails['page'] ?? null, $tracking['utm_source'] ?? null, $tracking['utm_medium'] ?? null,
                        $tracking['utm_campaign'] ?? null, $tracking['utm_term'] ?? null, $tracking['utm_content'] ?? null,
                        $tracking['gclid'] ?? $tracking['fbclid'] ?? null, $answers ?: null, $otherCustom ? Json::enc($otherCustom) : null,
                        $lead['budget_min'] === null ? null : (float) $lead['budget_min'], $lead['budget_max'] === null ? null : (float) $lead['budget_max'],
                        implode(', ', (array) Json::dec($lead['locations'], [])), $countType('instagram'),
                    ];
                }

                foreach ($activities as $activity) {
                    $lead = $leadById[$activity['lead_id']];
                    $activityRows[] = [
                        $client['name'], $activity['lead_id'], $lead['contact_name'], $lead['phone'], self::exportDate($activity['created_at']),
                        $activity['type'], $activity['direction'], $activity['outcome'], $activity['duration_minutes'], $activity['user_name'],
                        $activity['user_email'], $activity['description'],
                    ];
                    if ($activity['user_id'] && in_array($activity['type'], ['call', 'whatsapp', 'sms', 'email', 'instagram', 'meeting', 'note'], true)) {
                        $key = $clientActor->tenantId . ':' . $activity['user_id'];
                        if (!isset($team[$key])) $team[$key] = [
                            'client' => $client['name'], 'employee' => $activity['user_name'] ?? '', 'email' => $activity['user_email'] ?? '',
                            'leads' => [], 'calls' => 0, 'connected' => 0, 'whatsapp' => 0, 'sms' => 0, 'emails' => 0,
                            'meetings' => 0, 'notes' => 0, 'instagram' => 0,
                        ];
                        if ($activity['type'] === 'call') {
                            $team[$key]['calls']++;
                            if ($activity['outcome'] === 'connected') $team[$key]['connected']++;
                        } elseif ($activity['type'] === 'whatsapp') $team[$key]['whatsapp']++;
                        elseif ($activity['type'] === 'sms') $team[$key]['sms']++;
                        elseif ($activity['type'] === 'email') $team[$key]['emails']++;
                        elseif ($activity['type'] === 'instagram') $team[$key]['instagram']++;
                        elseif ($activity['type'] === 'meeting') $team[$key]['meetings']++;
                        else $team[$key]['notes']++;
                        $team[$key]['leads'][$activity['lead_id']] = true;
                    }
                }
                $clientRows[] = [
                    $client['name'], $client['industry'], count($leads),
                    count(array_filter($leads, static fn($r) => $r['closed_at'] === null)),
                    count(array_filter($leads, static fn($r) => $r['status'] === 'won')), count($activities),
                ];
                Audit::log($tx, 'lead.export', 'lead', $clientActor->tenantId, null, [
                    'rows' => count($leads), 'activities' => count($activities), 'via' => 'agency',
                    'format' => $format, 'dataset' => $dataset, 'filters' => $filters,
                ]);
            });
        }

        $timezoneLabel = '(' . $tzName . ')';
        $leadHeader = [
            'Client', 'Industry', 'Lead ID', 'Created ' . $timezoneLabel, 'Status', 'Stage', 'Contact name', 'Phone', 'Email', 'Assigned employee', 'Employee email', 'Source', 'Campaign', 'Tags', 'Lead score',
            'Estimated value', 'Currency', 'First reply due ' . $timezoneLabel, 'First reply sent ' . $timezoneLabel, 'First reply time (min)', 'Reply deadline',
            'Last activity ' . $timezoneLabel, 'Next follow-up ' . $timezoneLabel, 'Total touches', 'Calls', 'Calls connected', 'WhatsApp', 'SMS', 'E-mails', 'Meetings', 'Notes',
            'Last touch type', 'Last touch outcome', 'Last touch time ' . $timezoneLabel, 'Last touch by', 'Last touch note', 'Lost / not-a-fit reason', 'Closed ' . $timezoneLabel,
            'Lead source provider', 'Landing page', 'UTM source', 'UTM medium', 'UTM campaign', 'UTM term', 'UTM content', 'Ad click ID',
            'Form answers', 'Other custom data (JSON)', 'Budget min', 'Budget max', 'Locations', 'Instagram',
        ];
        foreach ($leadRows as $i => &$row) foreach ($customColumns as $column => $label) $row[] = $customRows[$i][$column] ?? null;
        unset($row);
        foreach ($customColumns as $label) $leadHeader[] = 'Custom: ' . $label;
        $activityHeader = ['Client', 'Lead ID', 'Contact', 'Contact phone', 'When ' . $timezoneLabel, 'Type', 'Direction', 'Outcome', 'Duration (min)', 'Employee', 'Employee email', 'Notes'];
        $day = (new \DateTimeImmutable('now', $timezone))->format('Y-m-d');
        $scope = 'all-clients';
        if ($clientId) {
            $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', (string) $targets[0][0]['name']) ?? '');
            $scope = trim(substr($slug, 0, 30), '-') ?: 'client';
        }

        if ($format === 'csv') {
            $rows = $dataset === 'activities' ? $activityRows : $leadRows;
            $header = $dataset === 'activities' ? $activityHeader : $leadHeader;
            $local = array_map(static fn($row) => array_map(static fn($value) => $value instanceof \DateTimeInterface ? $value->setTimezone($timezone)->format('Y-m-d H:i') : $value, $row), $rows);
            return [
                'body' => Csv::write($header, $local),
                'filename' => ($dataset === 'activities' ? 'activity-log' : 'leads') . '-' . $scope . '-' . $day . '.csv',
                'rows' => count($rows),
            ];
        }

        $teamRows = array_values($team);
        usort($teamRows, static fn($a, $b) => [$a['client'], $a['employee']] <=> [$b['client'], $b['employee']]);
        $teamRows = array_map(static fn($row) => [
            $row['client'], $row['employee'], $row['email'], count($row['leads']), $row['calls'], $row['connected'], $row['whatsapp'],
            $row['sms'], $row['emails'], $row['meetings'], $row['notes'], $row['instagram'],
        ], $teamRows);
        $body = XlsxWriter::build([
            ['name' => 'Leads', 'header' => $leadHeader, 'rows' => $leadRows, 'moneyColumns' => [15, 48, 49], 'wrapColumns' => [35, 46, 47], 'widths' => [24, 16, 38, 17]],
            ['name' => 'Activity log', 'header' => $activityHeader, 'rows' => $activityRows, 'wrapColumns' => [11], 'widths' => [24, 38, 22, 16, 17, 12, 10, 18, 9, 22, 28, 60]],
            ['name' => 'Team activity', 'header' => ['Client', 'Employee', 'Email', 'Leads touched', 'Calls', 'Calls connected', 'WhatsApp', 'SMS', 'E-mails', 'Meetings', 'Notes', 'Instagram'], 'rows' => $teamRows],
            ['name' => 'Clients', 'header' => ['Client', 'Industry', 'Leads in this download', 'Open', 'Won', 'Activities'], 'rows' => $clientRows],
        ], $tzName);
        return ['body' => $body, 'filename' => 'leads-' . $scope . '-' . $day . '.xlsx', 'rows' => count($leadRows)];
    }

    private static function exportDate(?string $value): ?\DateTimeImmutable
    {
        return $value === null || $value === '' ? null : new \DateTimeImmutable($value, new \DateTimeZone('UTC'));
    }

    public static function copyCustomFields(Actor $actor,string $targetId,string $sourceId): array { $ctx=self::context($actor);self::requireAdmin($ctx);$target=self::client($ctx,$targetId,false);$source=self::client($ctx,$sourceId);$sa=self::clientActor($ctx,$actor,$source);$defs=Db::tenant($sa)->read(static fn(Tx $tx)=>CustomFields::loadDefs($tx,$tx->tenantId));$ta=self::clientActor($ctx,$actor,$target);$copied=Db::tenant($ta)->tx(static function(Tx $tx)use($defs){$existing=CustomFields::loadDefs($tx,$tx->tenantId);$n=0;foreach($defs as$d){$keys=array_column($existing,'key');$labels=array_map(static fn($x)=>mb_strtolower($x['label']),$existing);if(in_array($d['key'],$keys,true)||in_array(mb_strtolower($d['label']),$labels,true))continue;$existing[]=CustomFields::insertDef($tx,$tx->tenantId,['key'=>$d['key'],'label'=>$d['label'],'type'=>$d['type'],'required'=>$d['required'],'options'=>$d['options'],'defaultValue'=>$d['defaultValue'],'helpText'=>$d['helpText'],'showInList'=>$d['showInList']],$existing);$n++;}return $n;});return ['copied'=>$copied]; }

    private static function nullable(mixed $v): ?string { if($v===null)return null;$s=trim((string)$v);return $s===''?null:$s; }
    private static function stageNames(string $industry): array { if($industry==='real_estate')return[];$word='Appointment';foreach(self::INDUSTRIES as$i)if($i['code']===$industry)$word=$i['appointment'];return['new'=>'New enquiry','contact_attempted'=>'Trying to reach','contacted'=>'In conversation','qualified'=>'Qualified','viewing_scheduled'=>$word.' booked','viewing_completed'=>$word.' done','negotiation'=>'Considering','booking_reserved'=>'Confirmed']; }
}
