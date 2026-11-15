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
     * "Latest" for updates, update checks and installs without a pick (#126):
     * the newest release build. Only a project with no release builds at all
     * falls back to its newest beta, then alpha, so beta-only mods still
     * install, but a mod on a release is never moved onto a pre-release.
     *
     * @param \ServerSuite\Support\Data\ModVersion[] $versions newest first
     */
    protected static function newestStable(array $versions): ?\ServerSuite\Support\Data\ModVersion
    {
        foreach (['release', 'beta', 'alpha'] as $channel) {
            foreach ($versions as $v) {
                if (($v->channel ?: 'release') === $channel) {
                    return $v;
                }
            }
        }

        return $versions[0] ?? null;
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
            'User-Agent' => (string) config('serversuite.versions.user_agent', 'ServerSuite'),   // item 76: one identifying agent, set in config
        ];
    }
}
