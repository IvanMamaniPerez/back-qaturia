<?php

namespace VentureDrake\LaravelCrm\Console\Traits;

use App\Models\Business;
use VentureDrake\LaravelCrm\Support\BusinessContext;

trait RunsForBusinesses
{
    public function handle(): int
    {
        if (! config('laravel-crm.teams')) {
            return $this->handleBusiness() ?? self::SUCCESS;
        }
        foreach (Business::orderBy('id')->cursor() as $business) {
            $result = app(BusinessContext::class)->run($business->id, fn () => $this->handleBusiness());
            if ($result !== null && $result !== self::SUCCESS) {
                return $result;
            }
        }

        return self::SUCCESS;
    }
}
