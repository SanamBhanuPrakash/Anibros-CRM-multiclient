<?php
declare(strict_types=1);
namespace App\Modules\Automation;
use App\Core\Ctx; use App\Core\Reply; use App\Core\Router; use App\Core\V;
final class AutomationRoutes { public static function register(): void { $page=['limit'=>V::coerceInt()->min(1)->max(200)->default(50),'cursor'=>V::str()->max(512)->optional()]; $routes=[
 ['method'=>'GET','path'=>'/','perm'=>'automation:read','handler'=>fn(Ctx $c)=>AutomationService::list($c->actor)],
 ['method'=>'POST','path'=>'/{template}/enable','perm'=>'automation:manage','params'=>V::obj(['template'=>V::str()->max(80)]),'status'=>200,'handler'=>fn(Ctx $c)=>new Reply(AutomationService::enable($c->actor,$c->params['template']),200)],
 ['method'=>'POST','path'=>'/{template}/pause','perm'=>'automation:manage','params'=>V::obj(['template'=>V::str()->max(80)]),'status'=>200,'handler'=>fn(Ctx $c)=>new Reply(AutomationService::pause($c->actor,$c->params['template']),200)],
 ['method'=>'GET','path'=>'/executions','perm'=>'automation:read','query'=>V::obj($page+['status'=>V::enum(['pending','running','completed','failed','skipped'])->optional(),'leadId'=>V::uuid()->optional()]),'handler'=>fn(Ctx $c)=>($x=AutomationService::executions($c->actor,$c->query))&&new Reply($x['items'],200,$x['page'])],
 ['method'=>'GET','path'=>'/executions/{id}','perm'=>'automation:read','params'=>V::obj(['id'=>V::uuid()]),'handler'=>fn(Ctx $c)=>AutomationService::execution($c->actor,$c->params['id'])],
 ['method'=>'POST','path'=>'/executions/{id}/retry','perm'=>'automation:manage','params'=>V::obj(['id'=>V::uuid()]),'status'=>202,'handler'=>fn(Ctx $c)=>new Reply(AutomationService::retry($c->actor,$c->params['id']),202)],
 ]; Router::mount('/api/v1/automation',$routes); Router::mount('/api/v1/automations',$routes); }}
