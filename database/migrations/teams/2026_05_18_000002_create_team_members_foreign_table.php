<?php

use Maya\Platform\Database\PostgresFdwMigration;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Vista local `team_members` proyectada desde `odoo.public.v_dms_team_members`
 * vía postgres_fdw.
 *
 * Fuente de verdad: Odoo (`maya_core_employee_maya_core_team_rel`). Read-only.
 * El `team_id` proyectado coincide con `v_dms_teams.id` (ambos `text` con el
 * id entero original de Odoo) — necesario para JOIN sin transformaciones.
 *
 * Sin FK física sobre `team_id`: PostgreSQL no permite REFERENCES a foreign
 * tables / vistas; la consistencia se garantiza desde la vista origen.
 *
 * Comparte server FDW `odoo_server` con `users` y `teams` — el server se crea
 * con `CREATE SERVER IF NOT EXISTS`, así que es idempotente.
 */
return new class extends Migration
{
    private const VIEW_NAME  = 'team_members';
    private const FDW_TABLE  = 'team_members_fdw';
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
        // No droppear `odoo_server`: compartido con users / teams FDW.
    }

    private function createTestingTable(): void
    {
        // `id`/`team_id` VARCHAR(255) (UUID-shaped) — alinean con la vista
        // FDW (text) y con JOINs JSONB↔team_id sin cast explícito.
        DB::statement('
            CREATE TABLE IF NOT EXISTS team_members (
                id         VARCHAR(255) PRIMARY KEY,
                team_id    VARCHAR(255) NOT NULL,
                user_id    VARCHAR(255) NOT NULL,
                role       VARCHAR(50)  NOT NULL DEFAULT \'member\',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE (team_id, user_id)
            )
        ');

        DB::statement('CREATE INDEX IF NOT EXISTS team_members_user_team_idx
            ON team_members (user_id, team_id)');
    }

    private function setupFdw(): void
    {
        PostgresFdwMigration::dropViewOrTableInPublic(self::VIEW_NAME);
        PostgresFdwMigration::dropForeignTableIfExists(self::FDW_TABLE);

        $host     = (string) config('database.fdw.team_members.host',     'maya_infra_postgres');
        $port     = (string) config('database.fdw.team_members.port',     '5432');
        $database = (string) config('database.fdw.team_members.database', 'odoo');
        $username = (string) config('database.fdw.team_members.username', 'maya');
        $password = (string) config('database.fdw.team_members.password', 'secret');
        $schema   = (string) config('database.fdw.team_members.schema',   'public');
        $source   = (string) config('database.fdw.team_members.table',    'v_dms_team_members');

        if (! PostgresFdwMigration::ensurePostgresFdwExtension('team_members catalog')) {
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

        PostgresFdwMigration::createForeignTableWithPassThroughView(
            self::VIEW_NAME,
            'id VARCHAR(255), team_id VARCHAR(255), user_id VARCHAR(255), role VARCHAR(50), created_at TIMESTAMP, updated_at TIMESTAMP',
            'id, team_id, user_id, role, created_at, updated_at',
            self::FDW_SERVER,
            $schema,
            $source,
        );
    }
};
