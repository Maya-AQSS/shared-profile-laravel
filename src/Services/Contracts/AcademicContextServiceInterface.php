<?php

declare(strict_types=1);

namespace Maya\Profile\Services\Contracts;

use Maya\Profile\Dtos\AcademicContextDto;

interface AcademicContextServiceInterface
{
    /**
     * Devuelve el contexto académico (study_types, studies, modules, teams)
     * de un usuario en forma enriquecida (id + code + name por entidad).
     *
     * Si una FDW catálogo no está disponible, el bloque afectado devuelve
     * lista vacía y `_status[block] = 'unavailable'`.
     */
    public function forUser(string $userId): AcademicContextDto;
}
