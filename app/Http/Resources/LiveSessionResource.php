<?php

namespace App\Http\Resources;

use App\Models\LiveSession;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin LiveSession */
class LiveSessionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id, 'clientSessionId' => $this->client_session_id, 'platform' => $this->platform,
            'sourceRoomId' => $this->source_room_id, 'creator' => $this->creator, 'status' => $this->status, 'title' => $this->title,
            'firstObservedAt' => $this->first_observed_at?->toISOString(), 'lastObservedAt' => $this->last_observed_at?->toISOString(),
            'createdAt' => $this->created_at?->toISOString(),
            'audienceConcurrent' => isset($this->projection['audience']['concurrent']) ? (int) $this->projection['audience']['concurrent'] : null,
            'audienceCumulative' => isset($this->projection['audience']['cumulative']) ? (int) $this->projection['audience']['cumulative'] : null,
            'audiencePopularity' => isset($this->projection['audience']['popularity']) ? (int) $this->projection['audience']['popularity'] : null,
            'audienceObservedAt' => isset($this->projection['audience']['observedAt']) ? (string) $this->projection['audience']['observedAt'] : null,
            'audienceQuality' => isset($this->projection['audience']['quality']) ? (string) $this->projection['audience']['quality'] : null,
            'likesTotal' => isset($this->projection['likesTotal']) ? (int) $this->projection['likesTotal'] : null,
            'likesIncrement' => isset($this->projection['likes']['increment']) ? (int) $this->projection['likes']['increment'] : null,
            'likesObservedAt' => isset($this->projection['likes']['observedAt']) ? (string) $this->projection['likes']['observedAt'] : null,
            'likesQuality' => isset($this->projection['likes']['quality']) ? (string) $this->projection['likes']['quality'] : null,
            'followCount' => (int) ($this->projection['followCount'] ?? 0),
            'shareCount' => (int) ($this->projection['shareCount'] ?? 0),
            'unknownSocialCount' => (int) ($this->projection['unknownCount'] ?? 0),
            'coverageQuality' => 'observed_only',
        ];
    }
}
