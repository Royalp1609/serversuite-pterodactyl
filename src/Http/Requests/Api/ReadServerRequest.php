<?php

namespace ServerSuite\Http\Requests\Api;

use Pterodactyl\Http\Requests\Api\Application\ApplicationApiRequest;
use Pterodactyl\Services\Acl\Api\AdminAcl;

/** #38: reading a server's Server Suite state needs the key's servers read permission. */
class ReadServerRequest extends ApplicationApiRequest
{
    protected ?string $resource = AdminAcl::RESOURCE_SERVERS;

    protected int $permission = AdminAcl::READ;
}
