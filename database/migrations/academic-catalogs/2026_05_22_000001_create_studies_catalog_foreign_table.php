<?php

use Maya\Platform\Database\PostgresFdwMigration;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Catálogo de estudios proyectado desde `odoo.public.maya_core_study`.
 *
 * Vista local resultante: `studies` (id, code, study_type_id, name, active).
 * El jsonb `name` se desempaqueta lado-Maya en el SELECT de la pass-through view.
 * Filtro `active = true` aplicado en la pass-through view.
 *
 * Read-only. Comparte server FDW `odoo_server` con las demás FDW del paquete.
 *
 * Rutas:
 * - `testing`: tabla física `studies` (factories pueden insertar).
 * - `local|staging|production`: FDW directa a `public.maya_core_study`.
 */
return new class extends Migration
{
    private const VIEW_NAME  = 'studies';
    private const FDW_TABLE  = 'studies_fdw';
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
        // No droppear `odoo_server`: compartido con users / teams / team_members FDW.
    }

    private function createTestingTable(): void
    {
        DB::statement('
            CREATE TABLE IF NOT EXISTS studies (
                id            VARCHAR(255) PRIMARY KEY,
                code          VARCHAR(7),
                study_type_id VARCHAR(255),
                name          VARCHAR(255),
                active        BOOLEAN NOT NULL DEFAULT TRUE
            )
        ');

        DB::statement('CREATE INDEX IF NOT EXISTS studies_study_type_id_idx
            ON studies (study_type_id)');
    }

    private function setupFdw(): void
    {
        $host     = (string) config('database.fdw.studies.host',     'maya_infra_postgres');
        $port     = (string) config('database.fdw.studies.port',     '5432');
        $database = (string) config('database.fdw.studies.database', 'odoo');
        $username = (string) config('database.fdw.studies.username', 'maya');
        $password = (string) config('database.fdw.studies.password', 'secret');
        $schema   = (string) config('database.fdw.studies.schema',   'public');
        $source   = (string) config('database.fdw.studies.table',    'maya_core_study');

        if (! PostgresFdwMigration::ensurePostgresFdwExtension('studies catalog')) {
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

        // Foreign table proyecta los tipos crudos de Odoo (jsonb para name).
        // `company_id` (FK a res_company) es el verdadero study_type — agrupa
        // estudios por compañía CEEDCV (FP/BACH/FPA).
        $foreignColumnsSql = 'id INTEGER, code VARCHAR(7), '
            .'company_id INTEGER, name JSONB, active BOOLEAN';

        $simpleSelect = "id::text AS id, code, company_id::text AS study_type_id, "
            ."(name->>'en_US') AS name, active";

        PostgresFdwMigration::createForeignTableWithPassThroughView(
            self::VIEW_NAME,
            $foreignColumnsSql,
            $simpleSelect,
            self::FDW_SERVER,
            $schema,
            $source,
        );

        // Reemplazar la view por una versión filtrada por active=true.
        DB::statement('DROP VIEW IF EXISTS '.self::VIEW_NAME);
        DB::statement("
            CREATE VIEW ".self::VIEW_NAME." AS
            SELECT id::text AS id, code, company_id::text AS study_type_id,
                   (name->>'en_US') AS name, active
            FROM ".self::FDW_TABLE."
            WHERE active = true
        ");
    }
};
