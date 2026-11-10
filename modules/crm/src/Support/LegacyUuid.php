<?php

namespace VentureDrake\LaravelCrm\Support;

use Ramsey\Uuid\Uuid;

final class LegacyUuid
{
    public static function fromInteger(int $id): string
    {
        return Uuid::uuid5(Uuid::NAMESPACE_OID, 'laravel-crm:'.$id)->toString();
    }
}
