<?php

declare(strict_types=1);

namespace YouWillLikeIT\CareDispatch\Domain;

/**
 * Ranked qualification levels.
 *
 * A caregiver may perform a visit as primary when their rank >= the visit's required rank.
 * Azubi has rank 0 and can never be the primary caregiver of a visit (only Begleitung).
 */
enum QualificationLevel: string
{
    case Azubi = 'Azubi';
    case Hilfskraft = 'Hilfskraft';
    case PflegehelferLG1 = 'PflegehelferLG1';
    case Fachkraft = 'Fachkraft';

    /** Enum spec for caregiver qualification DB fields. */
    public const DB_ENUM_ALL = "Enum('Azubi,Hilfskraft,PflegehelferLG1,Fachkraft','Hilfskraft')";

    /** Enum spec for requirement DB fields (Azubi is never a requirement). */
    public const DB_ENUM_REQUIREMENT = "Enum('Hilfskraft,PflegehelferLG1,Fachkraft','Hilfskraft')";

    public function rank(): int
    {
        return match ($this) {
            self::Azubi => 0,
            self::Hilfskraft => 1,
            self::PflegehelferLG1 => 2,
            self::Fachkraft => 3,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Azubi => 'Auszubildende/r',
            self::Hilfskraft => 'Pflegehilfskraft',
            self::PflegehelferLG1 => 'Pflegehelfer/in (LG1)',
            self::Fachkraft => 'Pflegefachkraft',
        };
    }

    public function canActAsPrimary(): bool
    {
        return $this !== self::Azubi;
    }

    /** Whether a caregiver with this level may perform a visit requiring $required as primary. */
    public function satisfies(self $required): bool
    {
        return $this->canActAsPrimary() && $this->rank() >= $required->rank();
    }

    public static function fromDb(?string $value, self $default = self::Hilfskraft): self
    {
        if ($value === null || $value === '') {
            return $default;
        }

        return self::tryFrom($value) ?? $default;
    }

    /** Returns the higher of two levels. */
    public static function max(self $a, self $b): self
    {
        return $a->rank() >= $b->rank() ? $a : $b;
    }

    /** @return array<string, string> value => label, for dropdowns */
    public static function options(bool $includeAzubi = true): array
    {
        $out = [];
        foreach (self::cases() as $case) {
            if (!$includeAzubi && $case === self::Azubi) {
                continue;
            }
            $out[$case->value] = $case->label();
        }

        return $out;
    }
}
