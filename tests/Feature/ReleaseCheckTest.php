<?php

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use ServerSuite\Jobs\SendNotificationJob;
use ServerSuite\Services\Notifier;
use ServerSuite\Services\ReleaseCheckService;

/** #12: tell panel admins when a newer Server Suite release is out. */

function releasesAt(string $installed): ReleaseCheckService
{
    $svc = new class extends ReleaseCheckService {
        public string $v = '';

        public function installedVersion(): string
        {
            return $this->v;
        }
    };
    $svc->v = $installed;
    app()->instance(ReleaseCheckService::class, $svc);

    return $svc;
}

function release(string $tag, array $extra = []): array
{
    return $extra + [
        'tag_name' => $tag, 'name' => "Server Suite {$tag}", 'draft' => false,
        'prerelease' => str_contains($tag, '-'), 'body' => "Server Suite {$tag} for Pterodactyl.",
        'html_url' => "https://github.com/royalp1609/serversuite-pterodactyl/releases/tag/{$tag}",
    ];
}

function fakeFeed(array $releases): void
{
    Http::fake(['api.github.com/*' => Http::response($releases)]);
}

it('orders versions by SemVer, including beta and rc suffixes', function () {
    $ordered = ['0.9.9', '1.0.0-alpha', '1.0.0-alpha.1', '1.0.0-alpha.beta', '1.0.0-beta', '1.0.0-beta.2',
        '1.0.0-beta.11', '1.0.0-rc.1', '1.0.0-rc.2', '1.0.0-rc.10', '1.0.0', '1.0.1', '1.1.0', '2.0.0'];
    foreach ($ordered as $i => $lower) {
        foreach (array_slice($ordered, $i + 1) as $higher) {
            expect(ReleaseCheckService::compare($lower, $higher))->toBeLessThan(0, "{$lower} < {$higher}")
                ->and(ReleaseCheckService::compare($higher, $lower))->toBeGreaterThan(0, "{$higher} > {$lower}");
        }
    }
    expect(ReleaseCheckService::compare('v1.2.3', '1.2.3'))->toBe(0)
        ->and(ReleaseCheckService::compare('1.2.3+build.5', '1.2.3'))->toBe(0)
        ->and(ReleaseCheckService::compare('not-a-version', '0.0.1'))->toBeLessThan(0);
});

it('picks the newest release, skipping drafts, odd tags, and pre-releases for a stable panel', function () {
    $feed = [release('v1.0.0'), release('v1.1.0-rc.1'), release('v1.0.2'), release('v9.9.9', ['draft' => true]), release('nightly'), release('v1.0.1')];

    expect(ReleaseCheckService::newest($feed, false)['version'])->toBe('1.0.2')
        ->and(ReleaseCheckService::newest($feed, true)['version'])->toBe('1.1.0-rc.1')
        ->and(ReleaseCheckService::newest([], true))->toBeNull()
        ->and(ReleaseCheckService::newest([release('v1.0.0', ['html_url' => 'https://evil.example/x'])], false)['url'])->toBe('');
});

it('shows a notice for a newer release, and none when up to date', function () {
    fakeFeed([release('v1.0.0-rc.1'), release('v1.0.0-rc.2')]);
    $svc = releasesAt('1.0.0-rc.1');
    $svc->check();

    expect($svc->notice())->toMatchArray(['installed' => '1.0.0-rc.1', 'version' => '1.0.0-rc.2', 'security' => false]);

    $svc->v = '1.0.0-rc.2';
    expect($svc->notice())->toBeNull();
});

it('marks a security release', function () {
    fakeFeed([release('v1.0.1', ['body' => "**Security release.** Upgrade soon.\n\nServer Suite 1.0.1 for Pterodactyl."])]);
    $svc = releasesAt('1.0.0');
    $svc->check();

    expect($svc->notice()['security'])->toBeTrue()
        ->and(ReleaseCheckService::isSecurity(release('v1.0.2')))->toBeFalse()
        ->and(ReleaseCheckService::isSecurity(release('v1.0.2', ['body' => 'Fixes a security header typo.'])))->toBeFalse();
});

it('hides a dismissed notice until the next release', function () {
    $svc = releasesAt('1.0.0');
    fakeFeed([release('v1.0.1')]);
    $svc->check();
    $svc->dismiss();
    expect($svc->notice())->toBeNull();

    $svc->check(); // same release again
    expect($svc->notice())->toBeNull();

    Http::swap(new Illuminate\Http\Client\Factory());
    fakeFeed([release('v1.0.1'), release('v1.0.2')]);
    $svc->check();
    expect($svc->notice()['version'])->toBe('1.0.2');
});

it('leaves no notice and no error when the feed fails', function () {
    $svc = releasesAt('1.0.0');
    Http::fake(['api.github.com/*' => Http::response('rate limited', 403)]);
    expect($svc->check())->toBeNull()->and($svc->notice())->toBeNull();

    Http::swap(new Illuminate\Http\Client\Factory());
    Http::fake(fn () => throw new Illuminate\Http\Client\ConnectionException('timed out'));
    expect($svc->check())->toBeNull()->and($svc->notice())->toBeNull();

    Http::swap(new Illuminate\Http\Client\Factory());
    Http::fake(['api.github.com/*' => Http::response('<html>', 200)]);
    expect($svc->check())->toBeNull()->and($svc->notice())->toBeNull();
});

it('keeps the last known release when a later check fails', function () {
    $svc = releasesAt('1.0.0');
    fakeFeed([release('v1.0.1')]);
    $svc->check();

    Http::swap(new Illuminate\Http\Client\Factory());
    Http::fake(['api.github.com/*' => Http::response('', 500)]);
    $svc->check();

    expect($svc->notice()['version'])->toBe('1.0.1');
});

it('makes no request and shows nothing when turned off', function () {
    $svc = releasesAt('1.0.0');
    fakeFeed([release('v1.0.1')]);
    $svc->check();
    $svc->setEnabled(false);

    Http::swap(new Illuminate\Http\Client\Factory());
    Http::fake();
    Artisan::call('serversuite:check-release');

    Http::assertNothingSent();
    expect($svc->check())->toBeNull()->and($svc->notice())->toBeNull();
});

it('sends only a plain GET to the release feed, nothing about the panel', function () {
    $svc = releasesAt('1.0.0');
    fakeFeed([]);
    $svc->check();

    Http::assertSent(fn ($r) => $r->method() === 'GET'
        && $r->url() === ReleaseCheckService::FEED
        && $r->body() === ''
        && ! $r->hasHeader('Authorization'));
});

it('tells the admin default targets once per new release', function () {
    Queue::fake();
    app(Notifier::class)->saveDefaults([['type' => 'ntfy', 'server' => 'https://ntfy.sh', 'topic' => 'ss-admin']], []);
    $svc = releasesAt('1.0.0');

    fakeFeed([release('v1.0.1')]);
    $svc->check();
    $svc->check();
    Queue::assertPushed(SendNotificationJob::class, 1);

    Http::swap(new Illuminate\Http\Client\Factory());
    fakeFeed([release('v1.0.2')]);
    $svc->check();
    Queue::assertPushed(SendNotificationJob::class, 2);
});

it('reads its own version from composer.json', function () {
    $composer = json_decode(file_get_contents(__DIR__ . '/../../composer.json'), true);
    expect((new ReleaseCheckService())->installedVersion())->toBe($composer['version']);
});
