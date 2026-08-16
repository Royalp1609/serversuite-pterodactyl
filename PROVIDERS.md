# Adding a new mod repository provider

ModManager uses a driver-based adapter layer so any mod repository can be added
without touching core code. Follow the steps below.

---

## 1. Create a driver class

```
src/Drivers/MyProviderDriver.php
```

Your driver must extend `ModManager\Drivers\AbstractDriver` and implement
`ModManager\Contracts\RepositoryContract`.

```php
<?php

namespace ModManager\Drivers;

use ModManager\Support\Data\ModResult;
use ModManager\Support\Data\ModVersion;

class MyProviderDriver extends AbstractDriver
{
    public function key(): string   { return 'myprovider'; }
    public function label(): string { return 'My Provider'; }

    public function isAvailable(): bool
    {
        // Return false if an API key is required but not configured
        return config('modmanager.repositories.myprovider', false);
    }

    protected function baseUrl(): string
    {
        return config('modmanager.urls.myprovider', 'https://api.myprovider.com/v1');
    }

    public function search(string $query, ?string $loader = null, ?string $gameVersion = null, int $limit = 20): array
    {
        $response = $this->http()->get('/search', [
            'query' => $query,
            'limit' => $limit,
        ]);

        // Map raw API response → ModResult[]
        return collect($response->json('results', []))
            ->map(fn ($item) => new ModResult(
                source: $this->key(),
                id: $item['id'],
                // ... map fields
            ))
            ->all();
    }

    public function get(string $modId): ?ModResult         { /* ... */ }
    public function versions(string $modId, ?string $loader = null, ?string $gameVersion = null): array { /* ... */ }
    public function latestVersion(string $modId, ?string $loader = null, ?string $gameVersion = null): ?ModVersion { /* ... */ }
}
```

---

## 2. Register the driver

In `ModManagerServiceProvider::boot()` (or in a separate service provider):

```php
use ModManager\Support\RepositoryManager;
use ModManager\Drivers\MyProviderDriver;

$this->app->resolving(RepositoryManager::class, function (RepositoryManager $manager) {
    $manager->register('myprovider', MyProviderDriver::class);
});
```

---

## 3. Add config entries

In `config/modmanager.php`:

```php
'repositories' => [
    // ... existing entries
    'myprovider' => env('MOD_MANAGER_MYPROVIDER_ENABLED', false),
],

'urls' => [
    // ... existing entries
    'myprovider' => env('MOD_MANAGER_MYPROVIDER_URL', 'https://api.myprovider.com/v1'),
],
```

And add to `.env.example`:

```dotenv
MOD_MANAGER_MYPROVIDER_ENABLED=false
# MOD_MANAGER_MYPROVIDER_URL=https://api.myprovider.com/v1
MYPROVIDER_API_KEY=
```

---

## 4. Add a migration (if needed)

If your provider needs a new enum value in `mod_manager_settings`, add a migration:

```php
Schema::table('mod_manager_settings', function (Blueprint $table) {
    $table->boolean('myprovider_enabled')->default(false)->after('hangar_enabled');
});
```

---

## Built-in providers for reference

| Key          | Class                   | Auth required |
|--------------|-------------------------|---------------|
| `modrinth`   | `ModrinthDriver`        | None          |
| `curseforge` | `CurseForgeDriver`      | API key       |
| `hangar`     | `HangarDriver`          | None          |

---

## Notes

- The `RepositoryManager` caches resolved driver instances — registering a class
  string is preferred over instantiating the driver yourself.
- All drivers must handle rate limits gracefully. Use `retry(2, 500)` on the
  HTTP client at minimum; add exponential back-off for providers with strict limits.
- CurseForge rate-limits to 10,000 requests/day on the free tier — cache search
  results aggressively using Laravel's cache layer.
