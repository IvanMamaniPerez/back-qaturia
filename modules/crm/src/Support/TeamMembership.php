<?php

namespace VentureDrake\LaravelCrm\Support;

class TeamMembership
{
    public static function inCurrentTeam($user): bool
    {
        if (! config('laravel-crm.teams')) {
            return true;
        }
        $id = app(BusinessContext::class)->id();

        return $id !== null && $user !== null && (string) $user->team_id === $id;
    }
}
