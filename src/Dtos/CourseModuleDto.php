<?php

declare(strict_types=1);

namespace Maya\Profile\Dtos;

/**
 * Módulo del catálogo académico (asignatura × estudio), con referencia al
 * estudio padre. El `id` viene en formato compuesto `{study_id}_{subject_id}`
 * proyectado desde Odoo (vista FDW `course_modules`), pero se expone
 * `study_id` como campo explícito para que el cliente no tenga que parsear.
 */
final class CourseModuleDto
{
    public function __construct(
        public readonly string $id,
        public readonly string $code,
        public readonly string $name,
        public readonly string $studyId,
    ) {}

    /**
     * @return array{id: string, code: string, name: string, study_id: string}
     */
    public function toArray(): array
    {
        return [
            'id'       => $this->id,
            'code'     => $this->code,
            'name'     => $this->name,
            'study_id' => $this->studyId,
        ];
    }
}
