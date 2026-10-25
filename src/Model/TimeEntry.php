<?php

declare(strict_types=1);

namespace YouWillLikeIT\CareDispatch\Model;

use DateTimeImmutable;
use SilverStripe\Core\Validation\ValidationResult;
use SilverStripe\ORM\DataObject;
use YouWillLikeIT\CareDispatch\Domain\Clock;
use YouWillLikeIT\CareDispatch\Model\Concern\DispatchPermissions;
use YouWillLikeIT\CareDispatch\Security\CareDispatchPermissions;

/**
 * Tatsächliche Anwesenheit (Ist): Kommen / Pause / Gehen.
 *
 * @property string      $CheckIn
 * @property string|null $CheckOut
 * @property string      $Status
 * @property string|null $BreakStartedAt
 * @property int         $BreakSeconds
 * @property bool        $AutoClosed
 * @property int         $CaregiverID
 * @method Caregiver Caregiver()
 */
class TimeEntry extends DataObject
{
    use DispatchPermissions;

    public const STATUS_ACTIVE = 'Active';
    public const STATUS_BREAK = 'Break';
    public const STATUS_COMPLETED = 'Completed';

    private static string $table_name = 'CareDispatch_TimeEntry';

    private static string $singular_name = 'Zeitbuchung';

    private static string $plural_name = 'Zeitbuchungen';

    private static string $edit_permission = CareDispatchPermissions::ROSTER;

    private static array $db = [
        'CheckIn' => 'Datetime',
        'CheckOut' => 'Datetime',
        'Status' => "Enum('Active,Break,Completed','Active')",
        'BreakStartedAt' => 'Datetime',
        'BreakSeconds' => 'Int',
        // Set by the stale-entry task so HR can review the booking.
        'AutoClosed' => 'Boolean',
    ];

    private static array $has_one = [
        'Caregiver' => Caregiver::class,
    ];

    private static array $indexes = [
        'Status' => true,
        'CaregiverCheckIn' => ['type' => 'index', 'columns' => ['CaregiverID', 'CheckIn']],
    ];

    private static array $summary_fields = [
        'Caregiver.Title' => 'Pflegekraft',
        'CheckIn.Nice' => 'Kommen',
        'CheckOut.Nice' => 'Gehen',
        'Status' => 'Status',
        'BreakMinutes' => 'Pause (Min.)',
        'AutoClosed.Nice' => 'Automatisch geschlossen',
    ];

    private static string $default_sort = '"CheckIn" DESC';

    public function isOpen(): bool
    {
        return $this->Status !== self::STATUS_COMPLETED;
    }

    public function getBreakMinutes(): int
    {
        $seconds = (int) $this->BreakSeconds;
        if ($this->Status === self::STATUS_BREAK && $this->BreakStartedAt) {
            $seconds += max(0, Clock::now()->getTimestamp() - (new DateTimeImmutable($this->BreakStartedAt))->getTimestamp());
        }

        return intdiv($seconds, 60);
    }

    /** The open entry of a caregiver, if any (there must never be more than one). */
    public static function openFor(int $caregiverID): ?self
    {
        return self::get()
            ->filter(['CaregiverID' => $caregiverID, 'Status:not' => self::STATUS_COMPLETED])
            ->sort('CheckIn', 'DESC')
            ->first();
    }

    public function validate(): ValidationResult
    {
        $result = parent::validate();
        if (!$this->CaregiverID) {
            $result->addFieldError('CaregiverID', 'Bitte eine Pflegekraft wählen.');
        }
        if (!$this->CheckIn) {
            $result->addFieldError('CheckIn', 'Kommen-Zeit fehlt.');
        }
        if ($this->CheckIn && $this->CheckOut && strtotime($this->CheckOut) < strtotime($this->CheckIn)) {
            $result->addFieldError('CheckOut', 'Gehen-Zeit liegt vor der Kommen-Zeit.');
        }
        if ($this->Status === self::STATUS_COMPLETED && !$this->CheckOut) {
            $result->addFieldError('CheckOut', 'Abgeschlossene Buchung benötigt eine Gehen-Zeit.');
        }
        if ($this->Status !== self::STATUS_COMPLETED && $this->CaregiverID) {
            $otherOpen = self::get()
                ->filter(['CaregiverID' => $this->CaregiverID, 'Status:not' => self::STATUS_COMPLETED])
                ->exclude('ID', $this->ID ?: 0)
                ->exists();
            if ($otherOpen) {
                $result->addError('Für diese Pflegekraft existiert bereits eine offene Zeitbuchung.');
            }
        }

        return $result;
    }
}
