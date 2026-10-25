<?php

declare(strict_types=1);

namespace YouWillLikeIT\CareDispatch\Model;

use SilverStripe\Core\Validation\ValidationResult;
use SilverStripe\Forms\DropdownField;
use SilverStripe\Forms\FieldList;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\HasManyList;
use YouWillLikeIT\CareDispatch\Domain\Clock;
use YouWillLikeIT\CareDispatch\Domain\QualificationLevel;
use YouWillLikeIT\CareDispatch\Model\Concern\DispatchPermissions;
use YouWillLikeIT\CareDispatch\Security\CareDispatchPermissions;

/**
 * Einsatz beim Patienten.
 *
 * Invariant: one visit = at most one tour = one caregiver. A second person on a double visit
 * is modelled as a separate companion visit (Role = Begleitung, PrimaryVisitID = main visit)
 * on their own tour. Companion visits carry no tasks and are not billed.
 *
 * @property string      $VisitDate
 * @property string      $Slot
 * @property string      $ScheduledStart
 * @property string      $ScheduledEnd
 * @property string|null $WindowStart
 * @property string|null $WindowEnd
 * @property string|null $ActualStart
 * @property string|null $ActualEnd
 * @property string      $Status
 * @property string      $Role
 * @property int         $RequiredStaff
 * @property string      $RequiredQualification  derived from tasks (+ override)
 * @property string      $QualificationOverride  PDL may only raise, never lower
 * @property int         $SortOrder
 * @property string      $Notes
 * @property int         $PatientID
 * @property int         $DailyTourID
 * @property int         $PrimaryVisitID
 * @method Patient Patient()
 * @method DailyTour DailyTour()
 * @method PatientVisit PrimaryVisit()
 * @method HasManyList<PatientVisit> CompanionVisits()
 * @method HasManyList<VisitTask> Tasks()
 */
class PatientVisit extends DataObject
{
    use DispatchPermissions;

    public const STATUS_PENDING = 'Pending';
    public const STATUS_IN_PROGRESS = 'InProgress';
    public const STATUS_COMPLETED = 'Completed';
    public const STATUS_CANCELLED = 'Cancelled';
    public const STATUS_UNASSIGNED = 'Unassigned';

    public const ROLE_PRIMARY = 'Primary';
    public const ROLE_COMPANION = 'Begleitung';

    /** Visits that can be moved between tours / the pool. */
    public const MOVABLE_STATUSES = [self::STATUS_PENDING, self::STATUS_UNASSIGNED];

    private static string $table_name = 'CareDispatch_PatientVisit';

    private static string $singular_name = 'Einsatz';

    private static string $plural_name = 'Einsätze';

    private static string $edit_permission = CareDispatchPermissions::DISPATCH;

    private static array $db = [
        'VisitDate' => 'Date',
        'Slot' => CarePlanItem::DB_ENUM_SLOT,
        'ScheduledStart' => 'Time',
        'ScheduledEnd' => 'Time',
        'WindowStart' => 'Time',
        'WindowEnd' => 'Time',
        'ActualStart' => 'Datetime',
        'ActualEnd' => 'Datetime',
        'Status' => "Enum('Pending,InProgress,Completed,Cancelled,Unassigned','Unassigned')",
        'Role' => "Enum('Primary,Begleitung','Primary')",
        'RequiredStaff' => 'Int',
        'RequiredQualification' => QualificationLevel::DB_ENUM_REQUIREMENT,
        // Empty or one of the requirement levels (validated); Varchar because Enum has no empty option.
        'QualificationOverride' => 'Varchar(20)',
        'SortOrder' => 'Int',
        'Notes' => 'Text',
    ];

    private static array $has_one = [
        'Patient' => Patient::class,
        'DailyTour' => DailyTour::class,
        'PrimaryVisit' => PatientVisit::class,
    ];

    private static array $has_many = [
        'CompanionVisits' => PatientVisit::class . '.PrimaryVisit',
        'Tasks' => VisitTask::class,
    ];

    private static array $cascade_deletes = ['Tasks'];

    private static array $defaults = [
        'Status' => self::STATUS_UNASSIGNED,
        'Role' => self::ROLE_PRIMARY,
        'RequiredStaff' => 1,
        'RequiredQualification' => 'Hilfskraft',
    ];

    private static array $indexes = [
        'VisitDate' => true,
        'DateStatus' => ['type' => 'index', 'columns' => ['VisitDate', 'Status']],
        'TourSort' => ['type' => 'index', 'columns' => ['DailyTourID', 'SortOrder']],
        'PatientDateSlot' => ['type' => 'index', 'columns' => ['PatientID', 'VisitDate', 'Slot']],
    ];

    private static array $summary_fields = [
        'VisitDate.Nice' => 'Datum',
        'ScheduledStart.Short' => 'Beginn',
        'Patient.Title' => 'Patient',
        'DailyTour.Title' => 'Tour',
        'Status' => 'Status',
        'Role' => 'Rolle',
        'RequiredQualification' => 'Qualifikation',
    ];

