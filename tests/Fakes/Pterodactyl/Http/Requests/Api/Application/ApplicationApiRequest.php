<?php

namespace Pterodactyl\Http\Requests\Api\Application;

use Illuminate\Foundation\Http\FormRequest;
use Pterodactyl\Services\Acl\Api\AdminAcl;

/** Test stand-in for the panel's ApplicationApiRequest: the same resource/permission check. */
abstract class ApplicationApiRequest extends FormRequest
{
    protected ?string $resource;

    protected int $permission = AdminAcl::NONE;

    public function authorize(): bool
    {
        return AdminAcl::check((string) $this->resource, $this->permission);
    }

    public function rules(): array
    {
        return [];
    }
}
