<?php

declare(strict_types=1);

namespace YouWillLikeIT\CareDispatch\Model;

use SilverStripe\Core\Validation\ValidationResult;
use SilverStripe\ORM\DataObject;
use YouWillLikeIT\CareDispatch\Domain\Clock;
use YouWillLikeIT\CareDispatch\Model\Concern\DispatchPermissions;
use YouWillLikeIT\CareDispatch\Security\CareDispatchPermissions;

/**
 * Geplanter Dienst (Soll).
 *
 * EndTime <= StartTime means the shift ends on the following day (night shift).
 *
 * @property string $ShiftDate
 * @property string $StartTime
 * @property string $EndTime
 * @property string $ShiftType
 * @property string $Status
 * @property int    $CaregiverID
 * @method Caregiver Caregiver()
 */
class Shift extends DataObject
{
    use DispatchPermissions;

    public const STATUS_SCHEDULED = 'Scheduled';
    public const STATUS_CANCELLED = 'Cancelled';

    public const TYPE_LABELS = [
        'Frueh' => 'Frühdienst',
        'Spaet' => 'Spätdienst',
        'Nacht' => 'Nachtdienst',
        'Teildienst' => 'Teildienst',
    ];

    private static string $table_name = 'CareDispatch_Shift';

    private static string $singular_name = 'Dienst';

    private static string $plural_name = 'Dienste';

    private static string $edit_permission = CareDispatchPermissions::ROSTER;

    private static array $db = [
        'ShiftDate' => 'Date',
        'StartTime' => 'Time',
        'EndTime' => 'Time',
        'ShiftType' => "Enum('Frueh,Spaet,Nacht,Teildienst','Frueh')",
        'Status' => "Enum('Scheduled,Cancelled','Scheduled')",
    ];

    private static array $has_one = [
        'Caregiver' => Caregiver::class,
    ];

    private static array $indexes = [
        'ShiftDate' => true,
        'CaregiverDate' => ['type' => 'index', 'columns' => ['CaregiverID', 'ShiftDate']],
    ];

    private static array $summary_fields = [
        'ShiftDate.Nice' => 'Datum',
        'Caregiver.Title' => 'Pflegekraft',
        'ShiftTypeLabel' => 'Dienst',
        'StartTime.Short' => 'Beginn',
        'EndTime.Short' => 'Ende',
        'Status' => 'Status',
    ];

    private static array $field_labels = [
        'ShiftDate' => 'Datum',
        'StartTime' => 'Beginn',
        'EndTime' => 'Ende',
        'ShiftType' => 'Dienstart',
        'Caregiver' => 'Pflegekraft',
    ];

    private static string $default_sort = '"ShiftDate" DESC, "StartTime" ASC';

    public function getShiftTypeLabel(): string
    {
        return self::TYPE_LABELS[$this->ShiftType] ?? (string) $this->ShiftType;
    }

    public function isActive(): bool
    {
        return $this->Status === self::STATUS_SCHEDULED;
    }

    /** @return array{0:int,1:int} [start, end] in seconds relative to ShiftDate 00:00; end may exceed 86400. */
    public function window(): array
    {
        $start = Clock::timeToSeconds($this->StartTime);
        $end = Clock::timeToSeconds($this->EndTime);
        if ($end <= $start) {
            $end += 86400;
        }

        return [$start, $end];
    }

    /** True if the interval [from, to] (seconds of ShiftDate) lies inside this shift. */
    public function covers(int $from, int $to): bool
    {
        [$start, $end] = $this->window();

        return $from >= $start && $to <= $end;
    }

    public function validate(): ValidationResult
    {
        $result = parent::validate();
        if (!$this->CaregiverID) {
            $result->addFieldError('CaregiverID', 'Bitte eine Pflegekraft wählen.');
        }
        if (!$this->ShiftDate) {
            $result->addFieldError('ShiftDate', 'Bitte ein Datum angeben.');
        }
        if (!$this->StartTime || !$this->EndTime) {
            $result->addError('Beginn und Ende sind Pflichtfelder.');
        } elseif ($this->StartTime === $this->EndTime) {
            $result->addFieldError('EndTime', 'Ende darf nicht gleich Beginn sein.');
        } elseif ($this->ShiftType !== 'Nacht' && Clock::timeToSeconds($this->EndTime) < Clock::timeToSeconds($this->StartTime)) {
            $result->addFieldError('EndTime', 'Nur Nachtdienste dürfen über Mitternacht gehen.');
        }
        if ($result->isValid() && $this->isActive()) {
            $overlap = self::get()
                ->filter([
                    'CaregiverID' => $this->CaregiverID,
                    'ShiftDate' => $this->ShiftDate,
                    'Status' => self::STATUS_SCHEDULED,
                ])
                ->exclude('ID', $this->ID ?: 0);
            [$start, $end] = $this->window();
            foreach ($overlap as $other) {
                [$oStart, $oEnd] = $other->window();
                if ($start < $oEnd && $oStart < $end) {
                    $result->addError(sprintf(
                        'Überschneidung mit vorhandenem Dienst %s–%s.',
                        Clock::short($other->StartTime),
                        Clock::short($other->EndTime)
                    ));
                    break;
                }
            }
        }

        return $result;
    }
}
