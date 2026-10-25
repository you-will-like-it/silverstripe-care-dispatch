<?php

declare(strict_types=1);

namespace YouWillLikeIT\CareDispatch\Control;

use SilverStripe\Control\HTTPRequest;
use SilverStripe\ORM\FieldType\DBHTMLText;
use YouWillLikeIT\CareDispatch\Domain\Clock;
use YouWillLikeIT\CareDispatch\Model\CareDispatchSettings;
use YouWillLikeIT\CareDispatch\Security\CareDispatchPermissions as P;
use YouWillLikeIT\CareDispatch\Security\Roles;

/**
 * HTML shell of the back-office app (PDL / Verwaltung). React mounts on #dispatch-app-root and
 * routes client-side: /pflege/board, /pflege/dienstplan, /pflege/patienten, … (see client/src/app).
 * Routed at /pflege (after /pflege/mobil).
 */
class PflegeAppController extends CareDispatchPageController
{
    private static array $required_permission = [
        P::DISPATCH, P::MASTERDATA, P::CAREPLAN, P::ROSTER, P::STAFF, P::AUDIT, P::SETTINGS,
    ];

    private static array $url_handlers = [
        '$Section/$ID/$Sub' => 'index',
    ];

    private static array $allowed_actions = ['index'];

    public function index(HTTPRequest $request): DBHTMLText
    {
        $this->requireBundle('app');

        return $this->renderWith(['YouWillLikeIT/CareDispatch/DispatchPage']);
    }

    public function getPageTitle(): string
    {
        $company = (string) CareDispatchSettings::current()->CompanyName;

        return $company !== '' ? $company . ' – Einsatzplanung' : 'Einsatzplanung';
    }

    protected function bootConfig(): array
    {
        $base = rtrim((string) $this->baseConfig()['baseUrl'], '/');
        $date = $this->getRequest()->getVar('date');
        $member = $this->currentMemberOrFail();
        $role = Roles::roleOf($member);

        return $this->baseConfig() + [
            'appBase' => $base . '/pflege',
            'apiBase' => $base . '/api/dispatch',
            'adminApiBase' => $base . '/api/admin',
            'date' => is_string($date) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ? $date : Clock::today(),
            'pollSeconds' => 30,
            'permissions' => P::flags($member),
            'canViewAudit' => P::check(P::AUDIT, $member),
            'role' => $role,
            'roleLabel' => $role !== null ? Roles::title($role) : null,
            'companyName' => (string) CareDispatchSettings::current()->CompanyName,
            'mobileUrl' => $base . '/pflege/mobil',
        ];
    }
}
