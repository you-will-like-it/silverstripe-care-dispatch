<?php

declare(strict_types=1);

namespace YouWillLikeIT\CareDispatch\Control\Api\Admin;

use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;
use YouWillLikeIT\CareDispatch\Domain\Clock;
use YouWillLikeIT\CareDispatch\Domain\Weekdays;
use YouWillLikeIT\CareDispatch\Exception\BadRequestException;
use YouWillLikeIT\CareDispatch\Exception\NotFoundException;
use YouWillLikeIT\CareDispatch\Model\CarePlanItem;
use YouWillLikeIT\CareDispatch\Model\Caregiver;
use YouWillLikeIT\CareDispatch\Model\Patient;
use YouWillLikeIT\CareDispatch\Model\Shift;
use YouWillLikeIT\CareDispatch\Model\TourTemplate;
use YouWillLikeIT\CareDispatch\Model\TourTemplateItem;
use YouWillLikeIT\CareDispatch\Security\CareDispatchPermissions as P;
use YouWillLikeIT\CareDispatch\Service\Transaction;

/**
 * /api/admin/templates – Tourvorlagen (DISPATCH).
 */
class TemplatesApiController extends AdminApiController
{
    private static array $url_handlers = [
        'GET list' => 'listTemplates',
        'POST create' => 'createTemplate',
        'POST $ID!/items' => 'saveItems',
        'POST $ID!/delete' => 'deleteTemplate',
        'GET $ID!' => 'template',
        'POST $ID!' => 'updateTemplate',
    ];

    private static array $allowed_actions = ['listTemplates', 'createTemplate', 'saveItems', 'deleteTemplate', 'template', 'updateTemplate'];

    public function listTemplates(HTTPRequest $request): HTTPResponse
    {
        return $this->handle(function (): array {
            $this->requirePermission(P::DISPATCH);

            return [
                'templates' => array_map(fn (TourTemplate $t): array => $this->summary($t), TourTemplate::get()->sort(['IsActive' => 'DESC', 'Title' => 'ASC'])->toArray()),
                'shiftTypes' => Shift::TYPE_LABELS,
                'slots' => CarePlanItem::SLOT_LABELS,
            ];
        });
    }

    public function template(HTTPRequest $request): HTTPResponse
    {
        return $this->handle(function () use ($request): array {
            $this->requirePermission(P::DISPATCH);

            return ['template' => $this->detail($this->load($request))];
        });
    }

    public function createTemplate(HTTPRequest $request): HTTPResponse
    {
        return $this->handle(function (): array {
            $this->requirePermission(P::DISPATCH);
            $template = TourTemplate::create();
            Transaction::run(function () use ($template): void {
                $this->apply($template, $this->body());
                $template->write();
            });

            return ['template' => $this->detail($template)];
        }, 201);
    }

    public function updateTemplate(HTTPRequest $request): HTTPResponse
    {
        return $this->handle(function () use ($request): array {
            $this->requirePermission(P::DISPATCH);
            $template = $this->load($request);
            Transaction::run(function () use ($template): void {
                $this->apply($template, $this->body());
                $template->write();
            });

            return ['template' => $this->detail($template)];
        });
    }

    /**
     * POST $ID/items {items: [{patientId, slot, plannedStart: "HH:MM", durationMinutes?}]}
     * Replaces all stops; order = array order.
     */
    public function saveItems(HTTPRequest $request): HTTPResponse
    {
        return $this->handle(function () use ($request): array {
            $this->requirePermission(P::DISPATCH);
            $template = $this->load($request);
            $items = $this->body()['items'] ?? null;
            if (!is_array($items)) {
                throw BadRequestException::because('items muss eine Liste sein.');
            }
            Transaction::run(function () use ($template, $items): void {
                $seen = [];
                $rows = [];
                foreach ($items as $i => $row) {
                    if (!is_array($row)) {
                        throw BadRequestException::because('Ungültiger Tourstopp.');
                    }
                    $patientID = (int) $this->intParam($row, 'patientId');
                    $slot = $this->stringParam($row, 'slot', 10);
                    if (!array_key_exists($slot, CarePlanItem::SLOT_LABELS)) {
                        throw BadRequestException::because('Unbekannter Einsatz-Slot.');
                    }
                    if (Patient::get()->byID($patientID) === null) {
                        throw NotFoundException::for('Patient', $patientID);
                    }
                    $key = $patientID . '|' . $slot;
                    if (isset($seen[$key])) {
                        throw BadRequestException::because(sprintf('Stopp %d: Patient und Slot sind doppelt.', $i + 1));
                    }
                    $seen[$key] = true;
                    $rows[] = [
                        'patientId' => $patientID,
                        'slot' => $slot,
                        'start' => $this->timeParam($row, 'plannedStart'),
                        'duration' => max(0, min(480, (int) ($row['durationMinutes'] ?? 0))),
                    ];
                }
                foreach ($template->Items() as $existing) {
                    $existing->delete();
                }
                $sort = 1;
                $first = null;
                $last = null;
                foreach ($rows as $row) {
                    $item = TourTemplateItem::create();
                    $item->TourTemplateID = (int) $template->ID;
                    $item->PatientID = $row['patientId'];
                    $item->Slot = $row['slot'];
                    $item->PlannedStart = $row['start'];
                    $item->DurationMinutes = $row['duration'];
                    $item->SortOrder = $sort++;
                    $item->write();
                    $first ??= Clock::timeToSeconds($row['start']);
                    $last = Clock::timeToSeconds($row['start']) + ($row['duration'] ?: 30) * 60;
                }
                $template->EstimatedDurationMinutes = $first !== null ? max(0, intdiv($last - $first, 60)) : 0;
                $template->write();
            });

            return ['template' => $this->detail($template)];
        });
    }

