<?php

use ServerSuite\Exceptions\BackupException;
use ServerSuite\Services\BackupRetentionResolver;
use ServerSuite\Services\BackupRotationService;
use ServerSuite\Support\Data\BackupRef;
use ServerSuite\Tests\Fakes\FakePanelServerOps;

function rotationService(FakePanelServerOps $ops): BackupRotationService
{
    return new BackupRotationService($ops, new BackupRetentionResolver());
}

/** Seed N successful, completed, unlocked backups oldest-first (c1..cN). */
function seedSuccessful(FakePanelServerOps $ops, string $uuid, int $n): void
{
    $specs = [];
    for ($i = 1; $i <= $n; $i++) {
        $specs[] = [
            'uuid'      => "c{$i}",
            'completed' => sprintf('2026-06-%02dT00:00:00+00:00', $i),
            'successful'=> true,
            'locked'    => false,
        ];
    }
    $ops->seed($uuid, $specs);
}

function createCount(FakePanelServerOps $ops): int
{
    return count(array_filter($ops->log, fn ($e) => $e[0] === 'create'));
}

// ---------------------------------------------------------------------------

it('creates then trims the oldest when at the keep target below the panel limit', function () {
    $ops = new FakePanelServerOps();
    $ops->setLimit('s', 10);
    seedSuccessful($ops, 's', 3);          // c1 < c2 < c3

    $result = rotationService($ops)->rotate('s', keepOverride: 3);

    expect($result->isRotated())->toBeTrue();
    expect($result->created)->toBeInstanceOf(BackupRef::class);
    expect($result->evictedCount())->toBe(1);
    expect($result->evicted[0]->uuid)->toBe('c1');            // oldest evicted
    // remaining completed-successful: c2, c3 (new one is in progress)
    expect($ops->successfulCount('s'))->toBe(2);
});

it('evicts BEFORE create when the server is at the hard panel limit', function () {
    $ops = new FakePanelServerOps();
    $ops->setLimit('s', 3);
    seedSuccessful($ops, 's', 3);          // exactly at limit

    $result = rotationService($ops)->rotate('s', keepOverride: 5); // capped to 3

    expect($result->isRotated())->toBeTrue();
    expect($result->effectiveKeep)->toBe(3);
    expect($result->evicted[0]->uuid)->toBe('c1');
    expect(createCount($ops))->toBe(1);
    // c2, c3 remain + one in-progress = 3 total, never exceeding the limit
    expect($ops->listBackups('s'))->toHaveCount(3);
});

it('refuses to evict when at the limit and every backup is locked', function () {
    $ops = new FakePanelServerOps();
    $ops->setLimit('s', 3);
    $ops->seed('s', [
        ['uuid' => 'c1', 'completed' => '2026-06-01T00:00:00+00:00', 'locked' => true],
        ['uuid' => 'c2', 'completed' => '2026-06-02T00:00:00+00:00', 'locked' => true],
        ['uuid' => 'c3', 'completed' => '2026-06-03T00:00:00+00:00', 'locked' => true],
    ]);

    $result = rotationService($ops)->rotate('s', keepOverride: 3);

    expect($result->isSkipped())->toBeTrue();
    expect($result->reason)->toBe('no_evictable_at_limit');
    expect(createCount($ops))->toBe(0);                       // nothing created
});

it('never evicts a locked backup during trim, picking the oldest UNLOCKED', function () {
    $ops = new FakePanelServerOps();
    $ops->setLimit('s', 10);
    $ops->seed('s', [
        ['uuid' => 'c1', 'completed' => '2026-06-01T00:00:00+00:00', 'locked' => true],
        ['uuid' => 'c2', 'completed' => '2026-06-02T00:00:00+00:00', 'locked' => false],
        ['uuid' => 'c3', 'completed' => '2026-06-03T00:00:00+00:00', 'locked' => false],
    ]);

    $result = rotationService($ops)->rotate('s', keepOverride: 2);

    expect($result->isRotated())->toBeTrue();
    expect($result->evicted[0]->uuid)->toBe('c2');           // oldest UNLOCKED, not c1
    $uuids = array_map(fn (BackupRef $b) => $b->uuid, $ops->listBackups('s'));
    expect($uuids)->toContain('c1');                          // locked one survives
});

it('never evicts the only successful backup at keep=1', function () {
    $ops = new FakePanelServerOps();
    $ops->setLimit('s', 10);
    seedSuccessful($ops, 's', 1);

    $result = rotationService($ops)->rotate('s', keepOverride: 1);

    expect($result->isRotated())->toBeTrue();
    expect($result->evictedCount())->toBe(0);                // last good backup kept
    expect($ops->successfulCount('s'))->toBe(1);
});

it('skips when backups are disabled (limit 0)', function () {
    $ops = new FakePanelServerOps();
    $ops->setLimit('s', 0);

    $result = rotationService($ops)->rotate('s', keepOverride: 5);

    expect($result->isSkipped())->toBeTrue();
    expect($result->reason)->toBe('backups_disabled');
});

