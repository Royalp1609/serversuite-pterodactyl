<?php

namespace ServerSuite\Support;

use ServerSuite\Exceptions\DeliveryException;
use ServerSuite\Support\Data\ModVersion;

/**
 * The exact version a user picked, never a different one in its place
 * (#138). Before, a pick outside the list filtered by the server's loader and
 * game version was quietly replaced by the latest compatible build.
 */
final class ChosenVersion
{
    /**
     * @throws DeliveryException when the pick is for another loader or no longer exists
     */
    public static function resolve($driver, string $modId, string $versionId, ?string $loader, ?string $gameVersion, string $modName): ModVersion
    {
        if ($versionId === '') {
            return $driver->latestVersion($modId, $loader, $gameVersion)
                ?? throw DeliveryException::daemonError("No compatible version found for {$modName}.");
        }

        foreach ($driver->versions($modId, $loader, $gameVersion) as $v) {
            if ($v->versionId === $versionId) {
                return $v;
            }
        }

        // Outside the filtered list: the version list shows every build, so
        // the user may have picked one tagged for another game version on
        // purpose. Keep it if it is built for this server's loader.
        foreach ($driver->versions($modId) as $v) {
            if ($v->versionId !== $versionId) {
                continue;
            }
            if (LoaderCompat::runs($loader, $v->loaders)) {
                return $v;
            }

            throw DeliveryException::daemonError(sprintf(
                '%s %s is built for %s, and this server runs %s. Pick a version built for %s.',
                $modName, $v->versionNumber, implode(', ', array_map('ucfirst', $v->loaders)), ucfirst((string) $loader), ucfirst((string) $loader),
            ));
        }

        throw DeliveryException::daemonError("The version of {$modName} you picked is no longer available. Pick another version.");
    }
}
