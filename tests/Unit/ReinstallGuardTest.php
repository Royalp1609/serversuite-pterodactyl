<?php

use Illuminate\Support\Facades\Http;
use ServerSuite\Contracts\DaemonFileClient;
use ServerSuite\Contracts\PanelServerOps;
use ServerSuite\Services\LoaderSwitchService;
use ServerSuite\Services\ServerVersionService;
use ServerSuite\Services\VersionSwitchService;
use ServerSuite\Support\ReinstallGuard;
use ServerSuite\Tests\Fakes\FakePanelServerOps;

/**
 * #3: version switch, loader switch and nuclear reset all stop/wipe/rewrite a
 * server and then rely on a reinstall. When the reinstall can't run
 * (skip_scripts on; or Pterodactyl 1.15's canBeReinstalled() says no) they
 * must refuse BEFORE touching anything.
 */

/** A Pterodactyl 1.15-style server model with canBeReinstalled(). */
class ReinstallableServer
{
    public function __construct(
        public string $uuid = 'srv-1',
        public bool $skip_scripts = false,
        public bool $allowed = true,
    ) {
    }

    public function canBeReinstalled(): bool
    {
        return $this->allowed;
    }
}

/** Daemon fake that records every call, so tests can assert nothing ran. */
function recordingDaemon(): DaemonFileClient
{
    return new class implements DaemonFileClient {
        public array $calls = [];

        public function pull(string $serverUuid, string $url, string $directory, ?string $filename = null, bool $foreground = true): void
        {
            $this->calls[] = ['pull', $directory, $filename];
        }

        public function listDirectory(string $serverUuid, string $directory): array
        {
            $this->calls[] = ['list', $directory];

            return [];
        }

        public function deleteFiles(string $serverUuid, string $directory, array $filenames): void
        {
            $this->calls[] = ['delete', $directory, $filenames];
        }

        public function readFile(string $serverUuid, string $path, int $maxBytes = 131072): ?string
        {
            return null;
        }

        public function writeFile(string $serverUuid, string $path, string $content): void
        {
        }
    };
}

/** Bind fakes and return [ops, daemon]; the server is running, so the first real step would be a stop. */
function bindSwitchFakes(): array
{
    $ops = new FakePanelServerOps();
    $ops->states['srv-1'] = ['power' => 'running', 'install' => null];
    $daemon = recordingDaemon();

    app()->instance(PanelServerOps::class, $ops);
    app()->instance(DaemonFileClient::class, $daemon);

    return [$ops, $daemon];
}

function writesOnly(array $log): array
{
    return array_values(array_filter($log, fn ($e) => $e[0] !== 'list'));
}

it('allows a normal server', function () {
    expect(ReinstallGuard::blocker((object) ['uuid' => 'srv-1', 'skip_scripts' => false]))->toBeNull();
    expect(ReinstallGuard::blocker(new ReinstallableServer()))->toBeNull();
});

it('blocks when Skip Egg Install Script is on', function () {
    expect(ReinstallGuard::blocker((object) ['uuid' => 'srv-1', 'skip_scripts' => true]))
        ->toContain('Skip Egg Install Script');
});

it('blocks skip_scripts even when 1.15 would allow the reinstall (failed install)', function () {
    // canBeReinstalled() is true for a failed install, but with skip_scripts on
    // the install script still never runs, so no new jar would be downloaded.
    expect(ReinstallGuard::blocker(new ReinstallableServer(skip_scripts: true, allowed: true)))
        ->toContain('Skip Egg Install Script');
});

it("blocks when the panel's canBeReinstalled() says no", function () {
    expect(ReinstallGuard::blocker(new ReinstallableServer(allowed: false)))
        ->toContain("won't allow this server to be reinstalled");
});

it('version switch refuses before stopping, wiping or changing variables', function () {
    [$ops, $daemon] = bindSwitchFakes();
    $server = new ReinstallableServer(skip_scripts: true);

    expect(fn () => app(VersionSwitchService::class)->apply($server, '1.21.1', null, false, true, 'clean_slate'))
        ->toThrow(RuntimeException::class, 'Cannot change version: This server has "Skip Egg Install Script" turned on');

    expect($ops->log)->toBe([]);
    expect(writesOnly($daemon->calls))->toBe([]);
});

it('nuclear reset refuses before stopping or wiping', function () {
    [$ops, $daemon] = bindSwitchFakes();
    $server = new ReinstallableServer(skip_scripts: true);

    expect(fn () => app(VersionSwitchService::class)->nuclear($server, 'clean_slate'))
        ->toThrow(RuntimeException::class, 'Cannot reset the server:');

    expect($ops->log)->toBe([]);
    expect(writesOnly($daemon->calls))->toBe([]);
});

it('loader switch refuses before stopping, wiping or swapping the egg', function () {
    [$ops, $daemon] = bindSwitchFakes();
    $server = new ReinstallableServer(skip_scripts: true);

    expect(fn () => app(LoaderSwitchService::class)->switchLoader($server, 5, '1.21.1', false))
        ->toThrow(RuntimeException::class, 'Cannot switch loader:');

    expect($ops->log)->toBe([]);
    expect(writesOnly($daemon->calls))->toBe([]);
});

it('does not block a normal server (nuclear proceeds to the stop)', function () {
    [$ops] = bindSwitchFakes();

    $result = app(VersionSwitchService::class)->nuclear(new ReinstallableServer(), 'clean_slate');

    expect($result)->toBe(['status' => 'stopping']);
    expect($ops->log)->toBe([['power', 'srv-1', 'stop']]);
});

it('shows the reason as a preflight blocker in the Versions screen', function () {
    bindSwitchFakes();
    Http::fake(['*' => Http::response([], 404)]);

    $pf = app(ServerVersionService::class)->preflight(new ReinstallableServer(skip_scripts: true), '1.21.1');

    expect($pf['can_proceed'])->toBeFalse();
    expect(implode(' ', $pf['blockers']))->toContain('Skip Egg Install Script');
});
