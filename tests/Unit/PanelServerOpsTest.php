<?php

use ServerSuite\Exceptions\BackupException;
use ServerSuite\Support\Data\BackupRef;
use ServerSuite\Tests\Fakes\FakePanelServerOps;

// ---------------------------------------------------------------------------
// BackupRef DTO
// ---------------------------------------------------------------------------

it('round-trips a BackupRef through toArray', function () {
    $ref = new BackupRef(
        uuid: 'abc',
        name: 'nightly',
        bytes: 2048,
        isLocked: true,
        isSuccessful: true,
        createdAt: '2026-06-27T01:00:00+00:00',
        completedAt: '2026-06-27T01:05:00+00:00',
        id: 7,
    );

    expect($ref->toArray())->toBe([
        'uuid'          => 'abc',
        'name'          => 'nightly',
        'bytes'         => 2048,
        'is_locked'     => true,
        'is_successful' => true,
        'created_at'    => '2026-06-27T01:00:00+00:00',
        'completed_at'  => '2026-06-27T01:05:00+00:00',
        'id'            => 7,
    ]);
});

// ---------------------------------------------------------------------------
// BackupException classifier — by class-name string, no panel imports needed
// ---------------------------------------------------------------------------

it('classifies a locked backup exception', function () {
    expect(BackupException::fromPanel(new BackupLockedException('locked'))->reason)
        ->toBe('locked');
});

it('classifies a too-many-backups exception as limit_reached', function () {
    expect(BackupException::fromPanel(new TooManyBackupsException('limit'))->reason)
        ->toBe('limit_reached');
});

it('classifies a 429 as throttled', function () {
    expect(BackupException::fromPanel(new \RuntimeException('slow down', 429))->reason)
        ->toBe('throttled');
});

it('falls back to panel_error for unknown exceptions', function () {
    $e = new \RuntimeException('boom');
    $mapped = BackupException::fromPanel($e);
    expect($mapped->reason)->toBe('panel_error');
    expect($mapped->getPrevious())->toBe($e);
});

// ---------------------------------------------------------------------------
// Fake seam sanity — the contract the rotation policy leans on
// ---------------------------------------------------------------------------

it('enforces the limit and scopes/locks on the fake seam', function () {
    $ops = new FakePanelServerOps();
    $uuid = 'srv-1';
    $ops->setLimit($uuid, 2);

    $a = $ops->createBackup($uuid, 'one');
    $ops->createBackup($uuid, 'two');

    expect(fn () => $ops->createBackup($uuid, 'three'))
        ->toThrow(BackupException::class);

    try {
        $ops->deleteBackup('other-server', $a->uuid);
        $this->fail('expected not_found');
    } catch (BackupException $e) {
        expect($e->reason)->toBe('not_found');
    }

    $ops->deleteBackup($uuid, $a->uuid);
    expect($ops->listBackups($uuid))->toHaveCount(1);
});

// Stand-in panel exception classes (names mirror Pterodactyl's) so fromPanel()
// can be exercised without the panel installed.
class BackupLockedException extends \RuntimeException {}
class TooManyBackupsException extends \RuntimeException {}

it("applies the egg's rules to the values it writes, all or nothing (#141 item 34)", function () {
    panelTables();
    $egg = \Pterodactyl\Models\Egg::query()->create(['name' => 'Paper']);
    $egg->variables()->create(['env_variable' => 'MINECRAFT_VERSION', 'default_value' => 'latest', 'rules' => 'required|string|max:20']);
    $egg->variables()->create(['env_variable' => 'BUILD_NUMBER', 'default_value' => 'latest', 'rules' => 'required|string|max:20']);
    $server = \Pterodactyl\Models\Server::query()->create(['uuid' => 'srv-rules', 'egg_id' => $egg->id]);
    $ops = new \ServerSuite\Panel\PterodactylPanelServerOps();

    expect(fn () => $ops->setServerVariables('srv-rules', ['MINECRAFT_VERSION' => '1.21.4', 'BUILD_NUMBER' => '']))
        ->toThrow(InvalidArgumentException::class, 'The egg refuses BUILD_NUMBER');
    expect(Illuminate\Support\Facades\DB::table('server_variables')->count())->toBe(0, 'nothing half-written');

    $ops->setServerVariables('srv-rules', ['MINECRAFT_VERSION' => '1.21.4', 'BUILD_NUMBER' => '100']);
    expect(Illuminate\Support\Facades\DB::table('server_variables')->pluck('variable_value')->sort()->values()->all())->toBe(['1.21.4', '100']);
});
