<?php
declare(strict_types=1);

namespace App\Core;

/** In-app notifications, written inside the producer's transaction (exists iff the business change committed). */
final class Notify
{
    /**
     * @param array{userId:string,title:string,message:string,type:string,link?:?string,entityType?:?string,entityId?:?string,dedupeKey?:?string} $n
     * @return string|null the notification id, null when the dedupe key was already delivered
     */
    public static function send(Tx $tx, array $n): ?string
    {
        $link = $n['link'] ?? null;
        // In-app path only: the database refused anything else so a notification can never be a phishing link
        if ($link !== null && (!str_starts_with($link, '/') || str_starts_with($link, '//'))) $link = null;
        $id = $tx->insertIgnore('notifications', [
            'tenant_id' => $tx->tenantId,
            'user_id' => $n['userId'],
            'title' => mb_substr($n['title'], 0, 200),
            'message' => mb_substr($n['message'], 0, 500),
            'type' => $n['type'],
            'metadata' => '{}',
            'link' => $link,
            'entity_type' => $n['entityType'] ?? null,
            'entity_id' => $n['entityId'] ?? null,
            'dedupe_key' => $n['dedupeKey'] ?? null,
        ]);
        if ($id) Events::emit($tx, 'notification.created', $id, ['userId' => $n['userId']]);
        return $id ?: null;
    }
}
