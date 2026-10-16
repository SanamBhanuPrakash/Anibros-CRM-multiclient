<?php
declare(strict_types=1);

namespace App\Modules\Inventory;

use App\Core\Actor;
use App\Core\AppError;
use App\Core\Audit;
use App\Core\Db;
use App\Core\Errors;
use App\Core\Events;
use App\Core\Ids;
use App\Core\Logger;
use App\Core\Map;
use App\Core\Storage;
use App\Core\Tx;

/**
 * Uploads: trust bytes, not headers. The declared type must agree with the sniffed signature, the size is capped before it is read, content is
 * de-duplicated per entity (same bytes twice → 409), and blob + row are written so that a crash can only ever leave an orphan blob (harmless,
 * swept by retention), never a row without bytes.
 */
final class FilesService
{
    private const MAX_FILES_PER_ENTITY = 40;
    private const PERMS = ['property' => ['read' => 'properties:read', 'write' => 'properties:update'], 'project' => ['read' => 'projects:read', 'write' => 'projects:update']];
    private const TABLE = ['property' => 'properties', 'project' => 'projects'];
    private const DTO = ['int' => ['sizeBytes', 'sortOrder']];

    public static function list(Actor $actor, string $entity, string $entityId): array
    {
        $actor->assertCan(self::PERMS[$entity]['read']);
        return Db::tenant($actor)->read(static function (Tx $tx) use ($actor, $entity, $entityId) {
            self::assertEntity($tx, $actor->tenantId, $entity, $entityId);
            return Map::rows($tx->all(
                'SELECT id, kind, name, mime, size_bytes AS sizeBytes, sort_order AS sortOrder, created_at AS createdAt FROM files
                  WHERE tenant_id = ? AND entity_type = ? AND entity_id = ? AND deleted_at IS NULL ORDER BY sort_order, created_at',
                [$actor->tenantId, $entity, $entityId]
            ), self::DTO);
        });
    }

    public static function upload(Actor $actor, string $entity, string $entityId, string $data, ?string $declaredMime, ?string $rawName): array
    {
        $actor->assertCan(self::PERMS[$entity]['write']);
        if ($data === '') throw Errors::badRequest('Send the file as the request body with its Content-Type (image/jpeg, image/png, image/webp or application/pdf)');
        $type = Storage::sniff($data);
        if (!$type) throw new AppError(415, 'BAD_REQUEST', 'Unsupported media type', 'Only JPEG, PNG, WebP images and PDF documents are accepted');
        $declared = strtolower(trim(explode(';', (string) $declaredMime)[0]));
        if ($declared !== '' && $declared !== 'application/octet-stream' && $declared !== $type['mime']) {
            throw Errors::validation([['path' => 'header.content-type', 'message' => "The file contents are {$type['mime']}, but the request says $declared"]]);
        }

        $sha256 = hash('sha256', $data);
        $key = $actor->tenantId . '/' . gmdate('Y/m') . '/' . Ids::uuid() . '.' . $type['ext'];
        $name = Storage::safeFileName($rawName, $type['kind'] . '.' . $type['ext']);
        Storage::put($key, $data);
        try {
            return Db::tenant($actor)->tx(static function (Tx $tx) use ($actor, $entity, $entityId, $type, $sha256, $key, $name, $data) {
                self::assertEntity($tx, $actor->tenantId, $entity, $entityId, true);
                $n = $tx->one('SELECT COUNT(*) AS n, COALESCE(MAX(sort_order), -1) + 1 AS next FROM files WHERE tenant_id = ? AND entity_type = ? AND entity_id = ? AND deleted_at IS NULL', [$actor->tenantId, $entity, $entityId]);
                if ((int) $n['n'] >= self::MAX_FILES_PER_ENTITY) throw Errors::conflict('At most ' . self::MAX_FILES_PER_ENTITY . " files per $entity. Delete some first.");
                try {
                    $id = $tx->insert('files', [
                        'tenant_id' => $actor->tenantId, 'entity_type' => $entity, 'entity_id' => $entityId, 'kind' => $type['kind'], 'name' => $name, 'mime' => $type['mime'],
                        'size_bytes' => strlen($data), 'sha256' => $sha256, 'storage_key' => $key, 'sort_order' => (int) $n['next'], 'created_by' => $actor->userId,
                    ]);
                } catch (\PDOException $e) {
                    if (($e->errorInfo[1] ?? 0) === 1062 && str_contains($e->getMessage(), 'uq_files_entity_content')) throw Errors::conflict('This exact file is already attached');
                    throw $e;
                }
                // The first picture becomes the cover.
                if ($type['kind'] === 'image' && $entity === 'property' && $tx->val('SELECT cover_file_id FROM properties WHERE id = ? AND tenant_id = ?', [$entityId, $actor->tenantId]) === null) {
                    PropertyWriter::update($tx, $actor->tenantId, $entityId, ['cover_file_id' => $id]);
                }
                Audit::log($tx, 'file.upload', $entity, $entityId, null, ['fileId' => $id, 'name' => $name, 'mime' => $type['mime'], 'size' => strlen($data)]);
                Events::emit($tx, $entity === 'property' ? 'property.updated' : 'project.updated', $entityId, ['files' => true]);
                return Map::row($tx->one('SELECT id, kind, name, mime, size_bytes AS sizeBytes, sort_order AS sortOrder, created_at AS createdAt FROM files WHERE id = ? AND tenant_id = ?', [$id, $actor->tenantId]), self::DTO);
            });
        } catch (\Throwable $e) {
            try { Storage::delete($key); } catch (\Throwable) { /* orphan blobs are swept by retention */ }
            throw $e;
        }
    }

