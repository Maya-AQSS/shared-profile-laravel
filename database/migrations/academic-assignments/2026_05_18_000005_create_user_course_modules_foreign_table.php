<?php

use Maya\Platform\Database\PostgresFdwMigration;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Vista `user_course_modules` proyectada desde Odoo (FDW directo):
 *   maya_core_subject_employee_rel JOIN maya_core_employee JOIN res_users
 *
 * Cada fila representa un módulo concreto que el empleado imparte. El
 * module_id es el composite `study_id || '_' || subject_id` — consistente
 * con la vista catálogo `course_modules` que proyecta el mismo formato.
 *
 * Source-of-truth: Odoo `maya_core_subject_employee_rel`.
 */
return new class extends Migration
{
    private const VIEW_NAME             = 'user_course_modules';
    private const REL_FDW_TABLE         = 'maya_core_subject_employee_rel_fdw';
    private const EMPLOYEE_FDW_TABLE    = 'maya_core_employee_fdw';
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
        PostgresFdwMigration::dropForeignTableIfExists(self::REL_FDW_TABLE);
        PostgresFdwMigration::dropForeignTableIfExists(self::EMPLOYEE_FDW_TABLE);
    }

    private function createTestingTable(): void
    {
        DB::statement('
            CREATE TABLE IF NOT EXISTS user_course_modules (
                id         VARCHAR(255) PRIMARY KEY,
                user_id    VARCHAR(255) NOT NULL,
                module_id  VARCHAR(255) NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )
        ');

        DB::statement('CREATE UNIQUE INDEX IF NOT EXISTS user_course_modules_user_module_uidx
            ON user_course_modules (user_id, module_id)');
        DB::statement('CREATE INDEX IF NOT EXISTS user_course_modules_user_id_idx
            ON user_course_modules (user_id)');
    }

    private function setupFdwView(): void
    {
        if (! PostgresFdwMigration::ensurePostgresFdwExtension('user_course_modules via subject_employee_rel')) {
            return;
        }

        // maya_core_subject_employee_rel
        PostgresFdwMigration::dropForeignTableIfExists(self::REL_FDW_TABLE);
        DB::statement('
            CREATE FOREIGN TABLE IF NOT EXISTS '.self::REL_FDW_TABLE.' (
                id INTEGER, subject_id INTEGER, employee_id INTEGER, study_id INTEGER,
                create_date TIMESTAMP, write_date TIMESTAMP
            )
            SERVER '.self::FDW_SERVER.'
            OPTIONS (schema_name \'public\', table_name \'maya_core_subject_employee_rel\')
        ');

        // maya_core_employee (bridge employee → res_users)
        PostgresFdwMigration::dropForeignTableIfExists(self::EMPLOYEE_FDW_TABLE);
        DB::statement('
            CREATE FOREIGN TABLE IF NOT EXISTS '.self::EMPLOYEE_FDW_TABLE.' (
                id INTEGER, user_id INTEGER
            )
            SERVER '.self::FDW_SERVER.'
            OPTIONS (schema_name \'public\', table_name \'maya_core_employee\')
        ');

        // res_users_kc_fdw ya creada por 000003 — idempotente.
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
                md5('ucm_' || u.keycloak_user_id || '_' || ser.study_id::text || '_' || ser.subject_id::text)::uuid::text AS id,
                u.keycloak_user_id                                              AS user_id,
                (ser.study_id::text || '_' || ser.subject_id::text)             AS module_id,
                ser.create_date                                                  AS created_at,
                ser.write_date                                                   AS updated_at
            FROM ".self::REL_FDW_TABLE." ser
            JOIN ".self::EMPLOYEE_FDW_TABLE." e ON e.id = ser.employee_id
            JOIN ".self::USERS_KC_FDW_TABLE." u ON u.id = e.user_id
            WHERE u.keycloak_user_id IS NOT NULL
              AND u.keycloak_user_id <> ''
              AND u.active = true
        ");

        $appUser = config('database.connections.pgsql.username');
        if (is_string($appUser) && $appUser !== '') {
            try {
                foreach ([self::REL_FDW_TABLE, self::EMPLOYEE_FDW_TABLE] as $ft) {
                    DB::statement('REVOKE INSERT, UPDATE, DELETE ON '.$ft.' FROM "'.$appUser.'"');
                    DB::statement('GRANT SELECT ON '.$ft.' TO "'.$appUser.'"');
                }
            } catch (\Throwable $e) {
                logger()->warning('FDW user_course_modules grants: '.$e->getMessage());
            }
        }
    }
};
