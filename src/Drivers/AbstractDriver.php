<?php

namespace ServerSuite\Drivers;

use Illuminate\Support\Facades\Http;
use Illuminate\Http\Client\PendingRequest;
use ServerSuite\Contracts\RepositoryContract;

abstract class AbstractDriver implements RepositoryContract
{
    /**
     * Return a pre-configured HTTP client for this provider.
     */
    protected function http(): PendingRequest
    {
        return Http::timeout(15)
            ->retry(2, 500)
            ->withHeaders($this->defaultHeaders())
            ->baseUrl($this->baseUrl());
    }

    /**
     * The base URL for this provider's API (from config).
     */
    abstract protected function baseUrl(): string;

    /**
     * Default headers to send with every request.
     *
     * @return array<string, string>
     */
    protected function defaultHeaders(): array
    {
        return [
            'Accept'     => 'application/json',
            'User-Agent' => 'PterodactylModManager/1.0 (contact@example.com)',
        ];
    }
}