    private static array $field_labels = [
        'VisitDate' => 'Datum',
        'ScheduledStart' => 'Geplanter Beginn',
        'ScheduledEnd' => 'Geplantes Ende',
        'WindowStart' => 'Zeitfenster von',
        'WindowEnd' => 'Zeitfenster bis',
        'ActualStart' => 'Tatsächlicher Beginn',
        'ActualEnd' => 'Tatsächliches Ende',
        'RequiredStaff' => 'Benötigte Personen',
        'RequiredQualification' => 'Benötigte Qualifikation (berechnet)',
        'QualificationOverride' => 'Qualifikation anheben auf',
        'Notes' => 'Notizen',
    ];

    private static string $default_sort = '"VisitDate" DESC, "ScheduledStart" ASC';

    public function isCompanion(): bool
    {
        return $this->Role === self::ROLE_COMPANION;
    }

    public function isMovable(): bool
    {
        return in_array($this->Status, self::MOVABLE_STATUSES, true);
    }

    public function getRequiredLevel(): QualificationLevel
    {
        return QualificationLevel::fromDb($this->RequiredQualification);
    }

    public function getDurationSeconds(): int
    {
        return max(0, Clock::timeToSeconds($this->ScheduledEnd) - Clock::timeToSeconds($this->ScheduledStart));
    }

    public function getAuditPatientID(): int
    {
        return (int) $this->PatientID;
    }

    /**
     * Required level = highest MinQualification of the visit's tasks, raised by the override.
     * Companion visits always require only Hilfskraft-level presence (Azubi allowed).
     */
    public function computeRequiredQualification(): QualificationLevel
    {
        if ($this->isCompanion()) {
            return QualificationLevel::Hilfskraft;
        }
        $level = QualificationLevel::Hilfskraft;
        if ($this->isInDB()) {
            foreach ($this->Tasks() as $task) {
                $level = QualificationLevel::max($level, $task->CareTask()->getMinQualificationLevel());
            }
        }
        if ($this->QualificationOverride) {
            $level = QualificationLevel::max($level, QualificationLevel::fromDb($this->QualificationOverride));
        }

        return $level;
    }

    /** Active companion visits (not cancelled). */
    public function activeCompanions(): HasManyList
    {
        return $this->CompanionVisits()->exclude('Status', self::STATUS_CANCELLED);
    }

    /** True if this primary visit needs a second person that is not yet on a tour. */
    public function isMissingCompanion(): bool
    {
        if ($this->isCompanion() || (int) $this->RequiredStaff < 2) {
            return false;
        }

        return !$this->activeCompanions()->filter('DailyTourID:GreaterThan', 0)->exists();
    }

    public function getCMSFields(): FieldList
    {
        $fields = parent::getCMSFields();
        $fields->replaceField(
            'QualificationOverride',
            DropdownField::create(
                'QualificationOverride',
                $this->fieldLabel('QualificationOverride'),
                QualificationLevel::options(false)
            )->setEmptyString('– keine Anhebung –')
        );
        $fields->makeFieldReadonly('RequiredQualification');

        return $fields;
    }

    public function validate(): ValidationResult
    {
        $result = parent::validate();
        if (!$this->PatientID) {
            $result->addFieldError('PatientID', 'Patient fehlt.');
        }
        if (!$this->VisitDate) {
            $result->addFieldError('VisitDate', 'Datum fehlt.');
        }
        if (!$this->ScheduledStart || !$this->ScheduledEnd) {
            $result->addError('Geplanter Beginn und Ende sind Pflichtfelder.');
        } elseif (Clock::timeToSeconds($this->ScheduledEnd) <= Clock::timeToSeconds($this->ScheduledStart)) {
            $result->addFieldError('ScheduledEnd', 'Geplantes Ende muss nach dem Beginn liegen.');
        }
        if ($this->ActualStart && $this->ActualEnd && strtotime($this->ActualEnd) < strtotime($this->ActualStart)) {
            $result->addFieldError('ActualEnd', 'Tatsächliches Ende liegt vor dem Beginn.');
        }
        if ($this->Status === self::STATUS_UNASSIGNED && $this->DailyTourID) {
            $result->addError('Ein nicht zugewiesener Einsatz darf keiner Tour angehören.');
        }
        if (in_array($this->Status, [self::STATUS_PENDING, self::STATUS_IN_PROGRESS], true) && !$this->DailyTourID) {
            $result->addError('Geplante oder laufende Einsätze müssen einer Tour angehören.');
        }
        if ($this->isCompanion() && !$this->PrimaryVisitID) {
            $result->addError('Begleit-Einsatz ohne Haupteinsatz.');
        }
        if (!in_array((int) $this->RequiredStaff, [1, 2], true)) {
            $result->addFieldError('RequiredStaff', 'Erlaubt sind 1 oder 2 Personen.');
        }
        if ($this->QualificationOverride && !array_key_exists($this->QualificationOverride, QualificationLevel::options(false))) {
            $result->addFieldError('QualificationOverride', 'Ungültige Qualifikationsstufe.');
        }

        return $result;
    }

    protected function onBeforeWrite(): void
    {
        parent::onBeforeWrite();
        $this->RequiredQualification = $this->computeRequiredQualification()->value;
        if ($this->isCompanion()) {
            $this->RequiredStaff = 1;
        }
        if (!$this->DailyTourID) {
            $this->SortOrder = 0;
        }
    }
}
