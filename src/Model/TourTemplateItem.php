<?php

declare(strict_types=1);

namespace YouWillLikeIT\CareDispatch\Model;

use SilverStripe\Core\Validation\ValidationResult;
use SilverStripe\ORM\DataObject;
use YouWillLikeIT\CareDispatch\Model\Concern\DispatchPermissions;
use YouWillLikeIT\CareDispatch\Security\CareDispatchPermissions;

/**
 * A stop on a tour template: which patient (and which visit slot of their care plan) is
 * served at which planned time.
 *
 * @property int    $SortOrder
 * @property string $Slot
 * @property string $PlannedStart
 * @property int    $DurationMinutes  0 = derived from the care plan tasks
 * @property int    $TourTemplateID
 * @property int    $PatientID
 * @method TourTemplate TourTemplate()
 * @method Patient Patient()
 */
class TourTemplateItem extends DataObject
{
    use DispatchPermissions;

    private static string $table_name = 'CareDispatch_TourTemplateItem';

    private static string $singular_name = 'Tourstopp';

    private static string $plural_name = 'Tourstopps';

    private static string $edit_permission = CareDispatchPermissions::MASTERDATA;

    private static array $db = [
        'SortOrder' => 'Int',
        'Slot' => CarePlanItem::DB_ENUM_SLOT,
        'PlannedStart' => 'Time',
        'DurationMinutes' => 'Int',
    ];

    private static array $has_one = [
        'TourTemplate' => TourTemplate::class,
        'Patient' => Patient::class,
    ];

    private static array $indexes = [
        'TemplateSort' => ['type' => 'index', 'columns' => ['TourTemplateID', 'SortOrder']],
    ];

    private static array $summary_fields = [
        'SortOrder' => '#',
        'PlannedStart.Short' => 'Zeit',
        'Patient.Title' => 'Patient',
        'SlotLabel' => 'Einsatz',
        'DurationMinutes' => 'Dauer (Min.)',
    ];

    private static array $field_labels = [
        'SortOrder' => 'Reihenfolge',
        'Slot' => 'Einsatz-Slot aus dem Pflegeplan',
        'PlannedStart' => 'Geplanter Beginn',
        'DurationMinutes' => 'Dauer in Min. (0 = aus Leistungen berechnen)',
        'Patient' => 'Patient',
    ];

    private static string $default_sort = '"SortOrder" ASC';

    public function getSlotLabel(): string
    {
        return CarePlanItem::SLOT_LABELS[$this->Slot] ?? (string) $this->Slot;
    }

    public function validate(): ValidationResult
    {
        $result = parent::validate();
        if (!$this->PatientID) {
            $result->addFieldError('PatientID', 'Bitte einen Patienten wählen.');
        }
        if (!$this->PlannedStart) {
            $result->addFieldError('PlannedStart', 'Geplanter Beginn ist ein Pflichtfeld.');
        }
        if ((int) $this->DurationMinutes < 0 || (int) $this->DurationMinutes > 480) {
            $result->addFieldError('DurationMinutes', 'Dauer muss zwischen 0 und 480 Minuten liegen.');
        }
        if ($this->TourTemplateID && $this->PatientID) {
            $duplicate = self::get()
                ->filter([
                    'TourTemplateID' => $this->TourTemplateID,
                    'PatientID' => $this->PatientID,
                    'Slot' => $this->Slot,
                ])
                ->exclude('ID', $this->ID ?: 0)
                ->exists();
            if ($duplicate) {
                $result->addError('Dieser Patient ist für diesen Slot bereits in der Vorlage enthalten.');
            }
        }

        return $result;
    }

    protected function onBeforeWrite(): void
    {
        parent::onBeforeWrite();
        if (!$this->SortOrder && $this->TourTemplateID) {
            $this->SortOrder = (int) self::get()->filter('TourTemplateID', $this->TourTemplateID)->max('SortOrder') + 1;
        }
    }
}
