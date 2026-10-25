<?php

declare(strict_types=1);

namespace YouWillLikeIT\CareDispatch\Model;

use SilverStripe\Core\Validation\ValidationResult;
use SilverStripe\Forms\CheckboxSetField;
use SilverStripe\Forms\DropdownField;
use SilverStripe\Forms\FieldList;
use SilverStripe\ORM\DataObject;
use YouWillLikeIT\CareDispatch\Domain\Clock;
use YouWillLikeIT\CareDispatch\Domain\Weekdays;
use YouWillLikeIT\CareDispatch\Model\Concern\DispatchPermissions;
use YouWillLikeIT\CareDispatch\Security\CareDispatchPermissions;

/**
 * Pflegeplan-Position: which task a patient receives, on which weekdays, in which visit slot
 * and time window. The day generator groups all positions of a patient per slot into one visit.
 *
 * Example: "Medikamentengabe – Metformin 500 mg, Mo–So, Früh, 07:30–08:30".
 *
 * @property string      $Weekdays
 * @property string      $Slot
 * @property string      $WindowStart
 * @property string      $WindowEnd
 * @property string|null $ValidFrom
 * @property string|null $ValidUntil
 * @property int         $RequiredStaff
 * @property string      $Instruction
 * @property int         $PatientID
 * @property int         $CareTaskID
 * @method Patient Patient()
 * @method CareTask CareTask()
 */
class CarePlanItem extends DataObject
{
    use DispatchPermissions;

    public const DB_ENUM_SLOT = "Enum('Frueh,Mittag,Spaet,Nacht','Frueh')";

    public const SLOT_LABELS = [
        'Frueh' => 'Früh',
        'Mittag' => 'Mittag',
        'Spaet' => 'Spät',
        'Nacht' => 'Nacht',
    ];

    private static string $table_name = 'CareDispatch_CarePlanItem';

    private static string $singular_name = 'Pflegeplan-Position';

    private static string $plural_name = 'Pflegeplan';

    private static string $edit_permission = CareDispatchPermissions::MASTERDATA;

    private static array $db = [
        'Weekdays' => 'Varchar(60)',
        'Slot' => self::DB_ENUM_SLOT,
        'WindowStart' => 'Time',
        'WindowEnd' => 'Time',
        'ValidFrom' => 'Date',
        'ValidUntil' => 'Date',
        'RequiredStaff' => 'Int',
        // Concrete instruction, e.g. medication and dosage or wound care regimen.
        'Instruction' => 'Text',
    ];

    private static array $has_one = [
        'Patient' => Patient::class,
        'CareTask' => CareTask::class,
    ];

    private static array $defaults = [
        'RequiredStaff' => 1,
    ];

    private static array $indexes = [
        'PatientSlot' => ['type' => 'index', 'columns' => ['PatientID', 'Slot']],
    ];

    private static array $summary_fields = [
        'CareTask.Title' => 'Leistung',
        'SlotLabel' => 'Slot',
        'WindowLabel' => 'Zeitfenster',
        'WeekdaysLabel' => 'Tage',
        'Instruction' => 'Anweisung',
        'RequiredStaff' => 'Personen',
    ];

    private static array $field_labels = [
        'Weekdays' => 'Wochentage',
        'Slot' => 'Einsatz-Slot',
        'WindowStart' => 'Zeitfenster von',
        'WindowEnd' => 'Zeitfenster bis',
        'ValidFrom' => 'Gültig ab (Verordnung)',
        'ValidUntil' => 'Gültig bis (Verordnung)',
        'RequiredStaff' => 'Benötigte Personen',
        'Instruction' => 'Anweisung (z. B. Medikament und Dosierung)',
        'CareTask' => 'Leistung',
    ];

    public function populateDefaults(): static
    {
        parent::populateDefaults();
        $this->Weekdays = Weekdays::all();

        return $this;
    }

    public function setWeekdays(mixed $value): static
    {
        $this->setField('Weekdays', Weekdays::normalize($value));

        return $this;
    }

    public function getWeekdaysLabel(): string
    {
        return Weekdays::label($this->Weekdays);
    }

    public function getSlotLabel(): string
    {
        return self::SLOT_LABELS[$this->Slot] ?? (string) $this->Slot;
    }

    public function getWindowLabel(): string
    {
        return sprintf('%s–%s', Clock::short($this->WindowStart) ?? '?', Clock::short($this->WindowEnd) ?? '?');
    }

    public function appliesOn(string $date): bool
    {
        if ($this->ValidFrom && $date < $this->ValidFrom) {
            return false;
        }
        if ($this->ValidUntil && $date > $this->ValidUntil) {
            return false;
        }

        return Weekdays::contains($this->Weekdays, Clock::isoWeekday($date));
    }

    public function getAuditPatientID(): int
    {
        return (int) $this->PatientID;
    }

    public function getCMSFields(): FieldList
    {
        $fields = parent::getCMSFields();
        $fields->replaceField('Weekdays', CheckboxSetField::create('Weekdays', $this->fieldLabel('Weekdays'), Weekdays::options()));
        $fields->replaceField(
            'RequiredStaff',
            DropdownField::create('RequiredStaff', $this->fieldLabel('RequiredStaff'), [1 => '1 Person', 2 => '2 Personen (Doppeleinsatz)'])
        );

        return $fields;
    }

    public function validate(): ValidationResult
    {
        $result = parent::validate();
        if (!$this->PatientID) {
            $result->addFieldError('PatientID', 'Patient fehlt.');
        }
        if (!$this->CareTaskID) {
            $result->addFieldError('CareTaskID', 'Bitte eine Leistung wählen.');
        }
        if (Weekdays::parse($this->Weekdays) === []) {
            $result->addFieldError('Weekdays', 'Mindestens einen Wochentag wählen.');
        }
        if (!$this->WindowStart || !$this->WindowEnd) {
            $result->addError('Zeitfenster von/bis sind Pflichtfelder.');
        } elseif (Clock::timeToSeconds($this->WindowEnd) <= Clock::timeToSeconds($this->WindowStart)) {
            $result->addFieldError('WindowEnd', 'Zeitfenster-Ende muss nach dem Beginn liegen.');
        }
        if ($this->ValidFrom && $this->ValidUntil && $this->ValidUntil < $this->ValidFrom) {
            $result->addFieldError('ValidUntil', 'Gültig bis liegt vor Gültig ab.');
        }
        if (!in_array((int) $this->RequiredStaff, [1, 2], true)) {
            $result->addFieldError('RequiredStaff', 'Erlaubt sind 1 oder 2 Personen.');
        }

        return $result;
    }
}
