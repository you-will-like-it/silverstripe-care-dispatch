<?php

declare(strict_types=1);

namespace YouWillLikeIT\CareDispatch\Control;

use SilverStripe\Control\Director;
use SilverStripe\Control\HTTPRequest;
use SilverStripe\ORM\FieldType\DBHTMLText;
use YouWillLikeIT\CareDispatch\Security\CareDispatchPermissions;

/**
 * Mobile-first view for field staff (.ss + Alpine.js). Routed at /pflege/mobil.
 * Works in any phone browser and is prepared for PWA / Android WebView wrapping.
 */
class MobileAppController extends CareDispatchPageController
{
    private static string $required_permission = CareDispatchPermissions::MOBILE;

    private static array $allowed_actions = ['index'];

    public function index(HTTPRequest $request): DBHTMLText
    {
        $this->requireBundle('mobile');

        return $this->renderWith(['YouWillLikeIT/CareDispatch/MobileApp']);
    }

    public function getPageTitle(): string
    {
        return 'Meine Tour';
    }

    protected function bootConfig(): array
    {
        return $this->baseConfig() + [
            'apiBase' => rtrim(Director::baseURL(), '/') . '/api/mobile',
            'pollSeconds' => 60,
        ];
    }
}
