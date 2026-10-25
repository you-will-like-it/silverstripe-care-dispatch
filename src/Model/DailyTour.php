<?php

declare(strict_types=1);

namespace YouWillLikeIT\CareDispatch\Model;

use SilverStripe\Core\Validation\ValidationResult;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\HasManyList;
use YouWillLikeIT\CareDispatch\Model\Concern\DispatchPermissions;
use YouWillLikeIT\CareDispatch\Security\CareDispatchPermissions;

/**
 * Konkrete Tour an einem Tag.
 *
 * Lifecycle: Draft -> Assigned -> InProgress -> Completed, or -> Interrupted (SOS, terminal).
 *
 * @property string      $TourDate
 * @property string      $Title
 * @property string      $Status
 * @property bool        $IsAdHoc
 * @property string|null $InterruptedAt
 * @property string      $InterruptReason
 * @property int         $CaregiverID
 * @property int         $TourTemplateID
 * @method Caregiver Caregiver()
 * @method TourTemplate TourTemplate()
 * @method HasManyList<PatientVisit> Visits()
 */
class DailyTour extends DataObject
{
    use DispatchPermissions;

    public const STATUS_DRAFT = 'Draft';
    public const STATUS_ASSIGNED = 'Assigned';
    public const STATUS_IN_PROGRESS = 'InProgress';
    public const STATUS_COMPLETED = 'Completed';
    public const STATUS_INTERRUPTED = 'Interrupted';

    /** Tours that can still receive visits. */
    public const OPEN_STATUSES = [self::STATUS_DRAFT, self::STATUS_ASSIGNED, self::STATUS_IN_PROGRESS];

    private static string $table_name = 'CareDispatch_DailyTour';

    private static string $singular_name = 'Tagestour';

    private static string $plural_name = 'Tagestouren';

    private static string $edit_permission = CareDispatchPermissions::DISPATCH;

    private static array $db = [
        'TourDate' => 'Date',
        'Title' => 'Varchar(150)',
        'Status' => "Enum('Draft,Assigned,InProgress,Completed,Interrupted','Draft')",
        'IsAdHoc' => 'Boolean',
        'InterruptedAt' => 'Datetime',
        'InterruptReason' => 'Text',
    ];

    private static array $has_one = [
        'Caregiver' => Caregiver::class,
        'TourTemplate' => TourTemplate::class,
    ];

    private static array $has_many = [
        'Visits' => PatientVisit::class,
    ];

    private static array $indexes = [
        'TourDate' => true,
        'DateCaregiver' => ['type' => 'index', 'columns' => ['TourDate', 'CaregiverID']],
        'DateTemplate' => ['type' => 'index', 'columns' => ['TourDate', 'TourTemplateID']],
    ];

    private static array $summary_fields = [
        'TourDate.Nice' => 'Datum',
        'Title' => 'Tour',
        'Caregiver.Title' => 'Pflegekraft',
        'Status' => 'Status',
        'Visits.Count' => 'Einsätze',
    ];

    private static string $default_sort = '"TourDate" DESC, "Title" ASC';

    public function isOpen(): bool
    {
        return in_array($this->Status, self::OPEN_STATUSES, true);
    }

    /**
     * Derives Draft/Assigned/InProgress/Completed from caregiver and visit states.
     * Interrupted is terminal and never left automatically.
     */
    public function refreshStatus(): void
    {
        if ($this->Status === self::STATUS_INTERRUPTED) {
            return;
        }
        if (!$this->CaregiverID) {
            $this->Status = self::STATUS_DRAFT;

            return;
        }
        $visits = $this->Visits();
        $total = $visits->count();
        $started = $visits->filter('Status', [PatientVisit::STATUS_IN_PROGRESS, PatientVisit::STATUS_COMPLETED])->count();
        $open = $visits->filter('Status', [PatientVisit::STATUS_PENDING, PatientVisit::STATUS_IN_PROGRESS])->count();

        if ($total > 0 && $open === 0 && $started > 0) {
            $this->Status = self::STATUS_COMPLETED;
        } elseif ($started > 0) {
            $this->Status = self::STATUS_IN_PROGRESS;
        } else {
            $this->Status = self::STATUS_ASSIGNED;
        }
    }

    /** Re-numbers SortOrder of the tour's visits by scheduled start. */
    public function resequence(): void
    {
        $i = 1;
        foreach ($this->Visits()->sort(['ScheduledStart' => 'ASC', 'ID' => 'ASC']) as $visit) {
            if ((int) $visit->SortOrder !== $i) {
                $visit->SortOrder = $i;
                $visit->write();
            }
            $i++;
        }
    }

    public function validate(): ValidationResult
    {
        $result = parent::validate();
        if (!$this->TourDate) {
            $result->addFieldError('TourDate', 'Datum fehlt.');
        }
        if (trim((string) $this->Title) === '') {
            $result->addFieldError('Title', 'Titel fehlt.');
        }
        if ($this->Status === self::STATUS_INTERRUPTED && trim((string) $this->InterruptReason) === '') {
            $result->addFieldError('InterruptReason', 'Unterbrochene Tour benötigt einen Grund.');
        }

        return $result;
    }
}
