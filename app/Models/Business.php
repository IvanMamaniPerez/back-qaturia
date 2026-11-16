<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use VentureDrake\LaravelCrm\Traits\HasCrmAddresses;
use VentureDrake\LaravelCrm\Traits\HasCrmEmails;
use VentureDrake\LaravelCrm\Traits\HasCrmPhones;

class Business extends Model
{
    use HasCrmAddresses, HasCrmEmails, HasCrmPhones, HasUuids;

    protected $guarded = ['id', 'owner_user_id'];

    public const SETTINGS = [
        'organization_name' => 'name',
        'legal_name' => 'legal_name',
        'vat_number' => 'tax_id',
        'fiscal_address' => 'fiscal_address',
        'business_email' => 'email',
        'business_phone' => 'phone',
        'website_url' => 'website_url',
        'logo_file' => 'logo_path',
        'logo_file_name' => 'logo_name',
    ];

    /** @return HasMany<User, $this> */
    public function users(): HasMany
    {
        return $this->hasMany(User::class, 'team_id');
    }

    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    /** @return Collection<int, User> */
    public function allUsers(): Collection
    {
        return $this->users()->get();
    }

    // Compatibility with the CRM's former host-team contract.
    public function getUserIdAttribute(): ?string
    {
        return $this->owner_user_id;
    }
}
