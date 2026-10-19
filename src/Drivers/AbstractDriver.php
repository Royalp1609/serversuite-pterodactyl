<?php

namespace ServerSuite\Drivers;

use Illuminate\Support\Facades\Http;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use ServerSuite\Contracts\RepositoryContract;

abstract class AbstractDriver implements RepositoryContract
{
    /**
     * Return a pre-configured HTTP client for this provider.
     *
     * Retries only transient failures (connection errors, 5xx, 429), and
     * never throws on a status code: callers check the response themselves
     * (e.g. 404 → null, other failures → RepositoryException).
     */
    protected function http(): PendingRequest
    {
        return Http::timeout(15)
            ->retry(2, 500, fn (\Throwable $e) => $e instanceof ConnectionException
                || ($e instanceof RequestException
                    && ($e->response->serverError() || $e->response->status() === 429)), throw: false)
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
