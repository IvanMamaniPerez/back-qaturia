<?php

namespace VentureDrake\LaravelCrm\Relations;

use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class BusinessBelongsToMany extends BelongsToMany
{
    use GuardsBusinessPivot;
}
