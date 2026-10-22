<?php

namespace Pterodactyl\Services\Acl\Api;

/** Test stand-in for the panel's AdminAcl: the constants, and its bit check. */
class AdminAcl
{
    public const NONE = 0;
    public const READ = 1;
    public const WRITE = 2;
    public const RESOURCE_SERVERS = 'servers';

    /** What the test's API key holds, resource => bits (the panel keeps r_<resource> columns). */
    public static array $key = ['servers' => 3];

    public static function check(string $resource, int $action): bool
    {
        return ((self::$key[$resource] ?? 0) & $action) > 0;
    }
}
