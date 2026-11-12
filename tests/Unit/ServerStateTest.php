<?php

use ServerSuite\Support\ServerState;

/** #116: the states Server Suite refuses, the same ones the panel's client API refuses for everyone. */
it('refuses a suspended server', function () {
    expect(ServerState::blocker((object) ['status' => 'suspended']))->toContain('suspended');
});

it('refuses a server whose node is in maintenance', function () {
    expect(ServerState::blocker((object) ['status' => null, 'node' => (object) ['maintenance_mode' => true]]))->toContain('maintenance');
});

it('uses the panel model methods when they are there', function () {
    $server = new class {
        public ?string $status = null;
        public object $node;

        public function __construct()
        {
            $this->node = new class {
                public function isUnderMaintenance(): bool
                {
                    return true;
                }
            };
        }

        public function isSuspended(): bool
        {
            return true;
        }
    };

    expect(ServerState::blocker($server))->toContain('suspended');
});

it('leaves installing, restoring and normal servers alone', function () {
    foreach ([null, 'installing', 'install_failed', 'restoring_backup'] as $status) {
        expect(ServerState::blocker((object) ['status' => $status, 'node' => (object) ['maintenance_mode' => false]]))->toBeNull();
    }
    expect(ServerState::blocker((object) []))->toBeNull();
});

it('is checked by every endpoint after the permission check, with a 409', function () {
    $src = file_get_contents(__DIR__ . '/../../extension/app/ModManagerController.php');
    $fn = substr($src, strpos($src, 'protected function authorizeEndpoint('));
    $fn = substr($fn, 0, strpos($fn, "\n    }\n"));

    expect($fn)->toContain('ServerState::blocker($server)')->toContain('abort(409, $state)')
        ->and(strpos($fn, 'PanelPermissions::allows'))->toBeLessThan(strpos($fn, 'ServerState::blocker'));
});
