<?php

namespace VentureDrake\LaravelCrm\Http\Requests\Api\V2;

use Illuminate\Foundation\Http\FormRequest;
use VentureDrake\LaravelCrm\Http\Rules\Api\V2\OwnerInCurrentTeam;
use VentureDrake\LaravelCrm\Http\Rules\Api\V2\ScopedExists;

class StoreOrganizationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'vat_number' => ['nullable', 'string', 'max:255'],
            'linkedin' => ['nullable', 'string', 'max:255'],
            'number_of_employees' => ['nullable', 'integer', 'min:0'],
            'annual_revenue' => ['nullable', 'numeric', 'min:0'],
            'total_money_raised' => ['nullable', 'numeric', 'min:0'],
            'organization_type_id' => ['bail', 'nullable', 'uuid', ScopedExists::for(config('laravel-crm.db_table_prefix').'organization_types', 'id')],
            'industry_id' => ['bail', 'nullable', 'uuid', ScopedExists::for(config('laravel-crm.db_table_prefix').'industries', 'id')],
            'timezone_id' => ['bail', 'nullable', 'uuid', ScopedExists::for(config('laravel-crm.db_table_prefix').'timezones', 'id')],
            'user_owner_id' => ['bail', 'nullable', 'uuid', 'exists:users,id', new OwnerInCurrentTeam],
        ];
    }
}
