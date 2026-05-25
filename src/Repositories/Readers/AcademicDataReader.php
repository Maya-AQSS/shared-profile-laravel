<?php

declare(strict_types=1);

namespace Maya\Profile\Repositories\Readers;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Maya\Profile\Repositories\Contracts\AcademicDataReaderInterface;
use Throwable;

/**
 * Reader compartido del ámbito académico/equipos del usuario.
 *
 * Consume las FDW locales que cada app proyecta vía `Migrations::academicAssignments()`
 * y `Migrations::teams()`:
 *
 *  - `user_study_types` → `study_type_ids`
 *  - `user_studies`     → `study_ids`
 *  - `user_course_modules` → `module_ids`
 *  - `team_members` → `team_ids`
 *
 * `read()` devuelve la forma minimal cross-app (solo IDs). Las apps que
 * necesiten los objetos completos de team (id+name+role+description+
 * is_department) llaman a `loadTeamsWithDetails()` por separado — esto se
 * limita a `maya_dms`. El resto de apps NO incluyen `teams[]` en `/me`.
 *
 * Lo usan tanto `FdwAcademicResolver` (apps periféricas: audit/logs/dashboard)
 * como resolvers app-específicos que NO leen permisos de FDW sino de su propio
 * service local (caso `maya_authorization`, `maya_dms`).
 *
 * Degradación: cada bloque tiene su propio try/catch. Si una vista FDW no está
 * disponible (test sin tabla, conexión rota, etc.), el campo se devuelve como
 * array vacío — nunca null, nunca lanza.
 *
 * Cache: TTL 5 min, key `me_academic:{userId}`. Independiente del cache de
 * permisos para permitir invalidaciones disjuntas.
 */
final class AcademicDataReader implements AcademicDataReaderInterface
{
    private const CACHE_TTL = 300;
    private const CACHE_TTL_ERROR = 30;

    /**
     * @return array{
     *   study_type_ids: list<string>,
     *   study_ids: list<string>,
     *   module_ids: list<string>,
     *   team_ids: list<string>,
     * }
     */
    public function read(string $userId): array
    {
        if ($userId === '') {
            return $this->empty();
        }

        try {
            return Cache::remember(
                $this->cacheKey($userId),
                self::CACHE_TTL,
                fn (): array => [
                    'study_type_ids' => $this->pluckIds('user_study_types', 'study_type_id', $userId),
                    'study_ids'      => $this->pluckIds('user_studies', 'study_id', $userId),
                    'module_ids'     => $this->pluckIds('user_course_modules', 'module_id', $userId),
                    'team_ids'       => $this->pluckIds('team_members', 'team_id', $userId),
                ],
            );
        } catch (Throwable) {
            return $this->empty();
        }
    }

    /**
     * Devuelve los objetos completos de team (id+name+description+role+
     * is_department) del usuario. Pensado para apps que materializan equipos
     * en su /me (maya_dms). No forma parte de `read()` para mantener la
     * forma cross-app minimal.
     *
     * @return list<array{id:string,name:string,description:?string,role:string,is_department:bool}>
     */
    public function loadTeamsWithDetails(string $userId): array
    {
        if ($userId === '') {
            return [];
        }

        return $this->loadTeams($userId);
    }

    public function invalidate(string $userId): void
    {
        Cache::forget($this->cacheKey($userId));
        Cache::forget($this->detailedCacheKey($userId));
    }

