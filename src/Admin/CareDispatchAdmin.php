<?php

declare(strict_types=1);

namespace YouWillLikeIT\CareDispatch\Admin;

use SilverStripe\Admin\ModelAdmin;
use SilverStripe\Forms\GridField\GridFieldAddNewButton;
use SilverStripe\Forms\GridField\GridFieldConfig;
use SilverStripe\Forms\GridField\GridFieldDeleteAction;
use SilverStripe\Forms\GridField\GridFieldImportButton;
use YouWillLikeIT\CareDispatch\Model\AuditEntry;
use YouWillLikeIT\CareDispatch\Model\CareTask;
use YouWillLikeIT\CareDispatch\Model\Caregiver;
use YouWillLikeIT\CareDispatch\Model\DailyTour;
use YouWillLikeIT\CareDispatch\Model\Patient;
use YouWillLikeIT\CareDispatch\Model\Shift;
use YouWillLikeIT\CareDispatch\Model\TimeEntry;
use YouWillLikeIT\CareDispatch\Model\TourTemplate;

/**
 * Master data and records in the CMS (/admin/einsatzplanung).
 * Access to the section: "Zugriff auf Bereich Einsatzplanung" (CMS_ACCESS_*); record-level
 * rights come from the PFLEGE_* permissions (see CareDispatchPermissions).
 */
class CareDispatchAdmin extends ModelAdmin
{
    private static string $url_segment = 'einsatzplanung';

    private static string $menu_title = 'Einsatzplanung';

    private static string $menu_icon_class = 'font-icon-calendar';

    private static array $managed_models = [
        'patients' => ['dataClass' => Patient::class, 'title' => 'Patienten'],
        'caregivers' => ['dataClass' => Caregiver::class, 'title' => 'Pflegekräfte'],
        'tasks' => ['dataClass' => CareTask::class, 'title' => 'Leistungskatalog'],
        'templates' => ['dataClass' => TourTemplate::class, 'title' => 'Tourvorlagen'],
        'shifts' => ['dataClass' => Shift::class, 'title' => 'Dienstplan'],
        'tours' => ['dataClass' => DailyTour::class, 'title' => 'Tagestouren'],
        'time' => ['dataClass' => TimeEntry::class, 'title' => 'Zeitbuchungen'],
        'audit' => ['dataClass' => AuditEntry::class, 'title' => 'Änderungsprotokoll'],
    ];

    private static array $model_importers = [];

    protected function getGridFieldConfig(): GridFieldConfig
    {
        $config = parent::getGridFieldConfig();
        $config->removeComponentsByType(GridFieldImportButton::class);

        // Day tours are created by the generator / board, the audit log only by the system.
        if (in_array($this->modelClass, [DailyTour::class, AuditEntry::class], true)) {
            $config->removeComponentsByType(GridFieldAddNewButton::class);
            $config->removeComponentsByType(GridFieldDeleteAction::class);
        }

        return $config;
    }
}
