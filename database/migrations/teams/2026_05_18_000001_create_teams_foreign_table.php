<?php

use Maya\Platform\Database\PostgresFdwMigration;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Vista local `teams` proyectada desde `odoo.public.v_dms_teams` vía postgres_fdw.
 *
 * Fuente de verdad: Odoo (`maya_core_team` expuesto en la vista `v_dms_teams`).
 * Read-only. Las apps Maya nunca escriben en estas filas.
 *
 * Rutas:
 * - `testing`:                   tabla física `teams` (factories pueden insertar).
 * - `local|staging|production`:  FDW + vista pass-through hacia
 *                                `odoo.public.v_dms_teams`.
 *
 * Configuración del FDW:
 * - Por defecto apunta a `maya_infra_postgres:5432/odoo`. Override vía
 *   `config('database.fdw.teams.*')` (host, port, database, username, password,
 *   schema, table) — útil en staging/prod cuando Odoo vive en otro host.
 *
 * NOTA: comparte servidor FDW `odoo_server` con la migración `users`. El
 * server se crea con `CREATE SERVER IF NOT EXISTS`, así que el orden no
 * importa. En `down()` NO se elimina el server (otras migraciones lo usan).
 */
return new class extends Migration
{
    private const VIEW_NAME  = 'teams';
    private const FDW_TABLE  = 'teams_fdw';
    private const FDW_SERVER = 'odoo_server';

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
            $this->createTestingTable();
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
        // No droppear `odoo_server`: compartido con users / team_members FDW.
    }

    private function createTestingTable(): void
    {
        // `id` VARCHAR(255) (UUID-shaped) para alinear con la vista FDW
        // (proyectada como text) y permitir JOINs JSONB↔text sin cast.
        DB::statement('
            CREATE TABLE IF NOT EXISTS teams (
                id              VARCHAR(255) PRIMARY KEY,
                name            VARCHAR(255) NOT NULL,
                description     TEXT,
                owner_id        VARCHAR(255),
                is_department   BOOLEAN NOT NULL DEFAULT FALSE,
                created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                deleted_at      TIMESTAMP NULL
            )
        ');
    }

    private function setupFdw(): void
    {
        $host     = (string) config('database.fdw.teams.host',     'maya_infra_postgres');
        $port     = (string) config('database.fdw.teams.port',     '5432');
        $database = (string) config('database.fdw.teams.database', 'odoo');
        $username = (string) config('database.fdw.teams.username', 'maya');
        $password = (string) config('database.fdw.teams.password', 'secret');
        $schema   = (string) config('database.fdw.teams.schema',   'public');
        $source   = (string) config('database.fdw.teams.table',    'v_dms_teams');

        if (! PostgresFdwMigration::ensurePostgresFdwExtension('teams catalog')) {
            return;
        }

        PostgresFdwMigration::createFdwServerWithUserMapping(
            self::FDW_SERVER,
            $host,
            $port,
            $database,
            $username,
            $password,
        );

        $foreignColumnsSql = 'id VARCHAR(255), name VARCHAR(255), description TEXT, owner_id VARCHAR(255), '
            .'is_department BOOLEAN, created_at TIMESTAMP, updated_at TIMESTAMP, deleted_at TIMESTAMP';

        $viewSelectSql = 'id, name, description, owner_id, is_department, created_at, updated_at, deleted_at';

        PostgresFdwMigration::createForeignTableWithPassThroughView(
            self::VIEW_NAME,
            $foreignColumnsSql,
            $viewSelectSql,
            self::FDW_SERVER,
            $schema,
            $source,
        );
    }
};