    /**
     * Variante enriquecida del contexto académico: devuelve objetos completos
     * (id, code, name) en lugar de solo ids. Pensada para vistas de admin
     * (maya_authorization.UserManagementPage) y perfil (maya_dashboard.ProfilePage).
     *
     * Cada bloque tiene su propio try/catch — si una FDW catálogo no está
     * disponible, ese bloque devuelve `[]` y `_status[block] = 'unavailable'`.
     * Esto permite distinguir "usuario sin asignaciones" (`[]` + status `ok`)
     * de "FDW caída" (`[]` + status `unavailable`).
     *
     * Cache asimétrico: TTL completo si todo es `ok`, TTL corto si algún
     * bloque está `unavailable` (para que se reintente pronto al recuperar
     * la FDW).
     *
     * @return array{
     *   study_types: list<array{id:string, code:string, name:string}>,
     *   studies:     list<array{id:string, code:string, name:string, study_type_id:string}>,
     *   modules:     list<array{id:string, code:string, name:string, study_id:string}>,
     *   teams:       list<array{id:string, code:string, name:string, is_department:bool}>,
     *   _status:     array{study_types:string, studies:string, modules:string, teams:string},
     * }
     */
    public function readDetailed(string $userId): array
    {
        if ($userId === '') {
            return $this->emptyDetailed();
        }

        $cacheKey = $this->detailedCacheKey($userId);
        $cached = Cache::get($cacheKey);
        if (is_array($cached)) {
            return $cached;
        }

        $studies      = $this->loadStudies($userId);
        $modules      = $this->loadCourseModules($userId);
        $teams        = $this->loadTeamsDetailed($userId);
        $studyTypes   = $this->loadStudyTypes($userId);

        $allOk = $studies['status'] === 'ok'
            && $modules['status'] === 'ok'
            && $teams['status'] === 'ok'
            && $studyTypes['status'] === 'ok';

        $payload = [
            'study_types' => $studyTypes['rows'],
            'studies'     => $studies['rows'],
            'modules'     => $modules['rows'],
            'teams'       => $teams['rows'],
            '_status'     => [
                'study_types' => $studyTypes['status'],
                'studies'     => $studies['status'],
                'modules'     => $modules['status'],
                'teams'       => $teams['status'],
            ],
        ];

        Cache::put($cacheKey, $payload, $allOk ? self::CACHE_TTL : self::CACHE_TTL_ERROR);

        return $payload;
    }

    /**
     * @return array{rows: list<array{id:string, code:string, name:string, study_type_id:string}>, status: string}
     */
    private function loadStudies(string $userId): array
    {
        try {
            $rows = DB::table('user_studies as us')
                ->join('studies as s', DB::raw('s.id::text'), '=', DB::raw('us.study_id::text'))
                ->where('us.user_id', '=', $userId)
                ->select('s.id', 's.code', 's.name', 's.study_type_id')
                ->get()
                ->map(static fn ($row): array => [
                    'id'            => (string) $row->id,
                    'code'          => (string) ($row->code ?? ''),
                    'name'          => (string) ($row->name ?? ''),
                    'study_type_id' => (string) ($row->study_type_id ?? ''),
                ])
                ->values()
                ->all();

            return ['rows' => $rows, 'status' => 'ok'];
        } catch (QueryException) {
            // Solo QueryException → vista FDW missing / conexión rota.
            // Otros Throwables (lógica, type errors) burbujean como bugs reales.
            return ['rows' => [], 'status' => 'unavailable'];
        }
    }

    /**
     * @return array{rows: list<array{id:string, code:string, name:string, study_id:string}>, status: string}
     */
    private function loadCourseModules(string $userId): array
    {
        try {
            $rows = DB::table('user_course_modules as ucm')
                ->join('course_modules as cm', DB::raw('cm.id::text'), '=', DB::raw('ucm.module_id::text'))
                ->where('ucm.user_id', '=', $userId)
                ->select('cm.id', 'cm.code', 'cm.name', 'cm.study_id')
                ->get()
                ->map(static fn ($row): array => [
                    'id'       => (string) $row->id,
                    'code'     => (string) ($row->code ?? ''),
                    'name'     => (string) ($row->name ?? ''),
                    'study_id' => (string) ($row->study_id ?? ''),
                ])
                ->values()
                ->all();

            return ['rows' => $rows, 'status' => 'ok'];
        } catch (QueryException) {
            // Solo QueryException → vista FDW missing / conexión rota.
            // Otros Throwables (lógica, type errors) burbujean como bugs reales.
            return ['rows' => [], 'status' => 'unavailable'];
        }
    }

    /**
     * @return array{rows: list<array{id:string, code:string, name:string, is_department:bool}>, status: string}
     */
    private function loadTeamsDetailed(string $userId): array
    {
        try {
            $query = DB::table('team_members as tm');

            if (DB::connection()->getDriverName() === 'pgsql') {
                $query->join('teams as t', DB::raw('t.id::text'), '=', DB::raw('tm.team_id::text'));
            } else {
                $query->join('teams as t', 't.id', '=', 'tm.team_id');
            }

            $rows = $query
                ->where('tm.user_id', '=', $userId)
                ->whereNull('t.deleted_at')
                ->select('t.id', 't.code', 't.name', 't.is_department')
                ->get()
                ->map(static fn ($row): array => [
                    'id'            => (string) $row->id,
                    'code'          => (string) ($row->code ?? ''),
                    'name'          => (string) ($row->name ?? ''),
                    'is_department' => (bool) ($row->is_department ?? false),
                ])
                ->values()
                ->all();

            return ['rows' => $rows, 'status' => 'ok'];
        } catch (QueryException) {
            // Solo QueryException → vista FDW missing / conexión rota.
            // Otros Throwables (lógica, type errors) burbujean como bugs reales.
            return ['rows' => [], 'status' => 'unavailable'];
        }
    }