    /** Day tours already created from the template keep their title and visits. */
    public function deleteTemplate(HTTPRequest $request): HTTPResponse
    {
        return $this->handle(function () use ($request): array {
            $this->requirePermission(P::DISPATCH);
            $template = $this->load($request);
            Transaction::run(static fn () => $template->delete());

            return ['deleted' => true];
        });
    }

    private function load(HTTPRequest $request): TourTemplate
    {
        $id = $this->routeID($request);

        return TourTemplate::get()->byID($id) ?? throw NotFoundException::for('Tourvorlage', $id);
    }

    /** @param array<string, mixed> $body */
    private function apply(TourTemplate $template, array $body): void
    {
        if ($this->has($body, 'title')) {
            $template->Title = $this->stringParam($body, 'title', 150);
        }
        if ($this->has($body, 'shiftType')) {
            $type = $this->stringParam($body, 'shiftType', 20);
            if (!array_key_exists($type, Shift::TYPE_LABELS)) {
                throw BadRequestException::because('Unbekannte Dienstart.');
            }
            $template->DefaultShiftType = $type;
        }
        if ($this->has($body, 'weekdays')) {
            $template->Weekdays = $body['weekdays'];
        }
        if ($this->has($body, 'defaultCaregiverId')) {
            $id = (int) ($body['defaultCaregiverId'] ?? 0);
            if ($id > 0 && Caregiver::get()->byID($id) === null) {
                throw NotFoundException::for('Pflegekraft', $id);
            }
            $template->DefaultCaregiverID = max(0, $id);
        }
        if ($this->has($body, 'isActive')) {
            $template->IsActive = $this->boolParam($body, 'isActive');
        }
    }

    /** @return array<string, mixed> */
    private function summary(TourTemplate $template): array
    {
        $items = $template->Items()->sort('SortOrder');
        $first = $items->first();

        return [
            'id' => (int) $template->ID,
            'title' => (string) $template->Title,
            'shiftType' => (string) $template->DefaultShiftType,
            'weekdays' => Weekdays::parse($template->Weekdays),
            'weekdaysLabel' => $template->getWeekdaysLabel(),
            'defaultCaregiverId' => (int) $template->DefaultCaregiverID ?: null,
            'defaultCaregiverName' => $template->DefaultCaregiverID ? $template->DefaultCaregiver()->getTitle() : null,
            'isActive' => (bool) $template->IsActive,
            'stopCount' => $items->count(),
            'start' => $first !== null ? Clock::short($first->PlannedStart) : null,
            'estimatedMinutes' => (int) $template->EstimatedDurationMinutes,
        ];
    }

    /** @return array<string, mixed> */
    private function detail(TourTemplate $template): array
    {
        return $this->summary($template) + [
            'items' => array_map(static fn (TourTemplateItem $i): array => [
                'id' => (int) $i->ID,
                'patientId' => (int) $i->PatientID,
                'patientName' => $i->Patient()->getTitle(),
                'address' => $i->Patient()->getAddressLine(),
                'slot' => (string) $i->Slot,
                'plannedStart' => Clock::short($i->PlannedStart),
                'durationMinutes' => (int) $i->DurationMinutes,
            ], $template->Items()->sort('SortOrder')->toArray()),
        ];
    }
}
