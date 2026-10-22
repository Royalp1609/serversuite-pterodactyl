<?php

namespace ServerSuite\Http\Controllers\Api;

use Pterodactyl\Models\Server;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use ServerSuite\Http\Requests\Api\ReadServerRequest;
use ServerSuite\Http\Requests\Api\SetAllowanceRequest;
use ServerSuite\Http\Requests\Api\SetBackupPlanRequest;
use ServerSuite\Models\ModManagerSetting;
use ServerSuite\Models\Subdomain;
use ServerSuite\Services\BackupRetentionResolver;
use ServerSuite\Services\SubdomainService;
use ServerSuite\Support\BackupSchedule;
use ServerSuite\Support\SuiteActivity;

/**
 * Application API for billing (#38): what Paymenter sells on top of a
 * server, the subdomain allowance and the backup plan, plus a read of the
 * server's Server Suite state. Mods, modpacks and versions stay free and
 * aren't here.
 *
 * Writes are idempotent (billing systems retry): the same request twice
 * changes nothing the second time, and only a real change is written to the
 * server's activity log. No DNS token is ever accepted or returned.
 */
class BillingApiController extends Controller
{
    public function show(ReadServerRequest $request, string $ref): JsonResponse
    {
        return $this->state($this->server($ref));
    }

    public function subdomains(SetAllowanceRequest $request, string $ref): JsonResponse
    {
        $server = $this->server($ref);
        $allowance = (int) $request->validated('allowance');
        $before = (int) ModManagerSetting::forServer($server->uuid)->subdomain_limit;
        if ($before !== $allowance) {
            app(SubdomainService::class)->setAllowance($server->uuid, $allowance);
            SuiteActivity::log($server, 'server:serversuite.subdomain-limit-set', ['from' => $before, 'to' => $allowance, 'via' => 'api'], $request->user());
        }

        return $this->state($server);
    }

    public function backups(SetBackupPlanRequest $request, string $ref): JsonResponse
    {
        $server = $this->server($ref);
        $v = $request->validated();
        $setting = ModManagerSetting::forServer($server->uuid);
        $plan = [
            'backup_rotation_enabled' => (bool) $v['enabled'],
            'backup_interval_hours' => isset($v['interval_hours']) ? (int) $v['interval_hours'] : $setting->backup_interval_hours,
            'backup_schedule_hour' => array_key_exists('hour', $v) ? ($v['hour'] === null ? null : (int) $v['hour']) : $setting->backup_schedule_hour,
            'backup_keep' => array_key_exists('keep', $v) ? ($v['keep'] === null ? null : (int) $v['keep']) : $setting->backup_keep,
        ];
        $setting->forceFill($plan);
        if ($setting->isDirty()) {
            $changed = array_keys($setting->getDirty());
            $setting->save();
            SuiteActivity::log($server, 'server:serversuite.backup-plan-set', ['changed' => $changed, 'via' => 'api'] + $plan, $request->user());
        }

        return $this->state($server);
    }

    // -------------------------------------------------------------------------

    /** The panel id, or external:<id> for the billing system's id. */
    private function server(string $ref): Server
    {
        $q = Server::query();
        if (str_starts_with($ref, 'external:')) {
            $external = substr($ref, 9);
            abort_if($external === '', 404);

            return $q->where('external_id', $external)->firstOrFail();
        }
        abort_unless(ctype_digit($ref), 404);

        return $q->findOrFail((int) $ref);
    }

    private function state(Server $server): JsonResponse
    {
        $s = ModManagerSetting::forServer($server->uuid);
        $subs = Subdomain::query()->where('server_uuid', $server->uuid)->orderBy('fqdn')->get();
        $interval = (int) ($s->backup_interval_hours ?: config('serversuite.backups.default_interval_hours', 12));
        $hour = $s->backup_schedule_hour === null || $s->backup_schedule_hour === '' ? null : (int) $s->backup_schedule_hour;
        $next = null;
        if ($s->backup_rotation_enabled) {
            try {
                $next = BackupSchedule::nextRun($interval, $hour, $s->backup_last_run_at, BackupSchedule::now())?->toIso8601String();
            } catch (\Throwable) {
            }
        }

        return response()->json([
            'object' => 'serversuite_server',
            'attributes' => [
                'id' => $server->id,
                'uuid' => $server->uuid,
                'external_id' => $server->external_id,
                'subdomains' => [
                    'allowance' => (int) $s->subdomain_limit,
                    'used' => $subs->count(),
                    'over_allowance' => $subs->count() > (int) $s->subdomain_limit,
                    'items' => $subs->map(fn (Subdomain $d) => ['fqdn' => $d->fqdn, 'own_domain' => $d->domain_id === null])->values()->all(),
                ],
                'game' => ['loader' => $s->loader ?: null, 'minecraft' => $s->game_version ?: null],
                'backups' => [
                    'enabled' => (bool) $s->backup_rotation_enabled,
                    'interval_hours' => $s->backup_interval_hours === null ? null : (int) $s->backup_interval_hours,
                    'hour' => $hour,
                    'keep' => $s->backup_keep === null ? null : (int) $s->backup_keep,
                    'effective_keep' => app(BackupRetentionResolver::class)->resolve($server->uuid),
                    'backup_limit' => (int) $server->backup_limit,
                    'timezone' => BackupSchedule::timezone(),
                    'next_run' => $next,
                ],
            ],
        ]);
    }
}
