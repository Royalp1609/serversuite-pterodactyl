<?php

use Pterodactyl\Facades\Activity;
use ServerSuite\Support\PanelPermissions;
use ServerSuite\Support\SuiteActivity;

/** #19: Server Suite actions in Pterodactyl's Activity tab. */

beforeEach(function () {
    Activity::$logged = [];
    Activity::$fail = false;
});

it('logs an event with the server as subject, and an actor when given', function () {
    $server = (object) ['uuid' => 'srv-1'];
    $user = (object) ['id' => 9];

    SuiteActivity::log($server, 'server:serversuite.mod-removed', ['mod' => 'Sodium']);
    SuiteActivity::log($server, 'server:serversuite.subdomain-limit-set', ['from' => 0, 'to' => 2], $user);

    expect(Activity::$logged)->toHaveCount(2)
        ->and(Activity::$logged[0])->toBe(['event' => 'server:serversuite.mod-removed', 'subject' => $server, 'properties' => ['mod' => 'Sodium'], 'actor' => null])
        ->and(Activity::$logged[1]['actor'])->toBe($user);
});

it('never breaks the feature when logging fails', function () {
    Activity::$fail = true;

    SuiteActivity::log((object) ['uuid' => 's'], 'server:serversuite.backup-created');

    expect(Activity::$logged)->toBe([]);
});

it('ships readable labels for the Activity tab', function () {
    expect(__('activity.server.serversuite.mod-removed', ['mod' => 'Sodium']))->toBe('Removed Sodium')
        ->and(__('activity.server.serversuite.subdomain-limit-set', ['from' => 0, 'to' => 2]))->toBe('Subdomain allowance changed from 0 to 2');
});

it('logs every endpoint that changes something, with a label for each event', function () {
    $src = file_get_contents(__DIR__ . '/../../extension/app/ModManagerController.php');
    $labels = require __DIR__ . '/../../lang/en/activity.php';

    foreach (PanelPermissions::ENDPOINTS as $endpoint => $perms) {
        $reads = array_filter($perms, fn ($p) => str_ends_with($p, '.read'));
        if (count($reads) === count($perms) || in_array($endpoint, ['plan', 'serverVersionPreflight'], true)) {
            continue;   // read-only, or a dry run
        }
        $start = strpos($src, "public function {$endpoint}(");
        $body = substr($src, $start, strpos($src, "\n    }\n", $start) - $start);
        expect(str_contains($body, '$this->audit(') || preg_match('/subdomainAction\(.*, \$srv, /', $body))->toBeTrue("{$endpoint} doesn't log");
    }

    preg_match_all("/audit\\(\\\$srv, '([a-z-]+)'|'([a-z-]+)'\\);\\s*\$|\\? '([a-z-]+)' : '([a-z-]+)'/m", $src, $m);
    foreach (array_filter(array_merge($m[1], $m[3], $m[4])) as $event) {
        expect($labels['server']['serversuite'])->toHaveKey($event);
    }
});
