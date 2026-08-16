<?php

namespace ServerSuite\Exceptions;

use RuntimeException;

class RepositoryException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $driver = '',
        public readonly int $statusCode = 0,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $statusCode, $previous);
    }

    public static function httpError(string $driver, int $status, string $body = ''): self
    {
        $preview = mb_substr($body, 0, 200);
        return new self(
            "HTTP {$status} from {$driver} repository: {$preview}",
            $driver,
            $status,
        );
    }

    public static function unavailable(string $driver): self
    {
        return new self(
            "Repository driver [{$driver}] is not available (missing API key or disabled).",
            $driver,
        );
    }
}
