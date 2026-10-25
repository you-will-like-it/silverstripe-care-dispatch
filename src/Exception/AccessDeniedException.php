<?php

declare(strict_types=1);

namespace YouWillLikeIT\CareDispatch\Exception;

final class AccessDeniedException extends CareDispatchException
{
    public static function because(string $message): self
    {
        return new self('ACCESS_DENIED', $message);
    }

    public function httpStatus(): int
    {
        return 403;
    }
}