    public static function setCover(Actor $actor, string $propertyId, ?string $fileId): array
    {
        $actor->assertCan('properties:update');
        return Db::tenant($actor)->tx(static function (Tx $tx) use ($actor, $propertyId, $fileId) {
            self::assertEntity($tx, $actor->tenantId, 'property', $propertyId, true);
            if ($fileId !== null && !$tx->exists("SELECT 1 FROM files WHERE id = ? AND tenant_id = ? AND entity_type = 'property' AND entity_id = ? AND kind = 'image' AND deleted_at IS NULL", [$fileId, $actor->tenantId, $propertyId])) {
                throw Errors::validation([['path' => 'body.fileId', 'message' => 'Choose one of this property’s pictures']]);
            }
            PropertyWriter::update($tx, $actor->tenantId, $propertyId, ['cover_file_id' => $fileId]);
            Events::emit($tx, 'property.updated', $propertyId, ['cover' => true]);
            return ['coverFileId' => $fileId];
        });
    }

    public static function remove(Actor $actor, string $fileId): void
    {
        $meta = Db::tenant($actor)->read(static fn(Tx $tx) => $tx->one('SELECT entity_type, entity_id FROM files WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL', [$fileId, $actor->tenantId]));
        if (!$meta) throw Errors::notFound('File');
        $actor->assertCan(self::PERMS[$meta['entity_type']]['write']);
        $key = Db::tenant($actor)->tx(static function (Tx $tx) use ($actor, $fileId, $meta) {
            $f = $tx->one('SELECT storage_key, name FROM files WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL FOR UPDATE', [$fileId, $actor->tenantId]);
            if (!$f) throw Errors::notFound('File');
            $tx->run('UPDATE files SET deleted_at = NOW(3) WHERE id = ? AND tenant_id = ?', [$fileId, $actor->tenantId]);
            if ($meta['entity_type'] === 'property' && $tx->val('SELECT cover_file_id FROM properties WHERE id = ? AND tenant_id = ?', [$meta['entity_id'], $actor->tenantId]) === $fileId) {
                // promote the next picture if the cover went away
                $next = $tx->val("SELECT id FROM files WHERE tenant_id = ? AND entity_type = 'property' AND entity_id = ? AND kind = 'image' AND deleted_at IS NULL ORDER BY sort_order, created_at LIMIT 1", [$actor->tenantId, $meta['entity_id']]);
                PropertyWriter::update($tx, $actor->tenantId, $meta['entity_id'], ['cover_file_id' => $next]);
            }
            Audit::log($tx, 'file.delete', $meta['entity_type'], $meta['entity_id'], ['fileId' => $fileId, 'name' => $f['name']]);
            Events::emit($tx, $meta['entity_type'] === 'property' ? 'property.updated' : 'project.updated', $meta['entity_id'], ['files' => true]);
            return $f['storage_key'];
        });
        try { Storage::delete($key); } catch (\Throwable $e) { Logger::log('warn', 'Blob delete failed (retention will sweep it)', ['fileId' => $fileId, 'error' => $e->getMessage()]); }
    }

    /** Bytes for the download route. Authorisation follows the entity the file belongs to. */
    public static function read(Actor $actor, string $fileId): array
    {
        $meta = Db::tenant($actor)->read(static fn(Tx $tx) => $tx->one('SELECT entity_type, mime, name, sha256, storage_key FROM files WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL', [$fileId, $actor->tenantId]));
        if (!$meta) throw Errors::notFound('File');
        $actor->assertCan(self::PERMS[$meta['entity_type']]['read']);
        try {
            return ['data' => Storage::get($meta['storage_key']), 'mime' => $meta['mime'], 'name' => $meta['name'], 'sha256' => $meta['sha256']];
        } catch (\Throwable) {
            Logger::log('error', 'File row exists but its bytes are missing', ['fileId' => $fileId, 'tenantId' => $actor->tenantId]);
            throw Errors::notFound('File');
        }
    }

    private static function assertEntity(Tx $tx, string $tenantId, string $entity, string $id, bool $lock = false): void
    {
        if (!$tx->exists('SELECT 1 FROM ' . self::TABLE[$entity] . ' WHERE id = ? AND tenant_id = ? AND deleted_at IS NULL' . ($lock ? ' FOR UPDATE' : ''), [$id, $tenantId])) {
            throw Errors::notFound($entity === 'property' ? 'Property' : 'Project');
        }
    }
}
