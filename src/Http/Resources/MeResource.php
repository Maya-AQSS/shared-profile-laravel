<?php

namespace Maya\Profile\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Maya\Profile\Dtos\UserProfileDto;

/**
 * @property-read UserProfileDto $resource
 */
final class MeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return $this->resource->toArray();
    }
}
