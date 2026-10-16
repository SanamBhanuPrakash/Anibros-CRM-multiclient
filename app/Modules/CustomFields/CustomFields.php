<?php
declare(strict_types=1);

namespace App\Modules\CustomFields;

use App\Core\Actor;
use App\Core\Audit;
use App\Core\Ctx;
use App\Core\Db;
use App\Core\Errors;
use App\Core\Json;
use App\Core\Reply;
use App\Core\Router;
use App\Core\Tx;
use App\Core\V;

/**
 * Per-client custom lead fields (definitions). Each client has its own set (workspace views); values are saved on the lead and checked
 * against these definitions whenever a lead is saved (see FieldRules and LeadsService). Fields are hidden, never deleted, so no saved value
 * is orphaned.
 */
final class CustomFields
{
    private const COLS = 'id, field_key, label, field_type, is_required, is_visible, options, default_value, help_text, show_in_list, order_index';

    /** @param array<string,mixed> $r @return array<string,mixed> */
    private static function toDef(array $r): array
    {
        return [
            'id' => $r['id'], 'key' => $r['field_key'], 'label' => $r['label'], 'type' => $r['field_type'], 'required' => (bool) $r['is_required'], 'visible' => (bool) $r['is_visible'],
            'options' => array_values((array) Json::dec($r['options'], [])), 'defaultValue' => $r['default_value'], 'helpText' => $r['help_text'], 'showInList' => (bool) $r['show_in_list'], 'order' => (int) $r['order_index'],
        ];
    }

    /** All definitions of a client's leads (visible and hidden), in display order. Used by lead saving and the exports. */
    public static function loadDefs(Tx $tx, string $tenantId): array
    {
        return array_map([self::class, 'toDef'], $tx->all("SELECT " . self::COLS . " FROM custom_field_definitions WHERE tenant_id = ? AND entity_type = 'lead' ORDER BY is_visible DESC, order_index, created_at, id", [$tenantId]));
    }

    /**
     * Insert one definition inside a workspace transaction. No permission check: callers authorise (API) or are trusted (starter fields, copy).
     *
     * @param array{label:string,type:string,key?:?string,required?:?bool,options?:array,defaultValue?:?string,helpText?:?string,showInList?:?bool} $in
     */
    public static function insertDef(Tx $tx, string $tenantId, array $in, ?array $existing = null): array
    {
        $defs = $existing ?? self::loadDefs($tx, $tenantId);
        $problems = [];
        $label = trim($in['label']);
        if ($label === '' || mb_strlen($label) > 60) $problems[] = ['path' => 'body.label', 'message' => 'Give the field a name of up to 60 characters'];
        $isChoice = in_array($in['type'], ['select', 'multi_select'], true);
        $options = [];
        if ($isChoice) {
            $n = FieldRules::normalizeOptions($in['options'] ?? []);
            $options = $n['options'];
            array_push($problems, ...$n['problems']);
            if (!$options) $problems[] = ['path' => 'body.options', 'message' => 'Add at least one option to choose from'];
        } elseif (!empty($in['options'])) {
            $problems[] = ['path' => 'body.options', 'message' => 'Only dropdown fields have options'];
        }
        $taken = [];
        foreach ($defs as $d) $taken[$d['key']] = true;
        if (isset($in['key'])) {
            if (!preg_match(FieldRules::KEY_RE, $in['key'])) $problems[] = ['path' => 'body.key', 'message' => 'Use lowercase letters, digits and underscores, starting with a letter (40 characters at most)'];
            elseif (isset($taken[$in['key']])) throw Errors::conflict("This client already has a field with the key “{$in['key']}”");
        }
        foreach ($defs as $d) if ($d['visible'] && mb_strtolower(trim($d['label'])) === mb_strtolower($label)) throw Errors::conflict("There is already a field called “{$label}”");
        if (count(array_filter($defs, static fn($d) => $d['visible'])) >= FieldRules::MAX_FIELDS_PER_CLIENT) throw Errors::unprocessable('A client can have at most ' . FieldRules::MAX_FIELDS_PER_CLIENT . ' custom fields. Hide one you no longer use.');

        $defaultValue = null;
        if (isset($in['defaultValue']) && $in['defaultValue'] !== '') {
            $probe = FieldRules::normalizeValue(['type' => $in['type'], 'options' => $options, 'label' => $label], $in['defaultValue']);
            if (!$probe['ok']) $problems[] = ['path' => 'body.defaultValue', 'message' => $probe['message']];
            else $defaultValue = is_array($probe['value']) ? implode(',', $probe['value']) : ($probe['value'] === null ? null : (is_bool($probe['value']) ? ($probe['value'] ? 'true' : 'false') : (string) $probe['value']));
        }
        if ($problems) throw Errors::validation($problems);

        $key = $in['key'] ?? FieldRules::keyFromLabel($label, $taken);
        $order = 0;
        foreach ($defs as $d) $order = max($order, $d['order'] + 1);
        $id = $tx->insert('custom_field_definitions', [
            'tenant_id' => $tenantId, 'entity_type' => 'lead', 'name' => $label, 'label' => $label, 'field_key' => $key, 'field_type' => $in['type'],
            'is_required' => !empty($in['required']) ? 1 : 0, 'is_visible' => 1, 'options' => Json::enc($options), 'default_value' => $defaultValue,
            'help_text' => !empty($in['helpText']) ? trim($in['helpText']) : null, 'show_in_list' => !empty($in['showInList']) ? 1 : 0, 'order_index' => $order,
        ]);
        return self::toDef($tx->one('SELECT ' . self::COLS . ' FROM custom_field_definitions WHERE id = ?', [$id]));
    }

