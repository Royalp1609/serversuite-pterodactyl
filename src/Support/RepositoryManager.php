<?php

namespace ServerSuite\Support;

use Illuminate\Contracts\Foundation\Application;
use ServerSuite\Contracts\RepositoryContract;
use ServerSuite\Drivers\ModrinthDriver;
use ServerSuite\Drivers\CurseForgeDriver;
use ServerSuite\Drivers\HangarDriver;
use InvalidArgumentException;

/**
 * Holds the map of registered repository drivers and resolves them on demand.
 * Register additional providers here or via ModManagerServiceProvider::boot().
 */
class RepositoryManager
{
    /** @var array<string, class-string<RepositoryContract>|RepositoryContract> */
    protected array $drivers = [];

    /** Install-only sources (no search): not listed by keys() or searched. */
    protected array $direct = ['geysermc'];

    public function __construct(protected Application $app)
    {
        $this->registerBuiltInDrivers();
    }

    // -------------------------------------------------------------------------
    // Registration
    // -------------------------------------------------------------------------

    /**
     * Register a driver by key.
     *
     * @param  class-string<RepositoryContract>|RepositoryContract $driver
     */
    public function register(string $key, string|RepositoryContract $driver): void
    {
        $this->drivers[$key] = $driver;
    }

    // -------------------------------------------------------------------------
    // Resolution
    // -------------------------------------------------------------------------

    /**
     * Resolve a single driver by key.
     */
    public function driver(string $key): RepositoryContract
    {
        if (! isset($this->drivers[$key])) {
            throw new InvalidArgumentException("ModManager: unknown repository driver [{$key}].");
        }

        $driver = $this->drivers[$key];

        if (is_string($driver)) {
            $driver = $this->app->make($driver);
            $this->drivers[$key] = $driver; // cache resolved instance
        }

        return $driver;
    }

    /**
     * Resolve all registered drivers that are currently available (API key set, etc.)
     *
     * @param  string[]|null $only  Limit to these keys (e.g. from server settings)
     * @return RepositoryContract[]
     */
    public function enabledDrivers(?array $only = null): array
    {
        $keys = $only ?? $this->keys();

        return array_values(
            array_filter(
                array_map(
                    fn ($key) => isset($this->drivers[$key]) ? $this->driver($key) : null,
                    $keys
                ),
                fn ($driver) => $driver !== null && $driver->isAvailable()
            )
        );
    }

    /**
     * Return all registered driver keys.
     *
     * @return string[]
     */
    public function keys(): array
    {
        return array_values(array_diff(array_keys($this->drivers), $this->direct));
    }

    // -------------------------------------------------------------------------
    // Built-in drivers
    // -------------------------------------------------------------------------

    protected function registerBuiltInDrivers(): void
    {
        $this->register('modrinth',   ModrinthDriver::class);
        $this->register('curseforge', CurseForgeDriver::class);
        $this->register('hangar',     HangarDriver::class);
        $this->register('geysermc',   \ServerSuite\Drivers\GeyserMcDriver::class);
    }
}
