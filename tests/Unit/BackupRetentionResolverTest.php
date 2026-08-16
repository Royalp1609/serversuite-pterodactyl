<?php

use ServerSuite\Models\ModManagerGlobalSetting;
use ServerSuite\Models\ModManagerSetting;
use ServerSuite\Services\BackupRetentionResolver;

it('uses an explicit override above everything', function () {
    ModManagerGlobalSetting::setValue('backup_keep_default', '7');

    expect((new BackupRetentionResolver())->resolve('s', 9))->toBe(9);
});

it('prefers a per-server backup_keep over the global default', function () {
    $s = ModManagerSetting::forServer('s');
    $s->backup_keep = 2;
    $s->save();

    ModManagerGlobalSetting::setValue('backup_keep_default', '7');

    expect((new BackupRetentionResolver())->resolve('s'))->toBe(2);
});

it('falls back to the global default when no per-server value is set', function () {
    ModManagerGlobalSetting::setValue('backup_keep_default', '7');

    expect((new BackupRetentionResolver())->resolve('no-row'))->toBe(7);
});

it('falls back to config when neither per-server nor global is set', function () {
    expect((new BackupRetentionResolver())->resolve('no-row'))
        ->toBe((int) config('serversuite.backups.keep_default'));
});
