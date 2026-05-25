<?php

declare(strict_types=1);

namespace Maya\Profile\Dtos;

/**
 * Contexto académico completo de un usuario.
 *
 * Cada bloque incluye su `_status`: `'ok'` cuando la consulta funcionó,
 * `'unavailable'` cuando la FDW subyacente falló (lo que permite distinguir
 * "usuario sin asignaciones" de "datos temporalmente no disponibles").
 */
final class AcademicContextDto
{
    /**
     * @param list<AcademicItemDto> $studyTypes
     * @param list<StudyDto> $studies
     * @param list<CourseModuleDto> $modules
     * @param list<TeamDto> $teams
     * @param array{study_types: string, studies: string, modules: string, teams: string} $status
     */
    public function __construct(
        public readonly array $studyTypes,
        public readonly array $studies,
        public readonly array $modules,
        public readonly array $teams,
        public readonly array $status,
    ) {}

    /**
     * @return array{
     *   study_types: list<array{id: string, code: string, name: string}>,
     *   studies: list<array{id: string, code: string, name: string, study_type_id: string}>,
     *   modules: list<array{id: string, code: string, name: string, study_id: string}>,
     *   teams: list<array{id: string, code: string, name: string, is_department: bool}>,
     *   _status: array{study_types: string, studies: string, modules: string, teams: string},
     * }
     */
    public function toArray(): array
    {
        return [
            'study_types' => array_map(static fn (AcademicItemDto $i): array => $i->toArray(), $this->studyTypes),
            'studies'     => array_map(static fn (StudyDto $s): array => $s->toArray(), $this->studies),
            'modules'     => array_map(static fn (CourseModuleDto $m): array => $m->toArray(), $this->modules),
            'teams'       => array_map(static fn (TeamDto $t): array => $t->toArray(), $this->teams),
            '_status'     => $this->status,
        ];
    }
}
