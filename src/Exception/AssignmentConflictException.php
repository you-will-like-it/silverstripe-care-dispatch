<?php

declare(strict_types=1);

namespace YouWillLikeIT\CareDispatch\Exception;

use YouWillLikeIT\CareDispatch\Domain\Violation;

/**
 * Only soft violations: the client must confirm with force=true and a reason (HTTP 409).
 */
final class AssignmentConflictException extends CareDispatchException
{
    /**
     * @param Violation[] $violations
     */
    public function __construct(array $violations)
    {
        parent::__construct('CONFIRMATION_REQUIRED', 'Die Zuweisung erzeugt Warnungen und muss bestätigt werden.', $violations);
    }

    public function httpStatus(): int
    {
        return 409;
    }
}
