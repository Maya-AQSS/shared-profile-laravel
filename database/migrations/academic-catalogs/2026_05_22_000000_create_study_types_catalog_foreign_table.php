<?php

use Maya\Platform\Database\PostgresFdwMigration;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Catálogo de tipos de estudio proyectado desde `odoo.public.res_company`.
 *
 * Mapping (Odoo CEEDCV): res_company.id es el `study_type_id` que se usa en
 * `user_study_types` y en `studies.study_type_id` (vía `maya_core_study.company_id`).
 *
 * Filtra el root CEEDCV (id=1) — solo las compañías hija (FP, BACH, FPA) son
 * verdaderos tipos de estudio.
 *
 * Vista local resultante: `study_types` (id, code, name).
 * - `code` = res_company.name (FP/BACH/FPA) — abreviatura útil en filtros.
 * - `name` = res_company.name — Odoo no expone descriptivos largos aquí.
 *
 * Read-only. Comparte server FDW `odoo_server`.
 */
return new class extends Migration
{
    private const VIEW_NAME  = 'study_types';
    private const FDW_TABLE  = 'res_company_fdw';
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
    }

    private function createTestingTable(): void
    {
        DB::statement('
            CREATE TABLE IF NOT EXISTS study_types (
                id   VARCHAR(255) PRIMARY KEY,
                code VARCHAR(64),
                name VARCHAR(255)
            )
        ');
    }

    private function setupFdw(): void
    {
        if (! PostgresFdwMigration::ensurePostgresFdwExtension('study_types catalog')) {
            return;
        }

        $schema = (string) config('database.fdw.study_types.schema', 'public');
        $source = (string) config('database.fdw.study_types.table',  'res_company');

        PostgresFdwMigration::dropForeignTableIfExists(self::FDW_TABLE);
        DB::statement('
            CREATE FOREIGN TABLE IF NOT EXISTS '.self::FDW_TABLE.' (
                id INTEGER, name VARCHAR(255), parent_id INTEGER
            )
            SERVER '.self::FDW_SERVER.'
            OPTIONS (schema_name \''.$schema.'\', table_name \''.$source.'\')
        ');

        DB::statement('DROP VIEW IF EXISTS '.self::VIEW_NAME);
        DB::statement("
            CREATE VIEW ".self::VIEW_NAME." AS
            SELECT id::text AS id, name AS code, name
            FROM ".self::FDW_TABLE."
            WHERE parent_id IS NOT NULL
        ");

        $appUser = config('database.connections.pgsql.username');
        if (is_string($appUser) && $appUser !== '') {
            try {
                DB::statement('REVOKE INSERT, UPDATE, DELETE ON '.self::FDW_TABLE.' FROM "'.$appUser.'"');
                DB::statement('GRANT SELECT ON '.self::FDW_TABLE.' TO "'.$appUser.'"');
            } catch (\Throwable $e) {
                logger()->warning('FDW study_types grants: '.$e->getMessage());
            }
        }
    }
};
