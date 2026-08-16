<?php

namespace ServerSuite\Services;

/**
 * Detects which panel variant the plugin is running inside and exposes
 * simple boolean helpers used by the service provider and UI layers.
 *
 * Detection order:
 *   1. Explicit config value ('pterodactyl' | 'pelican')
 *   2. Presence of Pelican-specific classes
 *   3. Presence of Pterodactyl-specific classes
 *   4. Falls back to 'pterodactyl'
 */
class PanelCompatService
{
    protected string $panel;

    public function __construct(string $configuredPanel = 'auto')
    {
        $this->panel = match ($configuredPanel) {
            'pelican', 'pterodactyl' => $configuredPanel,
            default => $this->detect(),
        };
    }

    public function panel(): string
    {
        return $this->panel;
    }

    public function isPelican(): bool
    {
        return $this->panel === 'pelican';
    }

    public function isPterodactyl(): bool
    {
        return $this->panel === 'pterodactyl';
    }

    // -------------------------------------------------------------------------

    protected function detect(): string
    {
        // Pelican ships with Filament and uses a distinct root namespace
        if (class_exists(\Filament\FilamentServiceProvider::class)
            && class_exists(\App\Filament\Pages\Dashboard::class)) {
            return 'pelican';
        }

        // Classic Pterodactyl ships with its own root service provider
        if (class_exists(\Pterodactyl\Providers\AppServiceProvider::class)) {
            return 'pterodactyl';
        }

        return 'pterodactyl';
    }
}
