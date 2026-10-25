<?php

declare(strict_types=1);

namespace YouWillLikeIT\CareDispatch\Model;

use SilverStripe\Core\Validation\ValidationResult;
use SilverStripe\Forms\CheckboxSetField;
use SilverStripe\Forms\FieldList;
use SilverStripe\Forms\GridField\GridField;
use SilverStripe\Forms\GridField\GridFieldConfig_RecordEditor;
use SilverStripe\ORM\DataObject;
use SilverStripe\ORM\HasManyList;
use YouWillLikeIT\CareDispatch\Domain\Weekdays;
use YouWillLikeIT\CareDispatch\Model\Concern\DispatchPermissions;
use YouWillLikeIT\CareDispatch\Security\CareDispatchPermissions;

/**
 * Tourvorlage, z. B. "Ruta Centar – Früh".
 *
 * @property string $Title
 * @property string $DefaultShiftType
 * @property int    $EstimatedDurationMinutes
 * @property string $Weekdays
 * @property bool   $IsActive
 * @property int    $DefaultCaregiverID
 * @method Caregiver DefaultCaregiver()
 * @method HasManyList<TourTemplateItem> Items()
 */
class TourTemplate extends DataObject
{
    use DispatchPermissions;

    private static string $table_name = 'CareDispatch_TourTemplate';

    private static string $singular_name = 'Tourvorlage';

    private static string $plural_name = 'Tourvorlagen';

    private static string $edit_permission = CareDispatchPermissions::DISPATCH;

    private static array $db = [
        'Title' => 'Varchar(150)',
        'DefaultShiftType' => "Enum('Frueh,Spaet,Nacht,Teildienst','Frueh')",
        'EstimatedDurationMinutes' => 'Int',
        'Weekdays' => 'Varchar(60)',
        'IsActive' => 'Boolean',
    ];

    private static array $has_one = [
        // Bezugspflege: preferred caregiver; auto-assigned by the generator if they have a shift that day.
        'DefaultCaregiver' => Caregiver::class,
    ];

    private static array $has_many = [
        'Items' => TourTemplateItem::class,
    ];

    private static array $cascade_deletes = ['Items'];

    private static array $defaults = [
        'IsActive' => true,
        'EstimatedDurationMinutes' => 300,
    ];

    private static array $summary_fields = [
        'Title' => 'Titel',
        'DefaultShiftType' => 'Dienst',
        'WeekdaysLabel' => 'Tage',
        'Items.Count' => 'Stopps',
        'DefaultCaregiver.Title' => 'Bezugspflegekraft',
        'IsActive.Nice' => 'Aktiv',
    ];

    private static array $field_labels = [
        'Title' => 'Titel',
        'DefaultShiftType' => 'Standard-Dienst',
        'EstimatedDurationMinutes' => 'Geschätzte Dauer (Min.)',
        'Weekdays' => 'Gültig an',
        'IsActive' => 'Aktiv',
        'DefaultCaregiver' => 'Bezugspflegekraft',
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

    public function appliesOn(int $isoWeekday): bool
    {
        return $this->IsActive && Weekdays::contains($this->Weekdays, $isoWeekday);
    }

    public function getCMSFields(): FieldList
    {
        $fields = parent::getCMSFields();
        $fields->replaceField('Weekdays', CheckboxSetField::create('Weekdays', $this->fieldLabel('Weekdays'), Weekdays::options()));
        if ($this->isInDB()) {
            $fields->removeByName('Items');
            $fields->addFieldToTab(
                'Root.Main',
                GridField::create('Items', 'Stopps', $this->Items(), GridFieldConfig_RecordEditor::create())
            );
        }

        return $fields;
    }

    public function validate(): ValidationResult
    {
        $result = parent::validate();
        if (trim((string) $this->Title) === '') {
            $result->addFieldError('Title', 'Titel ist ein Pflichtfeld.');
        }
        if (Weekdays::parse($this->Weekdays) === []) {
            $result->addFieldError('Weekdays', 'Mindestens einen Wochentag wählen.');
        }

        return $result;
    }
}
