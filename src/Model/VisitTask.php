<?php

declare(strict_types=1);

namespace YouWillLikeIT\CareDispatch\Model;

use SilverStripe\Core\Validation\ValidationResult;
use SilverStripe\ORM\DataObject;
use SilverStripe\Security\Member;
use YouWillLikeIT\CareDispatch\Model\Concern\DispatchPermissions;
use YouWillLikeIT\CareDispatch\Security\CareDispatchPermissions;

/**
 * Leistung innerhalb eines Einsatzes (Checkliste auf dem Handy).
 *
 * @property int         $SortOrder
 * @property string      $Instruction   copied from the care plan at generation time
 * @property string      $Status
 * @property string|null $DoneAt
 * @property string      $Note
 * @property int         $VisitID
 * @property int         $CareTaskID
 * @property int         $CarePlanItemID
 * @property int         $DoneByID
 * @method PatientVisit Visit()
 * @method CareTask CareTask()
 * @method CarePlanItem CarePlanItem()
 * @method Member DoneBy()
 */
class VisitTask extends DataObject
{
    use DispatchPermissions;

    public const STATUS_OPEN = 'Open';
    public const STATUS_DONE = 'Done';
    public const STATUS_NOT_DONE = 'NotDone';

    private static string $table_name = 'CareDispatch_VisitTask';

    private static string $singular_name = 'Einsatz-Leistung';

    private static string $plural_name = 'Einsatz-Leistungen';

    private static string $edit_permission = CareDispatchPermissions::DISPATCH;

    private static array $db = [
        'SortOrder' => 'Int',
        'Instruction' => 'Text',
        'Status' => "Enum('Open,Done,NotDone','Open')",
        'DoneAt' => 'Datetime',
        // Mandatory when NotDone (e.g. "Patient verweigert").
        'Note' => 'Text',
    ];

    private static array $has_one = [
        'Visit' => PatientVisit::class,
        'CareTask' => CareTask::class,
        'CarePlanItem' => CarePlanItem::class,
        'DoneBy' => Member::class,
    ];

    private static array $indexes = [
        'VisitSort' => ['type' => 'index', 'columns' => ['VisitID', 'SortOrder']],
    ];

    private static array $summary_fields = [
        'CareTask.Code' => 'Code',
        'CareTask.Title' => 'Leistung',
        'Instruction' => 'Anweisung',
        'Status' => 'Status',
        'DoneAt.Nice' => 'Erledigt am',
    ];

    private static string $default_sort = '"SortOrder" ASC';

    public function getAuditPatientID(): int
    {
        return (int) $this->Visit()->PatientID;
    }

    public function validate(): ValidationResult
    {
        $result = parent::validate();
        if (!$this->VisitID) {
            $result->addFieldError('VisitID', 'Einsatz fehlt.');
        }
        if (!$this->CareTaskID) {
            $result->addFieldError('CareTaskID', 'Leistung fehlt.');
        }
        if ($this->Status === self::STATUS_NOT_DONE && trim((string) $this->Note) === '') {
            $result->addFieldError('Note', 'Bei nicht erbrachter Leistung ist eine Begründung Pflicht.');
        }

        return $result;
    }

    protected function onAfterWrite(): void
    {
        parent::onAfterWrite();
        // New records count as changed for every set field, so this also covers inserts.
        if ($this->isChanged('CareTaskID')) {
            $this->syncVisitRequirement();
        }
    }

    protected function onAfterDelete(): void
    {
        parent::onAfterDelete();
        $this->syncVisitRequirement();
    }

    private function syncVisitRequirement(): void
    {
        $visit = $this->Visit();
        if (!$visit->exists()) {
            return;
        }
        $required = $visit->computeRequiredQualification()->value;
        if ($required !== $visit->RequiredQualification) {
            $visit->write();
        }
    }
}