    /** Give a new client the starter fields of its industry (inside the client's own workspace transaction). */
    public static function seedStarterFields(Tx $tx, string $tenantId, string $industry): int
    {
        $starters = FieldRules::starters($industry);
        $defs = self::loadDefs($tx, $tenantId);
        foreach ($starters as $s) $defs[] = self::insertDef($tx, $tenantId, ['label' => $s['label'], 'type' => $s['type'], 'options' => $s['options'] ?? [], 'helpText' => $s['helpText'] ?? null], $defs);
        return count($starters);
    }

    /** One definition change at a time per client: the uniqueness and count checks must not race. */
    public static function lockClient(Tx $tx, string $tenantId): void
    {
        $tx->val('SELECT id FROM tenants WHERE id = ? FOR UPDATE', [$tenantId]);
    }

    public static function list(Actor $actor, bool $includeHidden): array
    {
        $actor->assertCan('custom_fields:read');
        return Db::tenant($actor)->read(static fn(Tx $tx) => array_values(array_filter(self::loadDefs($tx, $actor->tenantId), static fn($d) => $includeHidden || $d['visible'])));
    }

    public static function create(Actor $actor, array $in): array
    {
        $actor->assertCan('custom_fields:manage');
        return Db::tenant($actor)->tx(static function (Tx $tx) use ($actor, $in) {
            self::lockClient($tx, $actor->tenantId);
            $def = self::insertDef($tx, $actor->tenantId, $in);
            Audit::log($tx, 'custom_field.create', 'custom_field', $def['id'], null, ['key' => $def['key'], 'label' => $def['label'], 'type' => $def['type'], 'required' => $def['required']]);
            return $def;
        });
    }

