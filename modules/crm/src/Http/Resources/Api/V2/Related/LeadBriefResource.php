<?php

namespace VentureDrake\LaravelCrm\Http\Resources\Api\V2\Related;

use Illuminate\Http\Resources\Json\JsonResource;
use VentureDrake\LaravelCrm\Models\Lead;

/** @mixin Lead */
class LeadBriefResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->external_id,
            'lead_id' => $this->lead_id,
            'title' => $this->title,
        ];
    }
}
