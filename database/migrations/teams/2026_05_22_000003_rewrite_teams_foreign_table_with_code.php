<?php

use Maya\Platform\Database\PostgresFdwMigration;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Reescribe la FDW de `teams` para leer directamente de `maya_core_team`
 * en lugar de `v_dms_teams`. Añade columna `code` (proyectada desde `abbr`).
 *
 * Una sola FDW por entidad lógica — no coexisten dos FDW de teams.
 *
 * La view local `teams` mantiene EXACTAMENTE las mismas columnas que ya
 * existían (compat con `AcademicDataReader::loadTeams()` actual) y AÑADE
 * `code`.
 *
 * Rutas:
 * - `testing`: ALTER TABLE para añadir `code` a la tabla física existente.
 * - `local|staging|production`: drop view + foreign table + recrear contra
 *   `maya_core_team` con jsonb unwrap en la pass-through view.
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
            // ALTER de la tabla creada por la migración anterior.
            // sqlite no soporta "ADD COLUMN IF NOT EXISTS"; usamos Schema con guard.
            if (DB::connection()->getDriverName() === 'sqlite') {
                if (! Schema::hasColumn(self::VIEW_NAME, 'code')) {
                    Schema::table(self::VIEW_NAME, function ($table): void {
                        $table->string('code', 5)->nullable();
                    });
                }
            } else {
                DB::statement('ALTER TABLE teams ADD COLUMN IF NOT EXISTS code VARCHAR(5)');
            }

            return;
        }

        // Drop la view y foreign table que apuntan a v_dms_teams.
        PostgresFdwMigration::dropViewOrTableInPublic(self::VIEW_NAME);
        PostgresFdwMigration::dropForeignTableIfExists(self::FDW_TABLE);

        $this->setupFdw();
    }

    public function down(): void
    {
        if ($this->isTestEnv()) {
            if (DB::connection()->getDriverName() === 'sqlite') {
                if (Schema::hasColumn(self::VIEW_NAME, 'code')) {
                    Schema::table(self::VIEW_NAME, function ($table): void {
                        $table->dropColumn('code');
                    });
                }
            } else {
                DB::statement('ALTER TABLE teams DROP COLUMN IF EXISTS code');
            }

            return;
        }

        // Drop la nueva FDW (apunta a maya_core_team).
        PostgresFdwMigration::dropViewOrTableInPublic(self::VIEW_NAME);
        PostgresFdwMigration::dropForeignTableIfExists(self::FDW_TABLE);

        // Recrear la FDW antigua apuntando a v_dms_teams (sin code).
        $this->setupLegacyFdw();
    }

    private function setupFdw(): void
    {
        $host     = (string) config('database.fdw.teams.host',     env('DB_HOST', 'maya_infra_postgres'));
        $port     = (string) config('database.fdw.teams.port',     '5432');
        $database = (string) config('database.fdw.teams.database', 'odoo');
        $username = (string) config('database.fdw.teams.username', 'maya');
        $password = (string) config('database.fdw.teams.password', 'secret');
        $schema   = (string) config('database.fdw.teams.schema',   'public');
        $source   = (string) config('database.fdw.teams.table',    'maya_core_team');

        if (! PostgresFdwMigration::ensurePostgresFdwExtension('teams catalog (maya_core_team)')) {
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

        // Foreign table proyecta el shape de v_dms_teams (vista remota ya
        // normalizada: id TEXT con md5 aplicado, is_department sin typo, sin
        // jsonb name). NO usar el shape raw de maya_core_team aquí: declarar
        // columnas que no coincidan con la vista hace que postgres_fdw mande
        // nombres inválidos en el push-down y rompa el JOIN.
        $foreignColumnsSql = 'id TEXT, name TEXT, description TEXT, '
            .'owner_id TEXT, is_department BOOLEAN, '
            .'created_at TIMESTAMP, updated_at TIMESTAMP, deleted_at TIMESTAMP';

        // Pass-through view: el shape ya viene normalizado desde el remoto;
        // solo añadimos `code` (NULL hasta que se cablee desde abbr si se
        // necesita un día).
        $viewSelectSql = 'id, name, description, owner_id, is_department, '
            .'NULL::varchar(5) AS code, '
            .'created_at, updated_at, deleted_at';

        PostgresFdwMigration::createForeignTableWithPassThroughView(
            self::VIEW_NAME,
            $foreignColumnsSql,
            $viewSelectSql,
            self::FDW_SERVER,
            $schema,
            $source,
        );
    }

    /**
     * Recrea la FDW antigua (legacy) apuntando a v_dms_teams, sin `code`.
     * Solo se usa en `down()` para preservar reversibilidad.
     */
    private function setupLegacyFdw(): void
    {
        $host     = (string) config('database.fdw.teams.host',     env('DB_HOST', 'maya_infra_postgres'));
        $port     = (string) config('database.fdw.teams.port',     '5432');
        $database = (string) config('database.fdw.teams.database', 'odoo');
        $username = (string) config('database.fdw.teams.username', 'maya');
        $password = (string) config('database.fdw.teams.password', 'secret');
        $schema   = 'public';
        $source   = 'v_dms_teams';

        if (! PostgresFdwMigration::ensurePostgresFdwExtension('teams catalog (legacy v_dms_teams)')) {
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
