<?php
declare(strict_types=1);
namespace App;

final class Audit
{
    public static function log(string $action, ?string $entity = null, ?int $entityId = null): void
    {
        Db::q('INSERT INTO audit_log (user_id, action, entity, entity_id, ip) VALUES (?,?,?,?,?)',
            [Auth::id(), $action, $entity, $entityId, client_ip()]);
    }
}