it('skips when a backup is already in progress', function () {
    $ops = new FakePanelServerOps();
    $ops->setLimit('s', 10);
    $ops->seed('s', [
        ['uuid' => 'running', 'completed' => null, 'successful' => false],
    ]);

    $result = rotationService($ops)->rotate('s', keepOverride: 3);

    expect($result->isSkipped())->toBeTrue();
    expect($result->reason)->toBe('backup_in_progress');
    expect(createCount($ops))->toBe(0);
});

it('defers the trim (not the create) when the throttle is hit mid-cycle', function () {
    $ops = new FakePanelServerOps();
    $ops->setLimit('s', 10);
    seedSuccessful($ops, 's', 3);
    $ops->throttleAfter = 1;                 // op #1 (create) ok, op #2 (delete) throttled

    $result = rotationService($ops)->rotate('s', keepOverride: 2);

    expect($result->isRotated())->toBeTrue(); // create preserved
    expect($result->evictedCount())->toBe(0); // trim deferred to next cycle
    expect(createCount($ops))->toBe(1);
});

it('runs the limit_reached backstop: make room once and retry the create', function () {
    $ops = new class extends FakePanelServerOps {
        public int $createCalls = 0;

        public function createBackup(string $serverUuid, ?string $name = null, ?array $ignoredFiles = null): BackupRef
        {
            $this->createCalls++;
            if ($this->createCalls === 1) {
                throw BackupException::limitReached();   // simulated race
            }

            return parent::createBackup($serverUuid, $name, $ignoredFiles);
        }
    };
    $ops->setLimit('s', 10);
    seedSuccessful($ops, 's', 3);

    $result = rotationService($ops)->rotate('s', keepOverride: 10);

    expect($result->isRotated())->toBeTrue();
    expect($ops->createCalls)->toBe(2);                      // retried once
    expect($result->evicted[0]->uuid)->toBe('c1');           // made room with oldest
});

it('serialises a result to array', function () {
    $ops = new FakePanelServerOps();
    $ops->setLimit('s', 10);
    seedSuccessful($ops, 's', 3);

    $array = rotationService($ops)->rotate('s', keepOverride: 3)->toArray();

    expect($array['status'])->toBe('rotated');
    expect($array['effective_keep'])->toBe(3);
    expect($array['created'])->toBeArray();
    expect($array['evicted'])->toHaveCount(1);
});

it('formats the backup name in the configured backup timezone', function () {
    \Illuminate\Support\Carbon::setTestNow('2026-06-28 14:38:00'); // UTC app tz
    config()->set('serversuite.backups.timezone', 'America/New_York');

    $ops = new FakePanelServerOps();
    $ops->setLimit('s', 10);
    $result = rotationService($ops)->rotate('s', keepOverride: 5);

    // 14:38 UTC -> 10:38 America/New_York (EDT, -4)
    expect($result->created->name)->toBe('Auto 2026-06-28 10:38');

    \Illuminate\Support\Carbon::setTestNow();
});

it('falls back to the app timezone for the name when none is configured', function () {
    \Illuminate\Support\Carbon::setTestNow('2026-06-28 14:38:00');
    config()->set('serversuite.backups.timezone', null);

    $ops = new FakePanelServerOps();
    $ops->setLimit('s', 10);
    $result = rotationService($ops)->rotate('s', keepOverride: 5);

    expect($result->created->name)->toBe('Auto 2026-06-28 14:38');

    \Illuminate\Support\Carbon::setTestNow();
});

it('does not break rotation when the backup timezone is invalid', function () {
    config()->set('serversuite.backups.timezone', 'Not/AZone');

    $ops = new FakePanelServerOps();
    $ops->setLimit('s', 10);
    $result = rotationService($ops)->rotate('s', keepOverride: 5);

    // Still rotates; name falls back to default formatting (prefix + a timestamp).
    expect($result->isRotated())->toBeTrue();
    expect($result->created->name)->toStartWith('Auto ');
});

it('protects a backup from eviction once it is locked via the seam', function () {
    $ops = new FakePanelServerOps();
    $ops->setLimit('s', 10);
    seedSuccessful($ops, 's', 3);              // c1 < c2 < c3, all unlocked

    // Lock the oldest — it must no longer be the eviction target.
    $ops->setBackupLocked('s', 'c1', true);

    $result = rotationService($ops)->rotate('s', keepOverride: 2);

    expect($result->isRotated())->toBeTrue();
    expect($result->evicted[0]->uuid)->toBe('c2');   // oldest UNLOCKED, not c1
    $uuids = array_map(fn ($b) => $b->uuid, $ops->listBackups('s'));
    expect($uuids)->toContain('c1');                 // locked one survived
});

it('unlocks a backup so it becomes evictable again', function () {
    $ops = new FakePanelServerOps();
    $ops->setLimit('s', 10);
    seedSuccessful($ops, 's', 2);              // c1 < c2
    $ops->setBackupLocked('s', 'c1', true);
    $ops->setBackupLocked('s', 'c1', false);   // unlock again

    $result = rotationService($ops)->rotate('s', keepOverride: 2);

    expect($result->evicted[0]->uuid)->toBe('c1');   // oldest, now evictable
});

