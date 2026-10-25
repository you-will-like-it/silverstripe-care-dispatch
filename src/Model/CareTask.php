<?php

declare(strict_types=1);

namespace YouWillLikeIT\CareDispatch\Model;

use SilverStripe\Core\Validation\ValidationResult;
use SilverStripe\Forms\DropdownField;
use SilverStripe\Forms\FieldList;
use SilverStripe\ORM\DataObject;
use YouWillLikeIT\CareDispatch\Domain\QualificationLevel;
use YouWillLikeIT\CareDispatch\Model\Concern\DispatchPermissions;
use YouWillLikeIT\CareDispatch\Security\CareDispatchPermissions;

/**
 * Leistungskatalog: a care task with its minimum qualification and planned duration.
 *
 * @property string $Code
 * @property string $Title
 * @property string $Category
 * @property string $MinQualification
 * @property int    $DurationMinutes
 * @property bool   $IsActive
 */
class CareTask extends DataObject
{
    use DispatchPermissions;

    public const CATEGORY_LABELS = [
        'SGBXI' => 'Grundpflege / Betreuung (SGB XI)',
        'SGBV' => 'Behandlungspflege (SGB V)',
        'Sonstiges' => 'Sonstiges',
    ];

    private static string $table_name = 'CareDispatch_CareTask';

    private static string $singular_name = 'Leistung';

    private static string $plural_name = 'Leistungskatalog';

    private static string $edit_permission = CareDispatchPermissions::MASTERDATA;

    private static array $db = [
        'Code' => 'Varchar(20)',
        'Title' => 'Varchar(150)',
        'Category' => "Enum('SGBXI,SGBV,Sonstiges','SGBXI')",
        'MinQualification' => QualificationLevel::DB_ENUM_REQUIREMENT,
        'DurationMinutes' => 'Int',
        'IsActive' => 'Boolean',
    ];

    private static array $defaults = [
        'MinQualification' => 'Hilfskraft',
        'DurationMinutes' => 10,
        'IsActive' => true,
    ];

    private static array $indexes = [
        'Code' => ['type' => 'unique', 'columns' => ['Code']],
    ];

    private static array $summary_fields = [
        'Code' => 'Code',
        'Title' => 'Leistung',
        'CategoryLabel' => 'Kategorie',
        'MinQualificationLabel' => 'Mindestqualifikation',
        'DurationMinutes' => 'Dauer (Min.)',
        'IsActive.Nice' => 'Aktiv',
    ];

    private static array $field_labels = [
        'Code' => 'Leistungscode (z. B. LK 1, HKP 26)',
        'Title' => 'Bezeichnung',
        'Category' => 'Kategorie',
        'MinQualification' => 'Mindestqualifikation',
        'DurationMinutes' => 'Planzeit (Min.)',
        'IsActive' => 'Aktiv',
    ];

    private static string $default_sort = '"Code" ASC';

    public function getMinQualificationLevel(): QualificationLevel
    {
        return QualificationLevel::fromDb($this->MinQualification);
    }

    public function getMinQualificationLabel(): string
    {
        return $this->getMinQualificationLevel()->label();
    }

    public function getCategoryLabel(): string
    {
        return self::CATEGORY_LABELS[$this->Category] ?? (string) $this->Category;
    }

    public function getCMSFields(): FieldList
    {
        $fields = parent::getCMSFields();
        $fields->replaceField(
            'MinQualification',
            DropdownField::create('MinQualification', $this->fieldLabel('MinQualification'), QualificationLevel::options(false))
        );
        $fields->replaceField('Category', DropdownField::create('Category', $this->fieldLabel('Category'), self::CATEGORY_LABELS));

        return $fields;
    }

    public function validate(): ValidationResult
    {
        $result = parent::validate();
        if (trim((string) $this->Code) === '') {
            $result->addFieldError('Code', 'Code ist ein Pflichtfeld.');
        }
        if (trim((string) $this->Title) === '') {
            $result->addFieldError('Title', 'Bezeichnung ist ein Pflichtfeld.');
        }
        if ((int) $this->DurationMinutes < 1 || (int) $this->DurationMinutes > 480) {
            $result->addFieldError('DurationMinutes', 'Planzeit muss zwischen 1 und 480 Minuten liegen.');
        }

        return $result;
    }
}
