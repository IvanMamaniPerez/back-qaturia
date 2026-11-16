<?php

namespace VentureDrake\LaravelCrm\Services;

use App\Models\Business;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use VentureDrake\LaravelCrm\Support\BusinessContext;

class BusinessRegistrationService
{
    public function register(array $data): User
    {
        return DB::transaction(function () use ($data) {
            $business = Business::create([
                'name' => $data['business_name'],
                'legal_name' => $data['legal_name'] ?? null,
                'tax_id' => $data['tax_id'] ?? null,
            ]);

            return app(BusinessContext::class)->run($business->id, function () use ($data, $business) {
                $user = User::forceCreate([
                    'name' => $data['name'], 'email' => $data['email'],
                    'password' => Hash::make($data['password']),
                    'team_id' => $business->id, 'crm_access' => true,
                ]);
                $role = Role::where('name', 'Owner')->where('guard_name', 'web')->whereNull('team_id')->firstOrFail();
                $user->assignRole($role);
                $business->forceFill(['owner_user_id' => $user->id])->save();

                return $user;
            });
        });
    }
}
