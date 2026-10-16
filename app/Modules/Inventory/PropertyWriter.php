<?php
declare(strict_types=1);

namespace App\Modules\Inventory;

use App\Core\Errors;
use App\Core\Raw;
use App\Core\Tx;

/**
 * The only writer of existing property rows. It does what the PostgreSQL triggers did: bump the version, stamp `status_changed_at`, and append the
 * status and price history rows (who changed it, why). Everything that edits a property (the API, the deal ledger, imports) goes through here.
 */
final class PropertyWriter
{
    /**
     * @param array<string,mixed> $set column => value (DB column names; Raw allowed)
     */
    public static function update(Tx $tx, string $tenantId, string $id, array $set, ?string $statusReason = null): void
    {
        $old = $tx->one('SELECT status, price, currency FROM properties WHERE id = ? AND tenant_id = ? FOR UPDATE', [$id, $tenantId]);
        if (!$old) throw Errors::notFound('Property');
        $set['version'] = new Raw('version + 1');
        $statusChanged = array_key_exists('status', $set) && $set['status'] !== $old['status'];
        if ($statusChanged) $set['status_changed_at'] = new Raw('NOW(3)');
        $tx->update('properties', $set, 'id = ? AND tenant_id = ?', [$id, $tenantId]);
        if ($statusChanged) {
            $tx->insert('property_status_history', ['tenant_id' => $tenantId, 'property_id' => $id, 'from_status' => $old['status'], 'to_status' => $set['status'], 'reason' => $statusReason !== null && $statusReason !== '' ? mb_substr($statusReason, 0, 300) : null, 'changed_by' => $tx->userId]);
        }
        $newPrice = array_key_exists('price', $set) ? (float) $set['price'] : (float) $old['price'];
        $newCur = array_key_exists('currency', $set) ? $set['currency'] : $old['currency'];
        if ($newPrice !== (float) $old['price'] || $newCur !== $old['currency']) {
            $tx->insert('property_price_history', ['tenant_id' => $tenantId, 'property_id' => $id, 'price' => $newPrice, 'currency' => $newCur, 'changed_by' => $tx->userId]);
        }
    }

    /** The first history rows of a newly listed property. */
    public static function recordListed(Tx $tx, string $tenantId, string $id, string $status, float|string $price, string $currency): void
    {
        $tx->insert('property_status_history', ['tenant_id' => $tenantId, 'property_id' => $id, 'from_status' => null, 'to_status' => $status, 'reason' => 'Listed', 'changed_by' => $tx->userId]);
        $tx->insert('property_price_history', ['tenant_id' => $tenantId, 'property_id' => $id, 'price' => $price, 'currency' => $currency, 'changed_by' => $tx->userId]);
    }
}
