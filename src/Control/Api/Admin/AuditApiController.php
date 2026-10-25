<?php

declare(strict_types=1);

namespace YouWillLikeIT\CareDispatch\Control\Api\Admin;

use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;
use SilverStripe\ORM\Queries\SQLSelect;
use YouWillLikeIT\CareDispatch\Domain\Clock;
use YouWillLikeIT\CareDispatch\Model\AuditEntry;
use YouWillLikeIT\CareDispatch\Security\CareDispatchPermissions as P;

/**
 * /api/admin/audit – Änderungsprotokoll (AUDIT), paged.
 */
class AuditApiController extends AdminApiController
{
    private const PAGE_SIZE = 50;

    private static array $url_handlers = [
        'GET list' => 'listEntries',
        'GET actions' => 'actions',
    ];

    private static array $allowed_actions = ['listEntries', 'actions'];

    /** GET list?patient=&member=&action=&from=&to=&page= */
    public function listEntries(HTTPRequest $request): HTTPResponse
    {
        return $this->handle(function () use ($request): array {
            $this->requirePermission(P::AUDIT);
            $list = AuditEntry::get()->sort(['Created' => 'DESC', 'ID' => 'DESC']);
            if ((int) $request->getVar('patient') > 0) {
                $list = $list->filter('PatientID', (int) $request->getVar('patient'));
            }
            if ((int) $request->getVar('member') > 0) {
                $list = $list->filter('MemberID', (int) $request->getVar('member'));
            }
            if (is_string($request->getVar('action')) && $request->getVar('action') !== '') {
                $list = $list->filter('Action', $request->getVar('action'));
            }
            if ($request->getVar('from')) {
                $list = $list->filter('Created:GreaterThanOrEqual', Clock::parseDate($request->getVar('from')) . ' 00:00:00');
            }
            if ($request->getVar('to')) {
                $list = $list->filter('Created:LessThanOrEqual', Clock::parseDate($request->getVar('to')) . ' 23:59:59');
            }
            $page = max(1, (int) ($request->getVar('page') ?: 1));
            $total = $list->count();

            return [
                'page' => $page,
                'pages' => max(1, (int) ceil($total / self::PAGE_SIZE)),
                'total' => $total,
                'entries' => array_map(static fn (AuditEntry $e): array => [
                    'id' => (int) $e->ID,
                    'at' => Clock::iso($e->Created),
                    'member' => $e->MemberName,
                    'memberId' => (int) $e->MemberID ?: null,
                    'action' => $e->Action,
                    'subject' => $e->getSubjectLabel(),
                    'patientId' => (int) $e->PatientID ?: null,
                    'patientName' => $e->PatientID ? $e->Patient()->getTitle() : null,
                    'changes' => (object) $e->getChangesArray(),
                    'reason' => $e->Reason ?: null,
                ], $list->limit(self::PAGE_SIZE, ($page - 1) * self::PAGE_SIZE)->toArray()),
            ];
        });
    }

    /** GET actions – distinct action names for the filter. */
    public function actions(HTTPRequest $request): HTTPResponse
    {
        return $this->handle(function (): array {
            $this->requirePermission(P::AUDIT);
            $table = AuditEntry::singleton()->baseTable();
            $actions = SQLSelect::create('"Action"', '"' . $table . '"')->setDistinct(true)->setOrderBy('"Action"')->execute()->column();

            return ['actions' => array_values(array_filter(array_map('strval', $actions)))];
        });
    }
}
