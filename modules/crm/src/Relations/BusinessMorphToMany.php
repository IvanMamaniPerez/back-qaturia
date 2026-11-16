<?php

namespace VentureDrake\LaravelCrm\Relations;

use Illuminate\Database\Eloquent\Relations\MorphToMany;

class BusinessMorphToMany extends MorphToMany
{
    use GuardsBusinessPivot;
}
