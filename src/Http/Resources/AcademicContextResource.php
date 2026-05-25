<?php

declare(strict_types=1);

namespace Maya\Profile\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Maya\Profile\Dtos\AcademicContextDto;

/**
 * @property-read AcademicContextDto $resource
 */
final class AcademicContextResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return $this->resource->toArray();
    }
}
