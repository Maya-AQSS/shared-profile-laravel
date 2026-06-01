<?php

use Maya\Platform\Database\PostgresFdwMigration;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Catálogo de módulos (estudio × asignatura) proyectado desde Odoo.
 *
 * El "módulo" en Maya es la combinación (estudio, asignatura) — un docente
 * imparte una asignatura DENTRO de un estudio concreto. Por eso el id es
 * compuesto `{study_id}_{subject_id}` (mismo shape que el legacy
 * `v_dms_course_modules` para compat con `user_course_modules.module_id`).
 *
 * Requiere dos FDW (no se puede materializar el JOIN en una sola foreign
 * table — postgres_fdw mapea una tabla remota → una foreign table):
 *  - `course_modules_subject_fdw` → `maya_core_subject` (code, name)
 *  - `course_modules_rel_fdw`     → `maya_core_study_maya_core_subject_rel`
 *
 * Vista local resultante: `course_modules` (id, code, year, name, study_id).
 *
 * Read-only. Comparte server FDW `odoo_server`.
 */
return new class extends Migration
{
    private const VIEW_NAME         = 'course_modules';
    private const FDW_TABLE_SUBJECT = 'course_modules_subject_fdw';
    private const FDW_TABLE_REL     = 'course_modules_rel_fdw';
    private const FDW_SERVER        = 'odoo_server';

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
        PostgresFdwMigration::dropForeignTableIfExists(self::FDW_TABLE_SUBJECT);
        PostgresFdwMigration::dropForeignTableIfExists(self::FDW_TABLE_REL);
    }

    private function createTestingTable(): void
    {
        DB::statement('
            CREATE TABLE IF NOT EXISTS course_modules (
                id       VARCHAR(255) PRIMARY KEY,
                code     VARCHAR(11),
                year     VARCHAR(255),
                name     VARCHAR(255),
                study_id VARCHAR(255)
            )
        ');

        DB::statement('CREATE INDEX IF NOT EXISTS course_modules_study_id_idx
            ON course_modules (study_id)');
    }

    private function setupFdw(): void
    {
        $host     = (string) config('database.fdw.course_modules.host',     env('DB_HOST', 'maya_infra_postgres'));
        $port     = (string) config('database.fdw.course_modules.port',     '5432');
        $database = (string) config('database.fdw.course_modules.database', 'odoo');
        $username = (string) config('database.fdw.course_modules.username', 'maya');
        $password = (string) config('database.fdw.course_modules.password', 'secret');
        $schema   = (string) config('database.fdw.course_modules.schema',   'public');

        if (! PostgresFdwMigration::ensurePostgresFdwExtension('course_modules catalog')) {
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

        // Foreign table 1: maya_core_subject (id, code, name).
        DB::statement('
            CREATE FOREIGN TABLE IF NOT EXISTS '.self::FDW_TABLE_SUBJECT.' (
                id INTEGER, code VARCHAR(11), year VARCHAR(255), name JSONB
            )
            SERVER '.self::FDW_SERVER.'
            OPTIONS (schema_name \''.$schema.'\', table_name \'maya_core_subject\')
        ');

        // Foreign table 2: maya_core_study_maya_core_subject_rel (study_id, subject_id).
        DB::statement('
            CREATE FOREIGN TABLE IF NOT EXISTS '.self::FDW_TABLE_REL.' (
                maya_core_study_id INTEGER,
                maya_core_subject_id INTEGER
            )
            SERVER '.self::FDW_SERVER.'
            OPTIONS (schema_name \''.$schema.'\', table_name \'maya_core_study_maya_core_subject_rel\')
        ');

        // Vista local que materializa el JOIN cross-FDW con id compuesto.
        DB::statement("
            CREATE OR REPLACE VIEW ".self::VIEW_NAME." AS
            SELECT (rel.maya_core_study_id::text || '_' || rel.maya_core_subject_id::text) AS id,
                   subj.code,
                   subj.year,
                   (subj.name->>'en_US') AS name,
                   rel.maya_core_study_id::text AS study_id
            FROM ".self::FDW_TABLE_REL." rel
            JOIN ".self::FDW_TABLE_SUBJECT." subj ON subj.id = rel.maya_core_subject_id
        ");

        // Revocar escritura en las foreign tables.
        try {
            $appUser = config('database.connections.pgsql.username');
            if (is_string($appUser) && $appUser !== '') {
                DB::statement('REVOKE INSERT, UPDATE, DELETE ON '.self::FDW_TABLE_SUBJECT.' FROM "'.$appUser.'"');
                DB::statement('REVOKE INSERT, UPDATE, DELETE ON '.self::FDW_TABLE_REL.' FROM "'.$appUser.'"');
                DB::statement('GRANT SELECT ON '.self::FDW_TABLE_SUBJECT.' TO "'.$appUser.'"');
                DB::statement('GRANT SELECT ON '.self::FDW_TABLE_REL.' TO "'.$appUser.'"');
            }
        } catch (\Throwable $e) {
            logger()->warning("FDW course_modules grants: ".$e->getMessage());
        }
    }
};
