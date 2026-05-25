<?php

declare(strict_types=1);

namespace Maya\Profile\Dtos;

/**
 * Estudio asignado a un usuario, con referencia al tipo de estudio padre.
 */
final class StudyDto
{
    public function __construct(
        public readonly string $id,
        public readonly string $code,
        public readonly string $name,
        public readonly string $studyTypeId,
    ) {}

    /**
     * @return array{id: string, code: string, name: string, study_type_id: string}
     */
    public function toArray(): array
    {
        return [
            'id'            => $this->id,
            'code'          => $this->code,
            'name'          => $this->name,
            'study_type_id' => $this->studyTypeId,
        ];
    }
}
