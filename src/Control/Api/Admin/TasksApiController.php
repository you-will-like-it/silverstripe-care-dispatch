<?php

declare(strict_types=1);

namespace YouWillLikeIT\CareDispatch\Control\Api\Admin;

use SilverStripe\Control\HTTPRequest;
use SilverStripe\Control\HTTPResponse;
use YouWillLikeIT\CareDispatch\Domain\QualificationLevel;
use YouWillLikeIT\CareDispatch\Exception\BadRequestException;
use YouWillLikeIT\CareDispatch\Exception\DomainRuleException;
use YouWillLikeIT\CareDispatch\Exception\NotFoundException;
use YouWillLikeIT\CareDispatch\Model\CarePlanItem;
use YouWillLikeIT\CareDispatch\Model\CareTask;
use YouWillLikeIT\CareDispatch\Model\VisitTask;
use YouWillLikeIT\CareDispatch\Security\CareDispatchPermissions as P;
use YouWillLikeIT\CareDispatch\Service\Transaction;

/**
 * /api/admin/tasks – Leistungskatalog.
 */
class TasksApiController extends AdminApiController
{
    private static array $url_handlers = [
        'GET list' => 'listTasks',
        'POST create' => 'createTask',
        'POST $ID!/delete' => 'deleteTask',
        'POST $ID!' => 'updateTask',
    ];

    private static array $allowed_actions = ['listTasks', 'createTask', 'deleteTask', 'updateTask'];

    public function listTasks(HTTPRequest $request): HTTPResponse
    {
        return $this->handle(function (): array {
            $usage = [];
            foreach (CarePlanItem::get() as $item) {
                $usage[(int) $item->CareTaskID] = ($usage[(int) $item->CareTaskID] ?? 0) + 1;
            }

            return [
                'tasks' => array_map(fn (CareTask $t): array => $this->present($t, $usage[(int) $t->ID] ?? 0), CareTask::get()->sort('Code')->toArray()),
                'categories' => CareTask::CATEGORY_LABELS,
                'qualifications' => QualificationLevel::options(false),
            ];
        });
    }

    public function createTask(HTTPRequest $request): HTTPResponse
    {
        return $this->handle(function (): array {
            $this->requirePermission(P::MASTERDATA);
            $task = CareTask::create();
            Transaction::run(function () use ($task): void {
                $this->apply($task, $this->body());
                $task->write();
            });

            return ['task' => $this->present($task, 0)];
        }, 201);
    }

    public function updateTask(HTTPRequest $request): HTTPResponse
    {
        return $this->handle(function () use ($request): array {
            $this->requirePermission(P::MASTERDATA);
            $task = $this->load($request);
            Transaction::run(function () use ($task): void {
                $this->apply($task, $this->body());
                $task->write();
            });

            return ['task' => $this->present($task, CarePlanItem::get()->filter('CareTaskID', $task->ID)->count())];
        });
    }

    /** Only unused tasks can be deleted; used ones are deactivated. */
    public function deleteTask(HTTPRequest $request): HTTPResponse
    {
        return $this->handle(function () use ($request): array {
            $this->requirePermission(P::MASTERDATA);
            $task = $this->load($request);
            $used = CarePlanItem::get()->filter('CareTaskID', $task->ID)->exists()
                || VisitTask::get()->filter('CareTaskID', $task->ID)->exists();
            if ($used) {
                throw new DomainRuleException('TASK_IN_USE', 'Die Leistung wird in Pflegeplänen oder dokumentierten Einsätzen verwendet. Bitte deaktivieren.');
            }
            Transaction::run(static fn () => $task->delete());

            return ['deleted' => true];
        });
    }

    private function load(HTTPRequest $request): CareTask
    {
        $id = $this->routeID($request);

        return CareTask::get()->byID($id) ?? throw NotFoundException::for('Leistung', $id);
    }

    /** @param array<string, mixed> $body */
    private function apply(CareTask $task, array $body): void
    {
        foreach (['code' => 'Code', 'title' => 'Title'] as $key => $field) {
            if ($this->has($body, $key)) {
                $task->$field = $this->stringParam($body, $key, 150);
            }
        }
        if ($this->has($body, 'category')) {
            $category = $this->stringParam($body, 'category', 20);
            if (!array_key_exists($category, CareTask::CATEGORY_LABELS)) {
                throw BadRequestException::because('Unbekannte Kategorie.');
            }
            $task->Category = $category;
        }
        if ($this->has($body, 'minQualification')) {
            $level = $this->stringParam($body, 'minQualification', 20);
            if (!array_key_exists($level, QualificationLevel::options(false))) {
                throw BadRequestException::because('Ungültige Mindestqualifikation.');
            }
            $task->MinQualification = $level;
        }
        if ($this->has($body, 'durationMinutes')) {
            $task->DurationMinutes = (int) $body['durationMinutes'];
        }
        if ($this->has($body, 'isActive')) {
            $task->IsActive = $this->boolParam($body, 'isActive');
        }
    }

    /** @return array<string, mixed> */
    private function present(CareTask $task, int $usage): array
    {
        return [
            'id' => (int) $task->ID,
            'code' => (string) $task->Code,
            'title' => (string) $task->Title,
            'category' => (string) $task->Category,
            'categoryLabel' => $task->getCategoryLabel(),
            'minQualification' => (string) $task->MinQualification,
            'minQualificationLabel' => $task->getMinQualificationLabel(),
            'durationMinutes' => (int) $task->DurationMinutes,
            'isActive' => (bool) $task->IsActive,
            'usage' => $usage,
        ];
    }
}
