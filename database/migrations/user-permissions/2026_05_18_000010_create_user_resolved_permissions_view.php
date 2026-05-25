<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Maya\Platform\Database\PostgresFdwMigration;

/**
 * Permisos resueltos del usuario consumidos por `RequirePermissionMiddleware`
 * y por `FdwAcademicResolver` (campo `permissions[]` en `/me`).
 *
 * Cada app del ecosistema proyecta esta vista localmente vía FDW. El
 * **nombre de la vista remota varía por app** (en maya_authorization:
 * `v_audit_user_permissions`, `v_logs_user_permissions`,
 * `v_dashboard_user_permissions`, `v_portal_user_permissions`, …).
 * Por eso el remote view se lee de config — cada app declara su valor en
 * `config/database.php` o `.env`:
 *
 *   FDW_USER_PERMISSIONS_REMOTE_VIEW=v_<app>_user_permissions
 *
 * maya_dashboard (portal) usa `v_portal_user_permissions` para exponer en
 * `/me` todos los permisos del usuario (incluidos `*.login`) y filtrar apps.
 *
 * Rutas:
 * - `testing` (o BD `*_test`): tabla física stub.
 * - `local`:                  FDW → `maya_auth` con la vista declarada.
 * - `staging`/`production`:   FDW remoto según `database.fdw.user_permissions.*`.
 */
return new class extends Migration
{
    private const VIEW_NAME  = 'user_resolved_permissions';
    private const FDW_TABLE  = 'user_resolved_permissions_fdw';
    private const FDW_SERVER = 'maya_auth_user_permissions_server';

    private function isTestEnv(): bool
    {
        if (app()->environment('testing')) {
            return true;
        }
        $db = config('database.connections.pgsql.database');

        return is_string($db) && str_ends_with($db, '_test');
    }

    public function up(): void
    {
        if ($this->isTestEnv()) {
            DB::statement('
                CREATE TABLE IF NOT EXISTS '.self::VIEW_NAME.' (
                    user_id          VARCHAR(255) NOT NULL,
                    permission_slug  VARCHAR(191) NOT NULL,
                    PRIMARY KEY (user_id, permission_slug)
                )
            ');

            return;
        }

        $this->setupFdw();
    }

    public function down(): void
    {
        if ($this->isTestEnv()) {
            DB::statement('DROP TABLE IF EXISTS '.self::VIEW_NAME);

            return;
        }

        PostgresFdwMigration::dropViewOrTableInPublic(self::VIEW_NAME);
        PostgresFdwMigration::dropForeignTableIfExists(self::FDW_TABLE);
        PostgresFdwMigration::dropFdwServerAndUserMapping(self::FDW_SERVER);
    }

    private function setupFdw(): void
    {
        $isLocal = app()->environment('local');

        if ($isLocal) {
            $host     = config('database.connections.pgsql.host');
            $port     = config('database.connections.pgsql.port');
            $database = config('database.fdw.user_permissions.database', 'maya_auth');
            $username = config('database.fdw.user_permissions.username', 'maya');
            $password = config('database.fdw.user_permissions.password', 'secret');
        } else {
            $host     = config('database.fdw.user_permissions.host');
            $port     = config('database.fdw.user_permissions.port');
            $database = config('database.fdw.user_permissions.database');
            $username = config('database.fdw.user_permissions.username');
            $password = config('database.fdw.user_permissions.password');
        }

        $remoteView = (string) config('database.fdw.user_permissions.remote_view', self::VIEW_NAME);

        if (! PostgresFdwMigration::ensurePostgresFdwExtension('user permissions')) {
            return;
        }

        // Drop previo (idempotente) — necesario porque `CREATE FOREIGN TABLE
        // IF NOT EXISTS` del helper conserva el `table_name` antiguo si la
        // foreign table ya existe. Cuando cambia la vista remota (p.ej. de
        // `v_<app>_user_permissions` a `v_portal_user_permissions`) hay que
        // recrearla. `migrate:fresh` no las dropea automáticamente (Postgres
        // las distingue de BASE TABLE).
        PostgresFdwMigration::dropViewOrTableInPublic(self::VIEW_NAME);
        PostgresFdwMigration::dropForeignTableIfExists(self::FDW_TABLE);

        PostgresFdwMigration::createFdwServerWithUserMapping(
            self::FDW_SERVER,
            (string) $host,
            (string) $port,
            (string) $database,
            (string) $username,
            (string) $password,
        );

        PostgresFdwMigration::createForeignTableWithPassThroughView(
            self::VIEW_NAME,
            'user_id VARCHAR(255), permission_slug VARCHAR(191)',
            'user_id, permission_slug',
            self::FDW_SERVER,
            'public',
            $remoteView,
        );
    }
};