    public static function update(Actor $actor, string $id, array $patch): array
    {
        $actor->assertCan('custom_fields:manage');
        return Db::tenant($actor)->tx(static function (Tx $tx) use ($actor, $id, $patch) {
            self::lockClient($tx, $actor->tenantId);
            $defs = self::loadDefs($tx, $actor->tenantId);
            $cur = null;
            foreach ($defs as $d) if ($d['id'] === $id) $cur = $d;
            if (!$cur) throw Errors::notFound('Field');
            $problems = [];
            $set = [];
            $changed = [];
            $sameLabel = static fn(array $d, string $label) => mb_strtolower(trim($d['label'])) === mb_strtolower($label);

            if (array_key_exists('label', $patch)) {
                $label = trim($patch['label']);
                if ($label === '' || mb_strlen($label) > 60) $problems[] = ['path' => 'body.label', 'message' => 'Give the field a name of up to 60 characters'];
                else {
                    foreach ($defs as $d) if ($d['id'] !== $id && $d['visible'] && ($patch['visible'] ?? $cur['visible']) && $sameLabel($d, $label)) throw Errors::conflict("There is already a field called “{$label}”");
                    if ($label !== $cur['label']) { $set['label'] = $label; $set['name'] = $label; $changed[] = 'label'; }
                }
            }
            if (array_key_exists('required', $patch) && $patch['required'] !== $cur['required']) { $set['is_required'] = $patch['required'] ? 1 : 0; $changed[] = 'required'; }
            if (array_key_exists('showInList', $patch) && $patch['showInList'] !== $cur['showInList']) { $set['show_in_list'] = $patch['showInList'] ? 1 : 0; $changed[] = 'showInList'; }
            if (array_key_exists('helpText', $patch)) { $set['help_text'] = !empty($patch['helpText']) ? trim($patch['helpText']) : null; $changed[] = 'helpText'; }
            if (array_key_exists('visible', $patch) && $patch['visible'] !== $cur['visible']) {
                if ($patch['visible']) {
                    if (count(array_filter($defs, static fn($d) => $d['visible'])) >= FieldRules::MAX_FIELDS_PER_CLIENT) throw Errors::unprocessable('A client can have at most ' . FieldRules::MAX_FIELDS_PER_CLIENT . ' custom fields.');
                    foreach ($defs as $d) if ($d['id'] !== $id && $d['visible'] && $sameLabel($d, (string) ($patch['label'] ?? $cur['label']))) throw Errors::conflict("There is already a field called “{$cur['label']}”. Rename one of them first.");
                }
                $set['is_visible'] = $patch['visible'] ? 1 : 0;
                $changed[] = $patch['visible'] ? 'shown' : 'hidden';
            }

            $options = $cur['options'];
            if (array_key_exists('options', $patch)) {
                if (!in_array($cur['type'], ['select', 'multi_select'], true)) $problems[] = ['path' => 'body.options', 'message' => 'Only dropdown fields have options'];
                else {
                    // Existing options keep their stored value when sent again by name, so renaming or reordering never changes saved leads.
                    $given = array_map(static function ($o) use ($cur) {
                        if (!is_string($o)) return $o;
                        foreach ($cur['options'] as $x) if (mb_strtolower($x['label']) === mb_strtolower(trim($o))) return ['value' => $x['value'], 'label' => $o];
                        return $o;
                    }, $patch['options']);
                    $n = FieldRules::normalizeOptions($given);
                    array_push($problems, ...$n['problems']);
                    if (!$n['options']) $problems[] = ['path' => 'body.options', 'message' => 'Keep at least one option'];
                    $newValues = array_column($n['options'], 'value');
                    $removed = array_values(array_filter(array_column($cur['options'], 'value'), static fn($v) => !in_array($v, $newValues, true)));
                    if ($removed && !$problems) {
                        $path = '$.' . $cur['key']; // keys are validated ([a-z0-9_]) so the JSON path is safe
                        $conds = ["JSON_UNQUOTE(JSON_EXTRACT(custom_fields, '$path')) IN (?)"];
                        $params = [$actor->tenantId, $removed];
                        foreach ($removed as $r) { $conds[] = "JSON_CONTAINS(custom_fields, ?, '$path')"; $params[] = Json::enc($r); }
                        $used = (int) $tx->val('SELECT COUNT(*) FROM leads WHERE tenant_id = ? AND deleted_at IS NULL AND (' . implode(' OR ', $conds) . ')', $params);
                        if ($used) throw Errors::conflict("$used lead" . ($used === 1 ? ' uses' : 's use') . ' an option you removed. Rename it instead, or move those leads first.');
                    }
                    if (!$problems) { $options = $n['options']; $set['options'] = Json::enc($n['options']); $changed[] = 'options'; }
                }
            }
            if (array_key_exists('defaultValue', $patch)) {
                if ($patch['defaultValue'] === null || $patch['defaultValue'] === '') { $set['default_value'] = null; $changed[] = 'defaultValue'; }
                else {
                    $probe = FieldRules::normalizeValue(['type' => $cur['type'], 'options' => $options, 'label' => $cur['label']], $patch['defaultValue']);
                    if (!$probe['ok']) $problems[] = ['path' => 'body.defaultValue', 'message' => $probe['message']];
                    else { $set['default_value'] = is_array($probe['value']) ? implode(',', $probe['value']) : (is_bool($probe['value']) ? ($probe['value'] ? 'true' : 'false') : (string) $probe['value']); $changed[] = 'defaultValue'; }
                }
            }
            if ($problems) throw Errors::validation($problems);
            if (!$set) return $cur;
            $tx->update('custom_field_definitions', $set, 'id = ? AND tenant_id = ?', [$id, $actor->tenantId]);
            Audit::log($tx, 'custom_field.update', 'custom_field', $id, null, ['key' => $cur['key'], 'fields' => $changed]);
            return self::toDef($tx->one('SELECT ' . self::COLS . ' FROM custom_field_definitions WHERE id = ?', [$id]));
        });
    }