it('defaults retention to the server backup limit when no override is given', function () {
    $ops = new FakePanelServerOps();
    $ops->setLimit('s', 3);            // panel admin sets the count via the limit
    seedSuccessful($ops, 's', 3);      // at the limit

    $result = rotationService($ops)->rotate('s');   // no override (UI / scheduler path)

    expect($result->effectiveKeep)->toBe(3);          // = limit, NOT the config default (5)
    expect($result->evicted)->toHaveCount(1);         // at limit → evict oldest to make room
    expect($result->evicted[0]->uuid)->toBe('c1');
});

it('still honours an explicit CLI override, capped to the limit', function () {
    $ops = new FakePanelServerOps();
    $ops->setLimit('s', 10);
    seedSuccessful($ops, 's', 4);

    // override below the limit takes effect...
    $r1 = rotationService($ops)->rotate('s', keepOverride: 2);
    expect($r1->effectiveKeep)->toBe(2);

    // ...and an override above the limit is capped to it.
    $ops2 = new FakePanelServerOps();
    $ops2->setLimit('s', 5);
    seedSuccessful($ops2, 's', 2);
    $r2 = rotationService($ops2)->rotate('s', keepOverride: 99);
    expect($r2->effectiveKeep)->toBe(5);
});

it('backupNow uses a verbatim custom name and never the Auto prefix', function () {
    $ops = new FakePanelServerOps();
    $ops->setLimit('s', 10);
    seedSuccessful($ops, 's', 1);

    $result = rotationService($ops)->backupNow('s', 'Before mod update');

    expect($result->created->name)->toBe('Before mod update');
});

it('backupNow falls back to a Manual-prefixed name when none is given', function () {
    $ops = new FakePanelServerOps();
    $ops->setLimit('s', 10);

    $result = rotationService($ops)->backupNow('s', null);

    expect($result->created->name)->toStartWith('Manual ');
    expect($result->created->name)->not->toStartWith('Auto');
});

it('renames a backup via the seam', function () {
    $ops = new FakePanelServerOps();
    $ops->setLimit('s', 10);
    seedSuccessful($ops, 's', 1);

    $ops->renameBackup('s', 'c1', 'Renamed snapshot');

    $names = array_map(fn ($b) => $b->name, $ops->listBackups('s'));
    expect($names)->toContain('Renamed snapshot');
});

// ---- #129 / #130 -----------------------------------------------------------

it('does not delete a backup to make room when the create would be throttled (#129)', function () {
    $ops = new FakePanelServerOps();
    $ops->setLimit('s', 3);
    seedSuccessful($ops, 's', 3);
    $ops->throttleWait = 240;

    $result = rotationService($ops)->rotate('s');

    expect($result->isSkipped())->toBeTrue()->and($result->reason)->toBe('throttled')
        ->and($ops->listBackups('s'))->toHaveCount(3)
        ->and(array_filter($ops->log, fn ($e) => $e[0] === 'delete'))->toBe([]);
});

it('ignores a backup stuck in progress for longer than the stale limit (#129)', function () {
    $ops = new FakePanelServerOps();
    $ops->setLimit('s', 10);
    seedSuccessful($ops, 's', 2);
    $ops->seed('s', [['uuid' => 'stuck', 'successful' => false, 'created' => now()->subHours(30)->toIso8601String()]]);

    expect(rotationService($ops)->rotate('s')->isRotated())->toBeTrue();

    // One that started recently still blocks.
    $ops->seed('s', [['uuid' => 'running', 'successful' => false, 'created' => now()->subMinutes(10)->toIso8601String()]]);
    expect(rotationService($ops)->rotate('s')->reason)->toBe('backup_in_progress');
});

it('a backup asked for without the delete permission never deletes one (#130)', function () {
    $ops = new FakePanelServerOps();
    $ops->setLimit('s', 3);
    seedSuccessful($ops, 's', 3);

    $at = rotationService($ops)->backupNow('s', 'Before a change', mayEvict: false);
    expect($at->isSkipped())->toBeTrue()->and($at->reason)->toBe('at_limit_needs_delete')
        ->and($at->message)->toContain('permission to delete backups')
        ->and($ops->listBackups('s'))->toHaveCount(3)
        ->and(createCount($ops))->toBe(0);

    // Below the limit it backs up, and leaves any trimming to someone who may delete.
    $ops->setLimit('s', 10);
    $below = rotationService($ops)->rotate('s', keepOverride: 3, mayEvict: false);
    expect($below->isRotated())->toBeTrue()->and($below->evictedCount())->toBe(0)
        ->and($ops->successfulCount('s'))->toBe(3);
});

it('with the delete permission a manual backup at the limit still makes room (#130)', function () {
    $ops = new FakePanelServerOps();
    $ops->setLimit('s', 3);
    seedSuccessful($ops, 's', 3);

    $r = rotationService($ops)->backupNow('s', 'Manual', mayEvict: true);
    expect($r->isRotated())->toBeTrue()->and($r->evicted[0]->uuid)->toBe('c1');
});
