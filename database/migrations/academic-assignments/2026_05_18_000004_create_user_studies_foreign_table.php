<?php

use Maya\Platform\Database\PostgresFdwMigration;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Vista `user_studies` proyectada desde Odoo (FDW directo):
 *   res_company_users_rel JOIN maya_core_study (por company_id)
 *
 * Semántica managerial: un usuario asignado a la compañía FP ve TODOS los
 * estudios FP activos (no sólo los que imparte). Para impartición específica
 * de (study, subject) ver `user_course_modules`.
 *
 * Source-of-truth: Odoo. Sin sincronización local.
 */
return new class extends Migration
{
    private const VIEW_NAME             = 'user_studies';
    private const REL_FDW_TABLE         = 'res_company_users_rel_fdw';
    private const STUDY_FDW_TABLE       = 'maya_core_study_for_user_studies_fdw';
    private const USERS_KC_FDW_TABLE    = 'res_users_kc_fdw';
    private const FDW_SERVER            = 'odoo_server';

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
        PostgresFdwMigration::dropForeignTableIfExists(self::STUDY_FDW_TABLE);
    }

    private function createTestingTable(): void
    {
        DB::statement('
            CREATE TABLE IF NOT EXISTS user_studies (
                id         VARCHAR(255) PRIMARY KEY,
                user_id    VARCHAR(255) NOT NULL,
                study_id   VARCHAR(255) NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )
        ');

        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS user_studies_user_study_uidx
            ON user_studies (user_id, study_id)');
        DB::statement('CREATE INDEX IF NOT EXISTS user_studies_user_id_idx
            ON user_studies (user_id)');
    }

    private function setupFdwView(): void
    {
        if (! PostgresFdwMigration::ensurePostgresFdwExtension('user_studies via res_company_users_rel')) {
            return;
        }

        // res_company_users_rel y res_users_kc_fdw: ya creadas por 000003.
        // Idempotente: si no existen las creamos aquí también.
        DB::statement('
            CREATE FOREIGN TABLE IF NOT EXISTS '.self::REL_FDW_TABLE.' (
                cid INTEGER, user_id INTEGER
            )
            SERVER '.self::FDW_SERVER.'
            OPTIONS (schema_name \'public\', table_name \'res_company_users_rel\')
        ');
        DB::statement('
            CREATE FOREIGN TABLE IF NOT EXISTS '.self::USERS_KC_FDW_TABLE.' (
                id INTEGER, keycloak_user_id VARCHAR(255), active BOOLEAN
            )
            SERVER '.self::FDW_SERVER.'
            OPTIONS (schema_name \'public\', table_name \'res_users\')
        ');

        // maya_core_study con company_id (para JOIN).
        PostgresFdwMigration::dropForeignTableIfExists(self::STUDY_FDW_TABLE);
        DB::statement('
            CREATE FOREIGN TABLE IF NOT EXISTS '.self::STUDY_FDW_TABLE.' (
                id INTEGER, company_id INTEGER, active BOOLEAN,
                create_date TIMESTAMP, write_date TIMESTAMP
            )
            SERVER '.self::FDW_SERVER.'
            OPTIONS (schema_name \'public\', table_name \'maya_core_study\')
        ');

        DB::statement('DROP VIEW IF EXISTS '.self::VIEW_NAME);
        DB::statement("
            CREATE OR REPLACE VIEW ".self::VIEW_NAME." AS
            SELECT
                md5('us_' || u.keycloak_user_id || '_' || s.id::text)::uuid::text AS id,
                u.keycloak_user_id  AS user_id,
                s.id::text          AS study_id,
                s.create_date       AS created_at,
                s.write_date        AS updated_at
            FROM ".self::REL_FDW_TABLE." rcr
            JOIN ".self::USERS_KC_FDW_TABLE." u ON u.id = rcr.user_id
            JOIN ".self::STUDY_FDW_TABLE." s ON s.company_id = rcr.cid
            WHERE u.keycloak_user_id IS NOT NULL
              AND u.keycloak_user_id <> ''
              AND u.active = true
              AND s.active = true
              AND rcr.cid <> 1
        ");

        $appUser = config('database.connections.pgsql.username');
        if (is_string($appUser) && $appUser !== '') {
            try {
                DB::statement('REVOKE INSERT, UPDATE, DELETE ON '.self::STUDY_FDW_TABLE.' FROM "'.$appUser.'"');
                DB::statement('GRANT SELECT ON '.self::STUDY_FDW_TABLE.' TO "'.$appUser.'"');
            } catch (\Throwable $e) {
                logger()->warning('FDW user_studies grants: '.$e->getMessage());
            }
        }
    }
};
