<?php

declare(strict_types=1);

namespace YouWillLikeIT\CareDispatch\Control;

use SilverStripe\Control\Controller;
use SilverStripe\Control\Director;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\Control\HTTPResponse_Exception;
use SilverStripe\Security\Member;
use SilverStripe\Security\Permission;
use SilverStripe\Security\Security;
use SilverStripe\Security\SecurityToken;
use SilverStripe\View\Requirements;
use YouWillLikeIT\CareDispatch\Security\CareDispatchPermissions;

/**
 * Base for the two HTML shells (PDL board and mobile view): login + permission gate,
 * asset loading, boot config for the client.
 */
abstract class CareDispatchPageController extends Controller
{
    public const MODULE = 'youwilllikeit/silverstripe-care-dispatch';

    /** Permission code(s); with several codes any one suffices. */
    private static string|array $required_permission = '';

    protected function init(): void
    {
        parent::init();
        $member = Security::getCurrentUser();
        $codes = array_values(array_filter((array) static::config()->get('required_permission')));
        if ($member === null || ($codes !== [] && !Permission::checkMember($member, $codes))) {
            // Field staff opening the back office are sent to their mobile view.
            if ($member !== null && !$this instanceof MobileAppController
                && Permission::checkMember($member, CareDispatchPermissions::MOBILE)) {
                $redirect = HTTPResponse::create();
                $redirect->redirect(rtrim(Director::baseURL(), '/') . '/pflege/mobil');
                throw new HTTPResponse_Exception($redirect);
            }
            $response = Security::permissionFailure($this, 'Bitte mit einem berechtigten Konto anmelden.');
            throw new HTTPResponse_Exception($response);
        }
        // Never cache pages that carry patient data or a CSRF token.
        $this->getResponse()->addHeader('Cache-Control', 'no-store, private');
        $this->getResponse()->addHeader('X-Frame-Options', 'SAMEORIGIN');
        $this->getResponse()->addHeader('Referrer-Policy', 'same-origin');
    }

    protected function requireBundle(string $entry): void
    {
        Requirements::css(self::MODULE . ':client/dist/' . $entry . '.css');
        Requirements::javascript(self::MODULE . ':client/dist/' . $entry . '.js', ['type' => 'module']);
    }

    /** @return array<string, mixed> */
    protected function baseConfig(): array
    {
        $token = SecurityToken::inst();
        /** @var Member $member */
        $member = Security::getCurrentUser();

        return [
            'baseUrl' => Director::baseURL(),
            'csrf' => ['header' => 'X-SecurityID', 'name' => $token->getName(), 'value' => $token->getValue()],
            'logoutUrl' => Security::logout_url(),
            'member' => ['id' => (int) $member->ID, 'name' => trim($member->FirstName . ' ' . $member->Surname)],
        ];
    }

    protected function currentMemberOrFail(): Member
    {
        $member = Security::getCurrentUser();
        if ($member === null) {
            throw new HTTPResponse_Exception(Security::permissionFailure($this));
        }

        return $member;
    }

    /** JSON for a data attribute; escaped by the template (.ATT). */
    public function getBootConfig(): string
    {
        return (string) json_encode($this->bootConfig(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    /** @return array<string, mixed> */
    abstract protected function bootConfig(): array;
}
