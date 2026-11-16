<?php

namespace VentureDrake\LaravelCrm\Traits;

use VentureDrake\LaravelCrm\Scopes\BelongsToTeamsScope;

trait BelongsToTeams
{
    public static function bootBelongsToTeams(): void
    {
        static::addGlobalScope(new BelongsToTeamsScope);
    }
}
