<?php
declare(strict_types=1);

namespace App\Services;

use App\Middleware\Auth;
use App\Support\Database;

/**
 * Registro de auditoria. Toda accion administrativa deja rastro, y el
 * rastro sobrevive a la cuenta que la ejecuto: user_label se guarda
 * copiado, no como referencia.
 */
final class AuditService
{
    public static function log(
        string $action,
        ?string $entityType = null,
        ?int $entityId = null,
        ?string $summary = null,
        ?array $changes = null
    ): void {
        Database::insert('audit_logs', [
            'user_id'     => Auth::id(),
            'user_label'  => Auth::label(),
            'action'      => substr($action, 0, 80),
            'entity_type' => $entityType,
            'entity_id'   => $entityId,
            'summary'     => $summary === null ? null : substr($summary, 0, 500),
            'changes'     => $changes === null ? null : json_encode($changes, JSON_UNESCAPED_UNICODE),
            'ip_hash'     => hash('sha256', ($_SERVER['REMOTE_ADDR'] ?? '') . '|auditoria'),
        ]);
    }

    /** Diferencia entre dos versiones, para guardar solo lo que cambio. */
    public static function diff(array $before, array $after, array $fields): array
    {
        $changes = [];
        foreach ($fields as $field) {
            $old = $before[$field] ?? null;
            $new = $after[$field] ?? null;
            if ((string) $old !== (string) $new) {
                $changes[$field] = [
                    'antes'   => is_string($old) ? mb_substr($old, 0, 300) : $old,
                    'despues' => is_string($new) ? mb_substr($new, 0, 300) : $new,
                ];
            }
        }
        return $changes;
    }

    public static function recent(int $limit, int $offset, array $filters = []): array
    {
        $conditions = ['1 = 1'];
        $params     = [];

        if (!empty($filters['action'])) {
            $conditions[]     = 'action = :action';
            $params['action'] = $filters['action'];
        }
        if (!empty($filters['entity_type'])) {
            $conditions[]          = 'entity_type = :entity_type';
            $params['entity_type'] = $filters['entity_type'];
        }
        if (!empty($filters['user_id'])) {
            $conditions[]      = 'user_id = :user_id';
            $params['user_id'] = (int) $filters['user_id'];
        }

        $where = implode(' AND ', $conditions);

        return [
            'items' => Database::all(
                'SELECT * FROM audit_logs WHERE ' . $where . ' ORDER BY created_at DESC, id DESC
                  LIMIT ' . (int) $limit . ' OFFSET ' . (int) $offset,
                $params
            ),
            'total' => (int) Database::value('SELECT COUNT(*) FROM audit_logs WHERE ' . $where, $params),
        ];
    }

    public static function actions(): array
    {
        return Database::all('SELECT DISTINCT action FROM audit_logs ORDER BY action');
    }
}
