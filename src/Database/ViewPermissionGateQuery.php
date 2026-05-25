<?php

declare(strict_types=1);

namespace Maya\Profile\Database;

use Illuminate\Database\Eloquent\Builder;

/**
 * Filtra filas cuyo gate (`view_permission_slug`) está en
 * `user_resolved_permissions` del usuario (proyectada por el paquete vía FDW).
 */
final class ViewPermissionGateQuery
{
    /**
     * @param  Builder<\Illuminate\Database\Eloquent\Model>  $query
     */
    public static function apply(Builder $query, string $userId, string $gateColumn = 'view_permission_slug'): void
    {
        $table = $query->getModel()->getTable();

        $query->where(function (Builder $inner) use ($userId, $gateColumn, $table) {
            $inner
                ->whereNull("{$table}.{$gateColumn}")
                ->orWhere("{$table}.{$gateColumn}", '')
                ->orWhereExists(function ($sub) use ($userId, $gateColumn, $table) {
                    $sub->selectRaw('1')
                        ->from('user_resolved_permissions')
                        ->whereColumn(
                            'user_resolved_permissions.permission_slug',
                            "{$table}.{$gateColumn}",
                        )
                        ->where('user_resolved_permissions.user_id', $userId);
                });
        });
    }
}
