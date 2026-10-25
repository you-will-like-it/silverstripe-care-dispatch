<?php

declare(strict_types=1);

namespace YouWillLikeIT\CareDispatch\Control;

use SilverStripe\Control\HTTPRequest;
use SilverStripe\ORM\FieldType\DBHTMLText;
use YouWillLikeIT\CareDispatch\Domain\Clock;
use YouWillLikeIT\CareDispatch\Security\CareDispatchPermissions;

/**
 * HTML shell of the PDL board. React mounts on #dispatch-app-root.
 * Routed at /pflege/board.
 */
class DispatchBoardController extends CareDispatchPageController
{
    private static string $required_permission = CareDispatchPermissions::DISPATCH;

    private static array $allowed_actions = ['index'];

    public function index(HTTPRequest $request): DBHTMLText
    {
        $this->requireBundle('board');

        return $this->renderWith(['YouWillLikeIT/CareDispatch/DispatchPage']);
    }

    public function getPageTitle(): string
    {
        return 'Einsatzplanung – PDL-Board';
    }

    protected function bootConfig(): array
    {
        $date = $this->getRequest()->getVar('date');

        return $this->baseConfig() + [
            'apiBase' => $this->absoluteApi('api/dispatch'),
            'date' => is_string($date) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ? $date : Clock::today(),
            'pollSeconds' => 30,
            'canViewAudit' => CareDispatchPermissions::check(CareDispatchPermissions::AUDIT),
            'adminUrl' => $this->absoluteApi('admin/einsatzplanung'),
            'mobileUrl' => $this->absoluteApi('pflege/mobil'),
        ];
    }

    private function absoluteApi(string $path): string
    {
        return rtrim($this->baseConfig()['baseUrl'], '/') . '/' . $path;
    }
}
