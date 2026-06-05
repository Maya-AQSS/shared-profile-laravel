<?php

use Maya\Platform\Database\PostgresFdwMigration;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Catálogo de idiomas activos proyectado desde Odoo `res.lang`.
 *
 * Vista local resultante: `languages` (code, name, is_default).
 *  - `code` = locale Maya (es/va/en): se mapea desde `res_lang.code`
 *    (`es_ES`→es, `ca_ES`→va [valenciano se gestiona como catalán en Odoo],
 *    `en_GB`/`en_US`→en; resto = prefijo de 2 letras).
 *  - `name` = `res_lang.name` (nombre nativo del idioma en Odoo).
 *  - `is_default` = (code = 'es').
 *
 * Solo idiomas `active = true`. DISTINCT por código mapeado para evitar
 * duplicados (p.ej. en_GB + en_US → un único `en`).
 *
 * Fuente read-only. Comparte el server FDW `odoo_server`.
 *
 * Rutas:
 * - `testing`/`*_test`: tabla física `languages` sembrada con es/va/en.
 * - `local|staging|production`: FDW + vista pass-through hacia `res_lang`.
 */
return new class extends Migration
{
    private const VIEW_NAME  = 'languages';
    private const FDW_TABLE  = 'res_lang_fdw';
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
        // No droppear `odoo_server`: compartido con users/teams/catalogs FDW.
    }

    private function createTestingTable(): void
    {
        DB::statement('
            CREATE TABLE IF NOT EXISTS languages (
                code       VARCHAR(12) PRIMARY KEY,
                name       VARCHAR(255) NOT NULL,
                is_default BOOLEAN NOT NULL DEFAULT FALSE
            )
        ');

        // Semilla: los locales con catálogo i18n de UI. `es` por defecto.
        DB::statement("
            INSERT INTO languages (code, name, is_default) VALUES
                ('es', 'Español', TRUE),
                ('va', 'Valencià', FALSE),
                ('en', 'English', FALSE)
            ON CONFLICT (code) DO NOTHING
        ");
    }

    private function setupFdw(): void
    {
        if (! PostgresFdwMigration::ensurePostgresFdwExtension('languages catalog')) {
            return;
        }

        $schema = (string) config('database.fdw.languages.schema', 'public');
        $source = (string) config('database.fdw.languages.table',  'res_lang');

        PostgresFdwMigration::dropForeignTableIfExists(self::FDW_TABLE);
        DB::statement('
            CREATE FOREIGN TABLE IF NOT EXISTS '.self::FDW_TABLE.' (
                id INTEGER, name VARCHAR(255), code VARCHAR(35), iso_code VARCHAR(35), active BOOLEAN
            )
            SERVER '.self::FDW_SERVER.'
            OPTIONS (schema_name \''.$schema.'\', table_name \''.$source.'\')
        ');

        DB::statement('DROP VIEW IF EXISTS '.self::VIEW_NAME);
        DB::statement("
            CREATE VIEW ".self::VIEW_NAME." AS
            SELECT DISTINCT ON (m.code)
                m.code,
                m.name,
                (m.code = 'es') AS is_default
            FROM (
                SELECT
                    CASE
                        WHEN code LIKE 'es%' THEN 'es'
                        WHEN code LIKE 'ca%' THEN 'va'
                        WHEN code LIKE 'en%' THEN 'en'
                        ELSE lower(substring(code FROM 1 FOR 2))
                    END AS code,
                    name
                FROM ".self::FDW_TABLE."
                WHERE active = TRUE
            ) m
            ORDER BY m.code, m.name
        ");

        $appUser = config('database.connections.pgsql.username');
        if (is_string($appUser) && $appUser !== '') {
            try {
                DB::statement('REVOKE INSERT, UPDATE, DELETE ON '.self::FDW_TABLE.' FROM "'.$appUser.'"');
                DB::statement('GRANT SELECT ON '.self::FDW_TABLE.' TO "'.$appUser.'"');
            } catch (\Throwable $e) {
                logger()->warning('FDW languages grants: '.$e->getMessage());
            }
        }
    }
};
