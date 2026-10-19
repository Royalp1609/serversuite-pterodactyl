<?php

use Carbon\CarbonImmutable;
use ServerSuite\Support\BackupSchedule;

/** #4: scheduled backups at a fixed hour of the day. */

function at(string $when): CarbonImmutable
{
    return CarbonImmutable::parse($when, 'UTC');
}

/**
 * Simulate the 15-minute scheduler tick from $from for $hours, recording
 * each run (and advancing last-run the way RotateBackupsCommand does).
 *
 * @return string[] run times as 'D H:i'
 */
function simulateTicks(int $interval, ?int $anchor, string $from, int $hours, ?string $lastRun = null): array
{
    $last = $lastRun ? at($lastRun) : null;
    $runs = [];
    for ($t = at($from), $end = at($from)->addHours($hours); $t->lt($end); $t = $t->addMinutes(15)) {
        if (BackupSchedule::isDue($interval, $anchor, $last, $t)) {
            $runs[] = $t->format('D H:i');
            $last = $t;
        }
    }

    return $runs;
}

it('keeps the drifting behaviour when no hour is set', function () {
    expect(BackupSchedule::isDue(24, null, null, at('2026-10-20 14:07')))->toBeTrue();
    expect(BackupSchedule::isDue(24, null, at('2026-10-19 14:07'), at('2026-10-20 14:00')))->toBeFalse();
    expect(BackupSchedule::isDue(24, null, at('2026-10-19 14:07'), at('2026-10-20 14:15')))->toBeTrue();
});

it('runs a daily schedule once, in the chosen hour', function () {
    // Two days of ticks, starting mid-afternoon with a drifted last run.
    expect(simulateTicks(24, 4, '2026-10-19 15:00', 48, '2026-10-19 14:07'))
        ->toBe(['Tue 04:00', 'Wed 04:00']);
});

it('only fires on the first tick of the hour', function () {
    $last = at('2026-10-20 04:00');
    foreach (['04:15', '04:30', '04:45'] as $hm) {
        expect(BackupSchedule::isDue(24, 4, $last, at("2026-10-20 $hm")))->toBeFalse();
    }
});

it('switching to a fixed hour takes effect at the next slot, not a day later', function () {
    // Daily backup last ran at 14:07; the owner then sets 04:00. The next run
    // is tomorrow 04:00 (14h later), not the day after (38h). The old
    // (interval - 1h) guard skipped it.
    expect(BackupSchedule::isDue(24, 4, at('2026-10-19 14:07'), at('2026-10-20 04:00')))->toBeTrue();
    // Weekly: a run 6.5 days earlier at 14:07 still allows next Monday's 03:00.
    expect(BackupSchedule::isDue(168, 3, at('2026-10-19 14:07'), at('2026-10-26 03:00')))->toBeTrue();
});

it('tolerates a late tick without skipping the next day', function () {
    // Yesterday's run happened at 04:50; today's 04:00 tick must still fire.
    expect(BackupSchedule::isDue(24, 4, at('2026-10-19 04:50'), at('2026-10-20 04:00')))->toBeTrue();
});

it('never runs a weekly schedule on day six, even after a late tick', function () {
    expect(BackupSchedule::isDue(168, 3, at('2026-10-19 03:59'), at('2026-10-25 03:00')))->toBeFalse();
    expect(BackupSchedule::isDue(168, 3, at('2026-10-19 03:59'), at('2026-10-26 03:00')))->toBeTrue();
});

it('runs a 2-day schedule every other day', function () {
    expect(simulateTicks(48, 1, '2026-10-19 00:00', 24 * 6, '2026-10-18 23:00'))
        ->toBe(['Tue 01:00', 'Thu 01:00', 'Sat 01:00']);
});

it('runs a weekly schedule once a week, not every day at that hour', function () {
    // Last (drifted) run Mon 02:00; the first 03:00 slot at least 145h later
    // is Sun 25 Oct, then every Sunday: once a week, nothing in between.
    expect(simulateTicks(168, 3, '2026-10-19 00:00', 24 * 15, '2026-10-19 02:00'))
        ->toBe(['Sun 03:00', 'Sun 03:00']);
});

it('runs a 6-hour schedule on hours in step with the anchor', function () {
    expect(simulateTicks(6, 2, '2026-10-20 00:00', 24))
        ->toBe(['Tue 02:00', 'Tue 08:00', 'Tue 14:00', 'Tue 20:00']);
});

it('runs a first anchored backup in the next matching hour, not immediately', function () {
    expect(BackupSchedule::isDue(24, 4, null, at('2026-10-20 15:00')))->toBeFalse();
    expect(simulateTicks(24, 4, '2026-10-20 15:00', 24))->toBe(['Wed 04:00']);
});

it('reports the next run for the Backups screen', function () {
    $now = at('2026-10-20 15:20');

    expect(BackupSchedule::nextRun(24, 4, at('2026-10-20 04:00'), $now)?->format('Y-m-d H:i'))->toBe('2026-10-21 04:00');
    expect(BackupSchedule::nextRun(6, 2, at('2026-10-20 14:00'), $now)?->format('Y-m-d H:i'))->toBe('2026-10-20 20:00');
    expect(BackupSchedule::nextRun(168, 3, at('2026-10-19 03:00'), $now)?->format('Y-m-d H:i'))->toBe('2026-10-26 03:00');
    expect(BackupSchedule::nextRun(12, null, at('2026-10-20 09:10'), $now)?->format('Y-m-d H:i'))->toBe('2026-10-20 21:10');
    expect(BackupSchedule::nextRun(12, null, null, $now))->toBeNull();
});