    /** @param string[] $ids */
    public static function reorder(Actor $actor, array $ids): array
    {
        $actor->assertCan('custom_fields:manage');
        return Db::tenant($actor)->tx(static function (Tx $tx) use ($actor, $ids) {
            self::lockClient($tx, $actor->tenantId);
            $defs = self::loadDefs($tx, $actor->tenantId);
            $known = array_flip(array_column($defs, 'id'));
            foreach ($ids as $i) if (!isset($known[$i])) throw Errors::notFound('Field');
            if (count(array_unique($ids)) !== count($ids)) throw Errors::validation([['path' => 'body.ids', 'message' => 'Each field can be listed once']]);
            // Listed fields first in the given order, then the rest in their current order.
            $rest = array_values(array_filter(array_column($defs, 'id'), static fn($i) => !in_array($i, $ids, true)));
            foreach (array_merge($ids, $rest) as $pos => $fid) $tx->run('UPDATE custom_field_definitions SET order_index = ? WHERE id = ? AND tenant_id = ?', [$pos, $fid, $actor->tenantId]);
            Audit::log($tx, 'custom_field.reorder', 'custom_field', $actor->tenantId, null, ['count' => count($ids) + count($rest)]);
            return self::loadDefs($tx, $actor->tenantId);
        });
    }

    public static function register(): void
    {
        $text = static fn(int $max): V => V::str()->trim()->max($max);
        $optionInput = static fn(): V => V::union([$text(80)->min(1), V::obj(['value' => V::str()->regex('/^[a-z0-9][a-z0-9_]{0,39}$/')->optional(), 'label' => $text(80)->min(1)])]);
        Router::mount('/api/v1/custom-fields', [
            ['method' => 'GET', 'path' => '/', 'perm' => 'custom_fields:read', 'query' => V::obj(['includeHidden' => V::enum(['true', 'false'])->default('false')]),
                'handler' => fn(Ctx $c) => self::list($c->actor, $c->query['includeHidden'] === 'true')],
            ['method' => 'POST', 'path' => '/', 'perm' => 'custom_fields:manage',
                'body' => V::obj([
                    'label' => $text(60)->min(1), 'type' => V::enum(FieldRules::TYPES), 'key' => V::str()->regex(FieldRules::KEY_RE, 'Lowercase letters, digits and underscores, starting with a letter')->optional(),
                    'required' => V::bool()->optional(), 'options' => V::arr($optionInput())->max(50)->optional(), 'defaultValue' => $text(500)->nullish(), 'helpText' => $text(200)->nullish(), 'showInList' => V::bool()->optional(),
                ]),
                'handler' => fn(Ctx $c) => new Reply(self::create($c->actor, $c->body), 201)],
            ['method' => 'PUT', 'path' => '/order', 'perm' => 'custom_fields:manage', 'body' => V::obj(['ids' => V::arr(V::uuid())->max(100)]),
                'handler' => fn(Ctx $c) => self::reorder($c->actor, $c->body['ids'])],
            ['method' => 'PATCH', 'path' => '/{id}', 'perm' => 'custom_fields:manage', 'params' => V::obj(['id' => V::uuid()]),
                'body' => V::obj([
                    'label' => $text(60)->min(1)->optional(), 'required' => V::bool()->optional(), 'visible' => V::bool()->optional(), 'options' => V::arr($optionInput())->max(50)->optional(),
                    'defaultValue' => $text(500)->nullish(), 'helpText' => $text(200)->nullish(), 'showInList' => V::bool()->optional(),
                ])->strict(),
                'handler' => fn(Ctx $c) => self::update($c->actor, $c->params['id'], $c->body)],
        ]);
    }
}
