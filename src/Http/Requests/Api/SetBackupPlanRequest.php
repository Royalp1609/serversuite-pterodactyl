<?php

namespace ServerSuite\Http\Requests\Api;

use Pterodactyl\Http\Requests\Api\Application\ApplicationApiRequest;
use Pterodactyl\Services\Acl\Api\AdminAcl;

/** #38: PUT .../backups { enabled, interval_hours, hour, keep } needs servers write. hour (0-23, the backup timezone) pins runs to a time of day; null lets them drift. keep is capped by the server's backup limit. */
class SetBackupPlanRequest extends ApplicationApiRequest
{
    protected ?string $resource = AdminAcl::RESOURCE_SERVERS;

    protected int $permission = AdminAcl::WRITE;

    public function rules(): array
    {
        return [
            'enabled' => ['required', 'boolean'],
            'interval_hours' => ['required_if:enabled,true', 'nullable', 'integer', 'min:1', 'max:720'],
            'hour' => ['nullable', 'integer', 'min:0', 'max:23'],
            'keep' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
