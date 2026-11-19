<?php

namespace App\Services;

use App\Models\LiveSession;
use App\Models\User;

class LiveAccessService
{
    public function allows(User $user, string $ability): bool
    {
        return $user->crm_access && $user->team_id && $user->business
            && ((string) $user->business->owner_user_id === (string) $user->id
                || $user->checkPermissionTo($ability, 'web')
                || ($ability === 'live.read' && $user->checkPermissionTo('live.ingest', 'web')));
    }

    public function session(User $user, string $id): LiveSession
    {
        return LiveSession::where('business_id', $user->team_id)->findOrFail($id);
    }
}
