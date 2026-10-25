<?php

declare(strict_types=1);

namespace YouWillLikeIT\CareDispatch\Control\Api\Admin;

use YouWillLikeIT\CareDispatch\Control\Api\JsonApiController;
use YouWillLikeIT\CareDispatch\Domain\Clock;
use YouWillLikeIT\CareDispatch\Exception\BadRequestException;
use YouWillLikeIT\CareDispatch\Security\CareDispatchPermissions;

/**
 * Base for the frontend administration APIs (/api/admin/*). Any back-office permission
 * opens the controller; each action checks its specific permission.
 */
abstract class AdminApiController extends JsonApiController
{
    private static array $required_permission = [
        CareDispatchPermissions::DISPATCH,
        CareDispatchPermissions::MASTERDATA,
        CareDispatchPermissions::CAREPLAN,
        CareDispatchPermissions::ROSTER,
        CareDispatchPermissions::STAFF,
        CareDispatchPermissions::AUDIT,
        CareDispatchPermissions::SETTINGS,
    ];

    /** @param array<string, mixed> $body */
    protected function dateParam(array $body, string $key, bool $required = true): ?string
    {
        $value = $body[$key] ?? null;
        if ($value === null || $value === '') {
            if ($required) {
                throw BadRequestException::because(sprintf('Parameter "%s" fehlt.', $key));
            }

            return null;
        }

        return Clock::parseDate((string) $value);
    }

    /** @param array<string, mixed> $body */
    protected function timeParam(array $body, string $key): string
    {
        $value = $body[$key] ?? '';
        if (!is_string($value) || $value === '') {
            throw BadRequestException::because(sprintf('Parameter "%s" fehlt.', $key));
        }

        return Clock::parseTime($value);
    }

    /**
     * @param array<string, mixed> $body
     * @return int[]
     */
    protected function idList(array $body, string $key): array
    {
        $value = $body[$key] ?? [];
        if (!is_array($value)) {
            throw BadRequestException::because(sprintf('Parameter "%s" muss eine Liste sein.', $key));
        }

        return array_values(array_unique(array_filter(array_map('intval', $value), static fn (int $id): bool => $id > 0)));
    }

    /** @param array<string, mixed> $body */
    protected function has(array $body, string $key): bool
    {
        return array_key_exists($key, $body);
    }
}
