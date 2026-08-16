<?php

namespace ServerSuite\Exceptions;

use RuntimeException;

/**
 * Raised by the PanelServerOps backup methods.
 *
 * The ->reason code lets the rotation policy engine branch without catching
 * panel-internal exception types directly (which would re-introduce the panel
 * coupling the seam exists to remove). The classifier inspects the panel
 * exception by fully-qualified class name, so this file never imports a
 * Pterodactyl class and the package stays installable without the panel.
 *
 * Reason codes:
 *   locked         — the backup is pinned (is_locked); the panel refused delete.
 *   limit_reached  — server is at its backup_limit; the panel refused create.
 *   throttled      — backup rate throttle hit (e.g. 2 per 600s); back off.
 *   not_found      — no backup with that uuid belongs to that server.
 *   panel_error    — any other panel-side failure.
 *   unavailable    — panel backup classes are missing in this environment.
 */
class BackupException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $reason = 'panel_error',
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }

    public static function locked(?\Throwable $previous = null): self
    {
        return new self(
            'The backup is locked (marked keep-forever) and cannot be deleted.',
            'locked',
            $previous,
        );
    }

    public static function limitReached(?\Throwable $previous = null): self
    {
        return new self(
            'The server has reached its backup limit and the panel refused to '
            . 'create a new backup.',
            'limit_reached',
            $previous,
        );
    }

    public static function throttled(?\Throwable $previous = null): self
    {
        return new self(
            'The backup rate throttle was hit; the operation must be retried later.',
            'throttled',
            $previous,
        );
    }

    public static function notFound(string $serverUuid, string $backupUuid): self
    {
        return new self(
            "Backup {$backupUuid} was not found for server {$serverUuid}.",
            'not_found',
        );
    }

    public static function unavailable(string $detail): self
    {
        return new self(
            "Panel backup services are not available in this environment: {$detail}",
            'unavailable',
        );
    }

    public static function panelError(string $detail, ?\Throwable $previous = null): self
    {
        return new self(
            "The panel could not complete the backup operation: {$detail}",
            'panel_error',
            $previous,
        );
    }

    /**
     * Classify an arbitrary panel-thrown exception into a typed BackupException.
     *
     * Matching is by class name string so no panel class needs importing.
     * Known Pterodactyl backup exceptions (verified, see handoff probe table):
     *   Pterodactyl\Exceptions\Service\Backup\BackupLockedException
     *   Pterodactyl\Exceptions\Service\Backup\TooManyBackupsException
     */
    public static function fromPanel(\Throwable $e): self
    {
        $class = get_class($e);

        if (str_contains($class, 'BackupLockedException')) {
            return self::locked($e);
        }

        if (str_contains($class, 'TooManyBackupsException')) {
            return self::limitReached($e);
        }

        // Symfony / Laravel rate-limit exceptions surface as 429s.
        if (str_contains($class, 'TooManyRequests') || $e->getCode() === 429) {
            return self::throttled($e);
        }

        return self::panelError($e->getMessage(), $e);
    }
}
