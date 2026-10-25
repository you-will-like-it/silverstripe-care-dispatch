<?php

declare(strict_types=1);

namespace YouWillLikeIT\CareDispatch\Domain;

use JsonSerializable;

/**
 * A rule violation found while validating an assignment.
 *
 * Hard violations block the action. Soft violations require an explicit PDL confirmation
 * (force + reason), which is written to the audit trail.
 */
final readonly class Violation implements JsonSerializable
{
    public const HARD = 'hard';
    public const SOFT = 'soft';

    // Hard
    public const QUALIFICATION_INSUFFICIENT = 'QUALIFICATION_INSUFFICIENT';
    public const AZUBI_AS_PRIMARY = 'AZUBI_AS_PRIMARY';
    public const CAREGIVER_EXCLUDED = 'CAREGIVER_EXCLUDED';
    public const CAREGIVER_INACTIVE = 'CAREGIVER_INACTIVE';
    public const VISIT_NOT_MOVABLE = 'VISIT_NOT_MOVABLE';
    public const TOUR_CLOSED = 'TOUR_CLOSED';
    public const DATE_MISMATCH = 'DATE_MISMATCH';
    public const COMPANION_SAME_CAREGIVER = 'COMPANION_SAME_CAREGIVER';

    // Soft
    public const TIME_OVERLAP = 'TIME_OVERLAP';
    public const OUTSIDE_SHIFT = 'OUTSIDE_SHIFT';
    public const NO_SHIFT = 'NO_SHIFT';
    public const NOT_CHECKED_IN = 'NOT_CHECKED_IN';
    public const OUTSIDE_TIME_WINDOW = 'OUTSIDE_TIME_WINDOW';
    public const NO_DRIVING_LICENSE = 'NO_DRIVING_LICENSE';

    public function __construct(
        public string $code,
        public string $message,
        public string $severity,
        public ?int $visitID = null,
    ) {
    }

    public static function hard(string $code, string $message, ?int $visitID = null): self
    {
        return new self($code, $message, self::HARD, $visitID);
    }

    public static function soft(string $code, string $message, ?int $visitID = null): self
    {
        return new self($code, $message, self::SOFT, $visitID);
    }

    public function isHard(): bool
    {
        return $this->severity === self::HARD;
    }

    /** @return array{code:string,message:string,severity:string,visitId:int|null} */
    public function jsonSerialize(): array
    {
        return [
            'code' => $this->code,
            'message' => $this->message,
            'severity' => $this->severity,
            'visitId' => $this->visitID,
        ];
    }
}
