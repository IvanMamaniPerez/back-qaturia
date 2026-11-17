<?php

namespace VentureDrake\LaravelCrm\Database\Seeders;

use Illuminate\Database\Seeder;
use VentureDrake\LaravelCrm\Models\Setting;

class LaravelCrmSettingsSeeder extends Seeder
{
    public function run(): void
    {
        $defaults = [
            'app_name' => config('app.name'),
            'app_env' => config('app.env'),
            'app_url' => config('app.url'),
            'version' => config('laravel-crm.version'),
            'team' => 'related',
            'currency' => config('laravel-crm.currency') ?? 'USD',
            'country' => config('laravel-crm.country') ?? 'United States',
            'language' => config('laravel-crm.language') ?? 'english',
            'timezone' => config('laravel-crm.timezone') ?? 'UTC',
            'date_format' => config('laravel-crm.date_format') ?? 'Y-m-d',
            'time_format' => config('laravel-crm.time_format') ?? 'g:i A',
            'tax_name' => config('laravel-crm.tax_name') ?? 'Tax',
            'tax_rate' => config('laravel-crm.tax_rate') ?? 0,
            'lead_prefix' => 'LD-',
            'deal_prefix' => 'DL-',
            'quote_prefix' => 'COT-',
            'order_prefix' => 'PED-',
            'invoice_prefix' => 'FAC-',
            'delivery_prefix' => 'ENT-',
            'purchase_order_prefix' => 'OC-',
            'dynamic_products' => '1',
            'show_related_activity' => '0',
        ];

        foreach ($defaults as $name => $value) {
            Setting::withoutGlobalScopes()->firstOrCreate(
                ['name' => $name, 'team_id' => null, 'user_id' => null],
                ['value' => (string) $value, 'global' => true]
            );
        }
    }
}
