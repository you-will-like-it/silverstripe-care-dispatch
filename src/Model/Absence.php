<?php

declare(strict_types=1);

namespace YouWillLikeIT\CareDispatch\Model;

use SilverStripe\Core\Validation\ValidationResult;
use SilverStripe\ORM\DataObject;
use YouWillLikeIT\CareDispatch\Model\Concern\DispatchPermissions;
use YouWillLikeIT\CareDispatch\Security\CareDispatchPermissions;

/**
 * Abwesenheit (Urlaub, Krankmeldung, Fortbildung …), whole days inclusive.
 *
 * Absent caregivers cannot be assigned (hard rule), are not planned by the route optimiser
 * and the generator does not staff their template tours.
 *
 * @property string $Type
 * @property string $DateFrom
 * @property string $DateTo
 * @property string $Note
 * @property int    $CaregiverID
 * @method Caregiver Caregiver()
 */
class Absence extends DataObject
{
    use DispatchPermissions;

    public const TYPE_LABELS = [
        'Urlaub' => 'Urlaub',
        'Krank' => 'Krankmeldung',
        'Fortbildung' => 'Fortbildung',
        'Frei' => 'Frei / Ausgleich',
        'Sonstiges' => 'Sonstiges',
    ];

    private static string $table_name = 'CareDispatch_Absence';

    private static string $singular_name = 'Abwesenheit';

    private static string $plural_name = 'Abwesenheiten';

    private static string $edit_permission = CareDispatchPermissions::ROSTER;

    private static array $db = [
        'Type' => "Enum('Urlaub,Krank,Fortbildung,Frei,Sonstiges','Urlaub')",
        'DateFrom' => 'Date',
        'DateTo' => 'Date',
        'Note' => 'Varchar(255)',
    ];

    private static array $has_one = [
        'Caregiver' => Caregiver::class,
    ];

    private static array $indexes = [
        'CaregiverRange' => ['type' => 'index', 'columns' => ['CaregiverID', 'DateFrom', 'DateTo']],
    ];

    private static array $summary_fields = [
        'Caregiver.Title' => 'Pflegekraft',
        'TypeLabel' => 'Art',
        'DateFrom.Nice' => 'Von',
        'DateTo.Nice' => 'Bis',
        'Note' => 'Notiz',
    ];

    private static string $default_sort = '"DateFrom" DESC';

    public function getTypeLabel(): string
    {
        return self::TYPE_LABELS[$this->Type] ?? (string) $this->Type;
    }

    public function covers(string $date): bool
    {
        return $date >= (string) $this->DateFrom && $date <= (string) $this->DateTo;
    }

    /** Absence of a caregiver on a day, if any. */
    public static function on(int $caregiverID, string $date): ?self
    {
        return self::get()->filter([
            'CaregiverID' => $caregiverID,
            'DateFrom:LessThanOrEqual' => $date,
            'DateTo:GreaterThanOrEqual' => $date,
        ])->first();
    }

    /** @return array<int, self> caregiverID => absence, for one day */
    public static function mapForDate(string $date): array
    {
        $out = [];
        foreach (self::get()->filter(['DateFrom:LessThanOrEqual' => $date, 'DateTo:GreaterThanOrEqual' => $date]) as $absence) {
            $out[(int) $absence->CaregiverID] = $absence;
        }

        return $out;
    }

    public function validate(): ValidationResult
    {
        $result = parent::validate();
        if (!$this->CaregiverID) {
            $result->addFieldError('CaregiverID', 'Bitte eine Pflegekraft wählen.');
        }
        if (!$this->DateFrom || !$this->DateTo) {
            $result->addError('Von und Bis sind Pflichtfelder.');
        } elseif ($this->DateTo < $this->DateFrom) {
            $result->addFieldError('DateTo', '„Bis“ liegt vor „Von“.');
        } elseif ($this->CaregiverID) {
            $overlap = self::get()
                ->filter([
                    'CaregiverID' => $this->CaregiverID,
                    'DateFrom:LessThanOrEqual' => $this->DateTo,
                    'DateTo:GreaterThanOrEqual' => $this->DateFrom,
                ])
                ->exclude('ID', $this->ID ?: 0)
                ->first();
            if ($overlap !== null) {
                $result->addError(sprintf(
                    'Überschneidung mit %s vom %s bis %s.',
                    $overlap->getTypeLabel(),
                    date('d.m.Y', strtotime((string) $overlap->DateFrom)),
                    date('d.m.Y', strtotime((string) $overlap->DateTo))
                ));
            }
        }

        return $result;
    }
}
