<?php

namespace VentureDrake\LaravelCrm\Support;

use Illuminate\Validation\ValidationException;

class CatalogIdentity
{
    public static function normalize(string $label): string
    {
        $label = \Normalizer::normalize($label, \Normalizer::FORM_C);
        $label = preg_replace('/\s+/u', ' ', preg_replace('/^\s+|\s+$/u', '', $label));

        return mb_convert_case($label, MB_CASE_FOLD, 'UTF-8');
    }

    public static function signature(array $pairs): string
    {
        if (! array_is_list($pairs)) {
            ksort($pairs, SORT_STRING);
        }
        foreach ($pairs as &$value) {
            if (is_array($value)) {
                $value = json_decode(self::signature($value), true, 512, JSON_THROW_ON_ERROR);
            }
        }
        unset($value);

        return json_encode($pairs, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }

    public static function key(array $pairs): string
    {
        return hash('sha256', self::signature($pairs));
    }

    public static function proposedId(string $scope, string $label): string
    {
        $hex = hash('sha256', $scope.'|'.self::normalize($label));

        return substr($hex, 0, 8).'-'.substr($hex, 8, 4).'-5'.substr($hex, 13, 3).'-a'.substr($hex, 17, 3).'-'.substr($hex, 20, 12);
    }

    public static function reject(string $code): never
    {
        throw ValidationException::withMessages(['catalog' => $code]);
    }
}
