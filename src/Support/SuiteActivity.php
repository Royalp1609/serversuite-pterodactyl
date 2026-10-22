<?php

namespace ServerSuite\Support;

/**
 * Writes Server Suite actions to Pterodactyl's activity log (#19), so they
 * show in the server's Activity tab like the panel's own. The actor is the
 * request's user (Pterodactyl fills it in); pass one for work done outside
 * a request, such as the billing API acting as its key's owner. Guarded:
 * logging never breaks the feature that logs.
 */
final class SuiteActivity
{
    private const FACADE = '\\Pterodactyl\\Facades\\Activity';
    private const SERVER = '\\Pterodactyl\\Models\\Server';

    /** @param array<string, mixed> $properties */
    public static function log(object $server, string $event, array $properties = [], ?object $actor = null): void
    {
        try {
            if (! class_exists(self::FACADE)) {
                return;
            }
            $facade = self::FACADE;
            $entry = $facade::event($event)->subject($server)->property($properties);
            if ($actor !== null) {
                $entry->actor($actor);
            }
            $entry->log();
        } catch (\Throwable $e) {
            logger()->debug('[Server Suite] activity not logged', ['event' => $event, 'error' => $e->getMessage()]);
        }
    }

    /** @param array<string, mixed> $properties */
    public static function logFor(string $serverUuid, string $event, array $properties = [], ?object $actor = null): void
    {
        try {
            $model = self::SERVER;
            $server = class_exists($model) ? $model::query()->where('uuid', $serverUuid)->first() : null;
            if ($server !== null) {
                self::log($server, $event, $properties, $actor);
            }
        } catch (\Throwable) {
        }
    }
}
