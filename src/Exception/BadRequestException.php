<?php

declare(strict_types=1);

namespace YouWillLikeIT\CareDispatch\Exception;

final class BadRequestException extends CareDispatchException
{
    public static function because(string $message): self
    {
        return new self('BAD_REQUEST', $message);
    }

    public function httpStatus(): int
    {
        return 400;
    }
}
