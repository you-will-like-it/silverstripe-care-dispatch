<?php

declare(strict_types=1);

namespace YouWillLikeIT\CareDispatch\Exception;

/** A business rule forbids the action (HTTP 422). */
final class DomainRuleException extends CareDispatchException
{
    public function httpStatus(): int
    {
        return 422;
    }
}
