<?php

declare(strict_types=1);

namespace YouWillLikeIT\CareDispatch\Domain;

/**
 * Weekday sets (ISO numbers, 1 = Monday ... 7 = Sunday).
 *
 * Storage format is a JSON array of strings (["1","3","5"]) because that is what
 * CheckboxSetField writes into a plain DB column. parse() also accepts CSV ("1,3,5")
 * so YAML fixtures and API payloads stay simple.
 */
final class Weekdays
{
    public const LABELS = [
        1 => 'Mo',
        2 => 'Di',
        3 => 'Mi',
        4 => 'Do',
        5 => 'Fr',
        6 => 'Sa',
        7 => 'So',
    ];

    /** @return int[] sorted, unique */
    public static function parse(mixed $value): array
    {
        if (is_string($value)) {
            $value = trim($value);
            if ($value === '') {
                return [];
            }
            $decoded = json_decode($value, true);
            $value = is_array($decoded) ? $decoded : explode(',', $value);
        }
        if (!is_array($value)) {
            return [];
        }
        $days = [];
        foreach ($value as $part) {
            $day = (int) trim((string) $part);
            if ($day >= 1 && $day <= 7) {
                $days[$day] = $day;
            }
        }
        ksort($days);

        return array_values($days);
    }

    /** Normalises any accepted input to the storage format. */
    public static function normalize(mixed $value): string
    {
        return json_encode(array_map('strval', self::parse($value)), JSON_THROW_ON_ERROR);
    }

    public static function all(): string
    {
        return self::normalize([1, 2, 3, 4, 5, 6, 7]);
    }

    public static function contains(mixed $value, int $isoWeekday): bool
    {
        return in_array($isoWeekday, self::parse($value), true);
    }

    public static function label(mixed $value): string
    {
        $days = self::parse($value);
        if ($days === []) {
            return '–';
        }
        if (count($days) === 7) {
            return 'täglich';
        }

        return implode(', ', array_map(static fn (int $d): string => self::LABELS[$d], $days));
    }

    /** @return array<string, string> for CheckboxSetField */
    public static function options(): array
    {
        $out = [];
        foreach (self::LABELS as $num => $label) {
            $out[(string) $num] = $label;
        }

        return $out;
    }
}
