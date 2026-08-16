<?php

namespace ServerSuite\Exceptions;

use RuntimeException;

class DeliveryException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $reason = 'unknown',
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function noDownloadUrl(string $modName): self
    {
        return new self(
            "Mod \"{$modName}\" has no public download URL (the author has disabled "
            . "third-party downloads). It must be installed manually.",
            'no_download_url',
        );
    }

    public static function daemonError(string $detail, ?\Throwable $previous = null): self
    {
        return new self(
            "The server daemon could not complete the file operation: {$detail}",
            'daemon_error',
            $previous,
        );
    }

    public static function verificationFailed(string $detail): self
    {
        return new self(
            "The downloaded file failed verification: {$detail}",
            'verification_failed',
        );
    }
}
