<?php

namespace ServerSuite\Support;

use Illuminate\Support\Carbon;
use ServerSuite\Contracts\PanelServerOps;

/** The "is there a recent completed backup?" check the switches share. */
final class RecentBackup
{
    public static function exists(PanelServerOps $ops, string $serverUuid, int $withinMinutes = 60): bool
    {
        $cutoff = now()->subMinutes($withinMinutes);
        foreach ($ops->listBackups($serverUuid) as $b) {
            if (! $b->isSuccessful || $b->completedAt === null) {
                continue;
            }
            try {
                if (Carbon::parse($b->completedAt)->greaterThanOrEqualTo($cutoff)) {
                    return true;
                }
            } catch (\Throwable) {
                // unparseable timestamp: ignore this backup
            }
        }

        return false;
    }

    /** A backup started in the last $withinMinutes that hasn't finished yet. */
    public static function running(PanelServerOps $ops, string $serverUuid, int $withinMinutes = 60): ?\ServerSuite\Support\Data\BackupRef
    {
        $cutoff = now()->subMinutes($withinMinutes);
        foreach ($ops->listBackups($serverUuid) as $b) {
            if ($b->completedAt !== null || $b->createdAt === null) {
                continue;
            }
            try {
                if (Carbon::parse($b->createdAt)->greaterThanOrEqualTo($cutoff)) {
                    return $b;
                }
            } catch (\Throwable) {
            }
        }

        return null;
    }
}
