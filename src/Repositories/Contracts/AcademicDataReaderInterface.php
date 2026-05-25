<?php

declare(strict_types=1);

namespace Maya\Profile\Repositories\Contracts;

/**
 * Contrato del reader compartido del ámbito académico/equipos del usuario.
 *
 * Permite que `AcademicContextService` (y consumidores en apps) dependa de la
 * interfaz, no de la implementación concreta `AcademicDataReader` — habilita
 * mocks en tests y reemplazos de origen (FDW directo, cache layer, etc.).
 */
interface AcademicDataReaderInterface
{
    /**
     * Forma minimal cross-app: solo IDs de cada bloque.
     *
     * @return array{
     *   study_type_ids: list<string>,
     *   study_ids: list<string>,
     *   module_ids: list<string>,
     *   team_ids: list<string>,
     * }
     */
    public function read(string $userId): array;

    /**
     * Forma enriquecida: objetos completos (id, code, name) + status por bloque.
     *
     * @return array{
     *   study_types: list<array{id:string, code:string, name:string}>,
     *   studies:     list<array{id:string, code:string, name:string, study_type_id:string}>,
     *   modules:     list<array{id:string, code:string, name:string, study_id:string}>,
     *   teams:       list<array{id:string, code:string, name:string, is_department:bool}>,
     *   _status:     array{study_types:string, studies:string, modules:string, teams:string},
     * }
     */
    public function readDetailed(string $userId): array;

    /**
     * Devuelve los objetos completos de team del usuario (para apps que los
     * materializan, como maya_dms).
     *
     * @return list<array{id:string,name:string,description:?string,role:string,is_department:bool}>
     */
    public function loadTeamsWithDetails(string $userId): array;

    /**
     * Invalida los caches del reader para un usuario (read + readDetailed).
     */
    public function invalidate(string $userId): void;
}
