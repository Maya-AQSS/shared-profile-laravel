<?php

declare(strict_types=1);

namespace Maya\Profile\Dtos;

/**
 * Ítem básico del catálogo académico: id + code + name.
 *
 * Forma canónica usada por `study_types` y `course_modules`.
 */
final class AcademicItemDto
{
    public function __construct(
        public readonly string $id,
        public readonly string $code,
        public readonly string $name,
    ) {}

    /**
     * @return array{id: string, code: string, name: string}
     */
    public function toArray(): array
    {
        return [
            'id'   => $this->id,
            'code' => $this->code,
            'name' => $this->name,
        ];
    }
}
