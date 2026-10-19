<?php

namespace ServerSuite\Support;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * When a server's scheduled backup is due. Pure, so it can be tested without
 * the scheduler; used by serversuite:rotate-backups (a 15-minute tick) and by
 * the Backups screen to show the next run.
 *
 * Two modes:
 *  - No fixed hour (anchor null): due once $interval hours have passed since
 *    the last run. The run time drifts with when rotation was enabled.
 *  - Fixed hour (anchor 0-23, panel timezone): daily and longer intervals run
 *    in the anchor hour; sub-daily intervals run on hours in phase with it
 *    (6h at 02 -> 02, 08, 14, 20). A slot fires when nothing has run in it yet
 *    (so only the first tick of the hour) AND at least (interval - 23h) has
 *    passed since the last run, which spaces 48h/weekly runs across days and
 *    tolerates a late tick. Switching an existing schedule to a fixed hour
 *    takes effect at the next slot instead of waiting a whole extra interval.
 *    If the scheduler misses the whole hour, that run waits for the next slot.
 */
class BackupSchedule
{
    public static function isDue(int $intervalHours, ?int $anchorHour, ?CarbonInterface $lastRun, CarbonInterface $now): bool
    {
        $interval = max(1, $intervalHours);

        if ($anchorHour === null) {
            return $lastRun === null || $lastRun->copy()->addHours($interval)->lte($now);
        }

        if (! self::inSlot($interval, $anchorHour, $now)) {
            return false;
        }

        if ($lastRun === null) {
            return true;
        }

        $slotStart = $now->copy()->startOfHour();

        return $lastRun->lt($slotStart)
            && $now->copy()->subHours(max(0, $interval - 23))->gte($lastRun);
    }

    /**
     * Start of the next hour in which isDue() would fire, or null with no
     * fixed hour and no previous run (due on the next tick).
     */
    public static function nextRun(int $intervalHours, ?int $anchorHour, ?CarbonInterface $lastRun, CarbonInterface $now): ?CarbonInterface
    {
        $interval = max(1, $intervalHours);

        if ($anchorHour === null) {
            return $lastRun?->copy()->addHours($interval);
        }

        // Walk hour slots from the current hour; the answer is always within
        // one interval plus a day.
        $slot = CarbonImmutable::instance($now)->startOfHour();
        for ($i = 0; $i <= $interval + 24; $i++, $slot = $slot->addHour()) {
            $at = $slot->lt($now) ? CarbonImmutable::instance($now) : $slot;
            if (self::isDue($interval, $anchorHour, $lastRun, $at)) {
                return $slot;
            }
        }

        return null;
    }

    private static function inSlot(int $interval, int $anchorHour, CarbonInterface $at): bool
    {
        $anchor = (($anchorHour % 24) + 24) % 24;

        return $interval < 24
            ? ((($at->hour - $anchor) % $interval) + $interval) % $interval === 0
            : $at->hour === $anchor;
    }
}
