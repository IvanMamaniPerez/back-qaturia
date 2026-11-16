<?php

namespace VentureDrake\LaravelCrm\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use VentureDrake\LaravelCrm\Models\Setting;
use VentureDrake\LaravelCrm\Services\SettingService;
use VentureDrake\LaravelCrm\Support\BusinessContext;

class BelongsToTeamsScope implements Scope
{
    public static function appliesToRequest(): bool
    {
        return true;
    }

    public static function signedDocumentRequest(): bool
    {
        return request()->routeIs('laravel-crm.portal.quotes.*', 'laravel-crm.portal.invoices.*', 'laravel-crm.portal.purchase-orders.*');
    }

    public function apply(Builder $builder, Model $model): void
    {
        $context = app(BusinessContext::class);
        if (! config('laravel-crm.teams') || $context->isSystem()) {
            return;
        }
        $column = $model->qualifyColumn('team_id');
        $id = $context->id();
        if ($model instanceof Setting) {
            $builder->where(function ($query) use ($id, $column) {
                if ($id) {
                    $query->where($column, $id)->orWhere(function ($global) use ($column) {
                        $global->whereNull($column)->whereNull('user_id')
                            ->whereIn('name', SettingService::GLOBAL_KEYS);
                    });
                } else {
                    $query->whereNull($column)->whereNull('user_id')
                        ->whereIn('name', SettingService::GLOBAL_KEYS);
                }
            });
            // Legacy readers still call Setting::where(...)->first().
            // Give a company's override precedence over shared defaults.
            $builder->orderByRaw('CASE WHEN '.$builder->getQuery()->getGrammar()->wrap($column).' IS NULL THEN 1 ELSE 0 END');
        } elseif (BusinessContext::shared($model->getTable())) {
            $builder->where(fn ($query) => $query->where($column, $id)->orWhereNull($column));
            $builder->orderByRaw('CASE WHEN '.$builder->getQuery()->getGrammar()->wrap($column).' IS NULL THEN 1 ELSE 0 END');
        } elseif ($id) {
            $builder->where($column, $id);
        } else {
            $builder->whereRaw('1 = 0');
        }
    }
}
