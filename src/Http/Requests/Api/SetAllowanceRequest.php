<?php

namespace ServerSuite\Http\Requests\Api;

use Pterodactyl\Http\Requests\Api\Application\ApplicationApiRequest;
use Pterodactyl\Services\Acl\Api\AdminAcl;

/** #38: PUT .../subdomains { allowance } needs servers write. */
class SetAllowanceRequest extends ApplicationApiRequest
{
    protected ?string $resource = AdminAcl::RESOURCE_SERVERS;

    protected int $permission = AdminAcl::WRITE;

    public function rules(): array
    {
        return ['allowance' => ['required', 'integer', 'min:0', 'max:100']];
    }
}
