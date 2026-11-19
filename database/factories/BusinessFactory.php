<?php

namespace Database\Factories;

use App\Models\Business;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use VentureDrake\LaravelCrm\Support\BusinessContext;

/** @extends Factory<Business> */
class BusinessFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return ['name' => fake()->company(), 'owner_user_id' => User::factory()->state(['crm_access' => true])];
    }

    public function configure(): static
    {
        return $this->afterCreating(function (Business $business): void {
            app(BusinessContext::class)->system(fn () => $business->owner->forceFill(['team_id' => $business->id])->save());
        });
    }
}
