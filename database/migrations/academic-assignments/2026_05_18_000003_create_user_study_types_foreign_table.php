<?php

use Maya\Platform\Database\PostgresFdwMigration;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Vista `user_study_types` proyectada desde Odoo (FDW directo):
 *   res_company_users_rel JOIN res_users
 *
 * `study_type_id` = `res_company.id` (compañía hija de CEEDCV: FP/BACH/FPA).
 * El root CEEDCV (id=1) se excluye — no es un study_type.
 *
 * Source-of-truth: Odoo. Las apps no sincronizan local source — la asignación
 * de tipos de estudio se gestiona en Odoo vía `res_company_users_rel`.
 *
 * En testing usamos una tabla física estándar (test fixtures controlan los datos).
 */
return new class extends Migration
{
    private const VIEW_NAME            = 'user_study_types';
    private const REL_FDW_TABLE        = 'res_company_users_rel_fdw';
    private const USERS_KC_FDW_TABLE   = 'res_users_kc_fdw';
    private const FDW_SERVER           = 'odoo_server';

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

        $this->setupFdwView();
    }

    public function down(): void
    {
        if ($this->isTestEnv()) {
            DB::statement('DROP TABLE IF EXISTS '.self::VIEW_NAME);
            return;
        }

        DB::statement('DROP VIEW IF EXISTS '.self::VIEW_NAME);
        PostgresFdwMigration::dropForeignTableIfExists(self::REL_FDW_TABLE);
        // res_users_kc_fdw lo comparten 3 migraciones — no lo dropeamos aquí
        // para no romper user_studies / user_course_modules.
    }

    private function createTestingTable(): void
    {
        DB::statement('
            CREATE TABLE IF NOT EXISTS user_study_types (
                id            VARCHAR(255) PRIMARY KEY,
                user_id       VARCHAR(255) NOT NULL,
                study_type_id VARCHAR(255) NOT NULL,
                created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )
        ');

        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS user_study_types_user_study_type_uidx
            ON user_study_types (user_id, study_type_id)');
        DB::statement('CREATE INDEX IF NOT EXISTS user_study_types_user_id_idx
            ON user_study_types (user_id)');
    }

    private function setupFdwView(): void
    {
        if (! PostgresFdwMigration::ensurePostgresFdwExtension('user_study_types via res_company_users_rel')) {
            return;
        }

        // res_company_users_rel: (cid, user_id) — compañía → usuarios asignados.
        PostgresFdwMigration::dropForeignTableIfExists(self::REL_FDW_TABLE);
        DB::statement('
            CREATE FOREIGN TABLE IF NOT EXISTS '.self::REL_FDW_TABLE.' (
                cid INTEGER, user_id INTEGER
            )
            SERVER '.self::FDW_SERVER.'
            OPTIONS (schema_name \'public\', table_name \'res_company_users_rel\')
        ');

        // res_users (compartida con las otras 2 migraciones). Idempotente: drop+create.
        PostgresFdwMigration::dropForeignTableIfExists(self::USERS_KC_FDW_TABLE);
        DB::statement('
            CREATE FOREIGN TABLE IF NOT EXISTS '.self::USERS_KC_FDW_TABLE.' (
                id INTEGER, keycloak_user_id VARCHAR(255), active BOOLEAN
            )
            SERVER '.self::FDW_SERVER.'
            OPTIONS (schema_name \'public\', table_name \'res_users\')
        ');

        DB::statement('DROP VIEW IF EXISTS '.self::VIEW_NAME);
        DB::statement("
            CREATE OR REPLACE VIEW ".self::VIEW_NAME." AS
            SELECT
                md5('ust_' || u.keycloak_user_id || '_' || rcr.cid::text)::uuid::text AS id,
                u.keycloak_user_id        AS user_id,
                rcr.cid::text             AS study_type_id,
                NULL::timestamp           AS created_at,
                NULL::timestamp           AS updated_at
            FROM ".self::REL_FDW_TABLE." rcr
            JOIN ".self::USERS_KC_FDW_TABLE." u ON u.id = rcr.user_id
            WHERE u.keycloak_user_id IS NOT NULL
              AND u.keycloak_user_id <> ''
              AND u.active = true
              AND rcr.cid <> 1
        ");

        $appUser = config('database.connections.pgsql.username');
        if (is_string($appUser) && $appUser !== '') {
            try {
                foreach ([self::REL_FDW_TABLE, self::USERS_KC_FDW_TABLE] as $ft) {
                    DB::statement('REVOKE INSERT, UPDATE, DELETE ON '.$ft.' FROM "'.$appUser.'"');
                    DB::statement('GRANT SELECT ON '.$ft.' TO "'.$appUser.'"');
                }
            } catch (\Throwable $e) {
                logger()->warning('FDW user_study_types grants: '.$e->getMessage());
            }
        }
    }
};
