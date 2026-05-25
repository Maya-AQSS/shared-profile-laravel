<?php

declare(strict_types=1);

namespace Maya\Profile\Dtos;

/**
 * Equipo/departamento al que pertenece un usuario.
 */
final class TeamDto
{
    public function __construct(
        public readonly string $id,
        public readonly string $code,
        public readonly string $name,
        public readonly bool $isDepartment,
    ) {}

    /**
     * @return array{id: string, code: string, name: string, is_department: bool}
     */
    public function toArray(): array
    {
        return [
            'id'            => $this->id,
            'code'          => $this->code,
            'name'          => $this->name,
            'is_department' => $this->isDepartment,
        ];
    }
}
