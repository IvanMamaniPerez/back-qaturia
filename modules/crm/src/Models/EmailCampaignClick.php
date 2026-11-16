<?php

namespace VentureDrake\LaravelCrm\Models;

use VentureDrake\LaravelCrm\Support\BusinessContext;

class EmailCampaignClick extends Model
{
    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::addGlobalScope('business', function ($query) {
            if (config('laravel-crm.teams') && ! app(BusinessContext::class)->isSystem()) {
                $query->whereHas('recipient');
            }
        });
    }

    protected $casts = [
        'clicked_at' => 'datetime',
    ];

    public function getTable()
    {
        return config('laravel-crm.db_table_prefix').'email_campaign_clicks';
    }

    public function recipient()
    {
        return $this->belongsTo(EmailCampaignRecipient::class, 'email_campaign_recipient_id');
    }
}
