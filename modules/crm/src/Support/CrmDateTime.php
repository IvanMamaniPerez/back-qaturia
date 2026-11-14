<?php

namespace VentureDrake\LaravelCrm\Support;

use DateTimeInterface;
use DateTimeZone;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class CrmDateTime
{
    public static function timezone(): string
    {
        $zone = app('laravel-crm.settings')->get('timezone') ?: config('laravel-crm.timezone', 'UTC');

        if (! is_string($zone) || ! in_array($zone, timezone_identifiers_list(DateTimeZone::ALL_WITH_BC), true)) {
            throw new InvalidArgumentException('The CRM timezone must be a valid IANA timezone.');
        }

        return $zone;
    }

    public static function utc(DateTimeInterface|string|int|null $value): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof DateTimeInterface) {
            return Carbon::instance($value)->copy()->utc();
        }

        return is_int($value)
            ? Carbon::createFromTimestampUTC($value)
            : Carbon::parse($value, 'UTC')->utc();
    }

    public static function local(DateTimeInterface|string|int|null $value): ?Carbon
    {
        return self::utc($value)?->setTimezone(self::timezone());
    }

    public static function now(): Carbon
    {
        return Carbon::now(self::timezone());
    }

    public static function today(): Carbon
    {
        return self::now()->startOfDay();
    }

    public static function civil(DateTimeInterface|string $value): Carbon
    {
        $day = $value instanceof DateTimeInterface ? $value->format('Y-m-d') : substr($value, 0, 10);

        return Carbon::parse($day, self::timezone())->startOfDay();
    }

    public static function bucketKey(DateTimeInterface $value, DateTimeInterface $start, string $bucket): ?int
    {
        $when = self::local($value);
        $origin = self::local($start);

        if ($when->lt($origin)) {
            return null;
        }

        if ($bucket === 'month') {
            return $origin->addMonths(($when->year - $origin->year) * 12 + $when->month - $origin->month)->getTimestamp();
        }

        if ($bucket === 'hour') {
            return $origin->getTimestamp() + (int) floor(($when->getTimestamp() - $origin->getTimestamp()) / 3600) * 3600;
        }

        $days = (int) $origin->copy()->startOfDay()->diffInDays($when->copy()->startOfDay());
        $days = $bucket === 'week' ? (int) floor($days / 7) * 7 : $days;

        return $origin->addDays($days)->getTimestamp();
    }

    /**
     * Interpret offset-free form input in the CRM zone. Explicit offsets and
     * DateTime objects already identify an instant and must not be shifted twice.
     */
    public static function fromLocal(DateTimeInterface|string|null $value, string $attribute = 'datetime', ?string $timezone = null): ?Carbon
    {
        if ($value === null || $value === '') {
            return null;
        }

        if ($value instanceof DateTimeInterface) {
            return self::utc($value);
        }

        $zone = $timezone ?? self::timezone();

        try {
            if (preg_match('/^(\d{4}-\d{2}-\d{2}(?:[T ]\d{2}:\d{2}(?::\d{2}(?:\.\d{1,6})?)?)?)(Z|[+-]\d{2}:\d{2})?$/i', $value, $parts)) {
                $wall = Carbon::parse($parts[1], 'UTC');
                $expected = str_replace('T', ' ', explode('.', $parts[1])[0]);
                $format = strlen($parts[1]) === 10 ? 'Y-m-d' : (strlen($parts[1]) === 16 ? 'Y-m-d H:i' : 'Y-m-d H:i:s');

                if ($wall->format($format) !== $expected) {
                    throw new InvalidArgumentException('Invalid calendar date.');
                }

                if (! empty($parts[2])) {
                    return self::utc($value);
                }
            } else {
                $format = app('laravel-crm.settings')->get('date_format', 'Y-m-d').' H:i';
                $wall = Carbon::createFromFormat('!'.$format, $value, 'UTC');

                if (! $wall || $wall->format($format) !== $value) {
                    throw new InvalidArgumentException('Invalid local date format.');
                }
            }

            $transitions = (new DateTimeZone($zone))->getTransitions($wall->getTimestamp() - 172800, $wall->getTimestamp() + 172800);
            $offsets = array_unique(array_column($transitions ?: [], 'offset'));
            $matches = [];

            foreach ($offsets as $offset) {
                $candidate = $wall->copy()->subSeconds($offset);

                if ($candidate->copy()->setTimezone($zone)->format('Y-m-d H:i:s.u') === $wall->format('Y-m-d H:i:s.u')) {
                    $matches[] = $candidate;
                }
            }

            if (count($matches) !== 1) {
                throw new InvalidArgumentException('The local time is missing or ambiguous because of a daylight-saving transition.');
            }

            return $matches[0];
        } catch (\Exception) {
            throw ValidationException::withMessages([
                $attribute => 'Enter a valid local date and time. For an ambiguous daylight-saving time, include an explicit UTC offset.',
            ]);
        }
    }

    public static function futureLocal(string $value, string $attribute = 'scheduled_at', ?string $timezone = null): Carbon
    {
        $date = self::fromLocal($value, $attribute, $timezone);

        if (! $date || $date->lte(Carbon::now('UTC'))) {
            throw ValidationException::withMessages([$attribute => 'The scheduled time must be in the future in the CRM timezone.']);
        }

        return $date;
    }

    /**
     * Preserve UTC and subsecond boundaries when binding regional calendar
     * ranges to SQL; Laravel's default DateTime binding drops microseconds.
     *
     * @return array{string, string}
     */
    public static function databaseRange(DateTimeInterface $start, DateTimeInterface $end): array
    {
        return [self::utc($start)->format('Y-m-d H:i:s.u'), self::utc($end)->format('Y-m-d H:i:s.u')];
    }
}
