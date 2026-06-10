<?php

use Maya\Platform\Database\PostgresFdwMigration;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Corrige la FDW de `teams`: vuelve a leer de la vista remota normalizada
 * `odoo.public.v_dms_teams` en lugar de la tabla cruda `maya_core_team`.
 *
 * CONTEXTO DEL BUG (introducido por 2026_05_22_000003):
 * La migración anterior repuntó la FDW de `v_dms_teams` → `maya_core_team`
 * "para añadir code desde abbr", pero `code` nunca se llegó a cablear (sigue
 * siendo `NULL` en la vista) y el cambio rompió por completo el bloque teams:
 *
 *   1. id-space: `maya_core_team.id` son enteros crudos (1,2,3…) mientras que
 *      `v_dms_team_members.team_id` usa `md5('team_'||id)::uuid::text`. El JOIN
 *      `teams.id = team_members.team_id` pasó a casar CERO filas.
 *   2. name: `maya_core_team.name` es jsonb (`{"en_US":"…"}`) sin desempaquetar;
 *      la vista pass-through no aplicaba `->>'en_US'`.
 *   3. tipo: la foreign table declara `id TEXT` pero el remoto pasó a ser
 *      `integer`. Como `text::text` es un no-op, postgres_fdw lo elimina al
 *      deparsear el push-down del JOIN y manda `r4.team_id::text = r5.id`
 *      (text = integer) → QueryException → `_status.teams = 'unavailable'`.
 *
 * Síntoma visible: badge "No disponible" en Equipos/Departamentos
 * (maya_authorization.UserManagementPage) y teams ausentes de forma
 * intermitente en el perfil de maya_dashboard (la intermitencia venía de que
 * el planner FDW no siempre elige el join push-down + el TTL de error de 30s).
 *
 * `v_dms_teams` ya entrega `id` como md5 text (alineado con `team_members`),
 * `name` desempaquetado e `is_department` normalizado. La columna `code` se
 * mantiene como `NULL::varchar(5)` (la vista normalizada no expone `abbr`);
 * ningún consumidor depende todavía de su valor.
 *
 * Rutas:
 * - `testing`: no-op (la tabla física `teams` ya tiene `code` desde 000003).
 * - `local|staging|production`: drop view + foreign table + recrear contra
 *   `v_dms_teams`.
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
            // La tabla física `teams` ya tiene la columna `code` (000003); nada
            // que reproyectar en entorno de test.
            return;
        }

        PostgresFdwMigration::dropViewOrTableInPublic(self::VIEW_NAME);
        PostgresFdwMigration::dropForeignTableIfExists(self::FDW_TABLE);

        $this->setupFdw('v_dms_teams');
    }

    public function down(): void
    {
        if ($this->isTestEnv()) {
            return;
        }

        // Reversibilidad estricta: vuelve al estado (roto) de 000003 apuntando
        // a `maya_core_team`.
        PostgresFdwMigration::dropViewOrTableInPublic(self::VIEW_NAME);
        PostgresFdwMigration::dropForeignTableIfExists(self::FDW_TABLE);

        $this->setupFdw('maya_core_team');
    }

    private function setupFdw(string $defaultSource): void
    {
        $host     = (string) config('database.fdw.teams.host',     env('DB_HOST', 'maya_infra_postgres'));
        $port     = (string) config('database.fdw.teams.port',     '5432');
        $database = (string) config('database.fdw.teams.database', 'odoo');
        $username = (string) config('database.fdw.teams.username', 'maya');
        $password = (string) config('database.fdw.teams.password', 'secret');
        $schema   = (string) config('database.fdw.teams.schema',   'public');
        $source   = (string) config('database.fdw.teams.table',    $defaultSource);

        if (! PostgresFdwMigration::ensurePostgresFdwExtension('teams catalog (v_dms_teams)')) {
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

        // Shape EXACTO de `v_dms_teams` (id text md5, name desempaquetado,
        // is_department normalizado). `id TEXT` coincide con el tipo remoto, así
        // que el push-down del JOIN deparsea text = text sin romper.
        $foreignColumnsSql = 'id TEXT, name TEXT, description TEXT, '
            .'owner_id TEXT, is_department BOOLEAN, '
            .'created_at TIMESTAMP, updated_at TIMESTAMP, deleted_at TIMESTAMP';

        // Pass-through: añadimos `code` como NULL (v_dms_teams no expone abbr).
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
};
