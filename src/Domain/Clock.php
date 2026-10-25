<?php

declare(strict_types=1);

namespace YouWillLikeIT\CareDispatch\Domain;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;
use SilverStripe\ORM\FieldType\DBDatetime;

/**
 * Time helpers bound to DBDatetime::now() so tests can mock the clock.
 */
final class Clock
{
    public const DB_DATETIME = 'Y-m-d H:i:s';
    public const DB_DATE = 'Y-m-d';
    public const DB_TIME = 'H:i:s';

    public static function now(): DateTimeImmutable
    {
        return (new DateTimeImmutable('@' . DBDatetime::now()->getTimestamp()))
            ->setTimezone(new DateTimeZone(date_default_timezone_get()));
    }

    public static function nowDb(): string
    {
        return self::now()->format(self::DB_DATETIME);
    }

    public static function today(): string
    {
        return self::now()->format(self::DB_DATE);
    }

    /** Validates and normalises a YYYY-MM-DD string. */
    public static function parseDate(?string $value): string
    {
        if ($value === null || $value === '') {
            return self::today();
        }
        $date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if ($date === false || $date->format(self::DB_DATE) !== $value) {
            throw new InvalidArgumentException(sprintf('Ungültiges Datum "%s", erwartet YYYY-MM-DD.', $value));
        }

        return $value;
    }

    /** Validates "HH:MM" or "HH:MM:SS", returns "HH:MM:SS". */
    public static function parseTime(string $value): string
    {
        if (!preg_match('/^([01]?\d|2[0-3]):([0-5]\d)(?::([0-5]\d))?$/', trim($value), $m)) {
            throw new InvalidArgumentException(sprintf('Ungültige Uhrzeit "%s", erwartet HH:MM.', $value));
        }

        return sprintf('%02d:%s:%s', (int) $m[1], $m[2], ($m[3] ?? '') !== '' ? $m[3] : '00');
    }

    /** "HH:MM[:SS]" -> seconds since midnight. */
    public static function timeToSeconds(?string $time): int
    {
        if ($time === null || $time === '') {
            return 0;
        }
        $parts = array_map('intval', explode(':', $time));

        return ($parts[0] ?? 0) * 3600 + ($parts[1] ?? 0) * 60 + ($parts[2] ?? 0);
    }

    /** Seconds since midnight -> "HH:MM:SS" (clamped to the same day). */
    public static function secondsToTime(int $seconds): string
    {
        $seconds = max(0, min(86399, $seconds));

        return sprintf('%02d:%02d:%02d', intdiv($seconds, 3600), intdiv($seconds % 3600, 60), $seconds % 60);
    }

    /** "HH:MM:SS" -> "HH:MM" for API output. */
    public static function short(?string $time): ?string
    {
        if ($time === null || $time === '') {
            return null;
        }

        return substr($time, 0, 5);
    }

    /** DB datetime string -> ISO 8601 for API output. */
    public static function iso(?string $datetime): ?string
    {
        if ($datetime === null || $datetime === '') {
            return null;
        }
        $dt = new DateTimeImmutable($datetime);

        return $dt->format(DATE_ATOM);
    }

    /** ISO weekday (1 = Monday ... 7 = Sunday) of a YYYY-MM-DD date. */
    public static function isoWeekday(string $date): int
    {
        return (int) (new DateTimeImmutable($date))->format('N');
    }

    public static function secondsOfDay(DateTimeImmutable $dt): int
    {
        return ((int) $dt->format('G')) * 3600 + ((int) $dt->format('i')) * 60 + (int) $dt->format('s');
    }
}
