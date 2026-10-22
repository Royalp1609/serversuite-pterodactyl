<?php

use ServerSuite\Contracts\PanelServerOps;
use ServerSuite\Models\ModManagerSetting;
use ServerSuite\Services\BackupRotationService;
use ServerSuite\Services\PreChangeBackup;
use ServerSuite\Support\Data\BackupRef;
use ServerSuite\Support\Data\RotationResult;

/** #24: the backup before a mod change, handed to the change as a clearance. */

beforeEach(function () {
    $this->backups = [];
    $this->limit = 5;
    $ops = Mockery::mock(PanelServerOps::class);
    $ops->shouldReceive('listBackups')->andReturnUsing(fn () => $this->backups);
    $ops->shouldReceive('serverBackupLimit')->andReturnUsing(fn () => $this->limit);
    $rotation = Mockery::mock(BackupRotationService::class);
    $rotation->shouldReceive('backupNow')->andReturnUsing(function ($uuid, $name) {
        $this->backups[] = new BackupRef('b-new', $name, 0, false, false);

        return RotationResult::rotated(new BackupRef('b-new', $name, 0, false, false), [], 5);
    });
    $this->svc = new PreChangeBackup($ops, $rotation);
});

function finishBackup(object $t): void
{
    $t->backups = [new BackupRef('b-new', $t->backups[0]->name, 10, false, true, now()->toIso8601String(), now()->toIso8601String())];
}

it('asks by default and needs an answer', function () {
    expect(PreChangeBackup::mode('srv'))->toBe('ask')
        ->and($this->svc->clear('srv', 'install Sodium', null))->toMatchArray(['status' => 'ask', 'token' => null])
        ->and($this->svc->redeem('srv', null)['ok'])->toBeFalse();
});

it('takes a backup and only clears the change once it has finished', function () {
    $c = $this->svc->clear('srv', 'install Sodium', true);
    expect($c['status'])->toBe('started')->and($c['backup'])->toBe("Pre-mod-change \u{2192} install Sodium")
        ->and($this->svc->clearanceState('srv', $c['token']))->toBe('waiting')
        ->and($this->svc->redeem('srv', $c['token'])['ok'])->toBeFalse('not before the backup is done');

    finishBackup($this);
    expect($this->svc->clearanceState('srv', $c['token']))->toBe('ready')
        ->and($this->svc->redeem('srv', $c['token']))->toBe(['ok' => true, 'backup' => "Pre-mod-change \u{2192} install Sodium", 'backup_uuid' => 'b-new', 'message' => null])
        ->and($this->svc->redeem('srv', $c['token'])['ok'])->toBeTrue('a mod and its unused dependencies share one');
});

it('lets the user skip when asked, but not when the server says always', function () {
    $skip = $this->svc->clear('srv', 'remove X', false);
    expect($skip['status'])->toBe('skipped')->and($this->svc->redeem('srv', $skip['token'])['ok'])->toBeTrue();

    ModManagerSetting::forServer('srv')->forceFill(['pre_change_backup' => 'always'])->save();
    expect($this->svc->clear('srv', 'remove X', false)['status'])->toBe('started', 'always ignores "no"')
        ->and($this->svc->redeem('srv', null)['ok'])->toBeFalse()
        ->and($this->svc->redeem('srv', 'not-a-token')['ok'])->toBeFalse();
});

it('needs no clearance when the server never backs up first, and none from another server', function () {
    ModManagerSetting::forServer('srv')->forceFill(['pre_change_backup' => 'never'])->save();
    expect($this->svc->redeem('srv', null)['ok'])->toBeTrue();

    $c = $this->svc->clear('other', 'install X', false);
    expect($this->svc->redeem('srv-2', $c['token'])['ok'])->toBeFalse('scoped to its server');
});

it('reuses a recent backup, and goes ahead where backups are off', function () {
    $this->backups = [new BackupRef('b-old', 'nightly', 10, false, true, now()->subMinutes(5)->toIso8601String(), now()->subMinutes(5)->toIso8601String())];
    expect($this->svc->clear('srv', 'update X', true))->toMatchArray(['status' => 'recent', 'backup' => 'nightly']);

    $this->limit = 0;
    expect($this->svc->clear('srv', 'update X', true)['status'])->toBe('disabled');
});
