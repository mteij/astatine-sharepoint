<?php

declare(strict_types=1);

namespace App\Service;

use RuntimeException;

final class GraphException extends RuntimeException
{
    public function __construct(string $message, private readonly int $status)
    {
        parent::__construct($message, $status);
    }

    public function status(): int
    {
        return $this->status;
    }

    public function isUnauthorized(): bool
    {
        return $this->status === 401;
    }

    public function isNotFound(): bool
    {
        return $this->status === 404;
    }
}
