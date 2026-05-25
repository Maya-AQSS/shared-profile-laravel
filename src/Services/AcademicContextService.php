<?php

declare(strict_types=1);

namespace Maya\Profile\Services;

use Maya\Profile\Dtos\AcademicContextDto;
use Maya\Profile\Dtos\AcademicItemDto;
use Maya\Profile\Dtos\CourseModuleDto;
use Maya\Profile\Dtos\StudyDto;
use Maya\Profile\Dtos\TeamDto;
use Maya\Profile\Repositories\Contracts\AcademicDataReaderInterface;
use Maya\Profile\Services\Contracts\AcademicContextServiceInterface;

final class AcademicContextService implements AcademicContextServiceInterface
{
    public function __construct(
        private readonly AcademicDataReaderInterface $reader,
    ) {}

    public function forUser(string $userId): AcademicContextDto
    {
        $payload = $this->reader->readDetailed($userId);

        return new AcademicContextDto(
            studyTypes: array_map(
                static fn (array $row): AcademicItemDto => new AcademicItemDto(
                    id: (string) $row['id'],
                    code: (string) $row['code'],
                    name: (string) $row['name'],
                ),
                $payload['study_types'],
            ),
            studies: array_map(
                static fn (array $row): StudyDto => new StudyDto(
                    id: (string) $row['id'],
                    code: (string) $row['code'],
                    name: (string) $row['name'],
                    studyTypeId: (string) $row['study_type_id'],
                ),
                $payload['studies'],
            ),
            modules: array_map(
                static fn (array $row): CourseModuleDto => new CourseModuleDto(
                    id: (string) $row['id'],
                    code: (string) $row['code'],
                    name: (string) $row['name'],
                    studyId: (string) ($row['study_id'] ?? ''),
                ),
                $payload['modules'],
            ),
            teams: array_map(
                static fn (array $row): TeamDto => new TeamDto(
                    id: (string) $row['id'],
                    code: (string) $row['code'],
                    name: (string) $row['name'],
                    isDepartment: (bool) $row['is_department'],
                ),
                $payload['teams'],
            ),
            status: $payload['_status'],
        );
    }
}