    /**
     * Catálogo de tipos de estudio asignados al usuario, resuelto vía JOIN con
     * la vista FDW `study_types` (proyectada desde `res_company`). El
     * `study_type_id` en `user_study_types` es `res_company.id`, mismo
     * identificador usado en `studies.study_type_id` (vía `maya_core_study.company_id`).
     *
     * @return array{rows: list<array{id:string, code:string, name:string}>, status: string}
     */
    private function loadStudyTypes(string $userId): array
    {
        try {
            $rows = DB::table('user_study_types as ust')
                ->join('study_types as st', DB::raw('st.id::text'), '=', DB::raw('ust.study_type_id::text'))
                ->where('ust.user_id', '=', $userId)
                ->distinct()
                ->select('st.id', 'st.code', 'st.name')
                ->get()
                ->map(static fn ($row): array => [
                    'id'   => (string) $row->id,
                    'code' => (string) ($row->code ?? ''),
                    'name' => (string) ($row->name ?? ''),
                ])
                ->values()
                ->all();

            return ['rows' => $rows, 'status' => 'ok'];
        } catch (QueryException) {
            // Solo QueryException → vista FDW missing / conexión rota.
            // Otros Throwables (lógica, type errors) burbujean como bugs reales.
            return ['rows' => [], 'status' => 'unavailable'];
        }
    }

    /**
     * @return array{study_types: list<array{id:string, code:string, name:string}>, studies: list<array{id:string, code:string, name:string, study_type_id:string}>, modules: list<array{id:string, code:string, name:string}>, teams: list<array{id:string, code:string, name:string, is_department:bool}>, _status: array{study_types:string, studies:string, modules:string, teams:string}}
     */
    private function emptyDetailed(): array
    {
        return [
            'study_types' => [],
            'studies'     => [],
            'modules'     => [],
            'teams'       => [],
            '_status'     => [
                'study_types' => 'ok',
                'studies'     => 'ok',
                'modules'     => 'ok',
                'teams'       => 'ok',
            ],
        ];
    }

    private function detailedCacheKey(string $userId): string
    {
        return "me_academic_detailed:{$userId}";
    }

    /**
     * @return list<string>
     */
    private function pluckIds(string $table, string $column, string $userId): array
    {
        try {
            return DB::table($table)
                ->where('user_id', '=', $userId)
                ->pluck($column)
                ->map(static fn (mixed $v): string => (string) $v)
                ->values()
                ->all();
        } catch (QueryException) {
            return [];
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @return list<array{id:string,name:string,description:?string,role:string,is_department:bool}>
     */
    private function loadTeams(string $userId): array
    {
        try {
            $query = DB::table('team_members');

            if (DB::connection()->getDriverName() === 'pgsql') {
                $query->join('teams', DB::raw('teams.id::text'), '=', DB::raw('team_members.team_id::text'));
            } else {
                $query->join('teams', 'teams.id', '=', 'team_members.team_id');
            }

            return $query
                ->where('team_members.user_id', '=', $userId)
                ->whereNull('teams.deleted_at')
                ->select([
                    'teams.id',
                    'teams.name',
                    'teams.description',
                    'team_members.role',
                    'teams.is_department',
                ])
                ->get()
                ->map(static fn ($row) => [
                    'id'            => (string) $row->id,
                    'name'          => (string) $row->name,
                    'description'   => $row->description !== null ? (string) $row->description : null,
                    'role'          => (string) $row->role,
                    'is_department' => (bool) ($row->is_department ?? false),
                ])
                ->values()
                ->all();
        } catch (QueryException) {
            return [];
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * @return array{
     *   study_type_ids: list<string>,
     *   study_ids: list<string>,
     *   module_ids: list<string>,
     *   team_ids: list<string>,
     * }
     */
    private function empty(): array
    {
        return [
            'study_type_ids' => [],
            'study_ids'      => [],
            'module_ids'     => [],
            'team_ids'       => [],
        ];
    }

    private function cacheKey(string $userId): string
    {
        return "me_academic:{$userId}";
    }
}
