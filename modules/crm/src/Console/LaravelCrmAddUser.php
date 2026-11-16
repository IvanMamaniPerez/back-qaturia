<?php

namespace VentureDrake\LaravelCrm\Console;

use App\Models\Business;
use App\Models\Role;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use VentureDrake\LaravelCrm\Support\BusinessContext;

class LaravelCrmAddUser extends Command
{
    protected $signature = 'laravelcrm:add-user {--business= : Existing business UUID} {--business-name= : Name for a new business}';

    protected $description = 'Create a business owner or add a collaborator to an existing business';

    public function handle(): int
    {
        $email = $this->ask("Enter the user's email address");
        $data = ['email' => $email];
        $user = User::withoutGlobalScopes()->where('email', $email)->first();
        if (! $user) {
            $data['name'] = trim($this->ask('First name').' '.$this->ask('Last name'));
            $data['password'] = $this->secret('Password');
        }
        $validation = Validator::make($data, [
            'email' => 'required|email|max:255',
            'name' => $user ? 'nullable' : 'required|string|max:255',
            'password' => $user ? 'nullable' : 'required|string|min:8',
        ]);
        if ($validation->fails()) {
            $this->error($validation->errors()->first());

            return self::FAILURE;
        }
        $business = null;
        $businessName = null;
        if (config('laravel-crm.teams')) {
            $id = $this->option('business') ?: $user?->team_id;
            if ($id) {
                $business = Business::find($id);
                if (! $business || ($user?->team_id && $user->team_id !== $business->id)) {
                    $this->error('Invalid business, or user already belongs to another business.');

                    return self::FAILURE;
                }
            } else {
                $businessName = $this->option('business-name') ?: $this->ask('Business name (creates a new business)');
                if (! is_string($businessName) || trim($businessName) === '' || strlen($businessName) > 255) {
                    $this->error('A business name is required.');

                    return self::FAILURE;
                }
            }
        }
        $roles = Role::where('crm_role', true)->where('guard_name', 'web')
            ->when(config('permission.teams'), fn ($q) => $q->whereNull('team_id'))->pluck('name')->all();
        if ($roles === []) {
            $this->error('Run php artisan laravelcrm:permissions first.');

            return self::FAILURE;
        }
        $selected = $businessName ? 'Owner' : $this->choice('Assign a CRM role', $roles, array_search('Employee', $roles) ?: 0);
        DB::transaction(function () use ($user, $business, $businessName, $data, $selected) {
            if ($businessName) {
                $business = Business::create(['name' => $businessName]);
            }
            app(BusinessContext::class)->run($business?->id, function () use ($user, $business, $businessName, $data, $selected) {
                $target = $user ?? User::forceCreate([
                    'name' => $data['name'], 'email' => $data['email'],
                    'password' => Hash::make($data['password']), 'team_id' => $business?->id,
                    'crm_access' => true,
                ]);
                $target->forceFill(['crm_access' => true, 'team_id' => $business?->id])->save();
                $role = Role::where('name', $selected)->where('guard_name', 'web')
                    ->when(config('permission.teams'), fn ($q) => $q->whereNull('team_id'))->firstOrFail();
                foreach ($target->roles()->where('crm_role', true)->whereKeyNot($role->id)->get() as $previous) {
                    $target->removeRole($previous);
                }
                $target->assignRole($role);
                if ($businessName) {
                    $business->forceFill(['owner_user_id' => $target->id])->save();
                }
            });
        });
        $this->info('User and business role saved successfully.');

        return self::SUCCESS;
    }
}
