<?php

namespace VentureDrake\LaravelCrm\Support;

use App\Models\Business;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use VentureDrake\LaravelCrm\Models\Team;

class BusinessIntegrity
{
    private array $columns = [];

    private array $foreignKeys = [];

    public function hasTeam(string $table): bool
    {
        return in_array('team_id', $this->columns[$table] ??= Schema::getColumnListing($table), true);
    }

    public function validate(Model $model, bool $deleting = false): void
    {
        $context = app(BusinessContext::class);
        if (! config('laravel-crm.teams') || $context->isSystem() || ! $this->hasTeam($model->getTable())) {
            return;
        }
        $id = $context->id();
        if (! $id) {
            throw ValidationException::withMessages(['business' => 'A business context is required for CRM writes.']);
        }
        if (! $model->exists && ! $model->team_id) {
            $model->team_id = $id;
        }
        if ((string) $model->team_id !== $id || ($model->exists && $model->isDirty('team_id'))) {
            throw ValidationException::withMessages(['team_id' => 'The record belongs to another business or is a shared read-only catalog.']);
        }
        if ($deleting) {
            return;
        }
        $foreignKeys = $this->foreignKeys[$model->getTable()] ??= Schema::getForeignKeys($model->getTable());
        // Some legacy migrations indexed relationships without declaring FKs.
        // Validate those too; their Eloquent relations carry the same tenancy.
        $references = [
            'person_id' => 'people', 'organization_id' => 'organizations',
            'client_id' => 'customers', 'customer_id' => 'customers',
            'lead_id' => 'leads', 'deal_id' => 'deals', 'quote_id' => 'quotes',
            'order_id' => 'orders', 'invoice_id' => 'invoices',
            'purchase_order_id' => 'purchase_orders',
            'product_id' => 'products', 'product_variation_id' => 'product_variations',
            'deal_product_id' => 'deal_products', 'quote_product_id' => 'quote_products',
            'order_product_id' => 'order_products',
            'email_campaign_id' => 'email_campaigns', 'sms_campaign_id' => 'sms_campaigns',
            'email_id' => 'emails', 'phone_id' => 'phones',
            'chat_widget_id' => 'chat_widgets', 'chat_visitor_id' => 'chat_visitors',
            'chat_conversation_id' => 'chat_conversations', 'monitor_id' => 'monitors',
            'feature_id' => 'features', 'feature_status_id' => 'feature_statuses',
            'pipeline_id' => 'pipelines', 'pipeline_stage_id' => 'pipeline_stages',
            'field_id' => 'fields', 'field_group_id' => 'field_groups',
        ];
        $declared = array_merge([], ...array_column($foreignKeys, 'columns'));
        foreach ($references as $column => $table) {
            $table = config('laravel-crm.db_table_prefix').$table;
            if (! in_array($column, $declared, true) && $table !== $model->getTable() && $model->getAttribute($column) !== null) {
                $foreignKeys[] = ['columns' => [$column], 'foreign_table' => $table, 'foreign_columns' => ['id']];
            }
        }
        foreach ($foreignKeys as $foreign) {
            if (count($foreign['columns']) !== 1 || $foreign['foreign_table'] === 'businesses') {
                continue;
            }
            $column = $foreign['columns'][0];
            $value = $model->getAttribute($column);
            if ($value === null || ! $this->hasTeam($foreign['foreign_table'])) {
                continue;
            }
            $businessUserColumns = ['user_owner_id', 'user_assigned_id', 'invited_by'];
            if ($model instanceof Team) {
                $businessUserColumns[] = 'user_id';
            }
            if ($foreign['foreign_table'] === 'users' && ! in_array($column, $businessUserColumns, true)) {
                continue;
            }
            $query = DB::table($foreign['foreign_table'])->where($foreign['foreign_columns'][0], $value);
            $this->constrain($query, $foreign['foreign_table'], $id);
            if (! $query->exists()) {
                throw ValidationException::withMessages([$column => 'The selected record does not belong to this business.']);
            }
        }
        foreach ($model->getAttributes() as $column => $type) {
            if (! str_ends_with($column, '_type') || ! is_string($type) || ! is_subclass_of($type, Model::class)) {
                continue;
            }
            $key = substr($column, 0, -5).'_id';
            $value = $model->getAttribute($key);
            if (! $value) {
                continue;
            }
            $related = new $type;
            if ($related instanceof Business) {
                $valid = (string) $value === $id;
            } elseif ($this->hasTeam($related->getTable())) {
                $query = DB::table($related->getTable())->where($related->getKeyName(), $value);
                $this->constrain($query, $related->getTable(), $id);
                $valid = $query->exists();
            } else {
                $valid = true;
            }
            if (! $valid) {
                throw ValidationException::withMessages([$key => 'The related entity belongs to another business.']);
            }
        }
    }

    private function constrain($query, string $table, string $id): void
    {
        if ($table === config('permission.table_names.roles')) {
            $query->whereNull('team_id');

            return;
        }
        $query->where(function ($query) use ($table, $id) {
            $query->where('team_id', $id);
            if (BusinessContext::shared($table)) {
                $query->orWhereNull('team_id');
            }
        });
    }
}
