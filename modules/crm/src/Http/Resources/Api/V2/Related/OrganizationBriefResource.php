<?php

namespace VentureDrake\LaravelCrm\Http\Resources\Api\V2\Related;

use Illuminate\Http\Resources\Json\JsonResource;
use VentureDrake\LaravelCrm\Models\Organization;

/** @mixin Organization */
class OrganizationBriefResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->external_id,
            'name' => $this->name,
        ];
    }
}
