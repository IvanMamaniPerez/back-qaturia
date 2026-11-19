<?php

namespace App\Support\ApiContracts;

use Dedoc\Scramble\Support\Generator\Types\ObjectType;

/** Scramble 0.13's ObjectType only accepts schema-valued additionalProperties. */
class LiveObjectType extends ObjectType
{
    public function serializeAs31(): mixed
    {
        return [...parent::serializeAs31(), 'additionalProperties' => false, 'minProperties' => 1];
    }

    public function serializeAs32(): mixed
    {
        return [...parent::serializeAs32(), 'additionalProperties' => false, 'minProperties' => 1];
    }
}
